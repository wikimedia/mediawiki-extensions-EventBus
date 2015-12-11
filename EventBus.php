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

class EventBus {

	/** HTTP request timeout in seconds */
	const REQ_TIMEOUT = 5;

	/** @var EventBus */
	private static $instance;

	/** @var MultiHttpClient */
	protected $http;

	public function __construct() {
		$this->http = new MultiHttpClient( array() );
	}

	/**
	 * Deliver an array of events to the remote service.
	 *
	 * @param array $events the events to send
	 */
	public function send( $events ) {
		$config = self::getConfig();
		$eventServiceUrl = $config->get( 'EventServiceUrl' );
		$eventServiceTimeout = $config->get( 'EventServiceTimeout' );

		$ret = $this->http->run(
			array(
				'url'	  => $eventServiceUrl,
				'method'  => 'POST',
				'body'	  => FormatJson::encode( $events ),
				'headers' => array( 'content-type' => 'application/json' )
			),
			array(
				'reqTimeout' => $eventServiceTimeout ?: self::REQ_TIMEOUT
			)
		);

		// 201: all events are accepted
		// 207: some but not all events are accepted
		// 400: no events are accepted
		if ( $ret['code'] != 201 ) {
			$this->onError( $ret );
		}
	}

	private function onError( $ret ) {
		$message = empty( $ret['error'] ) ? $ret['code'] . ': ' . $ret['reason'] : $ret['error'];
		$context = array( 'response' => $ret['body'] );

		$logger = LoggerFactory::getInstance( 'EventBus' );
		$logger->error( "Unable to deliver event: ${message}", $context );
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

	private static function getConfig() {
		return ConfigFactory::getDefaultInstance()->makeConfig( 'main' );
	}

}