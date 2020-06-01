<?php

/**
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
 * @author Petr Pchelko
 */

namespace MediaWiki\Extension\EventBus;

use InvalidArgumentException;
use MediaWiki\Config\ServiceOptions;
use Mediawiki\Extension\EventStreamConfig\StreamConfigs;
use MultiHttpClient;
use Psr\Log\LoggerInterface;

/**
 * Creates appropriate EventBus instance based on stream config.
 *
 * @package MediaWiki\Extension\EventBus
 * @since 1.35
 */
class EventBusFactory {

	public const CONSTRUCTOR_OPTIONS = [
		'EventServices',
		'EventServiceDefault',
		'EnableEventBus'
	];

	/**
	 * Key in wgEventStreams that specifies
	 * the event service name that should be used for a specific stream.
	 * If not found via StreamConfigs, EventServiceDefault will be used.
	 */
	private const EVENT_STREAM_CONFIG_SERVICE_SETTING = 'destination_event_service';

	/** @var array */
	private $eventServiceConfig;

	/** @var string */
	private $eventServiceDefault;

	/** @var StreamConfigs|null */
	private $streamConfigs;

	/** @var string */
	private $enableEventBus;

	/** @var EventFactory */
	private $eventFactory;

	/** @var MultiHttpClient */
	private $http;

	/** @var LoggerInterface */
	private $logger;

	/** @var EventBus[] */
	private $eventBusInstances = [];

	/**
	 * @param ServiceOptions $options
	 * @param StreamConfigs|null $streamConfigs
	 * @param EventFactory $eventFactory
	 * @param MultiHttpClient $http
	 * @param LoggerInterface $logger
	 */
	public function __construct(
		ServiceOptions $options,
		?StreamConfigs $streamConfigs,
		EventFactory $eventFactory,
		MultiHttpClient $http,
		LoggerInterface $logger
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );

		$this->eventServiceConfig = $options->get( 'EventServices' );
		$this->eventServiceDefault = $options->get( 'EventServiceDefault' );
		$this->enableEventBus = $options->get( 'EnableEventBus' );
		$this->streamConfigs = $streamConfigs;
		$this->eventFactory = $eventFactory;
		$this->http = $http;
		$this->logger = $logger;
	}

	/**
	 * @param string $eventServiceName
	 *   The name of a key in the EventServices config looked up via
	 *   MediawikiServices::getInstance()->getMainConfig()->get('EventServices').
	 *   The EventService config is keyed by service name, and should at least contain
	 *   a 'url' entry pointing at the event service endpoint events should be
	 *   POSTed to. They can also optionally contain a 'timeout' entry specifying
	 *   the HTTP POST request timeout. Instances are singletons identified by
	 *   $eventServiceName.
	 *
	 * @note Previously, this function took a $config object instead of an
	 * event service name.  This is a backwards compatible change, but because
	 * there are no other users of this extension, we can do this safely.
	 *
	 * @throws InvalidArgumentException if EventServices or $eventServiceName is misconfigured.
	 * @return EventBus
	 */
	public function getInstance( string $eventServiceName ) : EventBus {
		if ( !array_key_exists( $eventServiceName, $this->eventServiceConfig ) ||
			!array_key_exists( 'url', $this->eventServiceConfig[$eventServiceName] )
		) {
			$error = "Could not get EventBus instance for event service '$eventServiceName'. " .
				'This event service name must exist in EventServices config with a url setting.';
			$this->logger->error( $error );
			throw new InvalidArgumentException( $error );
		}

		$eventService = $this->eventServiceConfig[$eventServiceName];
		$url = $eventService['url'];
		$timeout = array_key_exists( 'timeout', $eventService ) ? $eventService['timeout'] : null;

		if ( !array_key_exists( $eventServiceName, $this->eventBusInstances ) ) {
			$this->eventBusInstances[$eventServiceName] = new EventBus(
				$this->http,
				$this->enableEventBus,
				$this->eventFactory,
				$url,
				$timeout
			);
		}

		return $this->eventBusInstances[$eventServiceName];
	}

	/**
	 * Gets an EventBus instance for a $stream.
	 * If none is configured specifically for $stream, EventServiceDefault will be used.
	 *
	 * @param string $stream the stream to send an event to
	 * @return EventBus
	 * @throws InvalidArgumentException
	 */
	public function getInstanceForStream( string $stream ) : EventBus {
		// Use eventServiceDefault if no streamConfigs were provided.
		if ( $this->streamConfigs === null ) {
			$this->logger->debug(
				'Using EventServiceDefault ' . $this->eventServiceDefault .
				" for stream $stream. EventStreamConfig is not enabled."
			);
			return self::getInstance( $this->eventServiceDefault );
		}

		// Else attempt to lookup EVENT_STREAM_CONFIG_SERVICE_SETTING for this stream.
		$streamConfigEntries = $this->streamConfigs->get( [ $stream ], true );
		if ( array_key_exists( $stream, $streamConfigEntries ) &&
			array_key_exists(
				self::EVENT_STREAM_CONFIG_SERVICE_SETTING, $streamConfigEntries[$stream]
			)
		) {
			$eventService = $streamConfigEntries[$stream][self::EVENT_STREAM_CONFIG_SERVICE_SETTING];
			$this->logger->debug(
				'Using ' . self::EVENT_STREAM_CONFIG_SERVICE_SETTING .
				" $eventService for stream $stream."
			);
			return self::getInstance( $eventService );
		} else {
			$this->logger->debug(
				'Using EventServiceDefault ' . $this->eventServiceDefault .
				" for stream $stream. " . self::EVENT_STREAM_CONFIG_SERVICE_SETTING . ' is not configured.'
			);
			return self::getInstance( $this->eventServiceDefault );
		}
	}
}
