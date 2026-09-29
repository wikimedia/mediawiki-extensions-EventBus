<?php

namespace MediaWiki\Extension\EventBus\Tests\Unit\LinkedArtifacts;

use MediaWiki\DomainEvent\DomainEventDispatcher;
use MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactResponse;
use MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactsClient;
use MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactsConfig;
use MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactsPrecompute;
use MediaWiki\Extension\EventBus\LinkedArtifacts\RevisionLinkedArtifactPrecomputedEvent;
use MediaWikiUnitTestCase;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @covers \MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactsPrecompute
 */
class LinkedArtifactsPrecomputeTest extends MediaWikiUnitTestCase {

	private function newPrecompute(
		LinkedArtifactResponse $response,
		DomainEventDispatcher $dispatcher
	): LinkedArtifactsPrecompute {
		$config = new LinkedArtifactsConfig( [
			'enabled' => true,
			'artifacts' => [
				'my-artifact' => [ 'entity_kind' => 'revision', 'precompute' => [ 'timeout_ms' => 30000 ] ],
			],
		] );

		$client = $this->createMock( LinkedArtifactsClient::class );
		// A no-cache fetch at the artifact's own timeout, not the default.
		$client->expects( $this->once() )
			->method( 'fetchRevisionArtifact' )
			->with( 'my-artifact', 'enwiki', 42, 99, 30000, LinkedArtifactsClient::CACHE_CONTROL_NO_CACHE )
			->willReturn( $response );

		return new LinkedArtifactsPrecompute(
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
		$result = $this->newPrecompute( $response, $dispatcher )->precomputeRevisionArtifact(
			'my-artifact', 'enwiki', 42, 99, 'PageLatestRevisionChanged', 'edit', true
		);

		$this->assertSame( $response, $result );
		$this->assertCount( 1, $dispatched );
		$event = $dispatched[0];
		$this->assertInstanceOf( RevisionLinkedArtifactPrecomputedEvent::class, $event );
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

	private function dispatchedEventTimestamp( LinkedArtifactResponse $response ): string {
		$dispatched = null;
		$dispatcher = $this->createMock( DomainEventDispatcher::class );
		$dispatcher->method( 'dispatch' )
			->willReturnCallback( static function ( $event ) use ( &$dispatched ) {
				$dispatched = $event;
			} );

		$this->newPrecompute( $response, $dispatcher )->precomputeRevisionArtifact( 'my-artifact', 'enwiki', 42, 99 );

		return $dispatched->getEventTimestamp()->getTimestamp( TS_MW );
	}

	public function testEventTimestampIsTheLastModifiedTime(): void {
		ConvertibleTimestamp::setFakeTime( '20261006130000' );
		$response = new LinkedArtifactResponse( 200, [ 'last-modified' => 'Tue, 06 Oct 2026 12:00:05 GMT' ], '{}' );

		$this->assertSame( '20261006120005', $this->dispatchedEventTimestamp( $response ) );
	}

	public function testEventTimestampIsNowWithoutLastModified(): void {
		ConvertibleTimestamp::setFakeTime( '20261006130000' );
		$response = new LinkedArtifactResponse( 200, [], '{}' );

		$this->assertSame( '20261006130000', $this->dispatchedEventTimestamp( $response ) );
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
			$this->newPrecompute( $response, $dispatcher )
				->precomputeRevisionArtifact( 'my-artifact', 'enwiki', 42, 99 )
		);
	}
}
