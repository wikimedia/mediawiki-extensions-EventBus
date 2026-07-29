<?php
namespace MediaWiki\Extension\EventBus\Tests\Integration\Adapters\Monolog;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\EventBus\Adapters\Monolog\EventBusMonologHandler;
use MediaWiki\Extension\EventBus\EventBus;
use MediaWiki\Extension\EventBus\EventBusFactory;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Logger\Monolog\ContextProcessor;
use MediaWikiIntegrationTestCase;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

/**
 * @covers \MediaWiki\Extension\EventBus\Adapters\Monolog\EventBusMonologHandler
 */
class EventBusMonologHandlerIntegrationTest extends MediaWikiIntegrationTestCase {
	private const TEST_EVENT_SERVICE_NAME = 'test-event-service';

	private EventBusFactory $eventBusFactory;

	private LoggerInterface $logger;

	protected function setUp(): void {
		parent::setUp();

		$this->eventBusFactory = $this->createMock( EventBusFactory::class );

		$this->setService( 'EventBus.EventBusFactory', $this->eventBusFactory );

		$handler = new EventBusMonologHandler( self::TEST_EVENT_SERVICE_NAME );

		$this->logger = new Logger( 'test', [ $handler ] );
	}

	public function testShouldEnqueueEvents(): void {
		$scope = DeferredUpdates::preventOpportunisticUpdates();

		$eventBus = $this->createMock( EventBus::class );
		$eventBus->expects( $this->once() )
			->method( 'send' )
			->with( [ [ 'foo' => 'bar' ], [ 'baz' => 'quux' ] ] );

		$this->eventBusFactory->method( 'getInstance' )
			->with( self::TEST_EVENT_SERVICE_NAME )
			->willReturn( $eventBus );

		$this->logger->info( 'Test log', [ 'foo' => 'bar' ] );
		$this->logger->warning( 'Test log', [ 'baz' => 'quux' ] );

		DeferredUpdates::doUpdates();
	}

	public function testShouldStripGlobalLoggingContextFromEvents(): void {
		$scope = DeferredUpdates::preventOpportunisticUpdates();

		$contextScope = LoggerFactory::getContext()->addScoped( [
			'context.oauth_consumer_id' => '562',
			'context.oauth_consumer_name' => 'InternetArchiveBot AUTH v2',
		] );

		$this->logger->pushProcessor( new ContextProcessor() );

		$eventBus = $this->createMock( EventBus::class );
		$eventBus->expects( $this->once() )
			->method( 'send' )
			->with( [ [ 'foo' => 'bar' ] ] );

		$this->eventBusFactory->method( 'getInstance' )
			->with( self::TEST_EVENT_SERVICE_NAME )
			->willReturn( $eventBus );

		$this->logger->info( 'Test log', [ 'foo' => 'bar' ] );

		DeferredUpdates::doUpdates();
	}
}
