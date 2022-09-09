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
use MediaWiki\Extension\EventStreamConfig\StreamConfigs;
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
		'EnableEventBus',
		'EventBusMaxBatchByteSize'
	];

	/**
	 * Key in wgEventStreams['stream_name']['producers'] that contains settings
	 * for this MediaWiki EventBus producer.
	 */
	public const EVENT_STREAM_CONFIG_PRODUCER_NAME = 'mediawiki_eventbus';

	/**
	 * Key in wgEventStreams['stream_name']['producers'][EVENT_STREAM_CONFIG_PRODUCER_NAME]
	 * that specifies if the stream is enabled. A stream is 'disabled' only if
	 * this setting is explicitly false, or if the stream name
	 * does not have an entry in wgEventStreams
	 * (and wgEventStreams is an array with other streams configured).
	 */
	public const EVENT_STREAM_CONFIG_ENABLED_SETTING = 'enabled';

	/**
	 * Key in wgEventStreams['stream_name']['producers'][EVENT_STREAM_CONFIG_PRODUCER_NAME] that specifies
	 * the event service name that should be used for a specific stream.
	 * This should be a key into $eventServiceConfig, which usually is configured
	 * using the EventBus MW config wgEventServices.
	 * If not found via StreamConfigs, EventServiceDefault will be used.
	 */
	public const EVENT_STREAM_CONFIG_SERVICE_SETTING = 'event_service_name';

	/**
	 * Internal name of an EventBus instance that never sends events.
	 * This is used for streams that are disabled or undeclared.
	 * This will also be used as the dummy 'url' of that instance.
	 * (Public only for testing purposes.)
	 */
	public const EVENT_SERVICE_DISABLED_NAME = '_disabled_eventbus_';

	/**
	 * @var array|mixed
	 */
	private array $eventServiceConfig;

	/**
	 * @var string|mixed
	 */
	private string $eventServiceDefault;

	/**
	 * @var StreamConfigs|null
	 */
	private ?StreamConfigs $streamConfigs;

	/**
	 * @var string|mixed
	 */
	private string $enableEventBus;

	/**
	 * @var int|mixed
	 */
	private int $maxBatchByteSize;

	/**
	 * @var EventFactory
	 */
	private EventFactory $eventFactory;

	/**
	 * @var MultiHttpClient
	 */
	private MultiHttpClient $http;

	/**
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * @var array
	 */
	private array $eventBusInstances = [];

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
		$this->maxBatchByteSize = $options->get( 'EventBusMaxBatchByteSize' );
		$this->streamConfigs = $streamConfigs;
		$this->eventFactory = $eventFactory;
		$this->http = $http;
		$this->logger = $logger;

		// Save a 'disabled' non producing EventBus instance that sets
		// the allowed event type to TYPE_NONE. No
		// events sent through this instance will actually be sent to an event service.
		// This is done to allow us to easily 'disable' streams.
		$this->eventBusInstances[self::EVENT_SERVICE_DISABLED_NAME] = new EventBus(
			$this->http,
			EventBus::TYPE_NONE,
			$this->eventFactory,
			self::EVENT_SERVICE_DISABLED_NAME,
			$this->maxBatchByteSize,
			0,
			false
		);
	}

	/**
	 * @param string $eventServiceName
	 *   The name of a key in the EventServices config looked up via
	 *   MediawikiServices::getInstance()->getMainConfig()->get('EventServices').
	 *   The EventService config is keyed by service name, and should at least contain
	 *   a 'url' entry pointing at the event service endpoint events should be
	 *   POSTed to. They can also optionally contain a 'timeout' entry specifying
	 *   the HTTP POST request timeout, and a 'x_client_ip_forwarding_enabled' entry
	 *   that can be set to true if the X-Client-IP header from the originating request
	 *   should be forwarded to the event service. Instances are singletons identified
	 *   by $eventServiceName.
	 *
	 * @note Previously, this function took a $config object instead of an
	 * event service name.  This is a backwards compatible change, but because
	 * there are no other users of this extension, we can do this safely.
	 *
	 * @throws InvalidArgumentException if EventServices or $eventServiceName is misconfigured.
	 * @return EventBus
	 */
	public function getInstance( string $eventServiceName ): EventBus {
		if ( array_key_exists( $eventServiceName, $this->eventBusInstances ) ) {
			// If eventServiceName has already been instantiated, return it.
			return $this->eventBusInstances[$eventServiceName];
		} elseif (
			array_key_exists( $eventServiceName, $this->eventServiceConfig ) &&
			array_key_exists( 'url', $this->eventServiceConfig[$eventServiceName] )
		) {
			// else, create eventServiceName instance from config
			// and save it in eventBusInstances.
			$eventServiceSettings = $this->eventServiceConfig[$eventServiceName];
			$url = $eventServiceSettings['url'];
			$timeout = $eventServiceSettings['timeout'] ?? null;
			$forwardXClientIP = $eventServiceSettings['x_client_ip_forwarding_enabled'] ?? false;

			$this->eventBusInstances[$eventServiceName] = new EventBus(
				$this->http,
				$this->enableEventBus,
				$this->eventFactory,
				$url,
				$this->maxBatchByteSize,
				$timeout,
				$forwardXClientIP
			);
			return $this->eventBusInstances[$eventServiceName];
		} else {
			$error = "Could not get EventBus instance for event service '$eventServiceName'. " .
				'This event service name must exist in EventServices config with a url setting.';
			$this->logger->error( $error );
			throw new InvalidArgumentException( $error );
		}
	}

	/**
	 * Gets an EventBus instance for a $stream.
	 *
	 * If EventStreamConfig is not configured, or if the stream is configured but
	 * does not set ['producers']['mediawiki_eventbus'][EVENT_STREAM_CONFIG_SERVICE_SETTING],
	 * EventServiceDefault will be used.
	 *
	 * If EventStreamConfig is configured, but the stream is not or the stream has
	 * ['producers']['mediawiki_eventbus']['enabled'] = false, this will return
	 * a non-producing EventBus instance.
	 *
	 * @param string $streamName the stream to send an event to
	 * @return EventBus
	 * @throws InvalidArgumentException
	 */
	public function getInstanceForStream( string $streamName ): EventBus {
		if ( $this->streamConfigs === null ) {
			$eventServiceName = $this->eventServiceDefault;
		} elseif ( !$this->isStreamEnabled( $streamName ) ) {
			// Don't send event if $streamName is explicitly disabled.

			$eventServiceName = self::EVENT_SERVICE_DISABLED_NAME;
			$this->logger->debug(
				"Using non-producing EventBus instance for stream $streamName. " .
				'This stream is either undeclared, or is explicitly disabled.'
			);
		} else {
			$eventServiceName = $this->getEventServiceNameForStream( $streamName ) ??
				$this->eventServiceDefault;
			$this->logger->debug(
				"Using event intake service $eventServiceName for stream $streamName."
			);
		}

		return self::getInstance( $eventServiceName );
	}

	/**
	 * Uses StreamConfigs to determine if a stream is enabled.
	 * By default, a stream is enabled.  It is disabled only if:
	 *
	 * - wgEventStreams[$streamName]['producers']['mediawiki_eventbus']['enabled'] === false
	 * OR
	 * - wgEventStreams != null, but, wgEventStreams[$streamName] is not declared
	 *
	 * @param string $streamName
	 * @return bool
	 */
	private function isStreamEnabled( string $streamName ): bool {
		// No streamConfigs means any stream is enabled
		if ( $this->streamConfigs === null ) {
			return true;
		}

		$streamConfigEntries = $this->streamConfigs->get( [ $streamName ], true );

		// If $streamName is not declared in EventStreamConfig, then it is not enabled.
		if ( !array_key_exists( $streamName, $streamConfigEntries ) ) {
			return false;
		}

		$streamSettings = $streamConfigEntries[$streamName];

		return $streamSettings['producers'][
			self::EVENT_STREAM_CONFIG_PRODUCER_NAME
		][self::EVENT_STREAM_CONFIG_ENABLED_SETTING] ?? true;
	}

	/**
	 * Looks up the wgEventStreams[$streamName]['producers']['mediawiki_eventbus'][EVENT_STREAM_CONFIG_SERVICE_SETTING]
	 * setting for this stream.
	 * If wgEventStreams is not configured, or if the stream is not configured in wgEventStreams,
	 * or if the stream does not have EVENT_STREAM_CONFIG_SERVICE_SETTING set,
	 * then this will return null.
	 *
	 * @param string $streamName
	 * @return string|null
	 */
	private function getEventServiceNameForStream( string $streamName ): ?string {
		// Use eventServiceDefault if no streamConfigs were provided.
		if ( $this->streamConfigs === null ) {
			return null;
		}

		// Else attempt to lookup EVENT_STREAM_CONFIG_SERVICE_SETTING for this stream.
		$streamConfigEntries = $this->streamConfigs->get( [ $streamName ] );

		$streamSettings = $streamConfigEntries[$streamName] ?? [];

		$eventServiceName = $streamSettings['producers'][
			self::EVENT_STREAM_CONFIG_PRODUCER_NAME
		][self::EVENT_STREAM_CONFIG_SERVICE_SETTING] ?? null;

		// For backwards compatibility, the event service name setting used to be a top level
		// stream setting 'destination_event_service'. If EVENT_STREAM_CONFIG_SERVICE_SETTING, use it instead.
		// This can be removed once all streams have been migrated to using the
		// producers.mediawiki_eventbus specific setting.
		// https://phabricator.wikimedia.org/T321557
		return $eventServiceName ?: $streamSettings['destination_event_service'] ?? null;
	}
}
