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

use ConfigException;
use Exception;
use FormatJson;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MultiHttpClient;
use Psr\Log\LoggerInterface;

class EventBus {

	/**
	 * @const int the special event type indicating no events should be accepted.
	 */
	const TYPE_NONE = 0;

	/**
	 * @const int the event type indicating that the event is a regular mediawiki event.
	 */
	const TYPE_EVENT = 1;

	/**
	 * @const int the event type indicating that the event is a serialized job.
	 */
	const TYPE_JOB = 2;

	/**
	 * @const int the event type indicating any event type. (TYPE_EVENT ^ TYPE_EVENT)
	 */
	const TYPE_ALL = 3;

	/** @const int Default HTTP request timeout in seconds */
	const DEFAULT_REQUEST_TIMEOUT = 10;

	/** @var EventBus[] */
	private static $instances = [];

	/** @var LoggerInterface instance for all EventBus instances */
	private static $logger;

	/** @var MultiHttpClient */
	protected $http;

	/** @var string EventServiceUrl for this EventBus instance */
	protected $url;

	/** @var int HTTP request timeout for this EventBus instance */
	protected $timeout;

	/** @var int which event types are allowed to be sent (TYPE_NONE|TYPE_EVENT|TYPE_JOB|TYPE_ALL) */
	private $allowedEventTypes;

	/** @var EventFactory|null event creator */
	private $eventFactory;

	/**
	 * @param string $url EventBus service endpoint URL. E.g. http://localhost:8085/v1/events
	 * @param int|null $timeout HTTP request timeout in seconds, defaults to 5.
	 * @param EventFactory|null $eventFactory an instance of
	 * 							the EventFactory to use for event construction.
	 */
	public function __construct( $url, $timeout = null, $eventFactory = null ) {
		global $wgEnableEventBus;

		$this->http = new MultiHttpClient( [] );
		$this->url = $url;
		$this->timeout = $timeout ?: self::DEFAULT_REQUEST_TIMEOUT;
		$this->eventFactory = $eventFactory;

		switch ( $wgEnableEventBus ) {
			case 'TYPE_NONE':
				$this->allowedEventTypes = self::TYPE_NONE;
				break;
			case 'TYPE_EVENT':
				$this->allowedEventTypes = self::TYPE_EVENT;
				break;
			case 'TYPE_JOB':
				$this->allowedEventTypes = self::TYPE_JOB;
				break;
			case 'TYPE_ALL':
				$this->allowedEventTypes = self::TYPE_ALL;
				break;
			default:
				self::logger()->log( 'warn',
					'Unknown $wgEnableEventBus config parameter value ' . $wgEnableEventBus );
				$this->allowedEventTypes = self::TYPE_ALL;
		}
	}

	/**
	 * Deliver an array of events to the remote service.
	 *
	 * @param array|string $events the events to send.
	 * @param int $type the type of the event being sent.
	 * @return bool|string True on success or an error string on failure
	 */
	public function send( $events, $type = self::TYPE_EVENT ) {
		if ( !$this->shouldSendEvent( $type ) ) {
			return "Events of type '$type' are not enqueueable";
		}
		if ( empty( $events ) ) {
			// Logstash doesn't like the args, because they could be of various types
			$context = [ 'exception' => new Exception() ];
			self::logger()->error( 'Must call send with at least 1 event. Aborting send.', $context );
			return "Provided event list is empty";
		}

		// If we already have a JSON string of events, just use it as the body.
		if ( is_string( $events ) ) {
			$body = $events;
		} else {
			self::validateJSONSerializable( $events );
			// Else serialize the array of events to a JSON string.
			$body = self::serializeEvents( $events );
			// If not $body, then something when wrong.
			// serializeEvents has already logged, so we can just return.
			if ( !$body ) {
				return "Unable to serialize events";
			}
		}

		$req = [
			'url'		=> $this->url,
			'method'	=> 'POST',
			'body'		=> $body,
			'headers'	=> [ 'content-type' => 'application/json' ]
		];

		$res = $this->http->run(
			$req,
			[
				'reqTimeout' => $this->timeout,
			]
		);

		// 201: all events accepted.
		// 202: all events accepted but not necessarily persisted. HTTP response is returned 'hastily'.
		// 207: some but not all events accepted: either due to validation failure or error.
		// 400: no events accepted: all failed schema validation.
		// 500: no events accepted: at least one caused an error, but some might have been invalid.
		if ( $res['code'] == 207 || $res['code'] >= 300 ) {
			$message = empty( $res['error'] ) ? $res['code'] . ': ' . $res['reason'] : $res['error'];
			// Limit the maximum size of the logged context to 8 kilobytes as that's where logstash
			// truncates the JSON anyway
			$context = [
				'raw_events'       => self::prepareEventsForLogging( $body ),
				'service_response' => $res
			];
			self::logger()->error( "Unable to deliver all events: ${message}", $context );

			return "Unable to deliver all events: $message";
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
			if ( empty( $serializedEvents ) ) {
				// Something failed. Let's figure out exactly which one.
				$bad = [];
				foreach ( $events as $event ) {
					$result = FormatJson::encode( $event, false, FormatJson::ALL_OK );
					if ( empty( $result ) ) {
						$bad[] = $event;
					}
				}
				$context = [
					'exception' => new Exception(),
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
	 * 		MediawikiServices::getInstance()->getMainConfig()->get('EventServices').
	 * 		The EventService config is keyed by service name, and should at least contain
	 * 		a 'url' entry pointing at the event service endpoint events should be
	 * 		POSTed to. They can also optionally contain a 'timeout' entry specifying
	 * 		the HTTP POST request timeout. Instances are singletons identified by
	 * 		$eventServiceName.
	 *
	 * 		NOTE: Previously, this function took a $config object instead of an
	 * 		event service name.  This is a backwards compatible change, but because
	 * 		there are no other users of this extension, we can do this safely.
	 *
	 * @throws ConfigException if EventServices or $eventServiceName is misconfigured.
	 * @return EventBus
	 */
	public static function getInstance( $eventServiceName ) {
		if ( !$eventServiceName ) {
			$error = 'EventBus::getInstance requires a configured $eventServiceName';
			self::logger()->error( $error );
			throw new ConfigException( $error );
		}

		$eventServices =
			MediaWikiServices::getInstance()->getMainConfig()->get( 'EventServices' );

		if ( !array_key_exists( $eventServiceName, $eventServices ) ||
			!array_key_exists( 'url', $eventServices[$eventServiceName] )
		) {
			$error = "Could not get configuration of EventBus instance for '$eventServiceName'. " .
				'$eventServiceName must exist in EventServices with a url in main config.';
			self::logger()->error( $error );
			throw new ConfigException( $error );
		}

		$eventService = $eventServices[$eventServiceName];
		$url = $eventService['url'];
		$timeout = array_key_exists( 'timeout', $eventService ) ? $eventService['timeout'] : null;

		if ( !array_key_exists( $eventServiceName, self::$instances ) ) {
			self::$instances[$eventServiceName] = new self( $url, $timeout, new EventFactory() );
		}

		return self::$instances[$eventServiceName];
	}

	/**
	 * Gets the configured EventServiceStreamConfig, which keys
	 * stream names to EventServiceNames, allowing for dynamic
	 * configuration routing of event streams to different Event Services.
	 * If EventServiceStreamConfig is not configured, this falls
	 * back to routing all to an EventServiceName of 'eventbus'.
	 *
	 * @return array
	 */
	private static function getEventServiceStreamConfig() {
		try {
			return MediaWikiServices::getInstance()
				->getMainConfig()
				->get( 'EventServiceStreamConfig' );
		}
		catch ( ConfigException $e ) {
			return [
				'default' => [
					'EventServiceName' => 'eventbus'
				]
			];
		}
	}

	/**
	 * Looks in EventServiceStreamConfig for an EventServiceName for $stream.
	 * If none is found, falls back to a 'default' entry.
	 *
	 * @param string $stream the stream to send an event to
	 * @return EventBus
	 * @throws ConfigException
	 */
	public static function getInstanceForStream( $stream ) {
		$streamConfig = self::getEventServiceStreamConfig();
		if ( array_key_exists( $stream, $streamConfig ) ) {
			return self::getInstance( $streamConfig[$stream]['EventServiceName'] );
		}
		if ( array_key_exists( 'default', $streamConfig ) ) {
			return self::getInstance( $streamConfig['default']['EventServiceName'] );
		}
		throw new ConfigException( 'wgEventServiceStreamConfig has no default provided' );
	}
}
