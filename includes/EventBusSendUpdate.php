<?php
namespace MediaWiki\Extension\EventBus;

use MediaWiki\Deferred\DeferrableUpdate;
use MediaWiki\Deferred\MergeableUpdate;
use Wikimedia\Assert\Assert;

/**
 * A deferred update that provides automatic batching for EventBus events.
 *
 * During a single request, events may be sent at different points in the
 * request lifecycle to various event streams that may however be backed by the same
 * underlying event service, resulting in unnecessary HTTP requests.
 * This deferred update provides automatic batching for these events
 * so that at most a single HTTP request is made for each underlying event service.
 */
class EventBusSendUpdate implements DeferrableUpdate, MergeableUpdate {

	private EventBusFactory $eventBusFactory;

	/**
	 * Associative array of event lists keyed by event service name.
	 * @var array[]
	 */
	private array $eventsByService = [];

	/**
	 * Create a new EventBusSendUpdate instance for a specific event service.
	 *
	 * @param EventBusFactory $eventBusFactory
	 * @param string $eventServiceName The event service to send the events to
	 * @param array $events List of events to send
	 */
	public function __construct(
		EventBusFactory $eventBusFactory,
		string $eventServiceName,
		array $events
	) {
		Assert::parameter(
			array_is_list( $events ),
			'$events',
			'must be a flat list of events'
		);

		$this->eventsByService[$eventServiceName] = $events;
		$this->eventBusFactory = $eventBusFactory;
	}

	/**
	 * Create a new EventBusSendUpdate instance for a specific stream.
	 * The stream name will be used to determine the event service to send the events to.
	 *
	 * @param EventBusFactory $eventBusFactory
	 * @param string $streamName The event stream to send the events to
	 * @param array $events List of events to send
	 * @return self
	 */
	public static function newForStream(
		EventBusFactory $eventBusFactory,
		string $streamName,
		array $events
	): self {
		$eventServiceName = $eventBusFactory->getEventServiceNameForStream( $streamName );
		return new self( $eventBusFactory, $eventServiceName, $events );
	}

	public function doUpdate(): void {
		foreach ( $this->eventsByService as $eventServiceName => $events ) {
			if ( count( $events ) > 0 ) {
				$this->eventBusFactory->getInstance( $eventServiceName )
					->send( $events );
			}
		}
	}

	public function merge( MergeableUpdate $update ): void {
		/** @var EventBusSendUpdate $update */
		Assert::parameterType( __CLASS__, $update, '$update' );
		'@phan-var EventBusSendUpdate $update';

		foreach ( $update->eventsByService as $eventServiceName => $events ) {
			$this->eventsByService[$eventServiceName] = array_merge(
				$this->eventsByService[$eventServiceName] ?? [],
				$events
			);
		}
	}
}
