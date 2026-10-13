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
					'prefetch' => [ 'events' => [ 'PageLatestRevisionChanged', 'SomeOtherEvent' ] ],
				],
				'other-artifact' => [
					'entity_kind' => 'revision',
					'prefetch' => [ 'events' => [ 'SomeOtherEvent' ] ],
				],
			],
		] );
	}

	public function testConnectionGettersReflectConfig(): void {
		$config = self::newConfig();

		$this->assertTrue( $config->isEnabled() );
		$this->assertSame( 'https://lac.example/base', $config->getBaseUrl() );
		$this->assertSame(
			LinkedArtifactsConfig::PREFETCH_TIMEOUT_MS_DEFAULT,
			$config->getPrefetchTimeoutMs( 'my-artifact' )
		);
	}

	public function testGetPrefetchTimeoutFallsBackToTheCodeDefault(): void {
		$config = self::newConfig();

		// There is no config-level default: an artifact that sets no timeout, and a name
		// that is not configured at all, both fall back to the class constant.
		$this->assertSame(
			LinkedArtifactsConfig::PREFETCH_TIMEOUT_MS_DEFAULT,
			$config->getPrefetchTimeoutMs( 'my-artifact' )
		);
		$this->assertSame(
			LinkedArtifactsConfig::PREFETCH_TIMEOUT_MS_DEFAULT,
			$config->getPrefetchTimeoutMs( 'unknown-artifact' )
		);
	}

	public function testGetPrefetchTimeoutHonoursPerArtifactOverride(): void {
		// Artifacts differ in how long their lambda takes, so each may override.
		$config = self::newConfig( [
			'slow-artifact' => [
				'entity_kind' => 'revision',
				'prefetch' => [ 'events' => [ 'SomeOtherEvent' ], 'timeout_ms' => 30000 ],
			],
			'default-artifact' => [
				'entity_kind' => 'revision',
				'prefetch' => [ 'events' => [ 'SomeOtherEvent' ] ],
			],
		] );

		$this->assertSame( 30000, $config->getPrefetchTimeoutMs( 'slow-artifact' ) );
		$this->assertSame(
			LinkedArtifactsConfig::PREFETCH_TIMEOUT_MS_DEFAULT,
			$config->getPrefetchTimeoutMs( 'default-artifact' )
		);
	}

	public function testGetArtifactsToPrefetchForEventReturnsSubscribedArtifacts(): void {
		$config = self::newConfig();

		$this->assertSame(
			[ 'my-artifact' ],
			$config->getArtifactsToPrefetchForEvent( 'PageLatestRevisionChanged' )
		);
		$this->assertSame(
			[ 'my-artifact', 'other-artifact' ],
			$config->getArtifactsToPrefetchForEvent( 'SomeOtherEvent' )
		);
	}

	public function testGetArtifactsToPrefetchForEventReturnsEmptyArrayForUnknownEvent(): void {
		$config = self::newConfig();

		$this->assertSame( [], $config->getArtifactsToPrefetchForEvent( 'PageDeleted' ) );
	}

	public function testMissingKeysDefaultSafely(): void {
		$config = new LinkedArtifactsConfig( [] );

		$this->assertFalse( $config->isEnabled() );
		$this->assertSame( '', $config->getBaseUrl() );
		$this->assertSame(
			LinkedArtifactsConfig::PREFETCH_TIMEOUT_MS_DEFAULT,
			$config->getPrefetchTimeoutMs( 'unknown-artifact' )
		);
		$this->assertSame( [], $config->getArtifactsToPrefetchForEvent( 'UnknownEvent' ) );
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
			'bad-artifact' => [ 'entity_kind' => 'revision', 'prefetch' => [ 'timeout_ms' => $timeoutMs ] ],
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
					'prefetch' => [ 'timeout_ms' => 0 ],
				],
			],
		] );
	}

	public function testGetArtifactsToPrefetchForEventExcludesDisabledArtifacts(): void {
		// The ingress relies on this filtering rather than checking each artifact itself.
		$config = self::newConfig( [
			'on' => [
				'entity_kind' => 'revision',
				'prefetch' => [ 'events' => [ 'SomeOtherEvent' ] ],
			],
			'off' => [
				'entity_kind' => 'revision',
				'prefetch' => [ 'enabled' => false, 'events' => [ 'SomeOtherEvent' ] ],
			],
		] );

		$this->assertSame( [ 'on' ], $config->getArtifactsToPrefetchForEvent( 'SomeOtherEvent' ) );
	}

	public function testIsPrefetchEnabledDefaultsToTrue(): void {
		$this->assertTrue( self::newConfig()->isPrefetchEnabled( 'my-artifact' ) );
	}

	public function testIsPrefetchEnabledHonoursTheArtifactSwitch(): void {
		$config = self::newConfig( [
			'off' => [ 'entity_kind' => 'revision', 'prefetch' => [ 'enabled' => false ] ],
			'on' => [ 'entity_kind' => 'revision', 'prefetch' => [ 'enabled' => true ] ],
		] );

		$this->assertFalse( $config->isPrefetchEnabled( 'off' ) );
		$this->assertTrue( $config->isPrefetchEnabled( 'on' ) );
	}

	public function testIsPrefetchEnabledIsFalseForAnUnconfiguredArtifact(): void {
		// A queued job can outlive its artifact's config; draining it is the right answer,
		// so this reports false rather than throwing the way getEntityKind() does.
		$this->assertFalse( self::newConfig()->isPrefetchEnabled( 'no-such-artifact' ) );
	}

	public function testANonBooleanPrefetchEnabledThrows(): void {
		$this->expectException( InvalidArgumentException::class );

		self::newConfig( [
			'bad-artifact' => [ 'entity_kind' => 'revision', 'prefetch' => [ 'enabled' => 'yes' ] ],
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
			'revision-artifact' => [ 'entity_kind' => 'revision' ],
		] );

		$this->assertSame( 'revision', $config->getEntityKind( 'revision-artifact' ) );
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
	 * @dataProvider provideArtifactsWithoutAValidEntityKind
	 */
	public function testAnArtifactWithoutAValidEntityKindThrows( array $artifactConfig ): void {
		// Required: there is no default, and an artifact whose entity kind no listener handles
		// is silently never prefetched, so a missing one must fail loudly at construction.
		$this->expectException( InvalidArgumentException::class );

		new LinkedArtifactsConfig( [
			'artifacts' => [ 'bad-artifact' => $artifactConfig ],
		] );
	}

	public static function provideArtifactsWithoutAValidEntityKind(): array {
		return [
			'absent' => [ [] ],
			'empty' => [ [ 'entity_kind' => '' ] ],
			'not a string' => [ [ 'entity_kind' => 7 ] ],
			// No code handles this entity kind, so the artifact would never be prefetched.
			'unknown' => [ [ 'entity_kind' => 'user' ] ],
		];
	}

	/**
	 * @dataProvider provideInvalidArtifactNames
	 */
	public function testAnInvalidArtifactNameThrows( string $artifactName ): void {
		$this->expectException( InvalidArgumentException::class );
		self::newConfig( [ $artifactName => [ 'entity_kind' => 'revision' ] ] );
	}

	public static function provideInvalidArtifactNames(): array {
		return [
			'dot' => [ 'my.artifact' ],
			'space' => [ 'my artifact' ],
			'slash' => [ 'my/artifact' ],
			'too long' => [ str_repeat( 'a', 121 ) ],
		];
	}

	public function testAnArtifactNameWithTheMaximumLengthIsAccepted(): void {
		$artifactName = str_repeat( 'a', 120 );
		$config = self::newConfig( [ $artifactName => [ 'entity_kind' => 'revision' ] ] );
		$this->assertSame( 'revision', $config->getEntityKind( $artifactName ) );
	}

	public function testAValidArtifactNameIsAccepted(): void {
		$config = self::newConfig( [ 'revision_feature-counts2' => [ 'entity_kind' => 'revision' ] ] );
		$this->assertSame( 'revision', $config->getEntityKind( 'revision_feature-counts2' ) );
	}
}
