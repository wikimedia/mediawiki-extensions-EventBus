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
class EventBusRCFeedEngine extends FormattedRCFeed {

	/**
	 * @param array $feed is expected to contain 'eventServiceName', which will
	 * 					  be looked up by EventBus in wgEventServices.
	 * @param string|array $line to send
	 * @return bool Success
	 *
	 * @see RCFeedEngine::send
	 */
	public function send( array $feed, $line ) {
		// $feed will contain the RCFeed config and eventServiceName
		// should match an entry in the wgEventServices config.
		if ( !array_key_exists( 'eventServiceName', $feed ) ) {
			EventBus::logger()->error(
				'Must set \'eventServiceName\' in RCFeeds configuration to ' .
				'use EventBusRCFeedEngine.'
			);
			return false;
		}

		$eventServiceName = $feed['eventServiceName'];
		DeferredUpdates::addCallableUpdate(
			function () use ( $eventServiceName, $line ) {
				return EventBus::getInstance( $eventServiceName )->send( $line );
			}
		);
		return true;
	}
}
