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

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

class EventBus {

	/** HTTP request timeout in seconds */
	const REQ_TIMEOUT = 5;

	/** @var EventBus */
	private static $instance;

	/** @var MultiHttpClient */
	protected $http;

	public function __construct() {
		$this->http = new MultiHttpClient( [] );
		$this->logger = LoggerFactory::getInstance( 'EventBus' );
	}

	/**
	 * Deliver an array of events to the remote service.
	 *
	 * @param array $events the events to send
	 */
	public function send( $events ) {
		if ( empty( $events ) ) {
			$context = [ 'backtrace' => debug_backtrace() ];
			$this->logger->error( 'Must call send with at least 1 event. Aborting send.', $context );
			return;
		}

		$config = self::getConfig();
		$eventServiceUrl = $config->get( 'EventServiceUrl' );
		$eventServiceTimeout = $config->get( 'EventServiceTimeout' );

		// If we already have a JSON string of events, just use it as the body.
		if ( is_string( $events ) ) {
			$body = $events;
		} else {
			// Else serialize the array of events to a JSON string.
			$body = self::serializeEvents( $events );
			// If not $body, then something when wrong.
			// serializeEvents has already logged, so we can just return.
			if ( !$body ) {
				return;
			}
		}

		$req = [
			'url'		=> $eventServiceUrl,
			'method'	=> 'POST',
			'body'		=> $body,
			'headers'	=> [ 'content-type' => 'application/json' ]
		];

		$res = $this->http->run(
			$req,
			[
				'reqTimeout' => $eventServiceTimeout ?: self::REQ_TIMEOUT
			]
		);

		// 201: all events are accepted
		// 207: some but not all events are accepted
		// 400: no events are accepted
		if ( $res['code'] != 201 ) {
			$this->onError( $req, $res );
		}
	}

	/**
	 * Serializes $events array to a JSON string.  If FormatJson::encode()
	 * returns false, this will log a detailed error message and return null.
	 *
	 * @param array $events
	 * @return string JSON
	 */
	public function serializeEvents( $events ) {
		$serializedEvents = FormatJson::encode( $events );

		if ( empty ( $serializedEvents ) ) {
			$context = [
				'backtrace' => debug_backtrace(),
				'events' => $events,
				'json_last_error' => json_last_error()
			];
			$this->logger->error(
				'FormatJson::encode($events) failed: ' . $context['json_last_error'] .
				'. Aborting send.', $context
			);
			return;
		}

		return $serializedEvents;
	}

	private function onError( $req, $res ) {
		$message = empty( $res['error'] ) ? $res['code'] . ': ' . $res['reason'] : $res['error'];
		$context = [ 'EventBus' => [ 'request' => $req, 'response' => $res ] ];
		$this->logger->error( "Unable to deliver event: ${message}", $context );
	}

	/**
	 * @return EventBus
	 */
	public static function getInstance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	// == static helper functions below ==

	/**
	 * Creates a full article path
	 *
	 * @param Title $title article title object
	 * @return string
	 */
	public static function getArticleURL( $title ) {
		global $wgCanonicalServer, $wgArticlePath;
		// can't use wfUrlencode, because it doesn't encode slashes. RESTBase
		// and services expect slashes to be encoded, so encode the whole title
		// right away to avoid reencoding it in change-propagation
		$titleURL = rawurlencode( $title->getPrefixedDBkey() );
		// The $wgArticlePath contains '$1' string where the article title should appear.
		return $wgCanonicalServer . str_replace( '$1', $titleURL, $wgArticlePath );
	}

	/**
	 * Given a User $user, returns an array suitable for
	 * use as the performer JSON object in various Mediawiki
	 * entity schemas.
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
		return $performerAttrs;
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
		$encodedUserURL = rawurlencode( strtr( $prefixedUserURL, ' ', '_' ) );
		// The $wgArticlePath contains '$1' string where the article title should appear.
		return $wgCanonicalServer . str_replace( '$1', $encodedUserURL, $wgArticlePath );
	}

	/**
	 * If $value is a string, but not UTF-8 encoded, then assume it is binary
	 * and base64 encode it and prefix it with a content type.
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
	 * @param array  $attrs
	 *
	 * @return array $attrs + meta subobject
	 */
	public static function createEvent( $uri, $topic, $attrs ) {
		global $wgServerName;
		$event = [
			'meta' => [
				'uri'        => $uri,
				'topic'      => $topic,
				'request_id' => self::getRequestId(),
				'id'         => self::newId(),
				'dt'         => date( 'c' ),
				'domain'     => $wgServerName ?: "unknown",
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
	 * Creates a new type 1 UUID string.
	 *
	 * @return string
	 */
	private static function newId() {
		return UIDGenerator::newUUIDv1();
	}

	/**
	 * Retrieve main config
	 * @return Config
	 */
	private static function getConfig() {
		return MediaWikiServices::getInstance()->getMainConfig();
	}
}
