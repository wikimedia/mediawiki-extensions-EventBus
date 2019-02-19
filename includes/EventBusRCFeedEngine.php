<?php

/**
 * Emit a recent change notification via EventBus.  The feed uri should be
 * start with eventbus://.  The event's topic will be 'mediawiki.recentchange' as
 * set in EventBusRCFeedFormatter::TOPIC.
 *
 * @example
 *
 * // Event Service config (for EventBus instances):
 * $wgEventServices = array(
 *      'eventbus-main' => array(
 *          'url'     => 'http://eventbus.svc.eqiad.wmnet:8085/v1/events',
 *          'timeout' => 60
 *      )
 * );
 *
 * // RCFeed configuration to use a defined Event Service instance.
 * $wgRCFeeds['eventbus-main'] = array(
 *      'class'            => 'EventBusRCFeedEngine',
 *      // eventServiceName must match an entry in wgEventServices.
 *      'eventServiceName' => 'eventbus-main'
 *      'formatter'        => 'EventBusRCFeedFormatter',
 * );
 *
 */
class EventBusRCFeedEngine extends RCFeedEngine {

	/**
	 * @param array $feed is expected to contain 'eventServiceName', which will
	 * 					  be looked up by EventBus in wgEventServices.
	 *                    If not given, the value of wgEventServiceUrl will be used
	 * 					  to configure the Event Service endpoint.
	 * @param string|array $line to send
	 * @return bool Success
	 *
	 * @see RCFeedEngine::send
	 */
	public function send( array $feed, $line ) {
		DeferredUpdates::addCallableUpdate(
			function () use ( $feed, $line ) {
				// construct EventBus config from RCFeed config eventServiceName config,
				// or wgEventServiceUrl if eventServiceName is not specified.
				$config = $feed;
				$eventServiceName = array_key_exists( 'eventServiceName', $config ) ?
					$config['eventServiceName'] : null;

				return EventBus::getInstance( $eventServiceName )->send( $line );
			}
		);
		return true;
	}
}
