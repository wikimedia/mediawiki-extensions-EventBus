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

use MediaWiki\DomainEvent\DomainEventIngress;
use MediaWiki\Extension\EventBus\LinkedArtifacts\Job\LinkedArtifactPrecomputeJob;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\JobQueue\JobSpecification;
use MediaWiki\Page\Event\PageLatestRevisionChangedEvent;
use MediaWiki\Page\Event\PageLatestRevisionChangedListener;

/**
 * Reacts to MediaWiki DomainEvents by enqueuing jobs that precompute the Linked Artifacts
 * Cache (LAC) artifacts configured for that event type.
 *
 * Currently, only PageLatestRevisionChanged is handled.
 *
 * Supporting another event type means adding a listener method. Supporting another entity
 * kind means adding a branch in the listener that
 * knows how to construct the correct LAC URI for the entity.
 */
class LinkedArtifactsPrecomputeIngress
	extends DomainEventIngress
	implements PageLatestRevisionChangedListener
{

	private JobQueueGroup $jobQueueGroup;
	private LinkedArtifactsConfig $config;
	private LinkedArtifactsFetcher $fetcher;

	public function __construct(
		JobQueueGroup $jobQueueGroup,
		LinkedArtifactsConfig $config,
		LinkedArtifactsFetcher $fetcher
	) {
		$this->jobQueueGroup = $jobQueueGroup;
		$this->config = $config;
		$this->fetcher = $fetcher;
	}

	// NOTE: PageLatestRevisionChanged is emitted for every new latest revision,
	// including page creations (which additionally emit PageCreated), so subscribing to both
	// would enqueue two precomputes for one edit.

	public function handlePageLatestRevisionChangedEvent(
		PageLatestRevisionChangedEvent $event
	): void {
		if ( !$this->config->isEnabled() ) {
			return;
		}

		// A new latest revision ID means a new LAC cache key, so it needs precomputing
		// even when the content is unchanged (e.g. dummy revisions created by protection changes).
		// Null edits mint no revision, but core flags them as reconciliation
		// requests: the supported way to ask for derived data to be recomputed.
		if ( !$event->changedLatestRevisionId() && !$event->isReconciliationRequest() ) {
			return;
		}
		// TODO: We will probably need to pass reconciliationRequest
		//       or DomainEvent type etc. info to the PrecomputeJob
		//       so it can decide what kind of
		//       LACPrecomputed DomainEvents to emit.

		$page = $event->getPageRecordAfter();
		// Resolve the page's identity once: the same values key the LAC path and the page
		// sampling hash, which have to agree. resolveWikiId() gives a real wiki_id, since
		// a local page's getWikiId() is false.
		$wikiId = $this->config->resolveWikiId( $page->getWikiId() );
		// TODO: $pageId should === $page->getId().  Do we need to be careful here?
		$pageId = $event->getPageId();
		$revisionId = $event->getLatestRevisionAfter()->getId();

		$artifactsForEvent = $this->config->getArtifactsToPrecomputeForEvent(
			PageLatestRevisionChangedEvent::TYPE
		);

		foreach ( $artifactsForEvent as $artifactName ) {
			// Resolve the LAC artifact URI path for this artifact's entity,
			// if this event can supply that entity's identifiers.
			$artifactUri = null;

			// entity kind,  e.g. revision, page, etc.
			$entityKind = $this->config->getEntityKind( $artifactName );

			// If we need to support more entityKinds, add them here.
			switch ( $entityKind ) {
				case LinkedArtifactsFetcher::REVISION_ENTITY_KIND:
					// If this page should be precomputed
					if ( $this->fetcher->coversPage(
						$artifactName,
						$wikiId,
						$pageId,
						$page->getNamespace(),
					) ) {
						// Get the artifact URI for this revision entity linked artifact.
						$artifactUri = $this->fetcher->getRevisionArtifactUri(
							$artifactName,
							$wikiId,
							$pageId,
							$revisionId,
						);
					}
					break;
			}

			// If no $artifactUri, then either:
			// - This event could not handle this entity kind in the switch statement above, or
			// - The artifact's configuration does not cover this specific entity
			//   (e.g. this page or this revision).
			if ( $artifactUri === null ) {
				continue;
			}

			$this->jobQueueGroup->lazyPush(
				new JobSpecification(
					LinkedArtifactPrecomputeJob::JOB_NAME,
					[
						LinkedArtifactPrecomputeJob::ARTIFACT_URI_PARAM => $artifactUri,
						LinkedArtifactPrecomputeJob::ARTIFACT_NAME_PARAM => $artifactName,
					],
					[ 'removeDuplicates' => true ]
				)
			);
		}
	}
}
