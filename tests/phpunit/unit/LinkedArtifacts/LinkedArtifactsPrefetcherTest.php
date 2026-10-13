<?php

namespace MediaWiki\Extension\EventBus\Tests\Unit\LinkedArtifacts;

use MediaWiki\DomainEvent\DomainEventDispatcher;
use MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactResponse;
use MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactsClient;
use MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactsConfig;
use MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactsPrefetcher;
use MediaWiki\Extension\EventBus\LinkedArtifacts\RevisionLinkedArtifactPrefetchedEvent;
use MediaWikiUnitTestCase;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @covers \MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactsPrefetcher
 */
class LinkedArtifactsPrefetcherTest extends MediaWikiUnitTestCase {

	/**
	 * @param LinkedArtifactResponse $response
	 * @param DomainEventDispatcher $dispatcher
	 * @param string|null $expectedCacheControl no-cache only for a reconciliation request.
	 */
	private function newPrefetcher(
		LinkedArtifactResponse $response,
		DomainEventDispatcher $dispatcher,
		?string $expectedCacheControl = null
	): LinkedArtifactsPrefetcher {
		$config = new LinkedArtifactsConfig( [
			'enabled' => true,
			'artifacts' => [
				'my-artifact' => [ 'entity_kind' => 'revision', 'prefetch' => [ 'timeout_ms' => 30000 ] ],
			],
		] );

		$client = $this->createMock( LinkedArtifactsClient::class );
		// A fetch at the artifact's own timeout, not the default.
		$client->expects( $this->once() )
			->method( 'fetchRevisionArtifact' )
			->with( 'my-artifact', 'enwiki', 42, 99, 30000, $expectedCacheControl )
			->willReturn( $response );

		return new LinkedArtifactsPrefetcher(
			$client,
			$config,
			$dispatcher,
			$this->createMock( IConnectionProvider::class )
		);
	}

	public function testSuccessDispatchesTheArtifact(): void {
		$dispatched = [];
		$dispatcher = $this->createMock( DomainEventDispatcher::class );
		$dispatcher->expects( $this->once() )
			->method( 'dispatch' )
			->willReturnCallback( static function ( $event ) use ( &$dispatched ) {
				$dispatched[] = $event;
			} );

		$response = new LinkedArtifactResponse( 200, [ 'content-type' => 'application/json' ], '{"a":1}' );
		// A reconciliation request makes LAC compute the artifact again.
		$result = $this->newPrefetcher(
			$response, $dispatcher, LinkedArtifactsClient::CACHE_CONTROL_NO_CACHE
		)->prefetchRevisionArtifact( 'my-artifact', 'enwiki', 42, 99, 'PageLatestRevisionChanged', 'edit', true );

		$this->assertSame( $response, $result );
		$this->assertCount( 1, $dispatched );
		$event = $dispatched[0];
		$this->assertInstanceOf( RevisionLinkedArtifactPrefetchedEvent::class, $event );
		$this->assertSame( 'my-artifact', $event->getArtifactName() );
		$this->assertSame( 'enwiki', $event->getWikiId() );
		$this->assertSame( 42, $event->getPageId() );
		$this->assertSame( 99, $event->getRevisionId() );
		$this->assertSame( 'application/json', $event->getContentType() );
		$this->assertSame( '{"a":1}', $event->getBody() );
		$this->assertSame( 'PageLatestRevisionChanged', $event->getTriggerEventType() );
		$this->assertSame( 'edit', $event->getTriggerCause() );
		$this->assertTrue( $event->isReconciliationRequest() );
	}

	private function dispatchedEvent( LinkedArtifactResponse $response ): RevisionLinkedArtifactPrefetchedEvent {
		$dispatched = null;
		$dispatcher = $this->createMock( DomainEventDispatcher::class );
		$dispatcher->method( 'dispatch' )
			->willReturnCallback( static function ( $event ) use ( &$dispatched ) {
				$dispatched = $event;
			} );

		$this->newPrefetcher( $response, $dispatcher )->prefetchRevisionArtifact( 'my-artifact', 'enwiki', 42, 99 );

		return $dispatched;
	}

	public function testTimestampsComeFromTheResponse(): void {
		ConvertibleTimestamp::setFakeTime( '20261006140000' );
		$event = $this->dispatchedEvent( new LinkedArtifactResponse(
			200,
			[ 'date' => 'Tue, 06 Oct 2026 13:00:00 GMT', 'last-modified' => 'Tue, 06 Oct 2026 12:00:05 GMT' ],
			'{}'
		) );

		// The event timestamp is when LAC responded. The compute time can be earlier.
		$this->assertSame( '20261006130000', $event->getEventTimestamp()->getTimestamp( TS_MW ) );
		$this->assertSame( '20261006120005', $event->getArtifactComputeTimestamp()->getTimestamp( TS_MW ) );
	}

	public function testTimestampsWithoutResponseHeaders(): void {
		ConvertibleTimestamp::setFakeTime( '20261006140000' );
		$event = $this->dispatchedEvent( new LinkedArtifactResponse( 200, [], '{}' ) );

		$this->assertSame( '20261006140000', $event->getEventTimestamp()->getTimestamp( TS_MW ) );
		$this->assertNull( $event->getArtifactComputeTimestamp() );
	}

	public static function provideFailures(): iterable {
		yield 'not found' => [ new LinkedArtifactResponse( 404, [ 'content-type' => 'application/problem+json' ] ) ];
		yield 'server error' => [ new LinkedArtifactResponse( 500, [ 'content-type' => 'text/plain' ] ) ];
	}

	/**
	 * @dataProvider provideFailures
	 */
	public function testFailureDoesNotDispatch( LinkedArtifactResponse $response ): void {
		$dispatcher = $this->createMock( DomainEventDispatcher::class );
		$dispatcher->expects( $this->never() )->method( 'dispatch' );

		$this->assertSame(
			$response,
			$this->newPrefetcher( $response, $dispatcher )
				->prefetchRevisionArtifact( 'my-artifact', 'enwiki', 42, 99 )
		);
	}
}
