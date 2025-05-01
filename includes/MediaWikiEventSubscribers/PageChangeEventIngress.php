<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\EventBus\MediaWikiEventSubscribers;

use MediaWiki\Config\Config;
use MediaWiki\Content\ContentHandlerFactory;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\DomainEvent\DomainEventIngress;
use MediaWiki\Extension\EventBus\EventBusFactory;
use MediaWiki\Extension\EventBus\HookHandlers\MediaWiki\PageChangeHooks;
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
use MediaWiki\Page\Event\PageDeletedEvent;
use MediaWiki\Page\Event\PageDeletedListener;
use MediaWiki\Page\Event\PageRevisionUpdatedEvent;
use MediaWiki\Page\Event\PageRevisionUpdatedListener;
use MediaWiki\Page\PageLookup;
use MediaWiki\Page\RedirectLookup;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Storage\PageUpdateCauses;
use MediaWiki\Title\TitleFormatter;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserGroupManager;
use Psr\Log\LoggerInterface;
use Wikimedia\Rdbms\IDBAccessObject;
use Wikimedia\Timestamp\TimestampException;
use Wikimedia\UUID\GlobalIdGenerator;

/**
 * Handles PageRevisionUpdated events by forwarding page edits to EventGate.
 */
class PageChangeEventIngress extends DomainEventIngress implements
	PageRevisionUpdatedListener,
	PageDeletedListener
{

	public const PAGE_CHANGE_STREAM_NAME_DEFAULT = 'mediawiki.page_change.staging.v1';

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
	 * @var WikiPageFactory
	 */
	private WikiPageFactory $wikiPageFactory;

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
		WikiPageFactory $wikiPageFactory,
		UserFactory $userFactory,
		RevisionStore $revisionStore,
		ContentHandlerFactory $contentHandlerFactory,
		RedirectLookup $redirectLookup,
		PageLookup $pageLookup ) {
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

		$this->wikiPageFactory = $wikiPageFactory;
		$this->userFactory = $userFactory;
		$this->revisionStore = $revisionStore;
		$this->redirectLookup = $redirectLookup;
		$this->pageLookup = $pageLookup;
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
	 *
	 * @return void
	 */
	public function handlePageRevisionUpdatedEvent( PageRevisionUpdatedEvent $event ) {
		if ( $this->isContentChangeCause( $event ) ) {
			// Null edits are only useful to trigger side-effects, and would be
			//   confusing to consumers of these events.  Since these would not be able to
			//   change page state, they also don't belong in here.  If filtering them out
			//   breaks a downstream consumer, we should send them to a different stream.
			if ( $event->getEditResult() && $event->getEditResult()->isNullEdit() ) {
				return;
			}

			$wikiPage =
				$this->wikiPageFactory->newFromID( $event->getPageId(),
					IDBAccessObject::READ_LATEST );

			$performer = $this->userFactory->newFromUserIdentity( $event->getPerformer() );
			$revisionRecord = $event->getLatestRevisionAfter();

			$redirectTarget =
				PageChangeHooks::lookupRedirectTarget( $wikiPage, $this->pageLookup,
					$this->redirectLookup );

			$pageChangeEvent = $event->isCreation()
				? $this->pageChangeEventSerializer->toCreateEvent( $this->streamName, $wikiPage,
					$performer, $revisionRecord, $redirectTarget )
				: $this->pageChangeEventSerializer->toEditEvent( $this->streamName, $wikiPage,
					$performer, $revisionRecord, $redirectTarget,
					$this->revisionStore->getRevisionById( $revisionRecord->getParentId() ) );

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
			$event->getCause() === PageUpdateCauses::CAUSE_UNDO;
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
	 * @see PageChangeEventSerializer::toDeleteEvent() For the event format
	 */
	public function handlePageDeletedEvent( PageDeletedEvent $event ) {
		$deletedRev = $event->getLatestRevisionBefore();

		$pageIdentity = $event->getPageRecordBefore();
		$wikiPage = $this->wikiPageFactory->newFromTitle( $pageIdentity );

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
			$wikiPage,
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
}
