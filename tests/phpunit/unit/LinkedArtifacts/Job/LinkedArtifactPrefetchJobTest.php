<?php

namespace MediaWiki\Extension\EventBus\Tests\Unit\LinkedArtifacts\Job;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\EventBus\LinkedArtifacts\Job\LinkedArtifactPrefetchJob;
use MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactResponse;
use MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactsConfig;
use MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactsPrefetcher;
use MediaWiki\MainConfigNames;
use MediaWiki\Settings\SettingsBuilder;
use MediaWikiUnitTestCase;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @covers \MediaWiki\Extension\EventBus\LinkedArtifacts\Job\LinkedArtifactPrefetchJob
 */
class LinkedArtifactPrefetchJobTest extends MediaWikiUnitTestCase {

	private function newPrefetchJob(
		array $params,
		LinkedArtifactsPrefetcher $prefetcher,
		LinkedArtifactsConfig $config
	): LinkedArtifactPrefetchJob {
		return new LinkedArtifactPrefetchJob( $params, $prefetcher, $config );
	}

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
			LinkedArtifactPrefetchJob::ARTIFACT_NAME_PARAM => 'my-artifact',
			LinkedArtifactPrefetchJob::ENTITY_KIND_PARAM => 'revision',
			LinkedArtifactPrefetchJob::WIKI_ID_PARAM => 'enwiki',
			LinkedArtifactPrefetchJob::PAGE_ID_PARAM => 42,
			LinkedArtifactPrefetchJob::REVISION_ID_PARAM => 99,
			LinkedArtifactPrefetchJob::TRIGGER_PARAM => [
				'event_type' => 'PageLatestRevisionChanged',
				'cause' => 'edit',
				'is_reconciliation_request' => true,
			],
			// A framework-added top-level param, which must not affect the prefetch.
			'requestId' => 'abc123',
		];
	}

	private function newNeverCalledPrefetcher(): LinkedArtifactsPrefetcher {
		$prefetcher = $this->createMock( LinkedArtifactsPrefetcher::class );
		$prefetcher->expects( $this->never() )->method( 'prefetchRevisionArtifact' );
		return $prefetcher;
	}

	public function testNewRevisionJobSpec(): void {
		// E.g. a reader request that is not a DomainEvent sets its own trigger.
		$spec = LinkedArtifactPrefetchJob::newRevisionJobSpec(
			'my-artifact', 'enwiki', 42, 99, false, 'MyReaderRequest'
		);

		$this->assertSame( 'linkedArtifactPrefetch.my-artifact', $spec->getType() );
		$params = $spec->getParams();
		// Added by JobSpecification.
		unset( $params['requestId'] );
		$this->assertSame(
			[
				LinkedArtifactPrefetchJob::ARTIFACT_NAME_PARAM => 'my-artifact',
				LinkedArtifactPrefetchJob::ENTITY_KIND_PARAM => 'revision',
				LinkedArtifactPrefetchJob::WIKI_ID_PARAM => 'enwiki',
				LinkedArtifactPrefetchJob::PAGE_ID_PARAM => 42,
				LinkedArtifactPrefetchJob::REVISION_ID_PARAM => 99,
				// An unknown cause is omitted, not null.
				LinkedArtifactPrefetchJob::TRIGGER_PARAM => [
					'event_type' => 'MyReaderRequest',
					'is_reconciliation_request' => false,
				],
			],
			$params
		);
		$this->assertTrue( $spec->ignoreDuplicates() );
	}

	/**
	 * @dataProvider provideTimestamps
	 */
	public function testNewRevisionJobSpecStoresTheTriggerTimeInUtcWithMilliseconds(
		ConvertibleTimestamp $triggerTimestamp,
		string $expectedDt
	): void {
		$spec = LinkedArtifactPrefetchJob::newRevisionJobSpec(
			'my-artifact', 'enwiki', 42, 99, false, 'MyReaderRequest', null, $triggerTimestamp
		);

		$this->assertSame( $expectedDt, $spec->getParams()[LinkedArtifactPrefetchJob::TRIGGER_PARAM]['dt'] );
	}

	public static function provideTimestamps(): iterable {
		yield 'whole seconds' => [ new ConvertibleTimestamp( '20261021100000' ), '2026-10-21T10:00:00.000Z' ];
		yield 'microseconds' => [ new ConvertibleTimestamp( '1792576800.123456' ), '2026-10-21T10:00:00.123Z' ];
		yield 'not UTC' => [ new ConvertibleTimestamp( '2026-10-21T12:00:00.5+02:00' ), '2026-10-21T10:00:00.500Z' ];
	}

	/**
	 * @dataProvider provideTriggerDts
	 */
	public function testTheTriggerTimeIsPassedToThePrefetcher( mixed $triggerDt, ?string $expectedDt ): void {
		$params = self::jobParams();
		$params[LinkedArtifactPrefetchJob::TRIGGER_PARAM]['dt'] = $triggerDt;

		$prefetcher = $this->createMock( LinkedArtifactsPrefetcher::class );
		$prefetcher->expects( $this->once() )
			->method( 'prefetchRevisionArtifact' )
			->with(
				'my-artifact', 'enwiki', 42, 99, true, 'PageLatestRevisionChanged', 'edit',
				$this->callback( static fn ( ?ConvertibleTimestamp $timestamp ): bool =>
					$timestamp?->format( 'Y-m-d\TH:i:s.v\Z' ) === $expectedDt
				)
			)
			->willReturn( new LinkedArtifactResponse( 200, [ 'content-type' => 'application/json' ], '{}' ) );

		$this->assertTrue( $this->newPrefetchJob( $params, $prefetcher, self::newConfig() )->run() );
	}

	public static function provideTriggerDts(): iterable {
		yield 'with milliseconds' => [ '2026-10-21T10:00:00.123Z', '2026-10-21T10:00:00.123Z' ];
		// ConvertibleTimestamp makes the time now from an empty string. The job must not.
		yield 'empty' => [ '', null ];
		yield 'not a timestamp' => [ 'yesterday-ish', null ];
		yield 'not a string' => [ 42, null ];
	}

	public function testTheJobTypeIsTheArtifactJobType(): void {
		$this->assertSame(
			'linkedArtifactPrefetch.my-artifact',
			LinkedArtifactPrefetchJob::getJobType( 'my-artifact' )
		);
		$job = $this->newPrefetchJob(
			self::jobParams(), $this->newNeverCalledPrefetcher(), self::newConfig()
		);
		$this->assertSame( 'linkedArtifactPrefetch.my-artifact', $job->getType() );
	}

	/**
	 * @param LinkedArtifactResponse $response What the prefetch should return.
	 */
	private function newJob( LinkedArtifactResponse $response ): LinkedArtifactPrefetchJob {
		$prefetcher = $this->createMock( LinkedArtifactsPrefetcher::class );
		$prefetcher->expects( $this->once() )
			->method( 'prefetchRevisionArtifact' )
			// The job params have no trigger time.
			->with( 'my-artifact', 'enwiki', 42, 99, true, 'PageLatestRevisionChanged', 'edit', null )
			->willReturn( $response );

		return $this->newPrefetchJob( self::jobParams(), $prefetcher, self::newConfig() );
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
		unset( $params[LinkedArtifactPrefetchJob::TRIGGER_PARAM] );

		$prefetcher = $this->createMock( LinkedArtifactsPrefetcher::class );
		$prefetcher->expects( $this->once() )
			->method( 'prefetchRevisionArtifact' )
			->with( 'my-artifact', 'enwiki', 42, 99, false, null, null, null )
			->willReturn( new LinkedArtifactResponse( 200, [ 'content-type' => 'application/json' ], '{}' ) );

		$this->assertTrue(
			$this->newPrefetchJob( $params, $prefetcher, self::newConfig() )->run()
		);
	}

	public function testAnOldUriOnlyJobIsDroppedWithoutAnyRequest(): void {
		// Jobs enqueued before the params carried revision identifiers.
		$job = $this->newPrefetchJob(
			[
				'artifact_uri' => '/revisions/v1/my-artifact/enwiki/42/99',
				LinkedArtifactPrefetchJob::ARTIFACT_NAME_PARAM => 'my-artifact',
			],
			$this->newNeverCalledPrefetcher(),
			self::newConfig()
		);

		$this->assertTrue( $job->run() );
		$this->assertStringContainsString( 'unsupported entity_kind', $job->getLastError() );
	}

	public function testAnUnsupportedEntityKindIsDroppedWithoutAnyRequest(): void {
		$params = [ LinkedArtifactPrefetchJob::ENTITY_KIND_PARAM => 'user' ] + self::jobParams();

		$job = $this->newPrefetchJob( $params, $this->newNeverCalledPrefetcher(), self::newConfig() );

		$this->assertTrue( $job->run() );
		$this->assertStringContainsString( "unsupported entity_kind 'user'", $job->getLastError() );
	}

	public function testMissingRevisionIdentifiersAreDroppedWithoutAnyRequest(): void {
		$params = self::jobParams();
		unset( $params[LinkedArtifactPrefetchJob::REVISION_ID_PARAM] );

		$job = $this->newPrefetchJob( $params, $this->newNeverCalledPrefetcher(), self::newConfig() );

		$this->assertTrue( $job->run() );
		$this->assertStringContainsString( 'no revision identifiers', $job->getLastError() );
	}

	public function testAnArtifactWithPrefetchDisabledDropsTheJob(): void {
		// The point of the per-artifact switch: turning it off drains jobs already queued
		// for that artifact, which blanking its `events` cannot do.
		$config = new LinkedArtifactsConfig( [
			'enabled' => true,
			'artifacts' => [
				'my-artifact' => [ 'entity_kind' => 'revision', 'prefetch' => [ 'enabled' => false ] ],
			],
		] );

		$job = $this->newPrefetchJob( self::jobParams(), $this->newNeverCalledPrefetcher(), $config );

		$this->assertTrue( $job->run() );
	}

	public function testADisabledWikiDropsTheJobWithoutAnyRequest(): void {
		// The feature can be switched off after a job is enqueued; queued jobs must not
		// keep prefetching artifacts readers are no longer allowed to ask for.
		$job = $this->newPrefetchJob(
			self::jobParams(),
			$this->newNeverCalledPrefetcher(),
			self::newConfig( false )
		);

		$this->assertTrue( $job->run() );
	}

	/**
	 * @param mixed $linkedArtifacts EventBusLinkedArtifacts config.
	 * @return array|null The JobClasses that onRegistration() sets, or null if it sets none.
	 */
	private function runOnRegistration( $linkedArtifacts ): ?array {
		$jobClasses = null;
		$settings = $this->createMock( SettingsBuilder::class );
		$settings->method( 'getConfig' )->willReturn( new HashConfig( [
			'EventBusLinkedArtifacts' => $linkedArtifacts,
			MainConfigNames::JobClasses => [
				'otherJob' => 'OtherJob',
				self::siteJobType() => 'SiteDefinedJob',
			],
		] ) );
		$settings->method( 'overrideConfigValue' )
			->willReturnCallback( static function ( string $key, $value ) use ( &$jobClasses, $settings ) {
				$jobClasses = $value;
				return $settings;
			} );
		LinkedArtifactPrefetchJob::onRegistration( [], $settings );
		return $jobClasses;
	}

	private static function siteJobType(): string {
		return LinkedArtifactPrefetchJob::getJobType( 'disabled' );
	}

	public function testOnRegistrationRegistersAJobTypeForEachArtifact(): void {
		$jobClasses = $this->runOnRegistration( [
			'artifacts' => [
				'with_events' => [ 'entity_kind' => 'revision', 'prefetch' => [ 'events' => [ 'E' ] ] ],
				// Jobs that are queued before prefetch is disabled must still run.
				'disabled' => [ 'entity_kind' => 'revision', 'prefetch' => [ 'enabled' => false ] ],
				'read_only' => [ 'entity_kind' => 'revision' ],
				// The registration callback must not throw on malformed config.
				'malformed' => 'not an array',
			],
		] );

		$this->assertSame(
			[
				'otherJob' => 'OtherJob',
				// A job type that the site configures explicitly wins.
				self::siteJobType() => 'SiteDefinedJob',
				LinkedArtifactPrefetchJob::getJobType( 'with_events' ) => LinkedArtifactPrefetchJob::JOB_SPEC,
				LinkedArtifactPrefetchJob::getJobType( 'read_only' ) => LinkedArtifactPrefetchJob::JOB_SPEC,
				LinkedArtifactPrefetchJob::getJobType( 'malformed' ) => LinkedArtifactPrefetchJob::JOB_SPEC,
			],
			$jobClasses
		);
	}

	/**
	 * @dataProvider provideConfigWithoutArtifacts
	 */
	public function testOnRegistrationWithoutArtifactsSetsNothing( $linkedArtifacts ): void {
		$this->assertNull( $this->runOnRegistration( $linkedArtifacts ) );
	}

	public static function provideConfigWithoutArtifacts(): iterable {
		yield 'no artifacts key' => [ [] ];
		yield 'empty artifacts' => [ [ 'artifacts' => [] ] ];
		yield 'artifacts is not an array' => [ [ 'artifacts' => 'bad' ] ];
		yield 'config is not an array' => [ 'bad' ];
	}
}
