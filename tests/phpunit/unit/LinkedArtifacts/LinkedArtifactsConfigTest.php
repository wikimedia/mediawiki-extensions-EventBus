<?php

namespace MediaWiki\Extension\EventBus\Tests\Unit\LinkedArtifacts;

use InvalidArgumentException;
use MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactsConfig;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactsConfig
 */
class LinkedArtifactsConfigTest extends MediaWikiUnitTestCase {

	private static function newConfig( ?array $artifacts = null ): LinkedArtifactsConfig {
		return new LinkedArtifactsConfig( [
			'enabled' => true,
			'base_url' => 'https://lac.example/base',
			'artifacts' => $artifacts ?? [
				'my-artifact' => [
					'entity_kind' => 'revision',
					'precompute' => [ 'events' => [ 'PageLatestRevisionChanged', 'SomeOtherEvent' ] ],
				],
				'other-artifact' => [
					'entity_kind' => 'revision',
					'precompute' => [ 'events' => [ 'SomeOtherEvent' ] ],
				],
			],
		] );
	}

	public function testConnectionGettersReflectConfig(): void {
		$config = self::newConfig();

		$this->assertTrue( $config->isEnabled() );
		$this->assertSame( 'https://lac.example/base', $config->getBaseUrl() );
		$this->assertSame(
			LinkedArtifactsConfig::PRECOMPUTE_TIMEOUT_MS_DEFAULT,
			$config->getPrecomputeTimeoutMs( 'my-artifact' )
		);
	}

	public function testGetPrecomputeTimeoutFallsBackToTheCodeDefault(): void {
		$config = self::newConfig();

		// There is no config-level default: an artifact that sets no timeout, and a name
		// that is not configured at all, both fall back to the class constant.
		$this->assertSame(
			LinkedArtifactsConfig::PRECOMPUTE_TIMEOUT_MS_DEFAULT,
			$config->getPrecomputeTimeoutMs( 'my-artifact' )
		);
		$this->assertSame(
			LinkedArtifactsConfig::PRECOMPUTE_TIMEOUT_MS_DEFAULT,
			$config->getPrecomputeTimeoutMs( 'unknown-artifact' )
		);
	}

	public function testGetPrecomputeTimeoutHonoursPerArtifactOverride(): void {
		// Artifacts differ in how long their lambda takes, so each may override.
		$config = self::newConfig( [
			'slow-artifact' => [
				'entity_kind' => 'revision',
				'precompute' => [ 'events' => [ 'SomeOtherEvent' ], 'timeout_ms' => 30000 ],
			],
			'default-artifact' => [
				'entity_kind' => 'revision',
				'precompute' => [ 'events' => [ 'SomeOtherEvent' ] ],
			],
		] );

		$this->assertSame( 30000, $config->getPrecomputeTimeoutMs( 'slow-artifact' ) );
		$this->assertSame(
			LinkedArtifactsConfig::PRECOMPUTE_TIMEOUT_MS_DEFAULT,
			$config->getPrecomputeTimeoutMs( 'default-artifact' )
		);
	}

	public function testGetArtifactsToPrecomputeForEventReturnsSubscribedArtifacts(): void {
		$config = self::newConfig();

		$this->assertSame(
			[ 'my-artifact' ],
			$config->getArtifactsToPrecomputeForEvent( 'PageLatestRevisionChanged' )
		);
		$this->assertSame(
			[ 'my-artifact', 'other-artifact' ],
			$config->getArtifactsToPrecomputeForEvent( 'SomeOtherEvent' )
		);
	}

	public function testGetArtifactsToPrecomputeForEventReturnsEmptyArrayForUnknownEvent(): void {
		$config = self::newConfig();

		$this->assertSame( [], $config->getArtifactsToPrecomputeForEvent( 'PageDeleted' ) );
	}

	public function testMissingKeysDefaultSafely(): void {
		$config = new LinkedArtifactsConfig( [] );

		$this->assertFalse( $config->isEnabled() );
		$this->assertSame( '', $config->getBaseUrl() );
		$this->assertSame(
			LinkedArtifactsConfig::PRECOMPUTE_TIMEOUT_MS_DEFAULT,
			$config->getPrecomputeTimeoutMs( 'unknown-artifact' )
		);
		$this->assertSame( [], $config->getArtifactsToPrecomputeForEvent( 'UnknownEvent' ) );
	}

	public function testAppliesToPageIsFalseForAnUnconfiguredArtifact(): void {
		// Readers ask this about artifacts that may not be enabled on this wiki, so an
		// unknown name must answer "not covered" rather than falling through the
		// namespace and sample defaults to "covered".
		$this->assertFalse(
			self::newConfig()->appliesToPage( 'no-such-artifact', 'enwiki', 42, 0 )
		);
	}

	/**
	 * @dataProvider provideUnusableTimeouts
	 */
	public function testAnUnusableTimeoutThrows( $timeoutMs ): void {
		// 0 reaches curl as CURLOPT_TIMEOUT_MS 0, which means no timeout at all, so a
		// hung LAC would hold a job runner indefinitely.
		$this->expectException( InvalidArgumentException::class );

		self::newConfig( [
			'bad-artifact' => [ 'entity_kind' => 'revision', 'precompute' => [ 'timeout_ms' => $timeoutMs ] ],
		] );
	}

	public static function provideUnusableTimeouts(): array {
		return [
			'zero' => [ 0 ],
			'negative' => [ -1 ],
			'not an integer' => [ '5000' ],
		];
	}

	public function testAnUnusablePerArtifactTimeoutThrows(): void {
		$this->expectException( InvalidArgumentException::class );

		new LinkedArtifactsConfig( [
			'artifacts' => [
				'bad-artifact' => [
					'entity_kind' => 'revision',
					'precompute' => [ 'timeout_ms' => 0 ],
				],
			],
		] );
	}

	public function testGetArtifactsToPrecomputeForEventExcludesDisabledArtifacts(): void {
		// The ingress relies on this filtering rather than checking each artifact itself.
		$config = self::newConfig( [
			'on' => [
				'entity_kind' => 'revision',
				'precompute' => [ 'events' => [ 'SomeOtherEvent' ] ],
			],
			'off' => [
				'entity_kind' => 'revision',
				'precompute' => [ 'enabled' => false, 'events' => [ 'SomeOtherEvent' ] ],
			],
		] );

		$this->assertSame( [ 'on' ], $config->getArtifactsToPrecomputeForEvent( 'SomeOtherEvent' ) );
	}

	public function testIsPrecomputeEnabledDefaultsToTrue(): void {
		$this->assertTrue( self::newConfig()->isPrecomputeEnabled( 'my-artifact' ) );
	}

	public function testIsPrecomputeEnabledHonoursTheArtifactSwitch(): void {
		$config = self::newConfig( [
			'off' => [ 'entity_kind' => 'revision', 'precompute' => [ 'enabled' => false ] ],
			'on' => [ 'entity_kind' => 'revision', 'precompute' => [ 'enabled' => true ] ],
		] );

		$this->assertFalse( $config->isPrecomputeEnabled( 'off' ) );
		$this->assertTrue( $config->isPrecomputeEnabled( 'on' ) );
	}

	public function testIsPrecomputeEnabledIsFalseForAnUnconfiguredArtifact(): void {
		// A queued job can outlive its artifact's config; draining it is the right answer,
		// so this reports false rather than throwing the way getEntityKind() does.
		$this->assertFalse( self::newConfig()->isPrecomputeEnabled( 'no-such-artifact' ) );
	}

	public function testANonBooleanPrecomputeEnabledThrows(): void {
		$this->expectException( InvalidArgumentException::class );

		self::newConfig( [
			'bad-artifact' => [ 'entity_kind' => 'revision', 'precompute' => [ 'enabled' => 'yes' ] ],
		] );
	}

	public function testGetEntityKindThrowsForAnUnconfiguredArtifact(): void {
		// Not a missing setting — `entity_kind` is required — but a caller asking about an
		// artifact that does not exist.
		$this->expectException( InvalidArgumentException::class );

		self::newConfig()->getEntityKind( 'no-such-artifact' );
	}

	public function testGetEntityKindReturnsConfiguredEntityKind(): void {
		$config = self::newConfig( [
			'user-artifact' => [ 'entity_kind' => 'user', 'precompute' => [ 'events' => [ 'SomeOtherEvent' ] ] ],
		] );

		$this->assertSame( 'user', $config->getEntityKind( 'user-artifact' ) );
	}

	/**
	 * @dataProvider provideOutOfRangeSampleRates
	 */
	public function testOutOfRangeSampleRateThrows( float $rate ): void {
		$this->expectException( InvalidArgumentException::class );

		new LinkedArtifactsConfig( [
			'artifacts' => [
				'bad-artifact' => [
					'entity_kind' => 'revision',
					'page' => [ 'sample' => $rate ],
				],
			],
		] );
	}

	public static function provideOutOfRangeSampleRates(): array {
		return [
			'negative' => [ -0.1 ],
			'above one' => [ 1.5 ],
		];
	}

	/**
	 * @dataProvider provideArtifactsMissingAnEntityKind
	 */
	public function testAnArtifactWithoutAnEntityKindThrows( array $artifactConfig ): void {
		// Required: there is no default, and an artifact whose entity kind no listener handles
		// is silently never precomputed, so a missing one must fail loudly at construction.
		$this->expectException( InvalidArgumentException::class );

		new LinkedArtifactsConfig( [
			'artifacts' => [ 'bad-artifact' => $artifactConfig ],
		] );
	}

	public static function provideArtifactsMissingAnEntityKind(): array {
		return [
			'absent' => [ [] ],
			'empty' => [ [ 'entity_kind' => '' ] ],
			'not a string' => [ [ 'entity_kind' => 7 ] ],
		];
	}
}
