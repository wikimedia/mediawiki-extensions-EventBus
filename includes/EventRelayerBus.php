<?php

/**
 * Event relayer adapter for sending messages to EventBus
 * Configuration:
 * $wgEventRelayerConfig['wancache-main-memcached-purge'] = [ 'class' => 'EventRelayerBus',
 *      'channels' => ['wancache-main-memcached-purge' => 'memcached.purge'],
 *      'uri' => '/memcached/purge' ];
 */
class EventRelayerBus extends EventRelayer {

	/**
	 * @var EventBus
	 */
	private $bus;

	/**
	 * Channel map for event bus.
	 * Maps MediaWiki notification channel names into EventBus channel names.
	 * @var string[]
	 */
	private $channels = [];

	/**
	 * Default URI for events
	 * @var string
	 */
	private $uri = '/generic/event';

	public function __construct( array $params ) {
		parent::__construct( $params );
		if ( !empty( $params['channels'] ) ) {
			$this->channels = $params['channels'];
		}
		if ( !empty( $params['uri'] ) ) {
			$this->uri = $params['uri'];
		}
		$this->bus = EventBus::getInstance();
	}

	/**
	 * Get URI for specific event
	 * @param array $event Event being processed
	 * @return string
	 */
	protected function getUri( $event ) {
		return $this->uri;
	}

	/**
	 * Process an event and add result(s) to collection
	 * @param array &$events Collection of events
	 * @param array $event Current event
	 * @param string $channel
	 */
	protected function processEvent( &$events, $event, $channel ) {
		$events[] = EventBusHooks::createEvent( $this->getUri( $event ), $channel, $event );
	}

	protected function doNotify( $channel, array $events ) {
		$outEvents = [];
		foreach ( $events as $event ) {
			$this->processEvent( $outEvents, $event, $channel );
		}
		$this->bus->send( $outEvents );
		return true;
	}
}
