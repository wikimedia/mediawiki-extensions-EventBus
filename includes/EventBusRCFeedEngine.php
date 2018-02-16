<?php

/**
 * Emit a recent change notification via EventBus.  The feed uri should be
 * start with eventbus://.  The event's topic will be 'mediawiki.recentchange' as
 * set in EventBusRCFeedFormatter::TOPIC.
 *
 * @example
 * $wgRCFeeds['eventbus'] = array(
 *      'formatter' => 'EventBusRCFeedFormatter',
 *      'uri'       => 'eventbus://eventbus.svc.eqiad.wmnet:8085/v1/events'
 * );
 * $wgRCEngines = array(
 *      'eventbus' => 'EventBusRCFeedEngine'
 * );
 *
 */
class EventBusRCFeedEngine extends RCFeedEngine {

	/**
	 * @param array $feed will be used for EventBus $config.  Singleton instances
	 *                     are identified by $feed['uri'];
	 * @param string|array $line to send
	 *
	 * @see RCFeedEngine::send
	 */
	public function send( array $feed, $line ) {
		DeferredUpdates::addCallableUpdate(
			function () use ( $feed, $line ) {
				// construct EventBus config from RCFeed $feed uri.
				$config = $feed;
				// RCFeedEngines are selected via URI protocol schemes.  This engine
				// is chosen using eventbus://, but EventBus URIs are just HTTP REST
				// endpoints.  Replace eventbus:// with http://
				$config['EventServiceUrl'] = str_replace( 'eventbus://', 'http://', $feed['uri'] );
				return EventBus::getInstance( $config )->send( $line );
			}
		);
	}
}
