<?php

namespace MediaWiki\Extension\EventBus\Adapters\RCFeed;

use MediaWiki\Extension\EventBus\EventBus;
use MediaWiki\MediaWikiServices;
use MediaWiki\RCFeed\MachineReadableRCFeedFormatter;
use RecentChange;

/**
 * Augments the RecentChange object for use with the EventBus service, and then
 * formats it into a JSON string.
 *
 * @extends MachineReadableRCFeedFormatter
 */
class EventBusRCFeedFormatter extends MachineReadableRCFeedFormatter {
	/**
	 * Stream name to which this event belongs.
	 */
	public const STREAM = 'mediawiki.recentchange';

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
			if ( $value === null ) {
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
	 * @suppress PhanTypeMismatchArgument
	 */
	public function getLine( array $feed, RecentChange $rc, $actionComment ) {
		$attrs = parent::getLine( $feed, $rc, $actionComment );

		$eventFactory = EventBus::getInstanceForStream( self::STREAM )->getFactory();
		$eventFactory->setCommentFormatter( MediaWikiServices::getInstance()->getCommentFormatter() );
		$event = $eventFactory->createRecentChangeEvent(
			self::STREAM,
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
	 * @suppress PhanTypeMismatchReturnProbablyReal
	 */
	protected function formatArray( array $event ) {
		return $event;
	}
}
