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
 */
namespace MediaWiki\Extension\EventBus\HookHandlers\MediaWiki;

use Config;
use DeferredUpdates;
use Exception;
use InvalidArgumentException;
use ManualLogEntry;
use MediaWiki\Content\ContentHandlerFactory;
use MediaWiki\Extension\EventBus\EventBusFactory;
use MediaWiki\Extension\EventBus\Serializers\EventSerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\PageChangeEventSerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\PageEntitySerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\RevisionEntitySerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\RevisionSlotEntitySerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\UserEntitySerializer;
use MediaWiki\Hook\ArticleRevisionVisibilitySetHook;
use MediaWiki\Hook\PageMoveCompleteHook;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Page\Hook\PageUndeleteCompleteHook;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserGroupManager;
use Psr\Log\LoggerInterface;
use RequestContext;
use TitleFormatter;
use Wikimedia\UUID\GlobalIdGenerator;

/**
 * HookHandler for sending mediawiki/page/change events
 * that represent changes to the current state of how a MediaWiki Page
 * looks to a non-logged-in / anonymous / public user.
 *
 * In MediaWiki, what 'state' is part of the Page is not clearly defined,
 * so we make some choices.
 * - Updates to past revisions (e.g. deleting old revisions) are not included.
 * - Information about editing restrictions are not included.
 * - Content bodies are not included here, although they may be added
 *   in other streams via enrichment.
 */
class PageChangeHooks implements
	PageSaveCompleteHook,
	PageMoveCompleteHook,
	PageDeleteCompleteHook,
	PageUndeleteCompleteHook,
	ArticleRevisionVisibilitySetHook
{

	/**
	 * Key in $mainConfig which will be used to map from EventBus owned 'stream names'
	 * to the names of the stream in EventStreamConfig.
	 * This config can be used to override the name of the stream that this
	 * HookHandler will produce.  This is mostly useful for testing and staging.
	 * NOTE: Logic to look up stream names for EventBus HookHandlers
	 * probably belongs elsewhere.  See README.md for more info.
	 */
	public const STREAM_NAMES_MAP_CONFIG_KEY = 'EventBusStreamNamesMap';

	/**
	 * Key in STREAM_NAMES_MAP_CONFIG_KEY that maps to the name of the stream in EventStreamConfig
	 * that this HookHandler will produce to.  This is mostly useful for testing and staging;
	 * in normal operation this does not need to be set and PAGE_CHANGE_STREAM_NAME_DEFAULT will be used.
	 */
	private const STREAM_NAMES_MAP_PAGE_CHANGE_KEY = 'mediawiki_page_change';

	/**
	 * Default value for $streamName.
	 * Used unless STREAM_NAMES_MAP_PAGE_CHANGE_KEY is set in
	 * STREAM_NAMES_MAP_CONFIG_KEY in $mainConfig.
	 * Note that this is a versioned stream name.
	 * The version suffix should match the stream's schema's major version.
	 * See: https://wikitech.wikimedia.org/wiki/Event_Platform/Stream_Configuration#Stream_versioning
	 */
	public const PAGE_CHANGE_STREAM_NAME_DEFAULT = 'mediawiki.page_change.v1';

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
	 * @param EventBusFactory $eventBusFactory
	 * @param Config $mainConfig
	 * @param GlobalIdGenerator $globalIdGenerator
	 * @param UserGroupManager $userGroupManager
	 * @param TitleFormatter $titleFormatter
	 * @param WikiPageFactory $wikiPageFactory
	 * @param UserFactory $userFactory
	 * @param RevisionStore $revisionStore
	 * @param ContentHandlerFactory $contentHandlerFactory
	 */
	public function __construct(
		EventBusFactory $eventBusFactory,
		Config $mainConfig,
		GlobalIdGenerator $globalIdGenerator,
		UserGroupManager $userGroupManager,
		TitleFormatter $titleFormatter,
		WikiPageFactory $wikiPageFactory,
		UserFactory $userFactory,
		RevisionStore $revisionStore,
		ContentHandlerFactory $contentHandlerFactory
	) {
		$this->logger = LoggerFactory::getInstance( self::class );

		// If EventBusStreamNamesMap is set, then get it out of mainConfig, else use an empty array.
		$streamNamesMap = $mainConfig->has( self::STREAM_NAMES_MAP_CONFIG_KEY ) ?
			$mainConfig->get( self::STREAM_NAMES_MAP_CONFIG_KEY ) : [];
		// Get the name of the page change stream this HookHandler should produce,
		// otherwise use PAGE_CHANGE_STREAM_NAME_DEFAULT
		$this->streamName = $streamNamesMap[self::STREAM_NAMES_MAP_PAGE_CHANGE_KEY]
			?? self::PAGE_CHANGE_STREAM_NAME_DEFAULT;

		$this->eventBusFactory = $eventBusFactory;

		$userEntitySerializer = new UserEntitySerializer( $userFactory, $userGroupManager );

		$this->pageChangeEventSerializer = new PageChangeEventSerializer(
			new EventSerializer( $mainConfig, $globalIdGenerator ),
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
	}

	/**
	 * Sends the events to the stream in a DeferredUPdate via the EventBus
	 * configured for the stream.
	 * NOTE: All events here must be destined to be sent $streamName.
	 * Do not use this function to send a batch of events to different streams.
	 *
	 * @param string $streamName
	 *
	 * @param array $events
	 *        This must be given as a list of events.
	 *
	 * @return void
	 * @throws Exception
	 */
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
	 * @inheritDoc
	 */
	public function onPageSaveComplete(
		$wikiPage,
		$user,
		$summary,
		$flags,
		$revisionRecord,
		$editResult
	) {
		$performer = $this->userFactory->newFromUserIdentity( $user );

		if ( $flags & EDIT_NEW ) {
			// New page state change event for page create
			$event = $this->pageChangeEventSerializer->toCreateEvent(
				$this->streamName,
				$wikiPage,
				$performer,
				$revisionRecord
			);

		} else {
			// New page state change event for page edit
			$event = $this->pageChangeEventSerializer->toEditEvent(
				$this->streamName,
				$wikiPage,
				$performer,
				$revisionRecord,
				$this->revisionStore->getRevisionById( $revisionRecord->getParentId() )
			);
		}

		$this->sendEvents( $this->streamName, [ $event ] );
	}

	/**
	 * @inheritDoc
	 */
	public function onPageMoveComplete(
		$oldTitle,
		$newTitle,
		$user,
		$pageid,
		$redirid,
		$reason,
		$revision
	) {
		$wikiPage = $this->wikiPageFactory->newFromID( $pageid );
		$performer = $this->userFactory->newFromUserIdentity( $user );

		$createdRedirectWikiPage = $redirid ? $this->wikiPageFactory->newFromID( $redirid ) : null;

		// The parentRevision is needed since a page move creates a new revision.
		$parentRevision = $this->revisionStore->getRevisionById( $revision->getParentId() );

		// NOTE: $newTitle not needed by pageChangeEventSerializer,
		//this is obtained via $wikiPage.
		$event = $this->pageChangeEventSerializer->toMoveEvent(
			$this->streamName,
			$wikiPage,
			$performer,
			$revision,
			$parentRevision,
			$oldTitle,
			$reason,
			$createdRedirectWikiPage,
		);

		$this->sendEvents( $this->streamName, [ $event ] );
	}

	// Supercedes ArticleDeleteComplete

	/**
	 * @inheritDoc
	 * @throws Exception
	 */
	public function onPageDeleteComplete(
		ProperPageIdentity $page,
		Authority $deleter,
		string $reason,
		int $pageID,
		RevisionRecord $deletedRev,
		ManualLogEntry $logEntry,
		int $archivedRevisionCount
	) {
		$wikiPage = $this->wikiPageFactory->newFromTitle( $page );
		$performer = $this->userFactory->newFromAuthority( $deleter );

		$event = $this->pageChangeEventSerializer->toDeleteEvent(
			$this->streamName,
			$wikiPage,
			$performer,
			$deletedRev,
			$reason,
			$logEntry->getTimestamp(),
			$archivedRevisionCount,
			$logEntry->getType() === 'suppress'
		);

		$this->sendEvents( $this->streamName, [ $event ] );
	}

	/**
	 * @inheritDoc
	 * @throws Exception
	 */
	public function onPageUndeleteComplete(
		ProperPageIdentity $page,
		Authority $restorer,
		string $reason,
		RevisionRecord $restoredRev,
		ManualLogEntry $logEntry,
		int $restoredRevisionCount,
		bool $created,
		array $restoredPageIds
	): void {
		$wikiPage = $this->wikiPageFactory->newFromTitle( $page );
		$performer = $this->userFactory->newFromAuthority( $restorer );

		// Send page change undelete event
		$event = $this->pageChangeEventSerializer->toUndeleteEvent(
			$this->streamName,
			$wikiPage,
			$performer,
			$restoredRev,
			$reason,
			$logEntry->getTimestamp(),
			$page->getId()
		);

		$this->sendEvents( $this->streamName, [ $event ] );
	}

	/**
	 * @inheritDoc
	 */
	public function onArticleRevisionVisibilitySet(
		$title,
		$revIds,
		$visibilityChangeMap
	) {
		// Only send an event if the visible-ness of the current revision has changed.
		foreach ( $revIds as $revId ) {
			// Read from primary since due to replication lag the updated field visibility
			// might not yet be available on a replica, and we are at risk of leaking
			// just suppressed data.
			$revisionRecord = $this->revisionStore->getRevisionById(
				$revId,
				RevisionStore::READ_LATEST
			);

			if ( $revisionRecord === null ) {
				$this->logger->warning(
					'revision ' . $revId . ' for page ' . $title->getId() .
					' could not be loaded from database and may have been deleted.' .
					' Cannot create visibility change event for ' . $this->streamName . '.'
				);
				continue;
			} elseif ( !array_key_exists( $revId, $visibilityChangeMap ) ) {
				// This should not happen, log it.
				$this->logger->error(
					'revision ' . $revId . ' for page ' . $title->getId() .
					' not found in visibilityChangeMap.' .
					' Cannot create visibility change event for ' . $this->streamName . '.'
				);
				continue;
			}

			// If this is the current revision of the page,
			// then we need to represent the fact that the visibility
			// properties of the current state of the page has changed.
			// Emit a page change visibility_change event.
			if ( $revisionRecord->isCurrent() ) {

				$visibilityChanges = $visibilityChangeMap[$revId];

				// current revision's visibility should be the same as we are given in
				// $visibilityChanges['newBits']. Just in case, assert that this is true.
				if ( $revisionRecord->getVisibility() != $visibilityChanges['newBits'] ) {
					throw new InvalidArgumentException(
						"Current revision $revId's' visibility did not match the expected " .
						'visibility change provided by hook. Current revision visibility is ' .
						$revisionRecord->getVisibility() . '. visibility changed to ' .
						$visibilityChanges['newBits']
					);
				}

				// We only need to emit an event if visibility has actually changed.
				if ( $visibilityChanges['newBits'] === $visibilityChanges['oldBits'] ) {
					$this->logger->warning(
						"onArticleRevisionVisibilitySet called on revision $revId " .
						'when no effective visibility change was made.'
					);
				}

				$wikiPage = $this->wikiPageFactory->newFromTitle( $title );

				// https://phabricator.wikimedia.org/T321411
				$performer = RequestContext::getMain()->getUser();
				$performer->loadFromId();

				$event = $this->pageChangeEventSerializer->toVisibilityChangeEvent(
					$this->streamName,
					$wikiPage,
					$performer,
					$revisionRecord,
					$visibilityChanges['oldBits'],
					// NOTE: ArticleRevisionVisibilitySet hook does not give us a proper event time.
					// The best we can do is use the current timestamp :(
					wfTimestampNow()
				);

				$this->sendEvents( $this->streamName, [ $event ] );
				// No need to search any further for the 'current' revision
				break;
			}
		}
	}
}
