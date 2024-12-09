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
use InvalidArgumentException;
use MediaWiki\Context\RequestContext;
use MediaWiki\Json\FormatJson;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Wikimedia\Assert\Assert;
use Wikimedia\Http\MultiHttpClient;
use Wikimedia\Stats\StatsFactory;

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

	/** @const string fallback value to use when a prometheus metrics label value
	 * (e.g. `meta.stream`) is not assigned.
	 */
	public const VALUE_UNKNOWN = "__value_unknown__";

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

	/** @var string intake event service name */
	private string $eventServiceName;

	/** @var ?StatsFactory wf:Stats factory instance */
	private ?StatsFactory $statsFactory;

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
	 * @param string $eventServiceName TODO: pass the event service name so that it can be used
	 *     to label metrics. This is a hack put in place while refactoring efforts on this class are
	 *   ongoing. This variable is used to label metrics in send().
	 * @param ?StatsFactory|null $statsFactory wf:Stats factory instance
	 */
	public function __construct(
		MultiHttpClient $http,
		$enableEventBus,
		EventFactory $eventFactory,
		string $url,
		int $maxBatchByteSize,
		?int $timeout = null,
		bool $forwardXClientIP = false,
		string $eventServiceName = EventBusFactory::EVENT_SERVICE_DISABLED_NAME,
		?StatsFactory $statsFactory = null
	) {
		$this->http = $http;
		$this->url = $url;
		$this->maxBatchByteSize = $maxBatchByteSize;
		$this->timeout = $timeout ?: self::DEFAULT_REQUEST_TIMEOUT;
		$this->eventFactory = $eventFactory;
		$this->forwardXClientIP = $forwardXClientIP;
		$this->eventServiceName = $eventServiceName;
		$this->statsFactory = $statsFactory;

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
	 * @param string $metricName
	 * @param int $value
	 * @param mixed ...$labels passed as $key => $value pairs
	 * @return void
	 */
	private function incrementMetricByValue( string $metricName, int $value, ...$labels ): void {
		if ( $this->statsFactory !== null ) {
			$metric = $this->statsFactory->getCounter( $metricName );
			foreach ( $labels as $label ) {
				foreach ( $label as $k => $v ) {
					// Bug: T373086
					if ( $v === null ) {
						$v = self::VALUE_UNKNOWN;
						self::logger()->warning(
							' Initialized metric label does not have an assigned value. ',
							[ "metric_label" => $k ]
						);
					}
					$metric->setLabel( $k, $v );
				}
			}
			// Under the hood, Counter::incrementBy will update an integer
			// valued counter, regardless of `$value` type.
			$metric->incrementBy( $value );
		}
	}

	/**
	 * Deliver an array of events to the remote event intake service.
	 *
	 * Statslib metrics emitted by this method:
	 *
	 * - events_outgoing_total
	 * - events_outgoing_by_stream_total
	 * - events_accepted_total
	 * - events_failed_total
	 * - events_failed_by_stream_total
	 * - event_service_response_total
	 * - event_batch_not_enqueable_total
	 * - event_batch_is_string_total
	 * - event_batch_not_serializable_total
	 * - event_batch_partitioned_total
	 *     (incremented if $events had to be paratitioned and sent in multiple POST requests)
	 *
	 * @param array|string $events the events to send.
	 * @param int $type the type of the event being sent.
	 * @return array|bool|string True on success or an error string or array on failure
	 * @throws Exception
	 */
	public function send( $events, $type = self::TYPE_EVENT ) {
		// Label metrics by event type name. If the lookup fails,
		// fall back to labeling with the $type id parameter. Unknown or invalid ids
		// will be reported by the `events_are_not_enqueable` metric, which
		// fires when an event type does not belong to this EventBus instance allow list.
		// It should not be possible to supply a $type that does not belong
		// to EVENT_TYPE_NAME. Falling back to a numerical id is just a guard
		// to help us spot eventual bugs.
		$eventType = array_search( $type, self::EVENT_TYPE_NAMES );
		$eventType = ( $eventType === false ) ? $type : $eventType;
		// Label metrics with the EventBus declared default service host.
		// In the future, for streams that declare one, use the one provided by EventStreamConfig instead.
		$baseMetricLabels = [
			"function_name" => "send",
			"event_type" => $eventType,
			"event_service_name" => $this->eventServiceName,
			"event_service_uri" => $this->url
		];

		if ( !$this->shouldSendEvent( $type ) ) {
			// Debug metric. How often is the `$events` param not enqueable?
			$this->incrementMetricByValue(
				"event_batch_not_enqueable_total",
				1,
				$baseMetricLabels
			);
			return "Events of type '$type' are not enqueueable";
		}
		if ( !$events ) {
			// Logstash doesn't like the args, because they could be of various types
			$context = [ 'exception' => new RuntimeException() ];
			self::logger()->error( 'Must call send with at least 1 event. Aborting send.', $context );
			return "Provided event list is empty";
		}

		// Historically, passing a JSON string has been supported, but we'd like to deprecate this feature.
		// This was done to avoid having extra encode+decode steps if the caller already has a JSON string.
		// But, we end up decoding always anyway, to properly increment metrics.
		if ( is_string( $events ) ) {
			// TODO: is $events ever passed as a string? We should refactor this block and simplify the union type.
			// Debug metric. How often is the `$events` param a string?
			$this->incrementMetricByValue(
				"event_batch_is_string_total",
				1,
				$baseMetricLabels
			);

			$decodedEvents = FormatJson::decode( $events, true );

			if ( $decodedEvents === null ) {
				$context = [
					'exception' => new RuntimeException(),
					'raw_events' => self::prepareEventsForLogging( $events )
				];
				self::logger()->error( 'Failed decoding events from JSON string.', $context );
				return "Failed decoding events from JSON string";
			}

			$events = $decodedEvents;
		}

		// Code below expects that $events is a numeric array of event assoc arrays.
		if ( !array_key_exists( 0, $events ) ) {
			$events = [ $events ];
		}
		$outgoingEventsCount = count( $events );

		// Increment events_outgoing_total
		// NOTE: We could just use events_outgoing_by_stream_total and sum,
		//       but below we want to emit an events_accepted_total.
		//       In the case of a 207 partial success, we don't know
		//       the stream names of the successful events
		//       (without diffing the response from the event service and $events).
		//       For consistency, we both events_outgoing_total and events_outgoing_by_stream_total.
		$this->incrementMetricByValue(
			"events_outgoing_total",
			$outgoingEventsCount,
			$baseMetricLabels,
		);

		// Increment events_outgoing_by_stream_total for each stream
		$eventsByStream = self::groupEventsByStream( $events );
		foreach ( $eventsByStream as $streamName => $eventsForStreamName ) {
			$this->incrementMetricByValue(
				"events_outgoing_by_stream_total",
				count( $eventsForStreamName ),
				$baseMetricLabels,
				[ "stream_name" => $streamName ]
			);
		}

		// validateJSONSerializable only logs if any part of the event is not serializable.
		// It does not return anything or raise any exceptions.
		self::validateJSONSerializable( $events );
		// Serialize the array of events to a JSON string.
		$serializedEvents = self::serializeEvents( $events );
		if ( !$serializedEvents ) {
			$this->incrementMetricByValue(
				"event_batch_not_serializable_total",
				1,
				$baseMetricLabels
			);
			// serializeEvents has already logged, so we can just return.
			return "Unable to serialize events";
		}

		// If the body would be too big, partition it into multiple bodies.
		if ( strlen( $serializedEvents ) > $this->maxBatchByteSize ) {
			$postBodies = $this->partitionEvents( $events, strlen( $serializedEvents ) );
			// Measure the number of times we partition events into more than one batch.
			$this->incrementMetricByValue(
				"event_batch_partitioned_total",
				1,
				$baseMetricLabels
			);
		} else {
			$postBodies = [ $serializedEvents ];
		}

		// Most of the time $postBodies will be a single element array, and we
		// will only need to send one POST request.
		// When the size is too large, $events will have been partitioned into
		// multiple $postBodies, for which each will be sent as its own POST request.
		$originalRequest = RequestContext::getMain()->getRequest();
		$requests = array_map(
			function ( $postBody ) use ( $originalRequest ) {
				$req = [
					'url' => $this->url,
					'method' => 'POST',
					'body' => $postBody,
					'headers' => [ 'content-type' => 'application/json' ]
				];
				if ( $this->forwardXClientIP ) {
					$req['headers']['x-client-ip'] = $originalRequest->getIP();
				}
				return $req;
			},
			$postBodies
		);

		// Do the POST requests.
		$responses = $this->http->runMulti(
			$requests,
			[
				'reqTimeout' => $this->timeout
			]
		);

		// Keep track of the total number of failed events.
		// This will be used to calculate events_accepted_count later.
		$failedEventsCountTotal = 0;

		// 201: all events accepted.
		// 202: all events accepted but not necessarily persisted. HTTP response is returned 'hastily'.
		// 207: some but not all events accepted: either due to validation failure or error.
		// 400: no events accepted: all failed schema validation.
		// 500: no events accepted: at least one caused an error, but some might have been invalid.
		$results = [];
		foreach ( $responses as $i => $response ) {
			$res = $response['response'];
			$code = $res['code'];

			$this->incrementMetricByValue(
				"event_service_response_total",
				1,
				$baseMetricLabels,
				[ "status_code" => $code ]
			);

			if ( $code == 207 || $code >= 300 ) {
				$message = empty( $res['error'] ) ?
					(string)$code . ': ' . (string)$res['reason'] : $res['error'];
				// Limit the maximum size of the logged context to 8 kilobytes as that's where logstash
				// truncates the JSON anyway.
				$context = [
					// $responses[$i] corresponds with $postBodies[$i]
					'raw_events' => self::prepareEventsForLogging( $postBodies[$i] ),
					'service_response' => $res,
					'exception' => new RuntimeException(),
				];
				self::logger()->error( "Unable to deliver all events: {$message}", $context );

				if ( isset( $res['body'] ) ) {
					// We expect the event service to return an array of objects
					// in the response body.
					// FormatJson::decode will return `null` if the message failed to parse.
					// If anything other than an array is parsed we treat it as unexpected
					// behaviour, and log the response at error severity.
					// See https://phabricator.wikimedia.org/T370428

					// $failureInfosByKind should look like:
					// {
					// 	  "<failure_kind">: [
					// 		{ ..., "event": {<failed event here>}, "context": {<failure context here>},
					//		{ ... }
					//    ],
					// }
					$failureInfosByKind = FormatJson::decode( $res['body'], true );
					if ( is_array( $failureInfosByKind ) ) {

						foreach ( $failureInfosByKind as $failureKind => $failureInfos ) {
							// $failureInfos should not be null or empty.
							// This is just a guard against what the intake
							// service returns (or the behavior of different json parsing methods - possibly).
							// https://www.mediawiki.org/wiki/Manual:Coding_conventions/PHP#empty()
							if ( $failureInfos === null || $failureInfos === [] ) {
								continue;
							}

							// Get the events that failed from the response.
							$failedEvents = array_map(
								static function ( $failureStatus ) {
									return $failureStatus['event'] ?? null;
								},
								$failureInfos
							);

							$failedEventsCount = count( $failedEvents );
							$failedEventsCountTotal += $failedEventsCount;

							// increment events_failed_total
							$this->incrementMetricByValue(
								"events_failed_total",
								$failedEventsCount,
								$baseMetricLabels,
								[
									"failure_kind" => $failureKind,
									"status_code" => $code,
								]
							);

							// Group failed events by stream and increment events_failed_by_stream_total.
							$failedEventsByStream = self::groupEventsByStream( $failedEvents );
							foreach ( $failedEventsByStream as $streamName => $failedEventsForStream ) {
								$this->incrementMetricByValue(
									"events_failed_by_stream_total",
									count( $failedEventsForStream ),
									$baseMetricLabels,
									[
										"failure_kind" => $failureKind,
										"status_code" => $code,
										"stream_name" => $streamName,
									]
								);
							}
						}

					} else {
						self::logger()->error( "Invalid event service response body", $context );
					}
				}
				$results[] = "Unable to deliver all events: $message";
			}
		}

		// increment events_accepted_total as the difference between
		// $outgoingEventsCount and $failedEventsCountTotal (if there were any failed events).
		$this->incrementMetricByValue(
			"events_accepted_total",
			$outgoingEventsCount - $failedEventsCountTotal,
			$baseMetricLabels,
		);

		if ( $results !== [] ) {
			return $results;
		}

		return true;
	}

	// == static helper functions below ==

	/**
	 * Given an event assoc array, extracts the stream name from meta.stream,
	 * or returns STREAM_NAME_UNKNOWN
	 * @param array|null $event
	 * @return mixed|string
	 */
	public static function getStreamNameFromEvent( ?array $event ) {
		return is_array( $event ) && isset( $event['meta']['stream'] ) ?
			$event['meta']['stream'] :
			self::VALUE_UNKNOWN;
	}

	/**
	 * Given an assoc array of events, this returns them grouped by stream name.
	 * @param array $events
	 * @return array
	 */
	public static function groupEventsByStream( array $events ): array {
		$groupedEvents = [];
		foreach ( $events as $event ) {
			$streamName = self::getStreamNameFromEvent( $event );
			$groupedEvents[$streamName] ??= [];
			$groupedEvents[$streamName][] = $event;
		}
		return $groupedEvents;
	}

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

	/**
	 * @param int $eventType
	 * @return int
	 */
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
	 *        The name of a key in the EventServices config looked up via
	 *        MediaWikiServices::getInstance()->getMainConfig()->get('EventServices').
	 *        The EventService config is keyed by service name, and should at least contain
	 *        a 'url' entry pointing at the event service endpoint events should be
	 *        POSTed to. They can also optionally contain a 'timeout' entry specifying
	 *        the HTTP POST request timeout, and a 'x_client_ip_forwarding_enabled' entry that can be
	 *        set to true if the X-Client-IP header from the originating request should be
	 *        forwarded to the event service. Instances are singletons identified by
	 *        $eventServiceName.
	 *
	 *        NOTE: Previously, this function took a $config object instead of an
	 *        event service name.  This is a backwards compatible change, but because
	 *        there are no other users of this extension, we can do this safely.
	 *
	 * @return EventBus
	 * @throws InvalidArgumentException if EventServices or $eventServiceName is misconfigured.
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
