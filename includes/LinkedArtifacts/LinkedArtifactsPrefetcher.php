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
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Timestamp\ConvertibleTimestamp;

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

	public function __construct(
		private readonly LinkedArtifactsClient $client,
		private readonly LinkedArtifactsConfig $config,
		private readonly DomainEventDispatcher $eventDispatcher,
		private readonly IConnectionProvider $connectionProvider
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
	 * @param string|null $triggerEventType See {@link RevisionLinkedArtifactPrefetchedEvent}.
	 * @param string|null $triggerCause
	 * @param bool $isReconciliationRequest If true, LAC computes the artifact again, even if
	 *   it has it cached.
	 * @return LinkedArtifactResponse The LAC response, for the caller's retry decision.
	 */
	public function prefetchRevisionArtifact(
		string $artifactName,
		string|false $wikiId,
		int $pageId,
		int $revisionId,
		?string $triggerEventType = null,
		?string $triggerCause = null,
		bool $isReconciliationRequest = false
	): LinkedArtifactResponse {
		$response = $this->client->fetchRevisionArtifact(
			$artifactName,
			$wikiId,
			$pageId,
			$revisionId,
			$this->config->getPrefetchTimeoutMs( $artifactName ),
			// Only a reconciliation request forces a new compute. Other prefetches accept an
			// artifact that LAC has cached, e.g. from a reader or from a duplicate job.
			$isReconciliationRequest ? LinkedArtifactsClient::CACHE_CONTROL_NO_CACHE : null
		);

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
					$triggerEventType,
					$triggerCause,
					$isReconciliationRequest
				),
				$this->connectionProvider
			);
		}

		return $response;
	}
}
