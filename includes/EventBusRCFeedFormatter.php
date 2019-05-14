<?php

/**
 * Augments the recentchanges object for use with the EventBus service, and then
 * formats it into a JSON string.
 *
 * @extends MachineReadableRCFeedFormatter
 */
class EventBusRCFeedFormatter extends MachineReadableRCFeedFormatter {
	/**
	 * Removes properties which values are 'null' from the event.
	 * Will modify the original event passed in
	 *
	 * @param array $event the event to modify.
	 * @return array
	 */
	private static function removeNulls( $event ) {
		if ( !is_array( $event ) ) {
			return $event;
		}
		foreach ( $event as $key => $value ) {
			if ( is_null( $value ) ) {
				unset( $event[$key] );
			} elseif ( is_array( $value ) ) {
				$event[$key] = self::removeNulls( $value );
			}
		}
		return $event;
	}

	/**
	 * Calls MachineReadableRCFeedFormatter's getLine(), augments
	 * the returned object so that it is suitable for POSTing to
	 * the EventBus service, and then returns those events
	 * serialized (AKA formatted) as a JSON string by calling
	 * EventBus serializeEvents().
	 *
	 * @inheritDoc
	 */
	public function getLine( array $feed, RecentChange $rc, $actionComment ) {
		$attrs = parent::getLine( $feed, $rc, $actionComment );

		$eventFactory = EventBus::getInstance( 'eventbus' )->getFactory();
		$event = $eventFactory->createRecentChangeEvent(
			'mediawiki.recentchange',
			$rc->getTitle(),
			$attrs
		);

		return EventBus::serializeEvents( [ self::removeNulls( $event ) ] );
	}

	/**
	 * Here, formatArray is implemented to just return the same
	 * event it is given.  Since parent::getLine() calls this,
	 * and we need to augment the $event after it is returned from
	 * parent::getLine, we don't actually want to serialize (AKA format)
	 * the event at this time.  This class' getLine function will
	 * serialize/format the event after it has augmented the
	 * event returned here.
	 *
	 * @inheritDoc
	 */
	protected function formatArray( array $event ) {
		return $event;
	}
}
