<?php

namespace MediaWiki\Extension\EventBus\Adapters\RCFeed;

use DeferredUpdates;
use FormattedRCFeed;
use MediaWiki\Extension\EventBus\EventBus;

/**
 * Emit a recent change notification via EventBus.
 *
 * The event's stream will be 'mediawiki.recentchange' as set
 * in EventBusRCFeedFormatter::STREAM. wgEventStreams or wgEventServiceDefault
 * specifies the destination event service for recentchange events.
 *
 * @example
 *
 * // Event Service MediaWiki config
 * $wgEventServices = array(
 * 	'eventgate-example' => array(
 * 		'url'     => 'http://eventgate-example.svc.example.org:4492/v1/events',
 * 		'timeout' => 60
 * 	)
 * );
 *
 * // Event Stream Config managed by EventStreamConfig extension
 * // per event stream configuration:
 * $wgEventStreams = [
 * 		[
 * 			'stream' => mediawiki.recentchange',
 * 			'destination_event_service' => 'eventgate-main'
 *    	],
 *    	...
 * ];
 *
 * // RCFeed configuration to use a defined Event Service instance.
 * $wgRCFeeds['eventbus'] = array(
 * 	'class'            => 'EventBusRCFeedEngine',
 * 	'formatter'        => 'EventBusRCFeedFormatter',
 * );
 *
 */
class EventBusRCFeedEngine extends FormattedRCFeed {

	/**
	 * @param array $feed is expected to contain 'eventServiceName', which will
	 *  be looked up by EventBus in wgEventServices.
	 * @param string|array $line to send
	 * @return bool Success
	 *
	 * @see RCFeedEngine::send
	 */
	public function send( array $feed, $line ) {
		$eventBus = EventBus::getInstanceForStream( EventBusRCFeedFormatter::STREAM );
		DeferredUpdates::addCallableUpdate(
			static function () use ( $eventBus, $line ) {
				return $eventBus->send( $line );
			}
		);

		return true;
	}
}
