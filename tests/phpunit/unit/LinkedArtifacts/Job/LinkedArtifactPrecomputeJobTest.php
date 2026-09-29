<?php

namespace MediaWiki\Extension\EventBus\Tests\Unit\LinkedArtifacts\Job;

use MediaWiki\Extension\EventBus\LinkedArtifacts\Job\LinkedArtifactPrecomputeJob;
use MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactResponse;
use MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactsConfig;
use MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactsPrecompute;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\EventBus\LinkedArtifacts\Job\LinkedArtifactPrecomputeJob
 */
class LinkedArtifactPrecomputeJobTest extends MediaWikiUnitTestCase {

	private static function newConfig( bool $enabled = true ): LinkedArtifactsConfig {
		return new LinkedArtifactsConfig( [
			'enabled' => $enabled,
			'artifacts' => [
				'my-artifact' => [ 'entity_kind' => 'revision' ],
			],
		] );
	}

	private static function jobParams(): array {
		return [
			LinkedArtifactPrecomputeJob::ARTIFACT_NAME_PARAM => 'my-artifact',
			LinkedArtifactPrecomputeJob::ENTITY_KIND_PARAM => 'revision',
			LinkedArtifactPrecomputeJob::WIKI_ID_PARAM => 'enwiki',
			LinkedArtifactPrecomputeJob::PAGE_ID_PARAM => 42,
			LinkedArtifactPrecomputeJob::REVISION_ID_PARAM => 99,
			LinkedArtifactPrecomputeJob::TRIGGER_PARAM => [
				'event_type' => 'PageLatestRevisionChanged',
				'cause' => 'edit',
				'is_reconciliation_request' => true,
			],
			// A framework-added top-level param, which must not affect the precompute.
			'requestId' => 'abc123',
		];
	}

	private function newNeverCalledPrecompute(): LinkedArtifactsPrecompute {
		$precompute = $this->createMock( LinkedArtifactsPrecompute::class );
		$precompute->expects( $this->never() )->method( 'precomputeRevisionArtifact' );
		return $precompute;
	}

	/**
	 * @param LinkedArtifactResponse $response What the precompute should return.
	 */
	private function newJob( LinkedArtifactResponse $response ): LinkedArtifactPrecomputeJob {
		$precompute = $this->createMock( LinkedArtifactsPrecompute::class );
		$precompute->expects( $this->once() )
			->method( 'precomputeRevisionArtifact' )
			->with( 'my-artifact', 'enwiki', 42, 99, 'PageLatestRevisionChanged', 'edit', true )
			->willReturn( $response );

		return new LinkedArtifactPrecomputeJob( self::jobParams(), $precompute, self::newConfig() );
	}

	public function testSuccessReportsDone(): void {
		$this->assertTrue(
			$this->newJob( new LinkedArtifactResponse( 200, [ 'content-type' => 'application/json' ], '{}' ) )->run()
		);
	}

	public function testNotFoundReportsDoneWithoutRetrying(): void {
		// LAC has no artifact for this key; retrying the same key will not change that.
		$this->assertTrue(
			$this->newJob( new LinkedArtifactResponse( 404, [ 'content-type' => 'application/problem+json' ] ) )->run()
		);
	}

	public function testHardFailureSetsLastErrorAndAsksForARetry(): void {
		$job = $this->newJob( new LinkedArtifactResponse( 500, [ 'content-type' => 'text/plain' ] ) );

		$this->assertFalse( $job->run() );
		$this->assertStringContainsString( 'my-artifact', $job->getLastError() );
		$this->assertStringContainsString( '500', $job->getLastError() );
	}

	public function testAJobWithoutATriggerPassesNoTrigger(): void {
		$params = self::jobParams();
		unset( $params[LinkedArtifactPrecomputeJob::TRIGGER_PARAM] );

		$precompute = $this->createMock( LinkedArtifactsPrecompute::class );
		$precompute->expects( $this->once() )
			->method( 'precomputeRevisionArtifact' )
			->with( 'my-artifact', 'enwiki', 42, 99, null, null, false )
			->willReturn( new LinkedArtifactResponse( 200, [ 'content-type' => 'application/json' ], '{}' ) );

		$this->assertTrue(
			( new LinkedArtifactPrecomputeJob( $params, $precompute, self::newConfig() ) )->run()
		);
	}

	public function testAnOldUriOnlyJobIsDroppedWithoutAnyRequest(): void {
		// Jobs enqueued before the params carried revision identifiers.
		$job = new LinkedArtifactPrecomputeJob(
			[
				'artifact_uri' => '/revisions/v1/my-artifact/enwiki/42/99',
				LinkedArtifactPrecomputeJob::ARTIFACT_NAME_PARAM => 'my-artifact',
			],
			$this->newNeverCalledPrecompute(),
			self::newConfig()
		);

		$this->assertTrue( $job->run() );
		$this->assertStringContainsString( 'unsupported entity_kind', $job->getLastError() );
	}

	public function testAnUnsupportedEntityKindIsDroppedWithoutAnyRequest(): void {
		$params = [ LinkedArtifactPrecomputeJob::ENTITY_KIND_PARAM => 'user' ] + self::jobParams();

		$job = new LinkedArtifactPrecomputeJob( $params, $this->newNeverCalledPrecompute(), self::newConfig() );

		$this->assertTrue( $job->run() );
		$this->assertStringContainsString( "unsupported entity_kind 'user'", $job->getLastError() );
	}

	public function testMissingRevisionIdentifiersAreDroppedWithoutAnyRequest(): void {
		$params = self::jobParams();
		unset( $params[LinkedArtifactPrecomputeJob::REVISION_ID_PARAM] );

		$job = new LinkedArtifactPrecomputeJob( $params, $this->newNeverCalledPrecompute(), self::newConfig() );

		$this->assertTrue( $job->run() );
		$this->assertStringContainsString( 'no revision identifiers', $job->getLastError() );
	}

	public function testAnArtifactWithPrecomputeDisabledDropsTheJob(): void {
		// The point of the per-artifact switch: turning it off drains jobs already queued
		// for that artifact, which blanking its `events` cannot do.
		$config = new LinkedArtifactsConfig( [
			'enabled' => true,
			'artifacts' => [
				'my-artifact' => [ 'entity_kind' => 'revision', 'precompute' => [ 'enabled' => false ] ],
			],
		] );

		$job = new LinkedArtifactPrecomputeJob( self::jobParams(), $this->newNeverCalledPrecompute(), $config );

		$this->assertTrue( $job->run() );
	}

	public function testADisabledWikiDropsTheJobWithoutAnyRequest(): void {
		// The feature can be switched off after a job is enqueued; queued jobs must not
		// keep precomputing artifacts readers are no longer allowed to ask for.
		$job = new LinkedArtifactPrecomputeJob(
			self::jobParams(),
			$this->newNeverCalledPrecompute(),
			self::newConfig( false )
		);

		$this->assertTrue( $job->run() );
	}
}
