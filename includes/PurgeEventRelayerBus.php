<?php

/**
 * Event relayer for URL purge events
 * The event should have "urls" parameter with the list of URLs.
 * Configuration:
 * $wgEventRelayerConfig['cdn-url-purges'] = [ 'class' => 'PurgeEventRelayerBus',
 *                    'channels' => ['cdn-url-purges' => 'resource_change'] ];
 */
class PurgeEventRelayerBus extends EventRelayerBus {

	protected function getUri( $event ) {
		if ( !isset( $event['url'] ) ) {
			return parent::getUri( $event );
		}
		return $event['url'];
	}
}
