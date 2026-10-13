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
use MediaWiki\Extension\EventBus\LinkedArtifacts\Job\LinkedArtifactPrefetchJob;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Page\Event\PageLatestRevisionChangedEvent;
use MediaWiki\Page\Event\PageLatestRevisionChangedListener;
use Psr\Log\LoggerInterface;

/**
 * Reacts to MediaWiki DomainEvents by enqueuing jobs that prefetch the Linked Artifacts
 * Cache (LAC) artifacts configured for that event type.
 *
 * Currently, only PageLatestRevisionChanged is handled.
 *
 * To support another event type,  add a listener method for that event.
 * To support another entity kind, add a branch in the listener(s) that
 * know how to construct the correct LinkedArtifactPrefetchJob params for the entity kind.
 */
class LinkedArtifactsPrefetchIngress
	extends DomainEventIngress
	implements PageLatestRevisionChangedListener
{

	private readonly LoggerInterface $logger;

	public function __construct(
		private readonly JobQueueGroup $jobQueueGroup,
		private readonly LinkedArtifactsConfig $config
	) {
		$this->logger = LoggerFactory::getInstance( 'EventBus.LinkedArtifacts' );
	}

	// NOTE: PageLatestRevisionChanged is emitted for every new latest revision,
	// including page creations (which additionally emit PageCreated), so subscribing to both
	// would enqueue two prefetches for one edit.
	public function handlePageLatestRevisionChangedEvent(
		PageLatestRevisionChangedEvent $event
	): void {
		// Stop now if LinkedArtifacts MW integration is fully disabled on this wiki.
		if ( !$this->config->isEnabled() ) {
			return;
		}

		// Skip enqueue prefetch jobs unless there is a new latest revision id created,
		// or if this is an explicit reconciliation request (e.g. a 'Null Edit').
		if ( !$event->changedLatestRevisionId() && !$event->isReconciliationRequest() ) {
			return;
		}

		$page = $event->getPageRecordAfter();
		$wikiId = $this->config->resolveWikiId( $page->getWikiId() );

		$pageId = $event->getPageId();
		if ( $pageId !== $page->getId() ) {
			// This should probably never happen, but just in case: log it.
			// We will still continue on below using the DomainEvent's pageId.
			$this->logger->warning(
				'PageLatestRevisionChanged event page ID {event_page_id} is not the ID of its page record, ' .
					'{page_record_id}',
				[
					'event_page_id' => $pageId,
					'page_record_id' => $page->getId(),
					'wiki_id' => $wikiId,
				]
			);
		}

		$revisionId = $event->getLatestRevisionAfter()->getId();

		$artifactsForEvent = $this->config->getArtifactsToPrefetchForEvent(
			PageLatestRevisionChangedEvent::TYPE
		);

		// Iterate through each artifact configured to be prefetched for this event,
		// and generate LinkedArtifactPrefetchJob specs for them.
		foreach ( $artifactsForEvent as $artifactName ) {
			// entity kind,  e.g. revision, page, etc.
			$entityKind = $this->config->getEntityKind( $artifactName );

			// The prefetch job for this artifact's entity,
			// if this event can supply that entity's identifiers.
			$jobSpec = null;

			// If we need to support more entityKinds, add them here.
			switch ( $entityKind ) {
				case LinkedArtifactsConfig::REVISION_ENTITY_KIND:
					// If this page should be prefetched.
					// NOTE: Redirects are prefetched too, including the redirects that page moves make.
					// We have not decided yet if they should be excluded.
					if ( $this->config->appliesToPage(
						$artifactName,
						$wikiId,
						$pageId,
						$page->getNamespace(),
					) ) {
						// The trigger info is passed through the job to the DomainEvent
						// that is emitted after the artifact is returned from LAC. It tells
						// the 'provenance' of the artifact, e.g. what caused the prefetch.
						$jobSpec = LinkedArtifactPrefetchJob::newRevisionJobSpec(
							$artifactName,
							$wikiId,
							$pageId,
							$revisionId,
							// Use the event TYPE constant. getEventType() returns a deprecated alias.
							PageLatestRevisionChangedEvent::TYPE,
							$event->getCause(),
							$event->isReconciliationRequest()
						);
					}
					break;
			}

			// If no $jobSpec, then either:
			// - This event could not handle this entity kind in the switch statement above, or
			// - The artifact's configuration does not cover this specific entity
			//   (e.g. this page or this revision).
			if ( $jobSpec === null ) {
				continue;
			}

			$this->jobQueueGroup->lazyPush( $jobSpec );
		}
	}
}
