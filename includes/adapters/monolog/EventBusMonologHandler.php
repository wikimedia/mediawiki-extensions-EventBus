<?php

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;

/**
 * Log handler that supports sending messages to Kafka over
 * EventBus and EventGate service.
 *
 * @file
 * @since 1.33
 * @copyright © 2019 Wikimedia Foundation and contributors
 * @author Petr Pchelko <ppchelko@wikimedia.org>
 */

class EventBusMonologHandler extends AbstractProcessingHandler {

	/**
	 * The instance of EventBus to use for logging
	 * @var EventBus
	 */
	private $eventBus;

	/**
	 * EventBusHandler constructor.
	 *
	 * @param string $eventServiceName the name of the event service to use
	 * @param int $level The minimum logging level at which this handler will be triggered
	 * @param Boolean $bubble Whether the messages that are handled can bubble up the stack or not
	 * @throws ConfigException
	 */
	public function __construct( $eventServiceName, $level = Logger::DEBUG, $bubble = true ) {
		parent::__construct( $level, $bubble );

		$this->eventBus = EventBus::getInstance( $eventServiceName );
	}

	/**
	 * Assumes that $record['context'] contains the event to send via EventBus.
	 *
	 * @param array $record
	 * @return void
	 */
	protected function write( array $record ) {
		// Use the log record context as formatted as the event data.
		$event = $record['context'];

		// wfDebugLog() adds a field called 'private' to the context
		// that does not belong in the event. Delete the 'private' field here and
		// then let EventBus serialize the log context to JSON string and send it.
		// NOTE: we could create a custom formatter for EventBus, but all
		// it would do is exactly this.
		unset( $event['private'] );

		DeferredUpdates::addCallableUpdate(
			function () use ( $event ) {
				// Events via Monolog might have binary strings in them.
				// We need to be sure that any binary data is first encoded.
				//
				EventBus::replaceBinaryValuesRecursive( $event );
				$this->eventBus->send( [ $event ] );
			}
		);
	}
}
