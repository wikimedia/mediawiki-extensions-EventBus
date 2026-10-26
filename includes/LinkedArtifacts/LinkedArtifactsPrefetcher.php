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

use MediaWiki\DomainEvent\DomainEventDispatcher;
use Wikimedia\Assert\Assert;
use Wikimedia\Assert\ParameterAssertionException;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Stats\StatsFactory;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use Wikimedia\Timestamp\TimestampFormat;

/**
 * Prefetches Linked Artifacts Cache (LAC) artifacts.
 * A prefetch is a fetch intended to happen before a reader needs to fetch,
 * usually causing LAC to cache the artifact. On success, it dispatches a
 * DomainEvent that carries the artifact.
 *
 * MediaWiki does not compute the artifact. On a cache miss, LAC consults the artifact's
 * lambda and stores the result.
 *
 * For reconciliation requests, the prefetch request sets
 * `Cache-Control: no-cache`, forcing the artifact to be (re)computed and stored in LAC.
 *
 * @unstable
 */
class LinkedArtifactsPrefetcher {

	/**
	 * Histogram buckets of `prefetch_trigger_to_response_duration_seconds`.
	 * StatsLib allows at most 10 buckets.
	 * Do not change the buckets after deployment. Rename the metric instead.
	 */
	private const TRIGGER_TO_RESPONSE_DURATION_SECONDS_BUCKETS = [ 0.1, 0.5, 1, 2, 5, 10, 30, 60, 300, 3600 ];

	public function __construct(
		private readonly LinkedArtifactsClient $client,
		private readonly LinkedArtifactsConfig $config,
		private readonly DomainEventDispatcher $eventDispatcher,
		private readonly IConnectionProvider $connectionProvider,
		private readonly StatsFactory $stats
	) {
	}

	/**
	 * Prefetch one revision's artifact, then dispatch a
	 * {@link RevisionLinkedArtifactPrefetchedEvent} on success.
	 *
	 * @param string $artifactName
	 * @param string|false $wikiId The page's wiki ID; false for the local wiki.
	 * @param int $pageId
	 * @param int $revisionId
	 * @param bool $isReconciliationRequest If true, LAC computes the artifact again, even if
	 *   it has it cached.
	 * @param string|null $triggerEventType See {@link RevisionLinkedArtifactPrefetchedEvent}.
	 * @param string|null $triggerCause
	 * @param ConvertibleTimestamp|null $triggerTimestamp See {@link RevisionLinkedArtifactPrefetchedEvent}.
	 *   If set, the `prefetch_trigger_to_response_duration_seconds` metric measures from this time.
	 * @return LinkedArtifactResponse The LAC response, for the caller's retry decision.
	 * @throws ParameterAssertionException If the artifact is not a configured revision artifact.
	 */
	public function prefetchRevisionArtifact(
		string $artifactName,
		string|false $wikiId,
		int $pageId,
		int $revisionId,
		bool $isReconciliationRequest = false,
		?string $triggerEventType = null,
		?string $triggerCause = null,
		?ConvertibleTimestamp $triggerTimestamp = null
	): LinkedArtifactResponse {
		// NOTE: getEntityKind() asserts that the artifact is configured.
		$entityKind = $this->config->getEntityKind( $artifactName );
		Assert::parameter(
			$entityKind === LinkedArtifactsConfig::REVISION_ENTITY_KIND,
			'$artifactName',
			"must name a revision linked artifact, '$artifactName' is linked to a '$entityKind' entity"
		);

		$response = $this->client->fetchRevisionArtifact(
			$artifactName,
			$wikiId,
			$pageId,
			$revisionId,
			$this->config->getPrefetchTimeoutMs( $artifactName ),
			// Only a reconciliation request forces a new compute. Other prefetches accept an
			// artifact that LAC has cached, e.g. from a reader or from a duplicate job.
			$isReconciliationRequest ? LinkedArtifactsClient::CACHE_CONTROL_NO_CACHE : null,
			LinkedArtifactsClient::PURPOSE_PREFETCH
		);

		if ( $triggerTimestamp ) {
			$this->recordTriggerToResponseDuration(
				$artifactName,
				$wikiId,
				$entityKind,
				$response,
				$triggerTimestamp,
				self::timestampNow()
			);
		}

		if ( $response->isSuccess() ) {
			$this->eventDispatcher->dispatch(
				new RevisionLinkedArtifactPrefetchedEvent(
					$artifactName,
					$this->config->resolveWikiId( $wikiId ),
					$pageId,
					$revisionId,
					$response->getContentType(),
					$response->getBody(),
					// When LAC sent the response. Use LAC's clock, so that this time is never
					// earlier than the compute time. If LAC does not send it, use the time now.
					$response->getDateTimestamp() ?? new ConvertibleTimestamp(),
					// When the lambda computed the artifact. On a cache hit, this is earlier
					// than the prefetch.
					$response->getLastModifiedTimestamp(),
					$isReconciliationRequest,
					$triggerEventType,
					$triggerCause,
					$triggerTimestamp
				),
				$this->connectionProvider
			);
		}

		return $response;
	}

	/**
	 * Observe `prefetch_trigger_to_response_duration_seconds`: the time from the trigger
	 * (e.g. an edit) to the LAC response of the prefetch.
	 *
	 * @param string $artifactName
	 * @param string|false $wikiId
	 * @param string $entityKind
	 * @param LinkedArtifactResponse $response
	 * @param ConvertibleTimestamp $triggerTimestamp
	 * @param ConvertibleTimestamp $responseTimestamp
	 */
	private function recordTriggerToResponseDuration(
		string $artifactName,
		string|false $wikiId,
		string $entityKind,
		LinkedArtifactResponse $response,
		ConvertibleTimestamp $triggerTimestamp,
		ConvertibleTimestamp $responseTimestamp
	): void {
		$labels = [
			'wiki_id' => $this->config->resolveWikiId( $wikiId ),
			'artifact' => $artifactName,
			'entity_kind' => $entityKind,
			'linked_artifact_service' => LinkedArtifactsConfig::LINKED_ARTIFACTS_CACHE_SERVICE_NAME,
			// Status code 0 is no response, e.g. a timeout.
			'status_code' => (string)$response->getStatusCode(),
		];
		$histogram = $this->stats->getHistogram(
			'prefetch_trigger_to_response_duration_seconds',
			self::TRIGGER_TO_RESPONSE_DURATION_SECONDS_BUCKETS
		);
		foreach ( $labels as $key => $value ) {
			$histogram->setLabel( $key, $value );
		}
		$duration = (float)$responseTimestamp->getTimestamp( TimestampFormat::UNIX_MICRO )
			- (float)$triggerTimestamp->getTimestamp( TimestampFormat::UNIX_MICRO );
		$histogram->observe( max( 0.0, $duration ) );
	}

	/**
	 * The time now, with microseconds. new ConvertibleTimestamp() has whole seconds only.
	 *
	 * @return ConvertibleTimestamp
	 */
	private static function timestampNow(): ConvertibleTimestamp {
		return new ConvertibleTimestamp( sprintf( '%.6F', microtime( true ) ) );
	}
}
