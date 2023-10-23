<?php

/**
 * Hooks for production of events to an HTTP service.
 *
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
 * @author Eric Evans, Andrew Otto
 */

namespace MediaWiki\Extension\EventBus;

use DeferredUpdates;
use ManualLogEntry;
use MediaWiki\Block\DatabaseBlock;
use MediaWiki\ChangeTags\Hook\ChangeTagsAfterUpdateTagsHook;
use MediaWiki\Deferred\LinksUpdate\LinksTable;
use MediaWiki\Deferred\LinksUpdate\LinksUpdate;
use MediaWiki\Hook\ArticleRevisionVisibilitySetHook;
use MediaWiki\Hook\BlockIpCompleteHook;
use MediaWiki\Hook\LinksUpdateCompleteHook;
use MediaWiki\Hook\PageMoveCompleteHook;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\Hook\ArticleProtectCompleteHook;
use MediaWiki\Page\Hook\ArticlePurgeHook;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Page\Hook\PageUndeleteCompleteHook;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\Hook\RevisionRecordInsertedHook;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\EditResult;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;
use RecentChange;
use RequestContext;
use User;
use Wikimedia\Assert\Assert;
use WikiPage;

/**
 * @deprecated since EventBus 0.5.0 Use specific feature based hooks in HookHandlers/,
 * 	or even better, put them in your own extension instead of in EventBus.
 */
class EventBusHooks implements
	PageSaveCompleteHook,
	PageMoveCompleteHook,
	PageDeleteCompleteHook,
	PageUndeleteCompleteHook,
	ArticleRevisionVisibilitySetHook,
	ArticlePurgeHook,
	BlockIpCompleteHook,
	LinksUpdateCompleteHook,
	ArticleProtectCompleteHook,
	ChangeTagsAfterUpdateTagsHook,
	RevisionRecordInsertedHook
{

	/**
	 * @return RevisionLookup
	 */
	private static function getRevisionLookup() {
		return MediaWikiServices::getInstance()->getRevisionLookup();
	}

	/**
	 * Creates and sends a single resource_change event to EventBus
	 *
	 * @param LinkTarget $title article title object
	 * @param array $tags the array of tags to use in the event
	 */
	private static function sendResourceChangedEvent(
		LinkTarget $title,
		array $tags
	) {
		$stream = 'resource_change';
		$eventbus = EventBus::getInstanceForStream( $stream );
		$event = $eventbus->getFactory()->createResourceChangeEvent( $stream, $title, $tags );

		DeferredUpdates::addCallableUpdate( static function () use ( $eventbus, $event ) {
			$eventbus->send( [ $event ] );
		} );
	}

	/**
	 * Occurs after the delete article request has been processed.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ArticleDeleteComplete
	 *
	 * @param ProperPageIdentity $page Page that was deleted.
	 * @param Authority $deleter Who deleted the page
	 * @param string $reason Reason the page was deleted
	 * @param int $pageID ID of the page that was deleted
	 * @param RevisionRecord $deletedRev Last revision of the deleted page
	 * @param ManualLogEntry $logEntry ManualLogEntry used to record the deletion
	 * @param int $archivedRevisionCount Number of revisions archived during the deletion
	 * @return true|void
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
		$stream = $logEntry->getType() === 'suppress' ?
			'mediawiki.page-suppress' : 'mediawiki.page-delete';
		$eventbus = EventBus::getInstanceForStream( $stream );

		$eventBusFactory = $eventbus->getFactory();
		$eventBusFactory->setCommentFormatter( MediaWikiServices::getInstance()->getCommentFormatter() );
		$title = Title::castFromPageIdentity( $page );
		Assert::postcondition( $title !== null, '$page can be cast to a LinkTarget' );
		$event = $eventBusFactory->createPageDeleteEvent(
			$stream,
			$deleter->getUser(),
			$pageID,
			$title,
			$title->isRedirect(),
			$archivedRevisionCount,
			$deletedRev,
			$reason
		);

		DeferredUpdates::addCallableUpdate(
			static function () use ( $eventbus, $event ) {
				$eventbus->send( [ $event ] );
			}
		);
	}

	/**
	 * When one or more revisions of an article are restored.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/PageUndeleteComplete
	 *
	 * @param ProperPageIdentity $page Page that was undeleted.
	 * @param Authority $restorer Who undeleted the page
	 * @param string $reason Reason the page was undeleted
	 * @param RevisionRecord $restoredRev Last revision of the undeleted page
	 * @param ManualLogEntry $logEntry Log entry generated by the restoration
	 * @param int $restoredRevisionCount Number of revisions restored during the deletion
	 * @param bool $created Whether the undeletion result in a page being created
	 * @param array $restoredPageIds Array of all undeleted page IDs.
	 *        This will have multiple page IDs if there was more than one deleted page with the same page title.
	 * @return void This hook must not abort, it must return no value
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
		$stream = 'mediawiki.page-undelete';
		$eventBus = EventBus::getInstanceForStream( $stream );
		$eventBusFactory = $eventBus->getFactory();
		$eventBusFactory->setCommentFormatter( MediaWikiServices::getInstance()->getCommentFormatter() );
		$event = $eventBusFactory->createPageUndeleteEvent(
			$stream,
			$restorer->getUser(),
			// @phan-suppress-next-line PhanTypeMismatchArgumentNullable PageIdentity is not null
			Title::castFromPageIdentity( $page ),
			$reason,
			$page->getId(),
			$restoredRev,
		);

		DeferredUpdates::addCallableUpdate( static function () use ( $eventBus, $event ) {
			$eventBus->send( [ $event ] );
		} );
	}

	/**
	 * Occurs whenever a request to move an article is completed.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/PageMoveComplete
	 *
	 * @param LinkTarget $oldTitle the old title
	 * @param LinkTarget $newTitle the new title
	 * @param UserIdentity $userIdentity User who did the move
	 * @param int $pageid database page_id of the page that's been moved
	 * @param int $redirid database page_id of the created redirect, or 0 if suppressed
	 * @param string $reason reason for the move
	 * @param RevisionRecord $newRevisionRecord revision created by the move
	 */
	public function onPageMoveComplete(
		$oldTitle,
		$newTitle,
		$userIdentity,
		$pageid,
		$redirid,
		$reason,
		$newRevisionRecord
	) {
		$stream = 'mediawiki.page-move';
		$eventBus = EventBus::getInstanceForStream( $stream );
		$eventBusFactory = $eventBus->getFactory();
		$eventBusFactory->setCommentFormatter( MediaWikiServices::getInstance()->getCommentFormatter() );
		$event = $eventBusFactory->createPageMoveEvent(
			$stream,
			$oldTitle,
			$newTitle,
			$newRevisionRecord,
			$userIdentity,
			$reason
		);

		DeferredUpdates::addCallableUpdate(
			static function () use ( $eventBus, $event ) {
				$eventBus->send( [ $event ] );
			}
		);
	}

	/**
	 * Called when changing visibility of one or more revisions of an article.
	 * Produces mediawiki.revision-visibility-change events.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ArticleRevisionVisibilitySet
	 *
	 * @param Title $title title object of the article
	 * @param array $revIds array of integer revision IDs
	 * @param array $visibilityChangeMap map of revision id to oldBits and newBits.
	 *              This array can be examined to determine exactly what visibility
	 *              bits have changed for each revision.  This array is of the form
	 *              [id => ['oldBits' => $oldBits, 'newBits' => $newBits], ... ]
	 */
	public function onArticleRevisionVisibilitySet(
		$title,
		$revIds,
		$visibilityChangeMap
	) {
		$stream = 'mediawiki.revision-visibility-change';
		$events = [];
		$eventBus = EventBus::getInstanceForStream( $stream );
		// https://phabricator.wikimedia.org/T321411
		$performer = RequestContext::getMain()->getUser();
		$performer->loadFromId();

		// Create an event for each revId that was changed.
		foreach ( $revIds as $revId ) {
			// Read from primary since due to replication lag the updated field visibility
			// might still not be available on a replica and we are at risk of leaking
			// just suppressed data.
			$revision = self::getRevisionLookup()
				->getRevisionById( $revId, RevisionLookup::READ_LATEST );

			// If the page is deleted simultaneously (null $revision) or if
			// this revId is not in the $visibilityChangeMap, then we can't
			// send a meaningful event.
			if ( $revision === null ) {
				wfDebug(
					__METHOD__ . ' revision ' . $revId .
					' could not be found and may have been deleted. Cannot ' .
					"create mediawiki/revision/visibility-change event.\n"
				);
				continue;
			} elseif ( !array_key_exists( $revId, $visibilityChangeMap ) ) {
				// This should not happen, log it.
				wfDebug(
					__METHOD__ . ' revision id ' . $revId .
					' not found in visibilityChangeMap. Cannot create ' .
					"mediawiki/revision/visibility-change event.\n"
				);
				continue;
			} else {
				$eventBusFactory = $eventBus->getFactory();
				$eventBusFactory->setCommentFormatter( MediaWikiServices::getInstance()->getCommentFormatter() );

				// If this revision is 'suppressed' AKA restricted, then the person performing
				// 'RevisionDelete' should not be visible in public data.
				// https://phabricator.wikimedia.org/T342487
				//
				// NOTE: This event stream tries to match the visibility of MediaWiki core logs,
				// where regular delete/revision events are public, and suppress/revision events
				// are private. In MediaWiki core logs, private events are fully hidden from
				// the public.  Here, we need to produce a 'private' event to the
				// mediawiki.revision-visibility-change stream, to indicate to consumers that
				// they should also 'suppress' the revision.  When this is done, we need to
				// make sure that we do not reproduce the data that has been suppressed
				// in the event itself.  E.g. if the username of the editor of the revision has been
				// suppressed, we should not include any information about that editor in the event.
				$performerForEvent =
					$visibilityChangeMap[$revId]['newBits'] & RevisionRecord::DELETED_RESTRICTED ?
					null :
					$performer;

				$events[] = $eventBusFactory->createRevisionVisibilityChangeEvent(
					$stream,
					$revision,
					$performerForEvent,
					$visibilityChangeMap[$revId]
				);
			}
		}

		if ( $events === [] ) {
			// For revision-visibility-set it's possible that
			// the page was deleted simultaneously and we can not
			// send a meaningful event.
			return;
		}

		DeferredUpdates::addCallableUpdate(
			static function () use ( $eventBus, $events ) {
				$eventBus->send( $events );
			}
		);
	}

	/**
	 * Callback for article purge.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ArticlePurge
	 *
	 * @param WikiPage $wikiPage
	 */
	public function onArticlePurge( $wikiPage ) {
		self::sendResourceChangedEvent( $wikiPage->getTitle(), [ 'purge' ] );
	}

	/**
	 * Occurs after the save page request has been processed.
	 *
	 * Sends an event if the new revision was also a page creation
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/PageSaveComplete
	 *
	 * @param WikiPage $wikiPage
	 * @param UserIdentity $userIdentity
	 * @param string $summary
	 * @param int $flags
	 * @param RevisionRecord $revisionRecord
	 * @param EditResult $editResult
	 */
	public function onPageSaveComplete(
		$wikiPage,
		$userIdentity,
		$summary,
		$flags,
		$revisionRecord,
		$editResult
	) {
		if ( $editResult->isNullEdit() ) {
			self::sendResourceChangedEvent( $wikiPage->getTitle(), [ 'null_edit' ] );
			return;
		}

		if ( $flags & EDIT_NEW ) {
			// Not just a new revision, but a new page
			self::sendRevisionCreateEvent(
				'mediawiki.page-create',
				$revisionRecord
			);
		}
	}

	/**
	 * Occurs after a revision is inserted into the database.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/RevisionRecordInserted
	 *
	 * @param RevisionRecord $revisionRecord RevisionRecord that has just been inserted
	 */
	public function onRevisionRecordInserted( $revisionRecord ) {
		self::sendRevisionCreateEvent(
			'mediawiki.revision-create',
			$revisionRecord
		);
	}

	/**
	 * @param string $stream
	 * @param RevisionRecord $revisionRecord
	 */
	private static function sendRevisionCreateEvent(
		string $stream,
		RevisionRecord $revisionRecord
	) {
		$eventBus = EventBus::getInstanceForStream( $stream );
		$eventBusFactory = $eventBus->getFactory();
		$eventBusFactory->setCommentFormatter(
			MediaWikiServices::getInstance()->getCommentFormatter()
		);
		$event = $eventBusFactory->createRevisionCreateEvent(
			$stream,
			$revisionRecord
		);

		DeferredUpdates::addCallableUpdate(
			static function () use ( $eventBus, $event ) {
				$eventBus->send( [ $event ] );
			}
		);
	}

	/**
	 * Occurs after the request to block an IP or user has been processed
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/BlockIpComplete
	 *
	 * @param DatabaseBlock $block the block object that was saved
	 * @param User $user the user who did the block (not the one being blocked)
	 * @param DatabaseBlock|null $previousBlock the previous block state for the block target.
	 *        null if this is a new block target.
	 */
	public function onBlockIpComplete(
		$block,
		$user,
		$previousBlock
	) {
		$stream = 'mediawiki.user-blocks-change';
		$eventBus = EventBus::getInstanceForStream( 'mediawiki.user-blocks-change' );
		$eventFactory = $eventBus->getFactory();
		$event = $eventFactory->createUserBlockChangeEvent(
			$stream, $user, $block, $previousBlock );

		DeferredUpdates::addCallableUpdate(
			static function () use ( $eventBus, $event ) {
				$eventBus->send( [ $event ] );
			}
		);
	}

	/**
	 * Sends page-properties-change and page-links-change events
	 *
	 * Emits two events separately: one when the page properties change, and
	 * the other when links are added to or removed from the page.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/LinksUpdateComplete
	 *
	 * @param LinksUpdate $linksUpdate the update object
	 * @param mixed $ticket
	 */
	public function onLinksUpdateComplete(
		$linksUpdate, $ticket
	) {
		$addedProps = $linksUpdate->getAddedProperties();
		$removedProps = $linksUpdate->getRemovedProperties();
		$arePropsEmpty = !$removedProps && !$addedProps;

		$addedLinks = $linksUpdate->getPageReferenceArray( 'pagelinks', LinksTable::INSERTED );
		$addedExternalLinks = $linksUpdate->getAddedExternalLinks();
		$removedLinks = $linksUpdate->getPageReferenceArray( 'pagelinks', LinksTable::DELETED );
		$removedExternalLinks = $linksUpdate->getRemovedExternalLinks();
		$areLinksEmpty = !$removedLinks && !$addedLinks
			&& !$removedExternalLinks && !$addedExternalLinks;

		if ( $arePropsEmpty && $areLinksEmpty ) {
			return;
		}

		$title = $linksUpdate->getTitle();
		$user = $linksUpdate->getTriggeringUser();

		// Use triggering revision's rev_id if it is set.
		// If the LinksUpdate didn't have a triggering revision
		// (probably because it was triggered by sysadmin maintenance).
		// Use the page's latest revision.
		$revRecord = $linksUpdate->getRevisionRecord();
		if ( $revRecord ) {
			$revId = $revRecord->getId();
		} else {
			$revId = $title->getLatestRevID();
		}
		$pageId = $linksUpdate->getPageId();

		if ( !$arePropsEmpty ) {
			$stream = 'mediawiki.page-properties-change';
			$eventBus = EventBus::getInstanceForStream( $stream );
			$eventFactory = $eventBus->getFactory();
			$propEvent = $eventFactory->createPagePropertiesChangeEvent(
				$stream,
				$title,
				$addedProps,
				$removedProps,
				$user,
				$revId,
				$pageId
			);

			DeferredUpdates::addCallableUpdate(
				static function () use ( $eventBus, $propEvent ) {
					$eventBus->send( [ $propEvent ] );
				}
			);
		}

		if ( !$areLinksEmpty ) {
			$stream = 'mediawiki.page-properties-change';
			$eventBus = EventBus::getInstanceForStream( $stream );
			$eventFactory = $eventBus->getFactory();
			$linkEvent = $eventFactory->createPageLinksChangeEvent(
				'mediawiki.page-links-change',
				$title,
				$addedLinks,
				$addedExternalLinks,
				$removedLinks,
				$removedExternalLinks,
				$user,
				$revId,
				$pageId
			);

			DeferredUpdates::addCallableUpdate(
				static function () use ( $eventBus, $linkEvent ) {
					$eventBus->send( [ $linkEvent ] );
				}
			);
		}
	}

	/**
	 * Sends a page-restrictions-change event
	 *
	 * @param WikiPage $wikiPage the article which restrictions were changed
	 * @param User $user the user who have changed the article
	 * @param string[] $protect set of new restrictions details
	 * @param string $reason the reason for page protection
	 */
	public function onArticleProtectComplete(
		$wikiPage,
		$user,
		$protect,
		$reason
	) {
		$stream = 'mediawiki.page-restrictions-change';
		$eventBus = EventBus::getInstanceForStream( $stream );
		$eventFactory = $eventBus->getFactory();

		$event = $eventFactory->createPageRestrictionsChangeEvent(
			$stream,
			$user,
			$wikiPage->getTitle(),
			$wikiPage->getId(),
			$wikiPage->getRevisionRecord(),
			$wikiPage->isRedirect(),
			$reason,
			$protect
		);

		DeferredUpdates::addCallableUpdate(
			static function () use ( $eventBus, $event ) {
				$eventBus->send( [ $event ] );
			}
		);
	}

	/** Called after tags have been updated with the ChangeTags::updateTags function.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ChangeTagsAfterUpdateTags
	 *
	 * @param array $addedTags tags effectively added in the update
	 * @param array $removedTags tags effectively removed in the update
	 * @param array $prevTags tags that were present prior to the update
	 * @param int|null $rc_id recentchanges table id
	 * @param int|null $rev_id revision table id
	 * @param int|null $log_id logging table id
	 * @param string|null $params tag params
	 * @param RecentChange|null $rc RecentChange being tagged when the tagging accompanies
	 * the action, or null
	 * @param User|null $user User who performed the tagging when the tagging is subsequent
	 * to the action, or null
	 */
	public function onChangeTagsAfterUpdateTags(
		$addedTags,
		$removedTags,
		$prevTags,
		$rc_id,
		$rev_id,
		$log_id,
		$params,
		$rc,
		$user
	) {
		if ( $rev_id === null ) {
			// We're only interested for revision (edits) tags for now.
			return;
		}

		$revisionRecord = self::getRevisionLookup()->getRevisionById( $rev_id );
		if ( $revisionRecord === null ) {
			// Revision might already have been deleted, so we're not interested in tagging those.
			return;
		}

		$stream = 'mediawiki.revision-tags-change';
		$eventBus = EventBus::getInstanceForStream( $stream );
		$eventBusFactory = $eventBus->getFactory();
		$eventBusFactory->setCommentFormatter( MediaWikiServices::getInstance()->getCommentFormatter() );
		$event = $eventBusFactory->createRevisionTagsChangeEvent(
			$stream,
			$revisionRecord,
			$prevTags,
			$addedTags,
			$removedTags,
			$user
		);

		DeferredUpdates::addCallableUpdate(
			static function () use ( $eventBus, $event ) {
				$eventBus->send( [ $event ] );
			}
		);
	}

}
