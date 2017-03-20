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

class EventBusHooks {

	/**
	 * Creates and sends a single resource_change event to EventBus
	 *
	 * @param Title $title article title object
	 * @param array $tags  the array of tags to use in the event
	 */
	private static function sendResourceChangedEvent( $title, $tags ) {
		$event = EventBus::createEvent(
			EventBus::getArticleURL( $title ),
			'resource_change',
			[ 'tags' => $tags ]
		);

		DeferredUpdates::addCallableUpdate( function() use ( $event ) {
			EventBus::getInstance()->send( [ $event ] );
		} );
	}

	/**
	 * Occurs after a revision is inserted into the DB
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/RevisionInsertComplete
	 *
	 * @param Revision $revision
	 * @param string $data
	 * @param integer $flags
	 */
	public static function onRevisionInsertComplete( $revision, $data, $flags ) {
		global $wgDBname;
		$events = [];

		// Create a mediawiki revision create event.
		$performer = User::newFromId( $revision->getUser() );
		$performer->loadFromId();

		$attrs = [
			// Common Mediawiki entity fields
			'database'           => $wgDBname,
			'performer'          => EventBus::createPerformerAttrs( $performer ),
			'comment'            => $revision->getComment(),

			// revision entity fields
			'page_id'            => $revision->getPage(),
			'page_title'         => $revision->getTitle()->getPrefixedDBkey(),
			'page_namespace'     => $revision->getTitle()->getNamespace(),
			'rev_id'             => $revision->getId(),
			'rev_timestamp'      => wfTimestamp( TS_ISO_8601, $revision->getTimestamp() ),
			'rev_sha1'           => $revision->getSha1(),
			'rev_minor_edit'     => $revision->isMinor(),
			'rev_content_model'  => $revision->getContentModel(),
			'rev_content_format' => $revision->getContentModel(),
		];

		// It is possible rev_len is not known. It's not a required field,
		// so don't set it if it's NULL
		if ( !is_null( $revision->getSize() ) ) {
			$attrs['rev_len'] = $revision->getSize();
		}

		// It is possible that the $revision object does not have any content
		// at the time of RevisionInsertComplete.  This might happen during
		// a page restore, if the revision 'created' during the restore
		// has its content hidden.
		$content = $revision->getContent();
		if ( !is_null( $content ) ) {
			$attrs['page_is_redirect'] = $content->isRedirect();
		} else {
			$attrs['page_is_redirect'] = false;
		}

		// The parent_revision_id attribute is not required, but when supplied
		// must have a minimum value of 1, so omit it entirely when there is no
		// parent revision (i.e. page creation).
		$parentId = $revision->getParentId();
		if ( !is_null( $parentId ) && $parentId !== 0 ) {
			$attrs['rev_parent_id'] = $parentId;
		}

		$events[] = EventBus::createEvent(
			EventBus::getArticleURL( $revision->getTitle() ),
			'mediawiki.revision-create',
			$attrs
		);

		DeferredUpdates::addCallableUpdate(
			function() use ( $events ) {
				EventBus::getInstance()->send( $events );
			}
		);
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
	 * @param $content the content of the deleted article, or null in case of error
	 * @param $logEntry the log entry used to record the deletion
	 * @param $archivedRevisionCount the number of revisions archived during the page delete
	 */
	public static function onArticleDeleteComplete(
		$wikiPage,
		$user,
		$reason,
		$id,
		$content,
		$logEntry,
		$archivedRevisionCount
	) {
		global $wgDBname;
		$events = [];

		// Create a mediawiki page delete event.
		$attrs = [
			// Common Mediawiki entity fields
			'database'           => $wgDBname,
			'performer'          => EventBus::createPerformerAttrs( $user ),
			'comment'            => $reason,

			// page entity fields
			'page_id'            => $id,
			'page_title'         => $wikiPage->getTitle()->getPrefixedDBkey(),
			'page_namespace'     => $wikiPage->getTitle()->getNamespace(),
			'page_is_redirect'   => $wikiPage->isRedirect(),
		];
		$headRevision = $wikiPage->getRevision();
		if ( !is_null( $headRevision ) ) {
			$attrs['rev_id'] = $headRevision->getId();
		}

		// page delete specific fields:
		if ( !is_null( $archivedRevisionCount ) ) {
			$attrs['rev_count'] = $archivedRevisionCount;
		}

		$events[] = EventBus::createEvent(
			EventBus::getArticleURL( $wikiPage->getTitle() ),
			'mediawiki.page-delete',
			$attrs
		);

		DeferredUpdates::addCallableUpdate(
			function() use ( $events ) {
				EventBus::getInstance()->send( $events );
			}
		);
	}

	/**
	 * When one or more revisions of an article are restored.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ArticleUndelete
	 *
	 * @param Title $title title corresponding to the article restored
	 * @param $create whether the restoration caused the page to be created
	 * @param $comment comment explaining the undeletion
	 * @param int $oldPageId ID of page previously deleted (from archive table)
	 */
	public static function onArticleUndelete( Title $title, $create, $comment, $oldPageId ) {
		global $wgDBname;
		$events = [];

		$performer = RequestContext::getMain()->getUser();

		// Create a mediawiki page undelete event.
		$attrs = [
			// Common Mediawiki entity fields
			'database'           => $wgDBname,
			'performer'          => EventBus::createPerformerAttrs( $performer ),
			'comment'            => $comment,

			// page entity fields
			'page_id'            => $title->getArticleID(),
			'page_title'         => $title->getPrefixedDBkey(),
			'page_namespace'     => $title->getNamespace(),
			'page_is_redirect'   => $title->isRedirect(),
			'rev_id'             => $title->getLatestRevID(),
		];

		// If this page had a different id in the archive table,
		// then save it as the prior_state page_id.  This will
		// be the page_id that the page had before it was deleted,
		// which is the same as the page_id that it had while it was
		// in the archive table.
		// Usually page_id will be the same, but there are some historical
		// edge cases where a new page_id is created as part of an undelete.
		if ( $oldPageId && $oldPageId != $attrs['page_id'] ) {
			// page undelete specific fields:
			$attrs['prior_state'] = [
				'page_id' => $oldPageId,
			];
		}

		$events[] = EventBus::createEvent(
			EventBus::getArticleURL( $title ),
			'mediawiki.page-undelete',
			$attrs
		);

		DeferredUpdates::addCallableUpdate( function() use ( $events ) {
			EventBus::getInstance()->send( $events );
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
		global $wgDBname;
		$events = [];

		// Create a mediawiki page delete event.
		$attrs = [
			// Common Mediawiki entity fields
			'database'           => $wgDBname,
			'performer'          => EventBus::createPerformerAttrs( $user ),
			'comment'            => $reason,

			// page entity fields
			'page_id'            => $pageid,
			'page_title'         => $newTitle->getPrefixedDBkey(),
			'page_namespace'     => $newTitle->getNamespace(),
			'page_is_redirect'   => $newTitle->isRedirect(),
			'rev_id'             => $newRevision->getId(),

			// page move specific fields:
			'prior_state'        => [
				'page_title'     => $oldTitle->getPrefixedDBkey(),
				'page_namespace' => $oldTitle->getNamespace(),
				'rev_id'         => $newRevision->getParentId(),
			],
		];

		// If a new redirect page was created during this move, then include
		// some information about it.
		if ( $redirid ) {
			$attrs['new_redirect_page'] = [
				'page_id'        => $redirid,
				// Redirect pages created as part of a page move
				// will have the same title and namespace that
				// the target page had before the move.
				'page_title'     => $attrs['prior_state']['page_title'],
				'page_namespace' => $attrs['prior_state']['page_namespace'],
				'rev_id'         => $oldTitle->getLatestRevID(),
			];
		}

		$events[] = EventBus::createEvent(
			EventBus::getArticleURL( $newTitle ),
			'mediawiki.page-move',
			$attrs
		);

		DeferredUpdates::addCallableUpdate(
			function() use ( $events ) {
				EventBus::getInstance()->send( $events );
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
	public static function onArticleRevisionVisibilitySet( $title, $revIds, $visibilityChangeMap ) {
		global $wgDBname;
		$events = [];

		/**
		 * Returns true if the Revision::DELETED_* field is set
		 * in the $hiddenBits.  False otherwise.
		 */
		function isHidden( $hiddenBits, $field ) {
			return ( $hiddenBits & $field ) == $field;
		}

		/**
		 * Converts a revision visibility hidden bitfield to an array with keys
		 * of each of the possible visibility settings name mapped to a boolean.
		 */
		function bitsToVisibilityObject( $bits ) {
			return [
				'text'    => !isHidden( $bits, Revision::DELETED_TEXT ),
				'user'    => !isHidden( $bits, Revision::DELETED_USER ),
				'comment' => !isHidden( $bits, Revision::DELETED_COMMENT ),
			];
		}

		$performer = RequestContext::getMain()->getUser();
		$performer->loadFromId();

		// Create a  event
		// for each revId that was changed.
		foreach ( $revIds as $revId ) {
			// Create the mediawiki/revision/visibilty-change event
			$revision = Revision::newFromid( $revId );

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
				$attrs = [
					// Common Mediawiki entity fields:
					'database'           => $wgDBname,
					'performer'          => EventBus::createPerformerAttrs( $performer ),
					'comment'            => $revision->getComment(),

					// revision entity fields:
					'page_id'            => $revision->getPage(),
					'page_title'         => $revision->getTitle()->getPrefixedDBkey(),
					'page_namespace'     => $revision->getTitle()->getNamespace(),
					'rev_id'             => $revision->getId(),
					'rev_timestamp'      => wfTimestamp( TS_ISO_8601, $revision->getTimestamp() ),
					'rev_sha1'           => $revision->getSha1(),
					'rev_len'            => $revision->getSize(),
					'rev_minor_edit'     => $revision->isMinor(),
					'rev_content_model'  => $revision->getContentModel(),
					'rev_content_format' => $revision->getContentModel(),

					// visibility-change state fields:
					'visibility'   => bitsToVisibilityObject( $visibilityChangeMap[$revId]['newBits'] ),
					'prior_state' => [
						'visibility' => bitsToVisibilityObject( $visibilityChangeMap[$revId]['oldBits'] ),
					]
				];

				// It is possible that the $revision object does not have any content
				// at the time of RevisionVisibilityChange.  This might happen if the
				// page content was hidden
				$content = $revision->getContent();
				if ( !is_null( $content ) ) {
					$attrs['page_is_redirect'] = $content->isRedirect();
				} else {
					$attrs['page_is_redirect'] = false;
				}

				$events[] = EventBus::createEvent(
					EventBus::getArticleURL( $title ),
					'mediawiki.revision-visibility-change',
					$attrs
				);
			}
		}

		DeferredUpdates::addCallableUpdate(
			function() use ( $events ) {
				EventBus::getInstance()->send( $events );
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
	public static function onArticlePurge( $wikiPage ) {
		self::sendResourceChangedEvent( $wikiPage->getTitle(), [ 'purge' ] );
	}

	/**
	 * Occurs after the save page request has been processed.
	 *
	 * It's used to detect null edits and create 'resource_change' events for purges.
	 * Actual edits are detected by the RevisionInsertComplete hook.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/PageContentSaveComplete
	 *
	 * @param WikiPage $article
	 * @param User $user
	 * @param Content $content
	 * @param string $summary
	 * @param boolean $isMinor
	 * @param boolean $isWatch
	 * @param $section Deprecated
	 * @param integer $flags
	 * @param {Revision|null} $revision
	 * @param Status $status
	 * @param integer $baseRevId
	 */
	public static function onPageContentSaveComplete( $article, $user, $content, $summary, $isMinor,
				$isWatch, $section, $flags, $revision, $status, $baseRevId
	) {
		// In case of a null edit the status revision value will be null
		if ( is_null( $status->getValue()['revision'] ) ) {
			self::sendResourceChangedEvent( $article->getTitle(), [ 'null_edit' ] );
		}
	}

	/**
	 * Occurs after the request to block an IP or user has been processed
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/BlockIpComplete
	 *
	 * @param Block $block the Block object that was saved
	 * @param User $user the user who did the block (not the one being blocked)
	 * @param Block $previousBlock the previous block state for the block target.
	 *        null if this is a new block target.
	 */
	public static function onBlockIpComplete( $block, $user, $previousBlock=null ) {
		global $wgDBname;
		$events = [];

		/**
		 * Given a Block $block, returns an array suitable for use
		 * as a 'blocks' object in the user/blocks-change event schema.
		 * This function exists just to DRY the code a bit.
		 */
		function getUserBlocksChangeAttributes( $block ) {
			$blockAttrs = [
				# mHideName is sometimes a string/int like '0'.
				# Cast to int then to bool to make sure it is a proper bool.
				'name'           => (bool)(int)$block->mHideName,
				'email'          => (bool)$block->prevents( 'sendemail' ),
				'user_talk'      => (bool)$block->prevents( 'editownusertalk' ),
				'account_create' => (bool)$block->prevents( 'createaccount' ),
			];
			if ( $block->getExpiry() != 'infinity' ) {
				$blockAttrs['expiry_dt'] = $block->getExpiry();
			}
			return $blockAttrs;
		}

		// This could be a User, a user_id, or a string (IP, etc.)
		$blockTarget = $block->getTarget();

		$attrs = [
			// Common Mediawiki entity fields:
			'database'           => $wgDBname,
			'performer'          => EventBus::createPerformerAttrs( $user ),
			'comment'            => $block->mReason,
		];

		// user entity fields:

		// Note that, except for null, it is always safe to treat the target
		// as a string; for User objects this will return User::__toString()
		// which in turn gives User::getName().
		$attrs['user_text'] = (string)$blockTarget;

		// if the blockTarget is a user, then set user_id.
		if ( get_class( $blockTarget ) == 'User' ) {
			// set user_id if the target User has a user_id
			if ( $blockTarget->getId() ) {
				$attrs['user_id'] = $blockTarget->getId();
			}

			// set user_groups, all Users will have this.
			$attrs['user_groups'] = $blockTarget->getEffectiveGroups();
		}

		// blocks-change specific fields:
		$attrs['blocks'] = getUserBlocksChangeAttributes( $block );

		// If we had a prior block settings, emit them as prior_state.blocks.
		if ( $previousBlock ) {
			$attrs['prior_state'] = [
				'blocks' => getUserBlocksChangeAttributes( $previousBlock )
			];
		}

		$events[] = EventBus::createEvent(
			EventBus::getUserPageURL( $block->getTarget() ),
			'mediawiki.user-blocks-change',
			$attrs
		);

		DeferredUpdates::addCallableUpdate(
			function() use ( $events ) {
				EventBus::getInstance()->send( $events );
			}
		);
	}

	/**
	 * Sends a page-properties-change event
	 *
	 * @param LinksUpdate $linksUpdate the update object
	 */
	public static function onLinksUpdateComplete( $linksUpdate ) {
		global $wgDBname;
		$events = [];

		$removedProps = $linksUpdate->getRemovedProperties();
		$addedProps = $linksUpdate->getAddedProperties();

		if ( empty( $removedProps ) && empty( $addedProps ) ) {
			return;
		}

		$title = $linksUpdate->getTitle();
		$user = $linksUpdate->getTriggeringUser();

		// Create a mediawiki page delete event.
		$attrs = [
			// Common Mediawiki entity fields
			'database'           => $wgDBname,

			// page entity fields
			'page_id'            => $title->getArticleID(),
			'page_title'         => $title->getPrefixedDBkey(),
			'page_namespace'     => $title->getNamespace(),
			'page_is_redirect'   => $title->isRedirect(),
		];

		// Use triggering revision's rev_id if it is set.
		// If the LinksUpdate didn't have a triggering revision
		// (probably because it was triggered by sysadmin maintenance).
		// Use the page's latest revision.
		$revision = $linksUpdate->getRevision();
		if ( $revision ) {
			$attrs['rev_id'] = $revision->getId();
		} else {
			$attrs['rev_id'] = $title->getLatestRevID();
		}

		if ( !is_null( $user ) ) {
			$attrs['performer'] = EventBus::createPerformerAttrs( $user );
		}

		if ( !empty( $addedProps ) ) {
			$attrs['added_properties'] = array_map( 'EventBus::replaceBinaryValues', $addedProps );
		}

		if ( !empty( $removedProps ) ) {
			$attrs['removed_properties'] = array_map( 'EventBus::replaceBinaryValues', $removedProps );
		}

		$events[] = EventBus::createEvent(
			EventBus::getArticleURL( $linksUpdate->getTitle() ),
			'mediawiki.page-properties-change',
			$attrs
		);

		DeferredUpdates::addCallableUpdate(
			function() use ( $events ) {
				EventBus::getInstance()->send( $events );
			}
		);
	}

	/**
	 * Sends a page-restrictions-change event
	 *
	 * @param WikiPage $article the article which restrictions were changed
	 * @param User $user the user who have changed the article
	 * @param array $protect set of new restrictions details
	 * @param string $reason the reason for page protection
	 */
	public static function onArticleProtectComplete( $article, $user, $protect, $reason ) {
		global $wgDBname;
		$events = [];

		// Create a mediawiki page restrictions change event.
		$attrs = [
			// Common Mediawiki entity fields
			'database'           => $wgDBname,
			'performer'          => EventBus::createPerformerAttrs( $user ),

			// page entity fields
			'page_id'            => $article->getId(),
			'page_title'         => $article->getTitle()->getPrefixedDBkey(),
			'page_namespace'     => $article->getTitle()->getNamespace(),
			'page_is_redirect'   => $article->isRedirect(),

			// page restrictions change specific fields:
			'reason'             => $reason,
			'page_restrictions'  => $protect
		];

		if ( $article->getRevision() ) {
			$attrs['rev_id'] = $article->getRevision()->getId();
		}

		$events[] = EventBus::createEvent(
			EventBus::getArticleURL( $article->getTitle() ),
			'mediawiki.page-restrictions-change',
			$attrs
		);

		DeferredUpdates::addCallableUpdate(
			function() use ( $events ) {
				EventBus::getInstance()->send( $events );
			}
		);
	}
}
