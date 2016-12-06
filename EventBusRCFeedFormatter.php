<?php

/**
 * Augments the recentchanges object for use with the EventBus service, and then
 * formats it into a JSON string.
 *
 * @extends MachineReadableRCFeedFormatter
 */
class EventBusRCFeedFormatter extends MachineReadableRCFeedFormatter {

	/**
	 * @const string topic that will be set as meta.topic for a recentchange
	 *               event that is POSTed to the EventBus service.
	 */
	const TOPIC = 'mediawiki.recentchange';

	/**
	 * Calls MachineReadableRCFeedFormatter's getLine(), augments
	 * the returned object so that it is suitable for POSTing to
	 * the EventBus service, and then returns those events
	 * serialized (AKA formatted) as a JSON string by calling
	 * EventBus serializeEvents().
	 *
	 * @see MachineReadableRCFeedFormatter::getLine
	 */
	public function getLine( array $feed, RecentChange $rc, $actionComment ) {
		$event = EventBus::createEvent(
			EventBus::getArticleURL( $rc->getTitle() ),
			self::TOPIC,
			parent::getLine( $feed, $rc, $actionComment )
		);

		// If timestamp exists on the recentchange event (it should),
		// then use it as the meta.dt event datetime.
		if ( array_key_exists( 'timestamp', $event ) ) {
			$event['meta']['dt'] = date( 'c', $event['timestamp'] );
		}
		$events = [ $event ];
		return EventBus::serializeEvents( $events );
	}

	/**
	 * Here, formatArray is implemented to just return the same
	 * event it is given.  Since parent::getLine() calls this,
	 * and we need to augment the $event after it is returned from
	 * parent::getLine, we don't actually want to serialize (AKA format)
	 * the event at this time.  This class' getLine function will
	 * serialize/format the event after it has augmented the
	 * event returned here.
	 */
	protected function formatArray( array $event ) {
		return $event;
	}
}
