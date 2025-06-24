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
 * @author Andrew Otto <otto@wikimedia.org>
 * @author Gabriele Modena <gmodena@wikimedia.org>
 */

declare( strict_types = 1 );

namespace MediaWiki\Extension\EventBus\MediaWikiEventSubscribers;

use IDBAccessObject;
use InvalidArgumentException;
use MediaWiki\Config\Config;
use MediaWiki\Content\ContentHandlerFactory;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\DomainEvent\DomainEventIngress;
use MediaWiki\Extension\EventBus\EventBusFactory;
use MediaWiki\Extension\EventBus\Redirects\RedirectTarget;
use MediaWiki\Extension\EventBus\Serializers\EventSerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\PageChangeEventSerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\PageEntitySerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\RevisionEntitySerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\RevisionSlotEntitySerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\UserEntitySerializer;
use MediaWiki\Extension\EventBus\StreamNameMapper;
use MediaWiki\Http\Telemetry;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Page\Event\PageCreatedEvent;
use MediaWiki\Page\Event\PageCreatedListener;
use MediaWiki\Page\Event\PageDeletedEvent;
use MediaWiki\Page\Event\PageDeletedListener;
use MediaWiki\Page\Event\PageHistoryVisibilityChangedEvent;
use MediaWiki\Page\Event\PageHistoryVisibilityChangedListener;
use MediaWiki\Page\Event\PageMovedEvent;
use MediaWiki\Page\Event\PageMovedListener;
use MediaWiki\Page\Event\PageRevisionUpdatedEvent;
use MediaWiki\Page\Event\PageRevisionUpdatedListener;
use MediaWiki\Page\PageLookup;
use MediaWiki\Page\PageReference;
use MediaWiki\Page\RedirectLookup;
use MediaWiki\Page\WikiPage;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Storage\PageUpdateCauses;
use MediaWiki\Title\TitleFormatter;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserGroupManager;
use Psr\Log\LoggerInterface;
use Wikimedia\Timestamp\TimestampException;
use Wikimedia\UUID\GlobalIdGenerator;

/**
 * Handles PageRevisionUpdated events by forwarding page edits to EventGate.
 */
class PageChangeEventIngress extends DomainEventIngress implements
	PageRevisionUpdatedListener,
	PageDeletedListener,
	PageMovedListener,
	PageCreatedListener,
	PageHistoryVisibilityChangedListener
{
	public const PAGE_CHANGE_STREAM_NAME_DEFAULT = "mediawiki.page_change.v1";

	/**
	 * Name of the stream that events will be produced to.
	 * @var string
	 */
	private string $streamName;

	/**
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * @var EventBusFactory
	 */
	private EventBusFactory $eventBusFactory;

	/**
	 * @var PageChangeEventSerializer
	 */
	private PageChangeEventSerializer $pageChangeEventSerializer;

	/**
	 * @var UserFactory
	 */
	private UserFactory $userFactory;

	/**
	 * @var RevisionStore
	 */
	private RevisionStore $revisionStore;

	/**
	 * @var RedirectLookup
	 */
	private RedirectLookup $redirectLookup;

	/**
	 * @var PageLookup
	 */
	private PageLookup $pageLookup;

	public function __construct(
		EventBusFactory $eventBusFactory,
		StreamNameMapper $streamNameMapper,
		Config $mainConfig,
		GlobalIdGenerator $globalIdGenerator,
		UserGroupManager $userGroupManager,
		TitleFormatter $titleFormatter,
		UserFactory $userFactory,
		RevisionStore $revisionStore,
		ContentHandlerFactory $contentHandlerFactory,
		RedirectLookup $redirectLookup,
		PageLookup $pageLookup
	) {
		$this->logger = LoggerFactory::getInstance( 'EventBus.PageChangeEventIngress' );

		$this->streamName = $streamNameMapper->resolve(
			self::PAGE_CHANGE_STREAM_NAME_DEFAULT
		);

		$this->eventBusFactory = $eventBusFactory;

		$userEntitySerializer = new UserEntitySerializer( $userFactory, $userGroupManager );

		$this->pageChangeEventSerializer = new PageChangeEventSerializer(
			new EventSerializer( $mainConfig, $globalIdGenerator, Telemetry::getInstance() ),
			new PageEntitySerializer( $mainConfig, $titleFormatter ),
			$userEntitySerializer,
			new RevisionEntitySerializer(
				new RevisionSlotEntitySerializer( $contentHandlerFactory ),
				$userEntitySerializer
			)
		);

		$this->userFactory = $userFactory;
		$this->revisionStore = $revisionStore;
		$this->redirectLookup = $redirectLookup;
		$this->pageLookup = $pageLookup;
	}

	/**
	 * Returns a redirect target of supplied {@link PageReference}, if any.
	 *
	 * If the page reference does not represent a redirect, `null` is returned.
	 *
	 * See {@link RedirectTarget} for the meaning of its properties.
	 *
	 * TODO visible for testing only, move into RedirectLookup?
	 *
	 * @param PageReference $page
	 * @param PageLookup $pageLookup
	 * @param RedirectLookup $redirectLookup
	 * @return RedirectTarget|null
	 * @see RedirectTarget
	 */
	public static function lookupRedirectTarget(
		PageReference $page, PageLookup $pageLookup, RedirectLookup $redirectLookup
	): ?RedirectTarget {
		if ( $page instanceof WikiPage ) {
			// RedirectLookup doesn't support reading from the primary db, but we
			// need the value from the new edit. Fetch directly through WikiPage which
			// was updated with the new value as part of saving the new revision.
			$redirectLinkTarget = $page->getRedirectTarget();
		} else {
			$redirectSourcePageReference =
				$pageLookup->getPageByReference( $page,
					\Wikimedia\Rdbms\IDBAccessObject::READ_LATEST );

			$redirectLinkTarget =
				$redirectSourcePageReference != null && $redirectSourcePageReference->isRedirect()
					? $redirectLookup->getRedirectTarget( $redirectSourcePageReference ) : null;
		}

		if ( $redirectLinkTarget != null ) {
			if ( !$redirectLinkTarget->isExternal() ) {
				try {
					$redirectTargetPage = $pageLookup->getPageForLink( $redirectLinkTarget );

					return new RedirectTarget( $redirectLinkTarget, $redirectTargetPage );
				} catch ( InvalidArgumentException $e ) {
					// silently ignore failed lookup, they are expected for anything but page targets
				}
			}

			return new RedirectTarget( $redirectLinkTarget );
		}

		return null;
	}

	private function sendEvents(
		string $streamName,
		array $events
	): void {
		$eventBus = $this->eventBusFactory->getInstanceForStream( $streamName );
		DeferredUpdates::addCallableUpdate( static function () use ( $eventBus, $events ) {
			$eventBus->send( $events );
		} );
	}

	/**
	 * Handles a `PageRevisionUpdatedEvent` and emits a corresponding page change event.
	 *
	 * This method is triggered when a page revision is updated. It filters out
	 * null edits (which do not change the page content) and constructs either
	 * a creation or edit event for downstream consumers, depending on the nature
	 * of the change.
	 *
	 * Null edits are ignored, as they are intended only to trigger side-effects
	 * and do not represent a meaningful change to page content.
	 *
	 * @param PageRevisionUpdatedEvent $event
	 *   The domain event carrying information about the page revision update, including
	 *   the page ID, revision data, user identity, and edit result.
	 */
	public function handlePageRevisionUpdatedEvent( PageRevisionUpdatedEvent $event ): void {
		if ( $this->isContentChangeCause( $event ) ) {
			// Null edits are only useful to trigger side-effects, and would be
			//   confusing to consumers of these events.  Since these would not be able to
			//   change page state, they also don't belong in here.  If filtering them out
			//   breaks a downstream consumer, we should send them to a different stream.
			if ( $event->getEditResult() && $event->getEditResult()->isNullEdit() ) {
				return;
			}

			$performer = $this->userFactory->newFromUserIdentity( $event->getPerformer() );
			$revisionRecord = $event->getLatestRevisionAfter();

			$redirectTarget =
				self::lookupRedirectTarget( $event->getPage(), $this->pageLookup,
					$this->redirectLookup );

			$pageChangeEvent = $event->isCreation()
				? $this->pageChangeEventSerializer->toCreateEvent( $this->streamName, $event->getPage(),
					$performer, $revisionRecord, $redirectTarget )
				: $this->pageChangeEventSerializer->toEditEvent( $this->streamName, $event->getPage(),
					$performer, $revisionRecord, $redirectTarget,
					$this->revisionStore->getRevisionById( $event->getPageRecordBefore()->getLatest() ) );

			$this->sendEvents( $this->streamName, [ $pageChangeEvent ] );
		}
	}

	/**
	 * Whether $event was emitted as a result of an action that modified content;
	 * this should match the code paths that previously would trigger onPageSaveComplete
	 * callbacks.
	 *
	 * @param PageRevisionUpdatedEvent $event
	 * @return bool
	 */
	private function isContentChangeCause( PageRevisionUpdatedEvent $event ): bool {
		return $event->getCause() === PageUpdateCauses::CAUSE_EDIT ||
			$event->getCause() === PageUpdateCauses::CAUSE_IMPORT ||
			$event->getCause() === PageUpdateCauses::CAUSE_ROLLBACK ||
			$event->getCause() === PageUpdateCauses::CAUSE_UNDO ||
			$event->getCause() === PageUpdateCauses::CAUSE_UPLOAD;
	}

	/**
	 * Handle a page deletion event by creating and sending a corresponding page change event.
	 *
	 * This method processes page deletion events and transforms them into page change events
	 * that can be consumed by event subscribers. It handles both regular deletions and
	 * suppressed deletions (where performer information is withheld).
	 *
	 * The generated event includes:
	 * - Page metadata (ID, title, etc.)
	 * - Deletion details (reason, timestamp, number of revisions deleted)
	 * - Performer information (unless suppressed)
	 * - Redirect target information (if the deleted page was a redirect)
	 *
	 * For suppressed deletions (oversight/revision deletion), performer information
	 * is intentionally omitted from the event for security reasons.
	 * See: https://phabricator.wikimedia.org/T342487
	 *
	 * @param PageDeletedEvent $event The page deletion event to process
	 * @throws TimestampException
	 * @throws TimestampException
	 * @see PageChangeEventSerializer::toDeleteEvent() For the event format
	 */
	public function handlePageDeletedEvent( PageDeletedEvent $event ): void {
		$deletedRev = $event->getLatestRevisionBefore();

		// Don't set performer in the event if this delete suppresses the page from other admins.
		// https://phabricator.wikimedia.org/T342487
		$performerForEvent = $event->isSuppressed() ?
			null :
			$this->userFactory->newFromUserIdentity( $event->getPerformer() );

		$redirectTarget = null;

		if ( $event->wasRedirect() ) {
			$targetBefore = $event->getRedirectTargetBefore();
			if ( $targetBefore ) {
				$redirectTarget = new RedirectTarget( $targetBefore );
			}
		}

		$pageChangeEvent = $this->pageChangeEventSerializer->toDeleteEvent(
			$this->streamName,
			$event->getDeletedPage(),
			$performerForEvent,
			$deletedRev,
			$event->getReason(),
			$event->getEventTimestamp()->getTimestamp(),
			$event->getArchivedRevisionCount(),
			$redirectTarget,
			$event->isSuppressed()
		);

		$this->sendEvents( $this->streamName, [ $pageChangeEvent ] );
	}

	/**
	 * Handles a page moved event by generating and sending a corresponding
	 * page change event.
	 *
	 * This method processes a `PageMovedEvent`, retrieves the necessary page state
	 * before and after the move, obtains user and revision context, identifies
	 * whether a redirect was created, and serializes all this information into
	 * a page change move event.
	 *
	 * Passes $event->getPageRecordBefore() directly to toMoveEvent, which accepts LinkTarget|PageReference.
	 *
	 * @param PageMovedEvent $event The event representing a page move, including
	 *                              references to the page before and after the move,
	 *                              the performing user, reason for the move, and
	 *                              any redirect that may have been created.
	 *
	 * @throws InvalidArgumentException If the moved-to page could not be found
	 *                                  using the latest page data.
	 */
	public function handlePageMovedEvent( PageMovedEvent $event ): void {
		if ( !$event->getPageRecordAfter()->exists() ) {
			throw new InvalidArgumentException(
				"No page moved from '{$event->getPageRecordBefore()->getDBkey()}' "
				. "to '{$event->getPageRecordAfter()->getDBkey()}'"
				. " with ID {$event->getPageId()} could be found"
			);
		}

		$performer = $this->userFactory->newFromUserIdentity( $event->getPerformer() );

		$redirectTarget =
			self::lookupRedirectTarget( $event->getPageRecordAfter(), $this->pageLookup,
				$this->redirectLookup );

		// The parentRevision is needed since a page move creates a new revision.
		$revision = $this->revisionStore->getRevisionById(
			$event->getPageRecordAfter()->getLatest() );
		$parentRevision = $this->revisionStore->getRevisionById(
			$event->getPageRecordBefore()->getLatest() );

		$event = $this->pageChangeEventSerializer->toMoveEvent(
			$this->streamName,
			$event->getPageRecordAfter(),
			$performer,
			$revision,
			$parentRevision,
			$event->getPageRecordBefore(),
			$event->getReason(),
			$event->getRedirectPage(),
			$redirectTarget
		);

		$this->sendEvents( $this->streamName, [ $event ] );
	}

	/**
	 * Handles `PageCreatedEvent` emitted after a page as been undeleted
	 * (e.g. a proper undelete into a new page).
	 *
	 * @param PageCreatedEvent $event
	 * @return void
	 * @throws TimestampException
	 */
	public function handlePageCreatedEvent( PageCreatedEvent $event ): void {
		if ( $event->getCause() === PageUpdateCauses::CAUSE_UNDELETE ) {
			$performer = $this->userFactory->newFromUserIdentity( $event->getPerformer() );

			$redirectTarget =
				self::lookupRedirectTarget( $event->getPageRecordAfter(), $this->pageLookup,
					$this->redirectLookup );

			// TODO: replace with $event->getPageRecordBefore()?->getId();
			//  once EventBus CI fully adopts php 8.
			$oldPage = $event->getPageRecordBefore();

			$event = $this->pageChangeEventSerializer->toUndeleteEvent(
				$this->streamName,
				$event->getPageRecordAfter(),
				$performer,
				$event->getLatestRevisionAfter(),
				$event->getReason(),
				$redirectTarget,
				$event->getEventTimestamp()->getTimestamp(),
				( $oldPage !== null ) ? $oldPage->getId() : null
			);

			$this->sendEvents( $this->streamName, [ $event ] );
		}
	}

	/**
	 * Handles `PageHistoryVisibilityChangedEvent` events.
	 *
	 * This method checks whether the visibility of the current revision of a page has changed.
	 * If so, it emits a corresponding `visibility_change` event to the configured stream.
	 *
	 * Notes:
	 * - Uses primary DB reads to prevent leaking suppressed data due to replication lag.
	 * - Emits private events when suppression occurs to match MediaWiki log visibility conventions.
	 * - Uses the current timestamp for event time due to limitations in the upstream hook.
	 *
	 * @param PageHistoryVisibilityChangedEvent $event
	 *
	 * @throws TimestampException
	 */
	public function handlePageHistoryVisibilityChangedEvent( PageHistoryVisibilityChangedEvent $event ): void {
		// https://phabricator.wikimedia.org/T321411
		$performer = $event->getPerformer();

		// Only send an event if the visible-ness of the current revision has changed
		try {
			// Read from primary since due to replication lag the updated field visibility
			// might not yet be available on a replica, and we are at risk of leaking
			// just suppressed data.
			$revisionRecord = $this->revisionStore->getRevisionByPageId(
				$event->getPageId(),
				0,
				IDBAccessObject::READ_LATEST
			);

			// If this is the current revision of the page,
			// then we need to represent the fact that the visibility
			// properties of the current state of the page has changed.
			// Emit a page change visibility_change event.
			if ( $revisionRecord === null || !$revisionRecord->isCurrent() ) {
				throw new InvalidArgumentException(
					'Current revision ' .
					' could not be loaded from database and may have been deleted.' .
					' Cannot create visibility change event for ' . $this->streamName .
					'.'
				);
			}

			$revId = $revisionRecord->getId();

			// current revision's visibility should be the same as we are given in
			// $visibilityChanges['newBits']. Just in case, assert that this is true.
			if ( $revisionRecord->getVisibility() !=
				$event->getVisibilityAfter( $revId ) ) {
				throw new InvalidArgumentException(
					"Current revision $revId's' visibility did not match the expected " .
					'visibility change provided by hook. Current revision visibility is ' .
					$revisionRecord->getVisibility() . '. visibility changed to ' .
					$event->getVisibilityAfter( $revId ) );
			}

			// We only need to emit an event if visibility has actually changed.
			if ( !$event->wasCurrentRevisionAffected() ) {
				$this->logger->warning(
					"handlePageHistoryVisibilityChangedEvent called on revision $revId " .
					'when no effective visibility change was made.'
				);
			} else {
				// If this revision is 'suppressed' AKA restricted, then the person performing
				// 'RevisionDelete' should not be visible in public data.
				// https://phabricator.wikimedia.org/T342487
				//
				// NOTE: This event stream tries to match the visibility of MediaWiki core logs,
				// where regular delete/revision events are public, and suppress/revision events
				// are private. In MediaWiki core logs, private events are fully hidden from
				// the public.  Here, we need to produce a 'private' event to the
				// mediawiki.page_change stream, to indicate to consumers that
				// they should also 'suppress' the revision.  When this is done, we need to
				// make sure that we do not reproduce the data that has been suppressed
				// in the event itself.  E.g. if the username of the editor of the revision has been
				// suppressed, we should not include any information about that editor in the event.
				$performerForEvent = $event->isSuppressed() ? null : $performer;

				$event =
					$this->pageChangeEventSerializer->toVisibilityChangeEvent( $this->streamName,
						$event->getPage(), $performerForEvent, $revisionRecord,
						$event->getVisibilityBefore( $revId ),
						$event->getEventTimestamp()->getTimestamp() );

				$this->sendEvents( $this->streamName, [ $event ] );
			}
		} catch ( InvalidArgumentException $e ) {
			$this->logger->error(
				' invalid revision for page ' . $event->getPageId() .
				' Cannot create visibility change event for ' . $this->streamName . ': '
				. $e->getMessage()
			);
		}
	}
}
