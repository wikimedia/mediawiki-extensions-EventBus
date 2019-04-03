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

use MediaWiki\Linker\LinkTarget;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionLookup;

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
	 * @param LinkTarget $title  article title object
	 * @param array $tags the array of tags to use in the event
	 */
	private static function sendResourceChangedEvent(
		LinkTarget $title,
		array $tags
	) {
		$eventbus = EventBus::getInstance( 'eventbus' );
		$events[] = $eventbus->getFactory()->createResourceChangeEvent( $title, $tags );

		DeferredUpdates::addCallableUpdate( function () use ( $eventbus, $events ) {
			$eventbus->send( $events );
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
		Content $content = null,
		ManualLogEntry $logEntry,
		$archivedRevisionCount
	) {
		$eventbus = EventBus::getInstance( 'eventbus' );

		$events[] = $eventbus->getFactory()->createPageDeleteEvent(
			$user,
			$id,
			$wikiPage->getTitle(),
			$wikiPage->isRedirect(),
			$archivedRevisionCount,
			$wikiPage->getRevision(),
			$reason
		);

		DeferredUpdates::addCallableUpdate(
			function () use ( $eventbus, $events ) {
				$eventbus->send( $events );
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
		$performer = RequestContext::getMain()->getUser();

		$eventBus = EventBus::getInstance( 'eventbus' );
		$events[] = $eventBus->getFactory()->createPageUndeleteEvent(
			$performer,
			$title,
			$oldPageId,
			$comment
		);

		DeferredUpdates::addCallableUpdate( function () use ( $eventBus, $events ) {
			EventBus::getInstance( 'eventbus' )->send( $events );
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
		$eventBus = EventBus::getInstance( 'eventbus' );
		$events[] = $eventBus->getFactory()->createPageMoveEvent(
			$oldTitle,
			$newTitle,
			$newRevision->getRevisionRecord(),
			$user,
			$reason
		);

		DeferredUpdates::addCallableUpdate(
			function () use ( $eventBus, $events ) {
				$eventBus->send( $events );
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
		$eventBus = EventBus::getInstance( 'eventbus' );
		$performer = RequestContext::getMain()->getUser();
		$performer->loadFromId();

		// Create a  event
		// for each revId that was changed.
		foreach ( $revIds as $revId ) {
			// Create the mediawiki/revision/visibilty-change event
			$revision = self::getRevisionLookup()->getRevisionById( $revId );

			// If the page is deleted simultaneously (null $revision) or if
			// this revId is not in the $visibilityChangeMap, then we can't
			// send a meaningful event.
			if ( is_null( $revision ) ) {
				wfDebug(
					__METHOD__ . ' revision ' . $revId .
					' could not be found and may have been deleted. Cannot ' .
					"create mediawiki/revision/visibility-change event.\n"
				);
				continue;
			} elseif ( !array_key_exists( $revId, $visibilityChangeMap ) ) {
				// This shoudln't happen, log it.
				wfDebug(
					__METHOD__ . ' revision id ' . $revId .
					' not found in visibilityChangeMap. Cannot create ' .
					"mediawiki/revision/visibility-change event.\n"
				);
				continue;
			} else {
				$events[] = $eventBus->getFactory()->createRevisionVisibilityChangeEvent(
					$revision,
					$performer,
					$visibilityChangeMap[$revId]
				);
			}
		}

		if ( empty( $events ) ) {
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
	 * Occurs after the insert page request has been processed.  This is a page creation event.
	 * Since page creation is really just a special case of revision create, this event
	 * re-uses the mediawiki/revision/create schema.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/PageContentInsertComplete
	 *
	 * @param WikiPage $article
	 * @param User $user
	 * @param Content $content
	 * @param string $summary
	 * @param bool $isMinor
	 * @param bool|null $isWatch
	 * @param string|null $section Deprecated
	 * @param int $flags
	 * @param Revision|null $revision
	 */
	public static function onPageContentInsertComplete(
		WikiPage $article,
		User $user,
		Content $content,
		$summary,
		$isMinor,
		$isWatch,
		$section,
		$flags,
		Revision $revision = null
	) {
		if ( is_null( $revision ) ) {
			wfDebug(
				__METHOD__ . ' new revision during PageContentInsertComplete for page_id: ' .
				$article->getId() . ' page_title: ' . $article->getTitle() .
				' is null.  Cannot create mediawiki/revision/create event.'
			);
			return;
		}

		$eventBus = EventBus::getInstance( 'eventbus' );
		$events[] = $eventBus->getFactory()->createPageCreateEvent(
			$revision->getRevisionRecord(),
			$revision->getTitle()
		);

		DeferredUpdates::addCallableUpdate(
			function () use ( $eventBus, $events ) {
				$eventBus->send( $events );
			}
		);
	}

	/**
	 * Occurs after the save page request has been processed.
	 *
	 * It's used to detect null edits and create 'resource_change' events for purges.
	 * Actual edits are detected by the RevisionInsertComplete hook.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/PageContentSaveComplete
	 *
	 * @param WikiPage $wikiPage
	 * @param User $user
	 * @param Content $content
	 * @param string $summary
	 * @param bool $isMinor
	 * @param bool|null $isWatch
	 * @param string|null $section Deprecated
	 * @param int $flags
	 * @param Revision|null $revision
	 * @param Status $status
	 * @param int $baseRevId
	 */
	public static function onPageContentSaveComplete(
		Wikipage $wikiPage,
		User $user,
		Content $content,
		$summary,
		$isMinor,
		$isWatch,
		$section,
		$flags,
		Revision $revision = null,
		Status $status,
		$baseRevId
	) {
		// In case of a null edit the status revision value will be null
		if ( is_null( $status->getValue()['revision'] ) ) {
			self::sendResourceChangedEvent( $wikiPage->getTitle(), [ 'null_edit' ] );
			return;
		}

		if ( is_null( $revision ) ) {
			wfDebug(
				__METHOD__ . ' new revision during PageContentSaveComplete ' .
				' is null.  Cannot create mediawiki/revision/create event.'
			);
			return;
		}

		$eventBus = EventBus::getInstance( 'eventbus' );
		$events[] = $eventBus->getFactory()->createRevisionCreateEvent(
			$revision->getRevisionRecord()
		);

		DeferredUpdates::addCallableUpdate(
			function () use ( $eventBus, $events ) {
				$eventBus->send( $events );
			}
		);
	}

	/**
	 * Occurs after the request to block an IP or user has been processed
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/BlockIpComplete
	 *
	 * @param Block $block the Block object that was saved
	 * @param User $user the user who did the block (not the one being blocked)
	 * @param Block|null $previousBlock the previous block state for the block target.
	 *        null if this is a new block target.
	 */
	public static function onBlockIpComplete(
		Block $block,
		User $user,
		Block $previousBlock = null
	) {
		$eventBus = EventBus::getInstance( 'eventbus' );
		$eventFactory = $eventBus->getFactory();
		$events[] = $eventFactory->createUserBlockChangeEvent( $user, $block, $previousBlock );

		DeferredUpdates::addCallableUpdate(
			function () use ( $eventBus, $events ) {
				$eventBus->send( $events );
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
		$revision = $linksUpdate->getRevision();
		if ( $revision ) {
			$revId = $revision->getId();
		} else {
			$revId = $title->getLatestRevID();
		}
		$pageId = $linksUpdate->mId;

		$eventBus = EventBus::getInstance( 'eventbus' );
		$eventFactory = $eventBus->getFactory();

		if ( !$arePropsEmpty ) {
			$propEvents[] = $eventFactory->createPagePropertiesChangeEvent(
				$title,
				$addedProps,
				$removedProps,
				$user,
				$revId,
				$pageId
			);

			DeferredUpdates::addCallableUpdate(
				function () use ( $eventBus, $propEvents ) {
					$eventBus->send( $propEvents );
				}
			);
		}

		if ( !$areLinksEmpty ) {
			$linkEvents[] = $eventFactory->createPageLinksChangeEvent(
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
				function () use ( $eventBus, $linkEvents ) {
					$eventBus->send( $linkEvents );
				}
			);
		}
	}

	/**
	 * Sends a page-restrictions-change event
	 *
	 * @param WikiPage $wikiPage the article which restrictions were changed
	 * @param User $user the user who have changed the article
	 * @param array $protect set of new restrictions details
	 * @param string $reason the reason for page protection
	 */
	public static function onArticleProtectComplete(
		Wikipage $wikiPage,
		User $user,
		array $protect,
		$reason
	) {
		$eventBus = EventBus::getInstance( 'eventbus' );
		$eventFactory = $eventBus->getFactory();

		$events[] = $eventFactory->createPageRestrictionsChangeEvent(
			$user,
			$wikiPage->getTitle(),
			$wikiPage->getId(),
			$wikiPage->getRevision(),
			$wikiPage->isRedirect(),
			$reason,
			$protect
		);

		DeferredUpdates::addCallableUpdate(
			function () use ( $eventBus, $events ) {
				$eventBus->send( $events );
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
		RecentChange $rc = null,
		User $user = null
	) {
		if ( is_null( $rev_id ) ) {
			// We're only interested for revision (edits) tags for now.
			return;
		}

		$revisionRecord = self::getRevisionLookup()->getRevisionById( $rev_id );
		if ( is_null( $revisionRecord ) ) {
			// Revision might already have been deleted, so we're not interested in tagging those.
			return;
		}

		$eventBus = EventBus::getInstance( 'eventbus' );
		$events[] = $eventBus->getFactory()->createRevisionTagsChangeEvent(
			$revisionRecord,
			$prevTags,
			$addedTags,
			$removedTags,
			$user
		);

		DeferredUpdates::addCallableUpdate(
			function () use ( $eventBus, $events ) {
				$eventBus->send( $events );
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
		$beginSettings,
		$endSettings,
		$summary
	) {
		$eventBus = EventBus::getInstance( 'eventbus' );
		$eventFactory = $eventBus->getFactory();

		// Since we're running this hook, we'll assume that CentralNotice is installed.
		$campaignUrl = Campaign::getCanonicalURL( $campaignName );

		switch ( $changeType ) {
			case 'created':
				$event = $eventFactory->createCentralNoticeCampaignCreateEvent(
					$campaignName,
					$user,
					$endSettings,
					$summary,
					$campaignUrl
				);
				break;

			case 'modified':
				$event = $eventFactory->createCentralNoticeCampaignChangeEvent(
					$campaignName,
					$user,
					$endSettings,
					$beginSettings,
					$summary,
					$campaignUrl
				);
				break;

			case 'removed':
				$event = $eventFactory->createCentralNoticeCampaignDeleteEvent(
					$campaignName,
					$user,
					$beginSettings,
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
