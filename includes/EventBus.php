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
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SuppressedDataException;
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
	 * @return string JSON
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
				return;
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
			return;
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
	 * Given a User $user, returns an array suitable for
	 * use as the performer JSON object in various Mediawiki
	 * entity schemas.
	 * @param User $user
	 * @return array
	 */
	public static function createPerformerAttrs( $user ) {
		$performerAttrs = [
			'user_text'   => $user->getName(),
			'user_groups' => $user->getEffectiveGroups(),
			'user_is_bot' => $user->getId() ? $user->isBot() : false,
		];
		if ( $user->getId() ) {
			$performerAttrs['user_id'] = $user->getId();
		}
		if ( $user->getRegistration() ) {
			$performerAttrs['user_registration_dt'] = wfTimestamp(
				TS_ISO_8601, $user->getRegistration()
			);
		}
		if ( $user->getEditCount() !== null ) {
			$performerAttrs['user_edit_count'] = $user->getEditCount();
		}

		return $performerAttrs;
	}

	/**
	 * Given a RevisionRecord $revision, returns an array suitable for
	 * use in mediawiki/revision entity schemas.
	 *
	 * @param RevisionRecord $revision
	 * @param User|null $performer
	 * @return array
	 */
	public static function createRevisionRecordAttrs( $revision, $performer = null ) {
		global $wgDBname;

		$linkTarget = $revision->getPageAsLinkTarget();
		$titleFormatter = MediaWikiServices::getInstance()->getTitleFormatter();
		$attrs = [
			// Common Mediawiki entity fields
			'database'           => $wgDBname,

			// revision entity fields
			'page_id'            => $revision->getPageId(),
			'page_title'         => $titleFormatter->getPrefixedDBkey( $linkTarget ),
			'page_namespace'     => $linkTarget->getNamespace(),
			'rev_id'             => $revision->getId(),
			'rev_timestamp'      => wfTimestamp( TS_ISO_8601, $revision->getTimestamp() ),
			'rev_sha1'           => $revision->getSha1(),
			'rev_minor_edit'     => $revision->isMinor(),
			'rev_len'            => $revision->getSize(),
		];

		$attrs['rev_content_model'] = $contentModel = $revision->getSlot( 'main' )->getModel();

		$contentFormat = $revision->getSlot( 'main' )->getFormat();
		if ( is_null( $contentFormat ) ) {
			try {
				$contentFormat = ContentHandler::getForModelID( $contentModel )->getDefaultFormat();
			}
			catch ( MWException $e ) {
				// Ignore, the `rev_content_format` is not required.
			}
		}
		if ( !is_null( $contentFormat ) ) {
			$attrs['rev_content_format'] = $contentFormat;
		}

		if ( !is_null( $performer ) ) {
			$attrs['performer'] = self::createPerformerAttrs( $performer );
		} elseif ( !is_null( $revision->getUser() ) ) {
			$performer = User::newFromId( $revision->getUser()->getId() );
			$performer->loadFromId();
			$attrs['performer'] = self::createPerformerAttrs( $performer );
		}

		// It is possible that the $revision object does not have any content
		// at the time of RevisionInsertComplete.  This might happen during
		// a page restore, if the revision 'created' during the restore
		// has its content hidden.
		// TODO: In MCR Content::isRedirect should not be used to derive a redirect directly.
		try {
			$content = $revision->getContent( 'main' );
			if ( !is_null( $content ) ) {
				$attrs['page_is_redirect'] = $content->isRedirect();
			} else {
				$attrs['page_is_redirect'] = false;
			}
		} catch ( SuppressedDataException $e ) {
			$attrs['page_is_redirect'] = false;
		}

		if ( !is_null( $revision->getComment() ) && strlen( $revision->getComment()->text ) ) {
			$attrs['comment'] = $revision->getComment()->text;
			$attrs['parsedcomment'] = Linker::formatComment( $revision->getComment()->text );
		}

		return $attrs;
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
	 * Adds a meta subobject to $attrs based on uri and topic and returns it.
	 *
	 * @param string $uri
	 * @param string $topic
	 * @param array $attrs
	 * @param string|bool $wiki
	 *
	 * @return array $attrs + meta subobject
	 */
	public static function createEvent( $uri, $topic, $attrs, $wiki = false ) {
		global $wgServerName;

		if ( $wiki ) {
			$wikiRef = WikiMap::getWiki( $wiki );
			if ( is_null( $wikiRef ) ) {
				$domain = $wgServerName;
			} else {
				$domain = $wikiRef->getDisplayName();
			}
		} else {
			$domain = $wgServerName;
		}

		$now = \DateTime::createFromFormat( 'U.u', microtime( true ) );
		$event = [
			'meta' => [
				'uri'        => $uri,
				'topic'      => $topic,
				'request_id' => self::getRequestId(),
				'id'         => self::newId(),
				// HHVM doesn't support `v` in the date formatting strings
				// and we actually want milliseconds, not microseconds.
				// TODO: once support for HHVM is dropped - change it to milliseconds.
				'dt'         => $now->format( 'Y-m-d\TH:i:s.uP' ),
				'domain'     => $domain ?: $wgServerName,
			],
		];

		return $event + $attrs;
	}

	/**
	 * Returns the X-Request-ID header, if set, otherwise a newly generated
	 * type 4 UUID string.
	 *
	 * @return string
	 */
	private static function getRequestId() {
		$context = RequestContext::getMain();
		$xreqid = $context->getRequest()->getHeader( 'x-request-id' );
		return $xreqid ?: UIDGenerator::newUUIDv4();
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

	/**
	 * Creates a new type 1 UUID string.
	 *
	 * @return string
	 */
	private static function newId() {
		return UIDGenerator::newUUIDv1();
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
	 * @param array|null $config EventBus config object.  This must at least contain EventServiceUrl.
	 *                      EventServiceTimeout is also a valid config key.  If null (default)
	 *                      this will lookup config using
	 *                      MediawikiServices::getInstance()->getMainConfig() and look for
	 *                      for EventServiceUrl and EventServiceTimeout.
	 *                      Note that instances are URL keyed singletons, so the first
	 *                      instance created with a given URL will be the only one.
	 *
	 * @return EventBus
	 */
	public static function getInstance( $config = null ) {
		if ( !$config ) {
			$config = MediaWikiServices::getInstance()->getMainConfig();
			$url = $config->get( 'EventServiceUrl' );
			$timeout = $config->get( 'EventServiceTimeout' );
		} else {
			$url = $config['EventServiceUrl'];
			$timeout = array_key_exists( 'EventServiceTimeout', $config ) ?
				$config['EventServiceTimeout'] : null;
		}

		if ( !$url ) {
			self::logger()->error(
				'Failed configuration of EventBus instance. \'EventServiceUrl\' must be set in $config.'
			);
			return;
		}

		if ( !array_key_exists( $url, self::$instances ) ) {
			self::$instances[$url] = new self( $url, $timeout );
		}

		return self::$instances[$url];
	}
}
