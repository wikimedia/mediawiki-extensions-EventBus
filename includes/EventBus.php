<?php

/**
 * Event delivery.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @author Eric Evans, Andrew Otto
 */

namespace MediaWiki\Extension\EventBus;

use Exception;
use FormatJson;
use InvalidArgumentException;
use MediaWiki\Context\RequestContext;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MultiHttpClient;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Wikimedia\Assert\Assert;

class EventBus {

	/**
	 * @const int the special event type indicating no events should be accepted.
	 */
	public const TYPE_NONE = 0;

	/**
	 * @const int the event type indicating that the event is a regular mediawiki event.
	 */
	public const TYPE_EVENT = 1;

	/**
	 * @const int the event type indicating that the event is a serialized job.
	 */
	public const TYPE_JOB = 2;

	/**
	 * @const int the event type indicating that the event is a CDN purge.
	 */
	public const TYPE_PURGE = 4;

	/**
	 * @const int the event type indicating any event type. (TYPE_EVENT ^ TYPE_EVENT)
	 */
	public const TYPE_ALL = self::TYPE_EVENT | self::TYPE_JOB | self::TYPE_PURGE;

	/**
	 * @const array names of the event type constants defined above
	 */
	private const EVENT_TYPE_NAMES = [
		'TYPE_NONE' => self::TYPE_NONE,
		'TYPE_EVENT' => self::TYPE_EVENT,
		'TYPE_JOB' => self::TYPE_JOB,
		'TYPE_PURGE' => self::TYPE_PURGE,
		'TYPE_ALL' => self::TYPE_ALL,
	];

	/** @const int Default HTTP request timeout in seconds */
	private const DEFAULT_REQUEST_TIMEOUT = 10;

	/** @var LoggerInterface instance for all EventBus instances */
	private static $logger;

	/** @var MultiHttpClient */
	private $http;

	/** @var string EventServiceUrl for this EventBus instance */
	private $url;

	/** @var int HTTP request timeout for this EventBus instance */
	private $timeout;

	/** @var int which event types are allowed to be sent (TYPE_NONE|TYPE_EVENT|TYPE_JOB|TYPE_PURGE|TYPE_ALL) */
	private $allowedEventTypes;

	/** @var EventFactory|null event creator */
	private $eventFactory;

	/** @var int Maximum byte size of a batch */
	private $maxBatchByteSize;

	/** @var bool Whether to forward the X-Client-IP header, if present */
	private $forwardXClientIP;

	/**
	 * @param MultiHttpClient $http
	 * @param string|int $enableEventBus A value of the wgEnableEventBus config, or a bitmask
	 * of TYPE_* constants
	 * @param EventFactory $eventFactory EventFactory to use for event construction.
	 * @param string $url EventBus service endpoint URL. E.g. http://localhost:8085/v1/events
	 * @param int $maxBatchByteSize Maximum byte size of a batch
	 * @param int|null $timeout HTTP request timeout in seconds, defaults to 5.
	 * @param bool $forwardXClientIP Whether the X-Client-IP header should be forwarded
	 *   to the intake service, if present
	 */
	public function __construct(
		MultiHttpClient $http,
		$enableEventBus,
		EventFactory $eventFactory,
		string $url,
		int $maxBatchByteSize,
		int $timeout = null,
		bool $forwardXClientIP = false
	) {
		$this->http = $http;
		$this->url = $url;
		$this->maxBatchByteSize = $maxBatchByteSize;
		$this->timeout = $timeout ?: self::DEFAULT_REQUEST_TIMEOUT;
		$this->eventFactory = $eventFactory;
		$this->forwardXClientIP = $forwardXClientIP;

		if ( is_int( $enableEventBus ) ) {
			Assert::precondition(
				(int)( $enableEventBus & self::TYPE_ALL ) === $enableEventBus,
				'Invalid $enableEventBus parameter: ' . $enableEventBus
			);
			$this->allowedEventTypes = $enableEventBus;
		} elseif ( is_string( $enableEventBus ) && $enableEventBus ) {
			$this->allowedEventTypes = self::TYPE_NONE;
			$allowedTypes = explode( '|', $enableEventBus );
			foreach ( $allowedTypes as $allowedType ) {
				Assert::precondition(
					array_key_exists( $allowedType, self::EVENT_TYPE_NAMES ),
					"EnableEventBus: $allowedType not recognized"
				);
				$this->allowedEventTypes |= self::EVENT_TYPE_NAMES[$allowedType];
			}
		} else {
			$this->allowedEventTypes = self::TYPE_ALL;
		}
	}

	/**
	 * @param array $events
	 * @param int $serializedSize
	 * @return array
	 */
	private function partitionEvents( array $events, int $serializedSize ): array {
		$results = [];

		if ( count( $events ) > 1 ) {
			$numOfChunks = ceil( $serializedSize / $this->maxBatchByteSize );
			$partitions = array_chunk( $events, (int)floor( count( $events ) / $numOfChunks ) );
			foreach ( $partitions as $partition ) {
				$serializedPartition = self::serializeEvents( $partition );
				if ( strlen( $serializedPartition ) > $this->maxBatchByteSize ) {
					$results = array_merge(
						$results,
						$this->partitionEvents( $partition, strlen( $serializedPartition ) )
					);
				} else {
					$results[] = $serializedPartition;
				}
			}
		} else {
			self::logger()->warning(
				"Event is larger than the maxBatchByteSize set.",
				[
					'raw_event' => self::prepareEventsForLogging( $events )
				]
			);
			$results = [ self::serializeEvents( $events ) ];
		}
		return $results;
	}

	/**
	 * Deliver an array of events to the remote service.
	 *
	 * @param array|string $events the events to send.
	 * @param int $type the type of the event being sent.
	 * @return array|bool|string True on success or an error string or array on failure
	 * @throws Exception
	 */
	public function send( $events, $type = self::TYPE_EVENT ) {
		if ( !$this->shouldSendEvent( $type ) ) {
			return "Events of type '$type' are not enqueueable";
		}
		if ( !$events ) {
			// Logstash doesn't like the args, because they could be of various types
			$context = [ 'exception' => new RuntimeException() ];
			self::logger()->error( 'Must call send with at least 1 event. Aborting send.', $context );
			return "Provided event list is empty";
		}

		// If we already have a JSON string of events, just use it as the body.
		if ( is_string( $events ) ) {
			if ( strlen( $events ) > $this->maxBatchByteSize ) {
				$decodeEvents = FormatJson::decode( $events, true );
				$body = $this->partitionEvents( $decodeEvents, strlen( $events ) );
			} else {
				$body = $events;
			}
		} else {
			self::validateJSONSerializable( $events );
			// Else serialize the array of events to a JSON string.
			$serializedEvents = self::serializeEvents( $events );
			// If not $body, then something when wrong.
			// serializeEvents has already logged, so we can just return.
			if ( !$serializedEvents ) {
				return "Unable to serialize events";
			}

			if ( strlen( $serializedEvents ) > $this->maxBatchByteSize ) {
				$body = $this->partitionEvents( $events, strlen( $serializedEvents ) );
			} else {
				$body = $serializedEvents;
			}
		}

		$originalRequest = RequestContext::getMain()->getRequest();

		$reqs = array_map( function ( $body ) use ( $originalRequest ) {
			$req = [
				'url'		=> $this->url,
				'method'	=> 'POST',
				'body'		=> $body,
				'headers'	=> [ 'content-type' => 'application/json' ]
			];
			if ( $this->forwardXClientIP ) {
				$req['headers']['x-client-ip'] = $originalRequest->getIP();
			}
			return $req;
		}, is_array( $body ) ? $body : [ $body ] );

		$responses = $this->http->runMulti(
			$reqs,
			[
				'reqTimeout' => $this->timeout
			]
		);

		// 201: all events accepted.
		// 202: all events accepted but not necessarily persisted. HTTP response is returned 'hastily'.
		// 207: some but not all events accepted: either due to validation failure or error.
		// 400: no events accepted: all failed schema validation.
		// 500: no events accepted: at least one caused an error, but some might have been invalid.
		$results = [];
		foreach ( $responses as $response ) {
			$res = $response['response'];
			if ( $res['code'] == 207 || $res['code'] >= 300 ) {
				$message = empty( $res['error'] ) ?
					(string)$res['code'] . ': ' . (string)$res['reason'] : $res['error'];
				// Limit the maximum size of the logged context to 8 kilobytes as that's where logstash
				// truncates the JSON anyway
				$context = [
					'raw_events' => self::prepareEventsForLogging( $body ),
					'service_response' => $res,
					'exception' => new RuntimeException(),
				];
				self::logger()->error( "Unable to deliver all events: {$message}", $context );

				$results[] = "Unable to deliver all events: $message";
			}
		}

		if ( $results !== [] ) {
			return $results;
		}

		return true;
	}

	// == static helper functions below ==

	/**
	 * Serializes $events array to a JSON string.  If FormatJson::encode()
	 * returns false, this will log a detailed error message and return null.
	 *
	 * @param array $events
	 * @return string|null JSON or null on failure
	 */
	public static function serializeEvents( $events ) {
		try {
			$serializedEvents = FormatJson::encode( $events, false, FormatJson::ALL_OK );
			if ( !$serializedEvents ) {
				// Something failed. Let's figure out exactly which one.
				$bad = [];
				foreach ( $events as $event ) {
					$result = FormatJson::encode( $event, false, FormatJson::ALL_OK );
					if ( !$result ) {
						$bad[] = $event;
					}
				}
				$context = [
					'exception' => new RuntimeException(),
					'json_last_error' => json_last_error_msg(),
					// Use PHP serialization since that will *always* work.
					'events' => serialize( $bad ),
				];
				self::logger()->error(
					'FormatJson::encode($events) failed: ' . $context['json_last_error'] .
					'. Aborting send.', $context
				);
				return null;
			}
			return $serializedEvents;
		} catch ( Exception $exception ) {
			$context = [
				'exception' => $exception,
				'json_last_error' => json_last_error_msg()
			];
			self::logger()->error(
				'FormatJson::encode($events) thrown exception: ' . $context['json_last_error'] .
				'. Aborting send.', $context
			);
			return null;
		}
	}

	/**
	 * Prepares events for logging - serializes if needed, limits the size
	 * of the serialized event to 8kb.
	 *
	 * @param string|array $events
	 * @return string|null
	 */
	private static function prepareEventsForLogging( $events ) {
		if ( is_array( $events ) ) {
			$events = self::serializeEvents( $events );
		}

		if ( $events === null ) {
			return null;
		}

		return strlen( $events ) > 8192 ? substr( $events, 0, 8192 ) : $events;
	}

	/**
	 * If $value is a string, but not UTF-8 encoded, then assume it is binary
	 * and base64 encode it and prefix it with a content type.
	 * @param mixed $value
	 * @return mixed
	 */
	public static function replaceBinaryValues( $value ) {
		if ( is_string( $value ) && !mb_check_encoding( $value, 'UTF-8' ) ) {
			return 'data:application/octet-stream;base64,' . base64_encode( $value );
		}
		return $value;
	}

	/**
	 * Recursively calls replaceBinaryValues on an array and transforms
	 * any binary values.  $array is passed by reference and will be modified.
	 * @param array &$array
	 * @return bool return value of array_walk_recursive
	 */
	public static function replaceBinaryValuesRecursive( &$array ) {
		return array_walk_recursive( $array, function ( &$item, $key ) {
			$item = self::replaceBinaryValues( $item );
		} );
	}

	/**
	 * Checks a part of the event for JSON-serializability
	 *
	 * @param array $originalEvent an original event that is being checked.
	 * @param array $eventPart the sub-object nested in the original event to be checked.
	 */
	private static function checkEventPart( $originalEvent, $eventPart ) {
		foreach ( $eventPart as $p => $v ) {
			if ( is_array( $v ) ) {
				self::checkEventPart( $originalEvent, $v );
			} elseif ( !is_scalar( $v ) && $v !== null ) {
				// Only log the first appearance of non-scalar property per event as jobs
				// might contain hundreds of properties and we do not want to log everything.
				self::logger()->error( 'Non-scalar value found in the event', [
					'raw_events' => self::prepareEventsForLogging( [ $originalEvent ] ),
					'prop_name' => $p,
					'prop_val_type' => get_class( $v )
				] );
				// Follow to the next event in the array
				return;
			}
		}
	}

	/**
	 * Checks if the event is JSON-serializable (contains only scalar values)
	 * and logs the event if non-scalar found.
	 *
	 * @param array $events
	 */
	private static function validateJSONSerializable( $events ) {
		foreach ( $events as $event ) {
			self::checkEventPart( $event, $event );
		}
	}

	private function shouldSendEvent( $eventType ) {
		return $this->allowedEventTypes & $eventType;
	}

	/**
	 * Returns a singleton logger instance for all EventBus instances.
	 * Use like: self::logger()->info( $mesage )
	 * We use this so we don't have to check if the logger has been created
	 * before attempting to log a message.
	 * @return LoggerInterface
	 */
	public static function logger() {
		if ( !self::$logger ) {
			self::$logger = LoggerFactory::getInstance( 'EventBus' );
		}
		return self::$logger;
	}

	/**
	 * Returns the EventFactory associated with this instance of EventBus
	 * @return EventFactory|null
	 */
	public function getFactory() {
		return $this->eventFactory;
	}

	/**
	 * @param string|null $eventServiceName
	 * 		The name of a key in the EventServices config looked up via
	 * 		MediaWikiServices::getInstance()->getMainConfig()->get('EventServices').
	 * 		The EventService config is keyed by service name, and should at least contain
	 * 		a 'url' entry pointing at the event service endpoint events should be
	 * 		POSTed to. They can also optionally contain a 'timeout' entry specifying
	 * 		the HTTP POST request timeout, and a 'x_client_ip_forwarding_enabled' entry that can be
	 * 		set to true if the X-Client-IP header from the originating request should be
	 * 		forwarded to the event service. Instances are singletons identified by
	 * 		$eventServiceName.
	 *
	 * 		NOTE: Previously, this function took a $config object instead of an
	 * 		event service name.  This is a backwards compatible change, but because
	 * 		there are no other users of this extension, we can do this safely.
	 *
	 * @throws InvalidArgumentException if EventServices or $eventServiceName is misconfigured.
	 * @return EventBus
	 */
	public static function getInstance( $eventServiceName ) {
		return MediaWikiServices::getInstance()
			->get( 'EventBus.EventBusFactory' )
			->getInstance( $eventServiceName );
	}

	/**
	 * Uses EventStreamConfig.StreamConfigs to look up the
	 * EventBus instance to use for a $stream.
	 * If the stream is disabled, a non-producing EventBus instance will be used.
	 * If none is found, falls back to using wgEventServiceDefault.
	 *
	 * @param string $stream the stream to send an event to
	 * @return EventBus
	 * @throws InvalidArgumentException if EventServices or $eventServiceName is misconfigured.
	 */
	public static function getInstanceForStream( $stream ) {
		return MediaWikiServices::getInstance()
			->get( 'EventBus.EventBusFactory' )
			->getInstanceForStream( $stream );
	}
}
