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

use MediaWiki\Linker\LinkTarget;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
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

	/** @var EventFactory event creator */
	private $eventFactory;

	/**
	 * @param string $url EventBus service endpoint URL. E.g. http://localhost:8085/v1/events
	 * @param int|null $timeout HTTP request timeout in seconds, defaults to 5.
	 */
	public function __construct( $url, $timeout = null ) {
		global $wgEnableEventBus;

		$this->http = new MultiHttpClient( [] );
		$this->url = $url;
		$this->timeout = $timeout ?: self::DEFAULT_REQUEST_TIMEOUT;
		$this->eventFactory = new EventFactory();

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
				self::$logger->log( 'warn',
					'Unknown $wgEnableEventBus config parameter value ' . $wgEnableEventBus );
				$this->allowedEventTypes = self::TYPE_ALL;
		}
	}

	/**
	 * Deliver an array of events to the remote service.
	 *
	 * @param array|string $events the events to send.
	 * @param int $type the type of the event being sent.
	 */
	public function send( $events, $type = self::TYPE_EVENT ) {
		if ( !$this->shouldSendEvent( $type ) ) {
			return;
		}
		if ( empty( $events ) ) {
			// Logstash doesn't like the args, because they could be of various types
			$context = [ 'exception' => new Exception() ];
			self::logger()->error( 'Must call send with at least 1 event. Aborting send.', $context );
			return;
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
				return;
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

		// 201: all events are accepted
		// 207: some but not all events are accepted
		// 400: no events are accepted
		if ( $res['code'] != 201 ) {
			$message = empty( $res['error'] ) ? $res['code'] . ': ' . $res['reason'] : $res['error'];
			// In case the event posted was too big we don't want to log all the request body
			// as it contains all
			$context = [
				'events'           => $events,
				'service_response' => $res
			];
			// Limit the maximum size of the logged context to 8 kilobytes as that's where logstash
			// truncates the JSON anyway
			if ( strlen( $body ) > 8192 ) {
				$context['events'] = array_column( $events, 'meta' );
			}
			self::logger()->error( "Unable to deliver all events: ${message}", $context );
		}
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
				$context = [
					'exception' => new Exception(),
					'events' => $events,
					'json_last_error' => json_last_error_msg()
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
				// The exception during serializing mostly happen when events are too big,
				// so we will not be able to log a complete thing, so truncate to only log meta
				'events' => array_column( $events, 'meta' ),
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
	 * Creates a full article path
	 *
	 * @param LinkTarget $target article title object
	 * @return string
	 */
	public static function getArticleURL( $target ) {
		global $wgCanonicalServer, $wgArticlePath;

		$titleFormatter = MediaWikiServices::getInstance()->getTitleFormatter();
		$titleURL = wfUrlencode( $titleFormatter->getPrefixedDBkey( $target ) );
		// The $wgArticlePath contains '$1' string where the article title should appear.
		return $wgCanonicalServer . str_replace( '$1', $titleURL, $wgArticlePath );
	}

	/**
	 * Creates a full user page path
	 *
	 * @param string $userName userName
	 * @return string
	 */
	public static function getUserPageURL( $userName ) {
		global $wgCanonicalServer, $wgArticlePath, $wgContLang;
		$prefixedUserURL = $wgContLang->getNsText( NS_USER ) . ':' . $userName;
		$encodedUserURL = wfUrlencode( strtr( $prefixedUserURL, ' ', '_' ) );
		// The $wgArticlePath contains '$1' string where the article title should appear.
		return $wgCanonicalServer . str_replace( '$1', $encodedUserURL, $wgArticlePath );
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
					'events' => self::serializeEvents( [ $originalEvent ] ),
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
	private static function logger() {
		if ( !self::$logger ) {
			self::$logger = LoggerFactory::getInstance( 'EventBus' );
		}
		return self::$logger;
	}

	/**
	 * Returns the EventFactory associated with this instance of EventBus
	 * @return EventFactory
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
			$error = "Could not get configuration of EventBus instance for '$eventServiceName'" .
				'$eventServiceName must exist in EventServices with a url in main config.';
			self::logger()->error( $error );
			throw new ConfigException( $error );
		}

		$url = $eventServices[$eventServiceName]['url'];
		$timeout = array_key_exists( 'timeout', $eventServices[$eventServiceName] ) ?
			$eventServices[$eventServiceName]['timeout'] : null;

		if ( !array_key_exists( $eventServiceName, self::$instances ) ) {
			self::$instances[$eventServiceName] = new self( $url, $timeout );
		}

		return self::$instances[$eventServiceName];
	}
}
