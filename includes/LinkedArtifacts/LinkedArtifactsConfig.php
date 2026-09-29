<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

declare( strict_types=1 );

namespace MediaWiki\Extension\EventBus\LinkedArtifacts;

use InvalidArgumentException;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Assert\Assert;

/**
 * EventBusLinkedArtifacts config value class.
 *
 * Represents the configuration for the Linked Artifacts Cache (LAC) precompute and access.
 *
 * Example of wgEventBusLinkedArtifacts MediaWiki config:
 * <pre>
 * [
 *   // Is the LinkedArtifacts feature enabled on this wiki?
 *   'enabled' => true,
 *
 *   // base service URL of LAC service.
 *   'base_url' => 'https://lac.example.org',
 *
 *   // per artifact configuration. Keys are the artifact name in LAC.
 *   'artifacts' => [
 *
 *     'exampleArtifact' => [
 *       // Type of entity the artifact is linked (keyed) by.
 *       // This is used to construct the correct LAC URL.
 *       'entity_kind' => 'revision',
 *
 *       // Precompute settings. Everything here affects precompute only;
 *       // `entity_kind` and `page` above also apply to readers.
 *       'precompute' => [
 *         // Set false to stop precomputing this artifact, including any jobs
 *         // already queued for it. Defaults to true.
 *         'enabled' => true,
 *
 *         // DomainEvent TYPE names that this artifact should be precomputed after.
 *         // NOTE: make sure LinkedArtifactsPrecomputeIngress is registered to
 *         // handle these event types.
 *         'events' => [ 'PageLatestRevisionChanged' ],
 *
 *         // precompute fetch timeout_ms override for this artifact
 *         // in milliseconds. Defaults to 5000.
 *         'timeout_ms' => 30000,
 *       ],
 *
 *       // page specific settings.  Only applies to artifacts that are linked to pages,
 *       // either directly to page_ids or as revisions that belong to page_ids.
 *       'page' => [
 *
 *         // page namespaces that this artifact applies to.
 *         // If a page does not belong to this namespace,
 *         // it will not be auto-precomputed or fetchable.
 *         'namespaces' => [ 0, 1 ],
 *
 *         // Deterministic sampling rate on page_id. This can be used to
 *         // avoid precomputing (or fetching) all pages indiscriminately.
 *         // Usually useful when rolling out new LAC features before enabling them for all pages.
 *         'sample' => 0.5,
 *       ],
 *     ],
 *   ],
 * ]
 * </pre>
 *
 * @see https://gitlab.wikimedia.org/repos/sre/hoarde/-/blob/main/API.md
 */
class LinkedArtifactsConfig {

	/**
	 * Default precompute fetch timeout for artifacts that do not set their own.
	 *
	 * Deliberately a code default rather than a config setting: per-artifact overrides
	 * cover what actually differs (lambdas vary in speed), and changing the fallback for
	 * every artifact is rare enough to be worth a code deployment.
	 */
	public const PRECOMPUTE_TIMEOUT_MS_DEFAULT = 5000;

	/**
	 * Whether the LAC integration is enabled on this wiki.
	 */
	private bool $enabled;

	/**
	 * LAC service base URL.
	 * @var string
	 */
	private string $baseUrl;

	/**
	 * Per-artifact config (the `artifacts` element), keyed by artifact name.
	 *
	 * @var array<string,array>
	 */
	private array $artifacts;

	/**
	 * Reverse map of DomainEvent type => list of artifact names that subscribe to it.
	 *
	 * @var array<string,string[]>
	 */
	private array $artifactsByEvent = [];

	/**
	 * @param array $config EventBusLinkedArtifacts MediaWiki config.
	 * @throws InvalidArgumentException if a setting has the wrong type, or if a
	 *   `page.sample` rate is outside [0.0, 1.0]
	 */
	public function __construct( array $config ) {
		Assert::parameterType( 'boolean', $config['enabled'] ?? false, 'enabled' );
		Assert::parameterType( 'string', $config['base_url'] ?? '', 'base_url' );
		Assert::parameterType( 'array', $config['artifacts'] ?? [], 'artifacts' );

		$this->enabled = $config['enabled'] ?? false;
		$this->baseUrl = $config['base_url'] ?? '';
		$this->artifacts = $config['artifacts'] ?? [];

		foreach ( $this->artifacts as $artifactName => $artifactConfig ) {
			// cast to string to avoid accidental "123" key as integer - PHP weirdness.
			$artifactName = (string)$artifactName;
			$this->validateArtifactConfig( $artifactName, $artifactConfig );

			// Store a map from DomainEvent types to artifact names.
			// This can be used to auto trigger artifact precompute when DomainEvents are emitted.
			// `events` is optional: an artifact may be read-only, never precomputed.
			foreach ( $artifactConfig['precompute']['events'] ?? [] as $eventType ) {
				$this->artifactsByEvent[$eventType][] = $artifactName;
			}
		}
	}

	/**
	 * Validates an artifact config entry.
	 * @param string $artifactName
	 * @param array $artifactConfig
	 * @return void
	 */
	private function validateArtifactConfig( string $artifactName, array $artifactConfig ): void {
		// The artifact name is the LAC cache name and the first component of every
		// artifact URL.
		Assert::parameter( $artifactName !== '', 'artifacts', 'must not contain an empty artifact name' );

		// dotted config key, used for assertion error messages.
		$configKey = 'artifacts.' . $artifactName;
		Assert::parameterType( 'array', $artifactConfig, $configKey );

		$page = $artifactConfig['page'] ?? [];
		$sample = $page['sample'] ?? 1.0;

		// Required: there is no sensible default entity, and an artifact whose entity no
		// listener handles is silently never precomputed.
		Assert::nonEmptyString( $artifactConfig['entity_kind'] ?? null, "$configKey.entity_kind" );
		$precompute = $artifactConfig['precompute'] ?? [];
		Assert::parameterType( 'array', $precompute, "$configKey.precompute" );

		if ( isset( $precompute['timeout_ms'] ) ) {
			Assert::parameterType( 'integer', $precompute['timeout_ms'], "$configKey.precompute.timeout_ms" );
			Assert::parameter(
				$precompute['timeout_ms'] > 0,
				"$configKey.precompute.timeout_ms",
				'must be greater than 0'
			);
		}
		if ( isset( $precompute['enabled'] ) ) {
			Assert::parameterType( 'boolean', $precompute['enabled'], "$configKey.precompute.enabled" );
		}
		Assert::parameterElementType( 'string', $precompute['events'] ?? [], "$configKey.precompute.events" );

		Assert::parameterElementType( 'integer', $page['namespaces'] ?? [], "$configKey.page.namespaces" );
		Assert::parameterType( 'integer|double', $sample, "$configKey.page.sample" );
		Assert::parameter(
			$sample >= 0.0 && $sample <= 1.0, "$configKey.page.sample", 'must be between 0.0 and 1.0'
		);
	}

	/**
	 * Whether the LAC integration is enabled on this wiki. Both the precompute path and
	 * readers must gate on this before doing anything else.
	 */
	public function isEnabled(): bool {
		return $this->enabled;
	}

	public function getBaseUrl(): string {
		return $this->baseUrl;
	}

	/**
	 * Timeout in milliseconds for precomputing an artifact, to use as
	 * {@link LinkedArtifactsFetcher::fetch()} `timeout` parameter.
	 * Precomputes are expected to be a cache miss that triggers a
	 * lambda compute, so this could potentially be higher than a reader's timeout.
	 *
	 * @param string $artifactName
	 * @return int
	 */
	public function getPrecomputeTimeoutMs( string $artifactName ): int {
		return $this->artifacts[$artifactName]['precompute']['timeout_ms']
			?? self::PRECOMPUTE_TIMEOUT_MS_DEFAULT;
	}

	/**
	 * Whether this artifact should be precomputed at all.
	 * Defaults to true.
	 *
	 * Checked both before enqueuing and inside the job, so that switching an artifact off
	 * also drains whatever was already queued for it.
	 *
	 * @param string $artifactName
	 * @return bool
	 */
	public function isPrecomputeEnabled( string $artifactName ): bool {
		if ( !isset( $this->artifacts[$artifactName] ) ) {
			return false;
		}

		return $this->artifacts[$artifactName]['precompute']['enabled'] ?? true;
	}

	/**
	 * Names of the artifacts that $eventType should precompute.
	 *
	 * That is, those whose `precompute.events` list contains $eventType and whose
	 * precompute is enabled. Enablement is folded in here rather than left to the caller
	 * because there is no other reason to ask which artifacts an event subscribes to —
	 * `precompute.events` is precompute-only config.
	 *
	 * @param string $eventType
	 * @return string[]
	 */
	public function getArtifactsToPrecomputeForEvent( string $eventType ): array {
		return array_values( array_filter(
			$this->artifactsByEvent[$eventType] ?? [],
			fn ( string $artifactName ): bool => $this->isPrecomputeEnabled( $artifactName )
		) );
	}

	/**
	 * The entity kind an artifact is computed from.
	 *
	 * @param string $artifactName A configured artifact name.
	 * @return string
	 * @throws InvalidArgumentException if no artifact is configured under that name
	 */
	public function getEntityKind( string $artifactName ): string {
		Assert::parameter(
			isset( $this->artifacts[$artifactName] ),
			'artifactName',
			"must name a configured artifact, got '$artifactName'"
		);

		return $this->artifacts[$artifactName]['entity_kind'];
	}

	/**
	 * Helper function to resolve a wiki ID to a string, defaulting to the local wiki.
	 *
	 * MediaWiki entities return false from getWikiId() when they belong to the local wiki.
	 * All LAC code must resolve this the same way, since the wiki_id is both part of the
	 * LAC URL and part of the page sampling hash.
	 *
	 * @param string|false $wikiId
	 * @return string
	 */
	public function resolveWikiId( string|false $wikiId ): string {
		return $wikiId ?: WikiMap::getCurrentWikiId();
	}

	/**
	 * Whether an artifact's configured page rules cover the page,
	 * i.e. whether the page is in an enabled namespace and within
	 * the artifact's sample.
	 *
	 * NOTE: {@link LinkedArtifactsFetcher::coversPage()} should likely be called instead.
	 *
	 * @param string $artifactName
	 * @param string|false $wikiId The page's wiki ID; false for the local wiki.
	 * @param int $pageId
	 * @param int $namespace
	 * @return bool
	 */
	public function appliesToPage(
		string $artifactName,
		string|false $wikiId,
		int $pageId,
		int $namespace
	): bool {
		// An artifact that is not configured does not apply to any page.
		if ( !isset( $this->artifacts[$artifactName] ) ) {
			return false;
		}

		return $this->matchesPageNamespace( $artifactName, $namespace )
			&& $this->isPageSampledIn( $artifactName, $this->resolveWikiId( $wikiId ), $pageId );
	}

	/**
	 * Whether the namespace is in an artifact's `page.namespaces` allowlist. True when the
	 * artifact declares no allowlist (i.e. all namespaces).
	 */
	private function matchesPageNamespace( string $artifactName, int $namespace ): bool {
		$namespaces = $this->artifacts[$artifactName]['page']['namespaces'] ?? null;
		if ( $namespaces === null ) {
			return true;
		}
		return in_array( $namespace, $namespaces, true );
	}

	/**
	 * Whether a page is within an artifact's sample. Deterministic on the page's identity
	 * `(wiki_id, page_id)` so a page is a stable cohort member.
	 */
	private function isPageSampledIn( string $artifactName, string $wikiId, int $pageId ): bool {
		$rate = $this->artifacts[$artifactName]['page']['sample'] ?? 1.0;

		// Avoid hashing if sampling is obvious.
		if ( $rate == 1.0 ) {
			return true;
		} elseif ( $rate == 0.0 ) {
			return false;
		}

		// Map the page identity to a stable bucket in [0, 1). Dividing by 2^32 rather than
		// 2^32 - 1 keeps the bucket strictly below 1.0, so a rate of 1.0 samples every page
		// in and 0.0 samples every page out.
		$bucket = hexdec( substr( sha1( "$wikiId:$pageId" ), 0, 8 ) ) / 0x100000000;
		return $bucket < $rate;
	}
}
