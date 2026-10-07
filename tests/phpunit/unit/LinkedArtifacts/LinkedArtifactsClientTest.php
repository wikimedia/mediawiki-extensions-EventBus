<?php

namespace MediaWiki\Extension\EventBus\Tests\Unit\LinkedArtifacts;

use MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactsClient;
use MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactsConfig;
use MediaWikiUnitTestCase;
use PHPUnit\Framework\Assert;
use Psr\Log\LoggerInterface;
use Wikimedia\Assert\ParameterAssertionException;
use Wikimedia\Http\MultiHttpClient;

/**
 * @covers \MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactsClient
 */
class LinkedArtifactsClientTest extends MediaWikiUnitTestCase {

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
	private function newClient(
		?array $httpResponse = null,
		?callable $assertRequest = null,
		?LoggerInterface $logger = null,
		bool $enabled = true,
		?array $artifacts = null,
		string $baseUrl = 'https://lac.example/base'
	): LinkedArtifactsClient {
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

		return new LinkedArtifactsClient(
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
			$this->newClient()->getRevisionArtifactUri( 'my-artifact', 'enwiki', 42, 99 )
		);
	}

	public function testRevisionUriEncodesNameAndWikiId(): void {
		$this->assertSame(
			'/revisions/v1/my%20artifact/en%20wiki/42/99',
			$this->newClient()->getRevisionArtifactUri( 'my artifact', 'en wiki', 42, 99 )
		);
	}

	public function testFetchRevisionArtifactReadsWithoutForcingARefresh(): void {
		// A reader must not make LAC re-run the lambda; only a precompute does that.
		$captured = null;
		$client = $this->newClient(
			[ 'code' => 200, 'headers' => [ 'content-type' => 'application/json' ], 'body' => '{}' ],
			static function ( $requests, $options ) use ( &$captured ) {
				$captured = [ 'requests' => $requests, 'options' => $options ];
			}
		);

		$response = $client->fetchRevisionArtifact( 'my-artifact', 'enwiki', 42, 99, 3000 );

		$this->assertSame( '{}', $response->getBody() );
		Assert::assertSame( 'https://lac.example/base' . self::URI, $captured['requests'][0]['url'] );
		Assert::assertSame( [], $captured['requests'][0]['headers'] );
		Assert::assertSame( 3.0, $captured['options']['reqTimeout'] );
	}

	// -- Transport ----------------------------------------------------------------------

	public function testCacheControlSendsTheHeaderAndPassesTheTimeoutThrough(): void {
		$captured = null;
		$client = $this->newClient(
			null,
			static function ( $requests, $options ) use ( &$captured ) {
				$captured = [ 'requests' => $requests, 'options' => $options ];
			}
		);

		$client->fetch( self::URI, 9000, LinkedArtifactsClient::CACHE_CONTROL_NO_CACHE );

		Assert::assertSame( 'https://lac.example/base' . self::URI, $captured['requests'][0]['url'] );
		Assert::assertSame( 'GET', $captured['requests'][0]['method'] );
		Assert::assertSame( [ 'Cache-Control' => 'no-cache' ], $captured['requests'][0]['headers'] );
		// MultiHttpClient takes seconds, so 9000ms must arrive as 9.
		Assert::assertSame( 9.0, $captured['options']['reqTimeout'] );
	}

	public function testOnlyIfCachedSendsTheHeader(): void {
		$captured = null;
		$client = $this->newClient(
			null,
			static function ( $requests ) use ( &$captured ) {
				$captured = $requests;
			}
		);

		$client->fetch( self::URI, 5000, LinkedArtifactsClient::CACHE_CONTROL_ONLY_IF_CACHED );

		Assert::assertSame( [ 'Cache-Control' => 'only-if-cached' ], $captured[0]['headers'] );
	}

	public function testAnUnsupportedCacheControlThrows(): void {
		$this->expectException( ParameterAssertionException::class );

		$this->newClient()->fetch( self::URI, 5000, 'max-age=0' );
	}

	public function testWithoutCacheControlOmitsTheHeader(): void {
		$captured = null;
		$client = $this->newClient(
			null,
			static function ( $requests ) use ( &$captured ) {
				$captured = $requests;
			}
		);

		$client->fetch( self::URI, 5000 );

		Assert::assertSame( [], $captured[0]['headers'] );
	}

	public function testTrailingSlashOnBaseUrlIsNotDoubled(): void {
		$captured = null;
		$client = $this->newClient(
			null,
			static function ( $requests ) use ( &$captured ) {
				$captured = $requests;
			},
			null,
			true,
			null,
			'https://lac.example/base/'
		);

		$client->fetch( self::URI, 5000 );

		Assert::assertSame( 'https://lac.example/base' . self::URI, $captured[0]['url'] );
	}

	public function testSuccessReturnsStatusContentTypeAndBody(): void {
		$client = $this->newClient( [
			'code' => 200,
			'headers' => [ 'content-type' => 'application/json' ],
			'body' => '{"ok":true}',
		] );

		$response = $client->fetch( self::URI, 5000, LinkedArtifactsClient::CACHE_CONTROL_NO_CACHE );

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

		$response = $this->newClient(
			[ 'code' => 404, 'headers' => [ 'content-type' => 'application/problem+json' ], 'body' => '{}' ],
			null,
			$logger
		)->fetch( self::URI, 5000, LinkedArtifactsClient::CACHE_CONTROL_NO_CACHE );

		$this->assertFalse( $response->isSuccess() );
		$this->assertTrue( $response->isNotFound() );
	}

	public function testNotCachedWithoutOnlyIfCachedLogsAnError(): void {
		// LAC reports a miss as a 504 only for only-if-cached. Any other 504 is a failure.
		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->once() )->method( 'error' );

		$this->newClient(
			[ 'code' => 504, 'headers' => [ 'content-type' => 'application/problem+json' ], 'body' => '{}' ],
			null,
			$logger
		)->fetch( self::URI, 5000, LinkedArtifactsClient::CACHE_CONTROL_NO_CACHE );
	}

	public function testHardFailureLogsAnError(): void {
		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->once() )->method( 'error' );
		$logger->expects( $this->never() )->method( 'warning' );

		$response = $this->newClient(
			[ 'code' => 500, 'headers' => [], 'body' => '', 'error' => 'boom' ],
			null,
			$logger
		)->fetch( self::URI, 5000, LinkedArtifactsClient::CACHE_CONTROL_NO_CACHE );

		$this->assertFalse( $response->isSuccess() );
		$this->assertFalse( $response->isNotFound() );
	}

	// -- Page coverage ------------------------------------------------------------------

	private function newCoverageClient( array $artifacts, bool $enabled = true ): LinkedArtifactsClient {
		return $this->newClient( null, null, null, $enabled, $artifacts );
	}

	public function testCoversPageRequiresTheFeatureToBeEnabled(): void {
		// A disabled wiki precomputes nothing, so nothing is worth requesting.
		$this->assertFalse(
			$this->newCoverageClient( self::DEFAULT_ARTIFACTS, false )
				->coversPage( 'my-artifact', 'enwiki', 42, 0 )
		);
		$this->assertTrue(
			$this->newCoverageClient( self::DEFAULT_ARTIFACTS, true )
				->coversPage( 'my-artifact', 'enwiki', 42, 0 )
		);
	}

	public function testCoversPageIsFalseForAnUnconfiguredArtifact(): void {
		// Artifacts are enabled per wiki, so a reader deployed fleet-wide will ask about
		// ones this wiki does not configure. That must answer "not covered" — the page
		// rules alone would say otherwise, since they default to every namespace and
		// every page.
		$this->assertFalse(
			$this->newCoverageClient( self::DEFAULT_ARTIFACTS )
				->coversPage( 'no-such-artifact', 'enwiki', 42, 0 )
		);
	}

	public function testCoversPageHonoursTheNamespaceAllowlist(): void {
		$client = $this->newCoverageClient( [
			'ns-artifact' => [
				'entity_kind' => 'revision',
				'page' => [ 'namespaces' => [ 0, 6 ] ],
			],
			'all-ns-artifact' => [ 'entity_kind' => 'revision' ],
		] );

		$this->assertTrue( $client->coversPage( 'ns-artifact', 'enwiki', 42, 0 ) );
		$this->assertTrue( $client->coversPage( 'ns-artifact', 'enwiki', 42, 6 ) );
		$this->assertFalse( $client->coversPage( 'ns-artifact', 'enwiki', 42, 1 ) );
		// No allowlist configured ⇒ all namespaces.
		$this->assertTrue( $client->coversPage( 'all-ns-artifact', 'enwiki', 42, 1 ) );
	}

	public function testCoversPageHonoursSampleRateBounds(): void {
		$client = $this->newCoverageClient( [
			'all' => [ 'entity_kind' => 'revision', 'page' => [ 'sample' => 1.0 ] ],
			'none' => [ 'entity_kind' => 'revision', 'page' => [ 'sample' => 0.0 ] ],
			'default' => [ 'entity_kind' => 'revision' ],
		] );

		// A rate of 1.0 must sample in every page and 0.0 must sample out every page,
		// including pages whose hash lands at the very top of the bucket range.
		foreach ( [ 1, 42, 12345, 999999 ] as $pageId ) {
			$this->assertTrue( $client->coversPage( 'all', 'enwiki', $pageId, 0 ) );
			$this->assertTrue( $client->coversPage( 'default', 'enwiki', $pageId, 0 ) );
			$this->assertFalse( $client->coversPage( 'none', 'enwiki', $pageId, 0 ) );
		}
	}

	public function testCoversPageIsDeterministic(): void {
		$client = $this->newCoverageClient( [
			'half' => [ 'entity_kind' => 'revision', 'page' => [ 'sample' => 0.5 ] ],
		] );

		// Same (wiki_id, page_id) ⇒ same decision on repeated calls, so that a precompute
		// path and a reading path asking independently always agree.
		$decision = $client->coversPage( 'half', 'enwiki', 42, 0 );
		$this->assertSame( $decision, $client->coversPage( 'half', 'enwiki', 42, 0 ) );
		$this->assertSame( $decision, $client->coversPage( 'half', 'enwiki', 42, 0 ) );
	}

	public function testCoversPageApproximatesTheConfiguredSampleRate(): void {
		$client = $this->newCoverageClient( [
			'tenth' => [ 'entity_kind' => 'revision', 'page' => [ 'sample' => 0.1 ] ],
		] );

		$sampledIn = 0;
		for ( $pageId = 1; $pageId <= 2000; $pageId++ ) {
			if ( $client->coversPage( 'tenth', 'enwiki', $pageId, 0 ) ) {
				$sampledIn++;
			}
		}

		// ~10% of 2000; allow generous slack for hash distribution noise.
		$this->assertGreaterThan( 140, $sampledIn );
		$this->assertLessThan( 260, $sampledIn );
	}
}
