<?php

namespace MediaWiki\Extension\EventBus\Tests\Unit\LinkedArtifacts\Job;

use MediaWiki\Extension\EventBus\LinkedArtifacts\Job\LinkedArtifactPrecomputeJob;
use MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactResponse;
use MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactsClient;
use MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactsConfig;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\EventBus\LinkedArtifacts\Job\LinkedArtifactPrecomputeJob
 */
class LinkedArtifactPrecomputeJobTest extends MediaWikiUnitTestCase {

	private const PATH = '/revisions/v1/my-artifact/enwiki/42/99';

	private static function newConfig( bool $enabled = true ): LinkedArtifactsConfig {
		return new LinkedArtifactsConfig( [
			'enabled' => $enabled,
			'artifacts' => [
				'my-artifact' => [ 'entity_kind' => 'revision', 'precompute' => [ 'timeout_ms' => 30000 ] ],
			],
		] );
	}

	private static function jobParams( string $path = self::PATH ): array {
		return [
			LinkedArtifactPrecomputeJob::ARTIFACT_URI_PARAM => $path,
			LinkedArtifactPrecomputeJob::ARTIFACT_NAME_PARAM => 'my-artifact',
			// A framework-added top-level param, which must not affect the request.
			'requestId' => 'abc123',
		];
	}

	/**
	 * @param LinkedArtifactResponse $response What the client should return.
	 * @param array|null $params
	 */
	private function newJob(
		LinkedArtifactResponse $response,
		?array $params = null
	): LinkedArtifactPrecomputeJob {
		$client = $this->createMock( LinkedArtifactsClient::class );
		// The job force-refreshes the configured path at the artifact's own timeout
		// (30000ms), not the top-level default (5000ms).
		$client->expects( $this->once() )
			->method( 'fetch' )
			->with( self::PATH, 30000, LinkedArtifactsClient::CACHE_CONTROL_NO_CACHE )
			->willReturn( $response );

		return new LinkedArtifactPrecomputeJob(
			$params ?? self::jobParams(),
			$client,
			self::newConfig()
		);
	}

	public function testSuccessReportsDone(): void {
		$this->assertTrue(
			$this->newJob( new LinkedArtifactResponse( 200, 'application/json', '{}' ) )->run()
		);
	}

	public function testNotFoundReportsDoneWithoutRetrying(): void {
		// LAC has no artifact for this key; retrying the same path will not change that.
		$this->assertTrue(
			$this->newJob( new LinkedArtifactResponse( 404, 'application/problem+json' ) )->run()
		);
	}

	public function testHardFailureSetsLastErrorAndAsksForARetry(): void {
		$job = $this->newJob( new LinkedArtifactResponse( 500, 'text/plain' ) );

		$this->assertFalse( $job->run() );
		$this->assertStringContainsString( 'my-artifact', $job->getLastError() );
		$this->assertStringContainsString( '500', $job->getLastError() );
	}

	public function testAJobWithNoPathIsDroppedWithoutAnyRequest(): void {
		$client = $this->createMock( LinkedArtifactsClient::class );
		$client->expects( $this->never() )->method( 'fetch' );

		$job = new LinkedArtifactPrecomputeJob(
			[ LinkedArtifactPrecomputeJob::ARTIFACT_NAME_PARAM => 'my-artifact' ],
			$client,
			self::newConfig()
		);

		$this->assertTrue( $job->run() );
		$this->assertStringContainsString( 'no LAC path', $job->getLastError() );
	}

	public function testAnArtifactWithPrecomputeDisabledDropsTheJob(): void {
		// The point of the per-artifact switch: turning it off drains jobs already queued
		// for that artifact, which blanking its `events` cannot do.
		$client = $this->createMock( LinkedArtifactsClient::class );
		$client->expects( $this->never() )->method( 'fetch' );

		$config = new LinkedArtifactsConfig( [
			'enabled' => true,
			'artifacts' => [
				'my-artifact' => [ 'entity_kind' => 'revision', 'precompute' => [ 'enabled' => false ] ],
			],
		] );

		$this->assertTrue( ( new LinkedArtifactPrecomputeJob( self::jobParams(), $client, $config ) )->run() );
	}

	public function testADisabledWikiDropsTheJobWithoutAnyRequest(): void {
		// The feature can be switched off after a job is enqueued; queued jobs must not
		// keep precomputing artifacts readers are no longer allowed to ask for.
		$client = $this->createMock( LinkedArtifactsClient::class );
		$client->expects( $this->never() )->method( 'fetch' );

		$job = new LinkedArtifactPrecomputeJob( self::jobParams(), $client, self::newConfig( false ) );

		$this->assertTrue( $job->run() );
	}
}
