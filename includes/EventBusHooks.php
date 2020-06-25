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

use Campaign;
use Content;
use DeferredUpdates;
use LinksUpdate;
use ManualLogEntry;
use MediaWiki\Block\DatabaseBlock;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\EditResult;
use MediaWiki\User\UserIdentity;
use RecentChange;
use RequestContext;
use Revision;
use Title;
use UnexpectedValueException;
use User;
use WikiPage;

class EventBusHooks {

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

		DeferredUpdates::addCallableUpdate( function () use ( $eventbus, $event ) {
			$eventbus->send( [ $event ] );
		} );
	}

	/**
	 * Occurs after the delete article request has been processed.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ArticleDeleteComplete
	 *
	 * @param WikiPage $wikiPage the WikiPage that was deleted
	 * @param User $user the user that deleted the article
	 * @param string $reason the reason the article was deleted
	 * @param int $id the ID of the article that was deleted
	 * @param Content|null $content the content of the deleted article, or null in case of error
	 * @param ManualLogEntry $logEntry the log entry used to record the deletion
	 * @param int $archivedRevisionCount the number of revisions archived during the page delete
	 */
	public static function onArticleDeleteComplete(
		WikiPage $wikiPage,
		User $user,
		$reason,
		$id,
		?Content $content,
		ManualLogEntry $logEntry,
		$archivedRevisionCount
	) {
		$stream = $logEntry->getType() === 'suppress' ?
			'mediawiki.page-suppress' : 'mediawiki.page-delete';
		$eventbus = EventBus::getInstanceForStream( $stream );

		$event = $eventbus->getFactory()->createPageDeleteEvent(
			$stream,
			$user,
			$id,
			$wikiPage->getTitle(),
			$wikiPage->isRedirect(),
			$archivedRevisionCount,
			$wikiPage->getRevisionRecord(),
			$reason
		);

		DeferredUpdates::addCallableUpdate(
			function () use ( $eventbus, $event ) {
				$eventbus->send( [ $event ] );
			}
		);
	}

	/**
	 * When one or more revisions of an article are restored.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ArticleUndelete
	 *
	 * @param Title $title title corresponding to the article restored
	 * @param bool $create whether the restoration caused the page to be created
	 * @param string $comment comment explaining the undeletion
	 * @param int $oldPageId ID of page previously deleted (from archive table)
	 */
	public static function onArticleUndelete(
		Title $title,
		$create,
		$comment,
		$oldPageId
	) {
		$stream = 'mediawiki.page-undelete';
		$performer = RequestContext::getMain()->getUser();

		$eventBus = EventBus::getInstanceForStream( $stream );
		$event = $eventBus->getFactory()->createPageUndeleteEvent(
			$stream,
			$performer,
			$title,
			$comment,
			$oldPageId
		);

		DeferredUpdates::addCallableUpdate( function () use ( $eventBus, $event ) {
			$eventBus->send( [ $event ] );
		} );
	}

	/**
	 * Occurs whenever a request to move an article is completed.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/TitleMoveComplete
	 *
	 * @param Title $oldTitle the old title
	 * @param Title $newTitle the new title
	 * @param User $user User who did the move
	 * @param int $pageid database page_id of the page that's been moved
	 * @param int $redirid database page_id of the created redirect, or 0 if suppressed
	 * @param string $reason reason for the move
	 * @param Revision $newRevision revision created by the move
	 */
	public static function onTitleMoveComplete(
		Title $oldTitle,
		Title $newTitle,
		User $user,
		$pageid,
		$redirid,
		$reason,
		Revision $newRevision
	) {
		$stream = 'mediawiki.page-move';
		$eventBus = EventBus::getInstanceForStream( $stream );
		$event = $eventBus->getFactory()->createPageMoveEvent(
			$stream,
			$oldTitle,
			$newTitle,
			$newRevision->getRevisionRecord(),
			$user,
			$reason
		);

		DeferredUpdates::addCallableUpdate(
			function () use ( $eventBus, $event ) {
				$eventBus->send( [ $event ] );
			}
		);
	}

	/**
	 * Called when changing visibility of one or more revisions of an article.
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
	public static function onArticleRevisionVisibilitySet(
		Title $title,
		array $revIds,
		array $visibilityChangeMap
	) {
		$stream = 'mediawiki.revision-visibility-change';
		$events = [];
		$eventBus = EventBus::getInstanceForStream( $stream );
		$performer = RequestContext::getMain()->getUser();
		$performer->loadFromId();

		// Create a  event
		// for each revId that was changed.
		foreach ( $revIds as $revId ) {
			// Create the mediawiki/revision/visibility-change event
			$revision = self::getRevisionLookup()->getRevisionById( $revId );

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
				$events[] = $eventBus->getFactory()->createRevisionVisibilityChangeEvent(
					$stream,
					$revision,
					$performer,
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
			function () use ( $eventBus, $events ) {
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
	public static function onArticlePurge( WikiPage $wikiPage ) {
		self::sendResourceChangedEvent( $wikiPage->getTitle(), [ 'purge' ] );
	}

	/**
	 * Occurs after the save page request has been processed.
	 *
	 * Sends two events if the new revision was also a page creation
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
	public static function onPageSaveComplete(
		WikiPage $wikiPage,
		UserIdentity $userIdentity,
		string $summary,
		int $flags,
		RevisionRecord $revisionRecord,
		EditResult $editResult
	) {
		if ( $editResult->isNullEdit() ) {
			self::sendResourceChangedEvent( $wikiPage->getTitle(), [ 'null_edit' ] );
			return;
		}

		self::sendRevisionCreateEvent( 'mediawiki.revision-create', $revisionRecord );

		if ( $flags & EDIT_NEW ) {
			// Not just a new revision, but a new page
			self::sendRevisionCreateEvent( 'mediawiki.page-create', $revisionRecord );
		}
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
		$event = $eventBus->getFactory()->createRevisionCreateEvent(
			$stream,
			$revisionRecord
		);

		DeferredUpdates::addCallableUpdate(
			function () use ( $eventBus, $event ) {
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
	public static function onBlockIpComplete(
		DatabaseBlock $block,
		User $user,
		?DatabaseBlock $previousBlock
	) {
		$stream = 'mediawiki.user-blocks-change';
		$eventBus = EventBus::getInstanceForStream( 'mediawiki.user-blocks-change' );
		$eventFactory = $eventBus->getFactory();
		$event = $eventFactory->createUserBlockChangeEvent(
			$stream, $user, $block, $previousBlock );

		DeferredUpdates::addCallableUpdate(
			function () use ( $eventBus, $event ) {
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
	 */
	public static function onLinksUpdateComplete(
		LinksUpdate $linksUpdate
	) {
		$addedProps = $linksUpdate->getAddedProperties();
		$removedProps = $linksUpdate->getRemovedProperties();
		$arePropsEmpty = empty( $removedProps ) && empty( $addedProps );

		$addedLinks = $linksUpdate->getAddedLinks();
		$addedExternalLinks = $linksUpdate->getAddedExternalLinks();
		$removedLinks = $linksUpdate->getRemovedLinks();
		$removedExternalLinks = $linksUpdate->getRemovedExternalLinks();
		$areLinksEmpty = empty( $removedLinks ) && empty( $addedLinks )
			&& empty( $removedExternalLinks ) && empty( $addedExternalLinks );

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
		$pageId = $linksUpdate->mId;

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
				function () use ( $eventBus, $propEvent ) {
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
				function () use ( $eventBus, $linkEvent ) {
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
	public static function onArticleProtectComplete(
		WikiPage $wikiPage,
		User $user,
		array $protect,
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
			function () use ( $eventBus, $event ) {
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
	public static function onChangeTagsAfterUpdateTags(
		array $addedTags,
		array $removedTags,
		array $prevTags,
		$rc_id,
		$rev_id,
		$log_id,
		$params,
		?RecentChange $rc,
		?User $user
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
		$event = $eventBus->getFactory()->createRevisionTagsChangeEvent(
			$stream,
			$revisionRecord,
			$prevTags,
			$addedTags,
			$removedTags,
			$user
		);

		DeferredUpdates::addCallableUpdate(
			function () use ( $eventBus, $event ) {
				$eventBus->send( [ $event ] );
			}
		);
	}

	/**
	 * Handle CentralNoticeCampaignChange hook. Send an event corresponding to the type
	 * of campaign change made (create, change or delete).
	 *
	 * This method is only expected to be called if CentralNotice is installed.
	 *
	 * @see https://www.mediawiki.org/wiki/Extension:CentralNotice/CentralNoticeCampaignChange
	 *
	 * @param string $changeType Type of change performed. Can be 'created', 'modified',
	 *   or 'removed'.
	 * @param string $time The time of the change. This is the same time that will be
	 *   recorded for the change in the cn_notice_log table.
	 * @param string $campaignName Name of the campaign created, modified or removed.
	 * @param User $user The user who performed the change.
	 * @param array|null $beginSettings Campaign settings before the change, if applicable.
	 *   These will include start, end, enabled, archived and banners. If not applicable,
	 *   this parameter will be null.
	 * @param array|null $endSettings Campaign settings after the change, if applicable.
	 *   These will include start, end, enabled, archived and banners. If not applicable,
	 *   this parameter will be null.
	 * @param string $summary Change summary provided by the user, or empty string if none
	 *   was provided.
	 */
	public static function onCentralNoticeCampaignChange(
		$changeType,
		$time,
		$campaignName,
		User $user,
		?array $beginSettings,
		?array $endSettings,
		$summary
	) {
		// Since we're running this hook, we'll assume that CentralNotice is installed.
		$campaignUrl = Campaign::getCanonicalURL( $campaignName );

		switch ( $changeType ) {
			case 'created':
				if ( !$endSettings ) {
					return;
				}

				$stream = 'mediawiki.centralnotice.campaign-create';
				$eventBus = EventBus::getInstanceForStream( $stream );
				$eventFactory = $eventBus->getFactory();
				$event = $eventFactory->createCentralNoticeCampaignCreateEvent(
					$stream,
					$campaignName,
					$user,
					$endSettings,
					$summary,
					$campaignUrl
				);
				break;

			case 'modified':
				if ( !$endSettings ) {
					return;
				}

				$stream = 'mediawiki.centralnotice.campaign-change';
				$eventBus = EventBus::getInstanceForStream( $stream );
				$eventFactory = $eventBus->getFactory();
				$event = $eventFactory->createCentralNoticeCampaignChangeEvent(
					$stream,
					$campaignName,
					$user,
					$endSettings,
					$beginSettings ?: [],
					$summary,
					$campaignUrl
				);
				break;

			case 'removed':
				$stream = 'mediawiki.centralnotice.campaign-delete';
				$eventBus = EventBus::getInstanceForStream( $stream );
				$eventFactory = $eventBus->getFactory();
				$event = $eventFactory->createCentralNoticeCampaignDeleteEvent(
					$stream,
					$campaignName,
					$user,
					$beginSettings ?: [],
					$summary,
					$campaignUrl
				);
				break;

			default:
				throw new UnexpectedValueException(
					'Bad CentralNotice change type: ' . $changeType );
		}

		DeferredUpdates::addCallableUpdate(
			function () use ( $eventBus, $event ) {
				$eventBus->send( [ $event ] );
			}
		);
	}

}
