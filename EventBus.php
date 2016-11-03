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
 * @author Eric Evans
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

		$body = FormatJson::encode( $events );

		if ( empty ( $body ) ) {
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

	/**
	 * Retrieve main config
	 * @return Config
	 */
	private static function getConfig() {
		return MediaWikiServices::getInstance()->getMainConfig();
	}

}
