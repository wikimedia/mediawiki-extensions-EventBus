<?php
namespace MediaWiki\Extension\EventBus\Tests\Unit;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\EventBus\EventBus;
use MediaWiki\Extension\EventBus\EventBusFactory;
use MediaWiki\Extension\EventBus\EventBusSendUpdate;
use MediaWikiUnitTestCase;
use Wikimedia\Assert\ParameterAssertionException;

/**
 * @covers \MediaWiki\Extension\EventBus\EventBusSendUpdate
 */
class EventBusSendUpdateTest extends MediaWikiUnitTestCase {

	private EventBusFactory $eventBusFactory;

	protected function setUp(): void {
		parent::setUp();

		$this->eventBusFactory = $this->createMock( EventBusFactory::class );
	}

	public function testShouldDoNothingIfNoEvents(): void {
		$this->eventBusFactory->expects( $this->never() )
			->method( $this->anything() );

		self::runUpdates(
			new EventBusSendUpdate( $this->eventBusFactory, 'stream', [] ),
			new EventBusSendUpdate( $this->eventBusFactory, 'otherStream', [] )
		);
	}

	public function testShouldRejectIfEventDataWasAccidentallyGivenInsteadOfList(): void {
		$this->expectException( ParameterAssertionException::class );
		$this->expectExceptionMessage( 'must be a flat list of events' );

		$event = [ 'foo' => 'bar' ];

		new EventBusSendUpdate( $this->eventBusFactory, 'stream', $event );
	}

	public function testShouldBatchEventsByBackingService(): void {
		$eventBus = $this->createMock( EventBus::class );
		$otherEventBus = $this->createMock( EventBus::class );
		$unusedEventBus = $this->createMock( EventBus::class );

		$this->eventBusFactory->method( 'getEventServiceNameForStream' )
			->willReturnMap( [
				[ 'stream', 'event-service' ],
				[ 'other-stream', 'other-event-service' ],
				[ 'third-stream', 'event-service' ],
				[ 'fourth-stream', 'unused-event-service' ]
			] );

		$this->eventBusFactory->method( 'getInstance' )
			->willReturnMap( [
				[ 'event-service', $eventBus ],
				[ 'other-event-service', $otherEventBus ],
				[ 'unused-event-service', $unusedEventBus ]
			] );

		$eventBus->expects( $this->once() )
			->method( 'send' )
			->with( [ 'event1', 'event2', 'event3', 'event6' ] );

		$otherEventBus->expects( $this->once() )
			->method( 'send' )
			->with( [ 'event4', 'event5', 'event7' ] );

		$unusedEventBus->expects( $this->never() )
			->method( 'send' );

		self::runUpdates(
			// events destined for streams backed by event-service
			EventBusSendUpdate::newForStream( $this->eventBusFactory, 'stream', [ 'event1', 'event2' ] ),
			EventBusSendUpdate::newForStream( $this->eventBusFactory, 'third-stream', [ 'event3' ] ),
			EventBusSendUpdate::newForStream( $this->eventBusFactory, 'stream', [] ),

			// events destined for streams backed by other-event-service
			EventBusSendUpdate::newForStream( $this->eventBusFactory, 'other-stream', [ 'event4' ] ),
			EventBusSendUpdate::newForStream( $this->eventBusFactory, 'other-stream', [ 'event5' ] ),

			// events explicitly sent to event-service
			new EventBusSendUpdate( $this->eventBusFactory, 'event-service', [ 'event6' ] ),

			// events explicitly sent to other-event-service
			new EventBusSendUpdate( $this->eventBusFactory, 'other-event-service', [ 'event7' ] ),

			// empty updates for unused-event-service that won't send events
			EventBusSendUpdate::newForStream( $this->eventBusFactory, 'fourth-stream', [] ),
			new EventBusSendUpdate( $this->eventBusFactory, 'unused-event-service', [] )
		);
	}

	/**
	 * Convenience function to enqueue and run a series of {@link EventBusSendUpdate} deferred updates.
	 * @param EventBusSendUpdate ...$updates
	 */
	private static function runUpdates( EventBusSendUpdate ...$updates ): void {
		foreach ( $updates as $update ) {
			DeferredUpdates::addUpdate( $update );
		}

		DeferredUpdates::doUpdates();
	}
}
