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
	 * @param String $eventServiceName the name of the event service to use
	 * @param int $level The minimum logging level at which this handler will be triggered
	 * @param Boolean $bubble Whether the messages that are handled can bubble up the stack or not
	 * @throws ConfigException
	 */
	public function __construct( $eventServiceName, $level = Logger::DEBUG, $bubble = true ) {
		parent::__construct( $level, $bubble );

		$this->eventBus = EventBus::getInstance( $eventServiceName );
	}

	/**
	 * Writes the record down to the log of the implementing handler
	 *
	 * @param array $record
	 * @return void
	 */
	protected function write( array $record ) {
		$events[] = $record;

		DeferredUpdates::addCallableUpdate(
			function () use ( $events ) {
				$this->eventBus->send( $events );
			}
		);
	}
}
