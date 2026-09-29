<?php

namespace MediaWiki\Extension\EventBus\Tests\Unit\LinkedArtifacts;

use MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactsConfig;
use MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactsFetcher;
use MediaWikiUnitTestCase;
use PHPUnit\Framework\Assert;
use Psr\Log\LoggerInterface;
use Wikimedia\Http\MultiHttpClient;

/**
 * @covers \MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactsFetcher
 */
class LinkedArtifactsFetcherTest extends MediaWikiUnitTestCase {

	private const URI = '/revisions/v1/my-artifact/enwiki/42/99';

	private const DEFAULT_ARTIFACTS = [ 'my-artifact' => [ 'entity_kind' => 'revision' ] ];

	/**
	 * @param array|null $httpResponse The `response` payload runMulti should return.
	 * @param callable|null $assertRequest Receives ( array $requests, array $options ).
	 * @param LoggerInterface|null $logger
	 * @param bool $enabled
	 * @param array|null $artifacts
	 * @param string $baseUrl
	 */
	private function newFetcher(
		?array $httpResponse = null,
		?callable $assertRequest = null,
		?LoggerInterface $logger = null,
		bool $enabled = true,
		?array $artifacts = null,
		string $baseUrl = 'https://lac.example/base'
	): LinkedArtifactsFetcher {
		$http = $this->createMock( MultiHttpClient::class );
		$http->method( 'runMulti' )
			->willReturnCallback(
				static function ( $requests, $options ) use ( $httpResponse, $assertRequest ) {
					if ( $assertRequest ) {
						$assertRequest( $requests, $options );
					}
					return [ [ 'response' => $httpResponse ?? [ 'code' => 200, 'headers' => [], 'body' => '' ] ] ];
				}
			);

		return new LinkedArtifactsFetcher(
			new LinkedArtifactsConfig( [
				'enabled' => $enabled,
				'base_url' => $baseUrl,
				'artifacts' => $artifacts ?? self::DEFAULT_ARTIFACTS,
			] ),
			$http,
			$logger ?? $this->createMock( LoggerInterface::class )
		);
	}

	// -- Revision routes ----------------------------------------------------------------

	public function testRevisionUriMatchesTheHoardeRoute(): void {
		// GET /revisions/v1/{name}/{wiki_id}/{page_id}/{revision_id}
		$this->assertSame(
			self::URI,
			$this->newFetcher()->getRevisionArtifactUri( 'my-artifact', 'enwiki', 42, 99 )
		);
	}

	public function testRevisionUriEncodesNameAndWikiId(): void {
		$this->assertSame(
			'/revisions/v1/my%20artifact/en%20wiki/42/99',
			$this->newFetcher()->getRevisionArtifactUri( 'my artifact', 'en wiki', 42, 99 )
		);
	}

	public function testFetchRevisionArtifactReadsWithoutForcingARefresh(): void {
		// A reader must not make LAC re-run the lambda; only a precompute does that.
		$captured = null;
		$fetcher = $this->newFetcher(
			[ 'code' => 200, 'headers' => [ 'content-type' => 'application/json' ], 'body' => '{}' ],
			static function ( $requests, $options ) use ( &$captured ) {
				$captured = [ 'requests' => $requests, 'options' => $options ];
			}
		);

		$response = $fetcher->fetchRevisionArtifact( 'my-artifact', 'enwiki', 42, 99, 3000 );

		$this->assertSame( '{}', $response->getBody() );
		Assert::assertSame( 'https://lac.example/base' . self::URI, $captured['requests'][0]['url'] );
		Assert::assertSame( [], $captured['requests'][0]['headers'] );
		Assert::assertSame( 3.0, $captured['options']['reqTimeout'] );
	}

	// -- Transport ----------------------------------------------------------------------

	public function testNoCacheSendsTheHeaderAndPassesTheTimeoutThrough(): void {
		$captured = null;
		$fetcher = $this->newFetcher(
			null,
			static function ( $requests, $options ) use ( &$captured ) {
				$captured = [ 'requests' => $requests, 'options' => $options ];
			}
		);

		$fetcher->fetch( self::URI, 9000, true );

		Assert::assertSame( 'https://lac.example/base' . self::URI, $captured['requests'][0]['url'] );
		Assert::assertSame( 'GET', $captured['requests'][0]['method'] );
		Assert::assertSame( [ 'Cache-Control' => 'no-cache' ], $captured['requests'][0]['headers'] );
		// MultiHttpClient takes seconds, so 9000ms must arrive as 9.
		Assert::assertSame( 9.0, $captured['options']['reqTimeout'] );
	}

	public function testWithoutNoCacheOmitsTheHeader(): void {
		$captured = null;
		$fetcher = $this->newFetcher(
			null,
			static function ( $requests ) use ( &$captured ) {
				$captured = $requests;
			}
		);

		$fetcher->fetch( self::URI, 5000 );

		Assert::assertSame( [], $captured[0]['headers'] );
	}

	public function testTrailingSlashOnBaseUrlIsNotDoubled(): void {
		$captured = null;
		$fetcher = $this->newFetcher(
			null,
			static function ( $requests ) use ( &$captured ) {
				$captured = $requests;
			},
			null,
			true,
			null,
			'https://lac.example/base/'
		);

		$fetcher->fetch( self::URI, 5000 );

		Assert::assertSame( 'https://lac.example/base' . self::URI, $captured[0]['url'] );
	}

	public function testSuccessReturnsStatusContentTypeAndBody(): void {
		$fetcher = $this->newFetcher( [
			'code' => 200,
			'headers' => [ 'content-type' => 'application/json' ],
			'body' => '{"ok":true}',
		] );

		$response = $fetcher->fetch( self::URI, 5000, true );

		$this->assertTrue( $response->isSuccess() );
		$this->assertSame( 200, $response->getStatusCode() );
		$this->assertSame( 'application/json', $response->getContentType() );
		$this->assertSame( '{"ok":true}', $response->getBody() );
	}

	public function testNotFoundLogsAWarningRatherThanAnError(): void {
		// An artifact LAC has nothing for is an expected outcome, not a failure.
		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->once() )->method( 'warning' );
		$logger->expects( $this->never() )->method( 'error' );

		$response = $this->newFetcher(
			[ 'code' => 404, 'headers' => [ 'content-type' => 'application/problem+json' ], 'body' => '{}' ],
			null,
			$logger
		)->fetch( self::URI, 5000, true );

		$this->assertFalse( $response->isSuccess() );
		$this->assertTrue( $response->isNotFound() );
	}

	public function testHardFailureLogsAnError(): void {
		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->once() )->method( 'error' );
		$logger->expects( $this->never() )->method( 'warning' );

		$response = $this->newFetcher(
			[ 'code' => 500, 'headers' => [], 'body' => '', 'error' => 'boom' ],
			null,
			$logger
		)->fetch( self::URI, 5000, true );

		$this->assertFalse( $response->isSuccess() );
		$this->assertFalse( $response->isNotFound() );
	}

	// -- Page coverage ------------------------------------------------------------------

	private function newCoverageFetcher( array $artifacts, bool $enabled = true ): LinkedArtifactsFetcher {
		return $this->newFetcher( null, null, null, $enabled, $artifacts );
	}

	public function testCoversPageRequiresTheFeatureToBeEnabled(): void {
		// A disabled wiki precomputes nothing, so nothing is worth requesting.
		$this->assertFalse(
			$this->newCoverageFetcher( self::DEFAULT_ARTIFACTS, false )
				->coversPage( 'my-artifact', 'enwiki', 42, 0 )
		);
		$this->assertTrue(
			$this->newCoverageFetcher( self::DEFAULT_ARTIFACTS, true )
				->coversPage( 'my-artifact', 'enwiki', 42, 0 )
		);
	}

	public function testCoversPageIsFalseForAnUnconfiguredArtifact(): void {
		// Artifacts are enabled per wiki, so a reader deployed fleet-wide will ask about
		// ones this wiki does not configure. That must answer "not covered" — the page
		// rules alone would say otherwise, since they default to every namespace and
		// every page.
		$this->assertFalse(
			$this->newCoverageFetcher( self::DEFAULT_ARTIFACTS )
				->coversPage( 'no-such-artifact', 'enwiki', 42, 0 )
		);
	}

	public function testCoversPageHonoursTheNamespaceAllowlist(): void {
		$fetcher = $this->newCoverageFetcher( [
			'ns-artifact' => [
				'entity_kind' => 'revision',
				'page' => [ 'namespaces' => [ 0, 6 ] ],
			],
			'all-ns-artifact' => [ 'entity_kind' => 'revision' ],
		] );

		$this->assertTrue( $fetcher->coversPage( 'ns-artifact', 'enwiki', 42, 0 ) );
		$this->assertTrue( $fetcher->coversPage( 'ns-artifact', 'enwiki', 42, 6 ) );
		$this->assertFalse( $fetcher->coversPage( 'ns-artifact', 'enwiki', 42, 1 ) );
		// No allowlist configured ⇒ all namespaces.
		$this->assertTrue( $fetcher->coversPage( 'all-ns-artifact', 'enwiki', 42, 1 ) );
	}

	public function testCoversPageHonoursSampleRateBounds(): void {
		$fetcher = $this->newCoverageFetcher( [
			'all' => [ 'entity_kind' => 'revision', 'page' => [ 'sample' => 1.0 ] ],
			'none' => [ 'entity_kind' => 'revision', 'page' => [ 'sample' => 0.0 ] ],
			'default' => [ 'entity_kind' => 'revision' ],
		] );

		// A rate of 1.0 must sample in every page and 0.0 must sample out every page,
		// including pages whose hash lands at the very top of the bucket range.
		foreach ( [ 1, 42, 12345, 999999 ] as $pageId ) {
			$this->assertTrue( $fetcher->coversPage( 'all', 'enwiki', $pageId, 0 ) );
			$this->assertTrue( $fetcher->coversPage( 'default', 'enwiki', $pageId, 0 ) );
			$this->assertFalse( $fetcher->coversPage( 'none', 'enwiki', $pageId, 0 ) );
		}
	}

	public function testCoversPageIsDeterministic(): void {
		$fetcher = $this->newCoverageFetcher( [
			'half' => [ 'entity_kind' => 'revision', 'page' => [ 'sample' => 0.5 ] ],
		] );

		// Same (wiki_id, page_id) ⇒ same decision on repeated calls, so that a precompute
		// path and a reading path asking independently always agree.
		$decision = $fetcher->coversPage( 'half', 'enwiki', 42, 0 );
		$this->assertSame( $decision, $fetcher->coversPage( 'half', 'enwiki', 42, 0 ) );
		$this->assertSame( $decision, $fetcher->coversPage( 'half', 'enwiki', 42, 0 ) );
	}

	public function testCoversPageApproximatesTheConfiguredSampleRate(): void {
		$fetcher = $this->newCoverageFetcher( [
			'tenth' => [ 'entity_kind' => 'revision', 'page' => [ 'sample' => 0.1 ] ],
		] );

		$sampledIn = 0;
		for ( $pageId = 1; $pageId <= 2000; $pageId++ ) {
			if ( $fetcher->coversPage( 'tenth', 'enwiki', $pageId, 0 ) ) {
				$sampledIn++;
			}
		}

		// ~10% of 2000; allow generous slack for hash distribution noise.
		$this->assertGreaterThan( 140, $sampledIn );
		$this->assertLessThan( 260, $sampledIn );
	}
}
