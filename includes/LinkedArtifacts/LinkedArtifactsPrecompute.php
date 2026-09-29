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
 * Precomputes Linked Artifacts Cache (LAC) artifacts: a no-cache fetch makes LAC
 * consult the artifact's lambda and store the result. On success, a DomainEvent
 * that carries the artifact is dispatched.
 *
 * This class does not compute the artifact. "Precompute" means that it causes LAC to
 * compute the artifact before a reader asks for it.
 *
 * This does not check whether precompute is enabled. Callers decide that.
 *
 * @unstable
 */
class LinkedArtifactsPrecompute {

	public function __construct(
		private readonly LinkedArtifactsClient $client,
		private readonly LinkedArtifactsConfig $config,
		private readonly DomainEventDispatcher $eventDispatcher,
		private readonly IConnectionProvider $connectionProvider
	) {
	}

	/**
	 * Precompute one revision's artifact, then dispatch a
	 * {@link RevisionLinkedArtifactPrecomputedEvent} on success.
	 *
	 * @param string $artifactName
	 * @param string|false $wikiId The page's wiki ID; false for the local wiki.
	 * @param int $pageId
	 * @param int $revisionId
	 * @param string|null $triggerEventType See {@link RevisionLinkedArtifactPrecomputedEvent}.
	 * @param string|null $triggerCause
	 * @param bool $isReconciliationRequest
	 * @return LinkedArtifactResponse The LAC response, for the caller's retry decision.
	 */
	public function precomputeRevisionArtifact(
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
			$this->config->getPrecomputeTimeoutMs( $artifactName ),
			LinkedArtifactsClient::CACHE_CONTROL_NO_CACHE
		);

		if ( $response->isSuccess() ) {
			$this->eventDispatcher->dispatch(
				new RevisionLinkedArtifactPrecomputedEvent(
					$artifactName,
					$this->config->resolveWikiId( $wikiId ),
					$pageId,
					$revisionId,
					$response->getContentType(),
					$response->getBody(),
					// The time that the lambda computed the artifact.
					// If LAC does not send it, use the time that the fetch completed (now).
					$response->getLastModified() ?? new ConvertibleTimestamp(),
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
