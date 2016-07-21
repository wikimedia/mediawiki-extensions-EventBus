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
 * @author Eric Evans
 */

class EventBusHooks {

	/** Event object stub */
	public static function createEvent( $uri, $topic, $attrs ) {
		global $wgServerName;
		$event = [
			'meta' => [
				'uri' => $uri,
				'topic' => $topic,
				'request_id' => self::getRequestId(),
				'id' => self::newId(),
				'dt' => date( 'c' ),
				'domain' => $wgServerName ?: "unknown",
			],
		];
		return $event + $attrs;
	}

	/**
	 * Returns the X-Request-ID header, if set, otherwise a newly generated
	 * type 4 UUID string.
	 *
	 * @return string
	 */
	private static function getRequestId() {
		$context = RequestContext::getMain();
		$xreqid = $context->getRequest()->getHeader( 'x-request-id' );
		return $xreqid ?: UIDGenerator::newUUIDv4();
	}

	/**
	 * Creates a new type 1 UUID string.
	 *
	 * @return string
	 */
	private static function newId() {
		return UIDGenerator::newUUIDv1();
	}

	/**
	 * Creates a full article path
	 *
	 * @param Title $title article title object
	 * @return string
	 */
	private static function getArticleURL( $title ) {
		global $wgCanonicalServer, $wgArticlePath;
		// can't use wfUrlencode, because it doesn't encode slashes. RESTBase
		// and services expect slashes to be encoded, so encode the whole title
		// right away to avoid reencoding it in change-propagation
		$titleURL = rawurlencode( $title->getPrefixedDBkey() );
		// The $wgArticlePath contains '$1' string where the article title should appear.
		return $wgCanonicalServer . str_replace( '$1', $titleURL, $wgArticlePath );
	}

	/**
	 * Creates a full user page path
	 *
	 * @param string $userName userName
	 * @returns string
	 */
	private static function getUserPageURL( $userName ) {
		global $wgCanonicalServer, $wgArticlePath, $wgContLang;
		$prefixedUserURL = $wgContLang->getNsText( NS_USER ) . ':' . $userName;
		$encodedUserURL = rawurlencode( strtr( $prefixedUserURL, ' ', '_' ) );
		// The $wgArticlePath contains '$1' string where the article title should appear.
		return $wgCanonicalServer . str_replace( '$1', $encodedUserURL, $wgArticlePath );
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
		$attrs = [];
		$user = User::newFromId( $revision->getUser() );
		$attrs['page_title'] = $revision->getTitle()->getPrefixedDBkey();
		$attrs['page_id'] = $revision->getPage();
		$attrs['page_namespace'] = $revision->getTitle()->getNamespace();
		$attrs['rev_id'] = $revision->getId();
		$attrs['rev_timestamp'] = wfTimestamp( TS_ISO_8601, $revision->getTimestamp() );
		$attrs['user_id'] = $revision->getUser();
		$attrs['user_text'] = $revision->getUserText();
		$attrs['comment'] = $revision->getComment();
		$attrs['rev_by_bot'] = $user->loadFromId() ? $user->isBot() : false;

		// The parent_revision_id attribute is not required, but when supplied
		// must have a minimum value of 1, so omit it entirely when there is no
		// parent revision (i.e. page creation).
		$parentId = $revision->getParentId();
		if ( !is_null( $parentId ) && $parentId !== 0 ) {
			$attrs['rev_parent_id'] = $parentId;
		}

		$event = self::createEvent( self::getArticleURL( $revision->getTitle() ),
			'mediawiki.revision_create', $attrs );

		DeferredUpdates::addCallableUpdate( function() use ( $event ) {
			EventBus::getInstance()->send( [ $event ] );
		} );
	}

	/**
	 * Occurs after the delete article request has been processed.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ArticleDeleteComplete
	 *
	 * @param WikiPage $article the article that was deleted
	 * @param User $user the user that deleted the article
	 * @param string $reason the reason the article was deleted
	 * @param int $id the ID of the article that was deleted
	 * @param $content the content of the deleted article, or null in case of error
	 * @param $logEntry the log entry used to record the deletion
	 */
	public static function onArticleDeleteComplete( $article, $user, $reason, $id, $content,
			$logEntry
	) {
		$attrs = [];
		$attrs['title'] = $article->getTitle()->getPrefixedDBkey();
		$attrs['page_id'] = $id;
		$attrs['user_id'] = $user->getId();
		$attrs['user_text'] = $user->getName();
		$attrs['summary'] = $reason;

		$event = self::createEvent( self::getArticleURL( $article->getTitle() ),
			'mediawiki.page_delete', $attrs );

		DeferredUpdates::addCallableUpdate( function() use ( $event ) {
			EventBus::getInstance()->send( [ $event ] );
		} );
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
		$attrs = [];
		$attrs['title'] = $title->getPrefixedDBkey();
		$attrs['new_page_id'] = $title->getArticleID();
		if ( !is_null( $oldPageId ) && $oldPageId !== 0 ) {
		    $attrs['old_page_id'] = $oldPageId;
		}
		$attrs['namespace'] = $title->getNamespace();
		$attrs['summary'] = $comment;

		$context = RequestContext::getMain();
		$attrs['user_id'] = $context->getUser()->getId();
		$attrs['user_text'] = $context->getUser()->getName();

		$event = self::createEvent( self::getArticleURL( $title ), 'mediawiki.page_restore', $attrs );

		DeferredUpdates::addCallableUpdate( function() use ( $event ) {
			EventBus::getInstance()->send( [ $event ] );
		} );
	}

	/**
	 * Occurs whenever a request to move an article is completed.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/TitleMoveComplete
	 *
	 * @param Title $title the old title
	 * @param Title $newtitle the new title
	 * @param User $user User who did the move
	 * @param int $oldid database page_id of the page that's been moved
	 * @param int $newid database page_id of the created redirect, or 0 if suppressed
	 * @param string $reason reason for the move
	 * @param Revision $newRevision revision created by the move
	 */
	public static function onTitleMoveComplete( Title $title, Title $newtitle, User $user, $oldid,
			$newid, $reason, Revision $newRevision
	) {
		$attrs = [];
		$attrs['new_title'] = $newtitle->getPrefixedDBkey();
		$attrs['old_title'] = $title->getPrefixedDBkey();
		$attrs['page_id'] = $oldid;
		$attrs['new_revision_id'] = $newRevision->getId();
		$attrs['old_revision_id'] = $newRevision->getParentId();
		$attrs['user_id'] = $user->getId();
		$attrs['user_text'] = $user->getName();
		$attrs['summary'] = $reason;

		$event = self::createEvent( self::getArticleURL( $newtitle ), 'mediawiki.page_move', $attrs );

		DeferredUpdates::addCallableUpdate( function() use ( $event ) {
			EventBus::getInstance()->send( [ $event ] );
		} );
	}

	/**
	 * Called when changing visibility of one or more revisions of an article.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ArticleRevisionVisibilitySet
	 *
	 * @param Title $title title object of the article
	 * @param array $revIds array of integer revision IDs
	 */
	public static function onArticleRevisionVisibilitySet( $title, $revIds ) {
		$user = RequestContext::getMain()->getUser();
		$userId = $user->getId();
		$userText = $user->getName();

		$events = [];
		foreach ( $revIds as $revId ) {
			$revision = Revision::newFromId( $revId );

			// If the page gets deleted simultaneously with this code
			// we can't access the revision any more, so can't send a
			// meaningful event.
			if ( !is_null( $revision ) ) {
				$attrs =  [
					'revision_id' => (int)$revId,
					'hidden' => [
						'text' => $revision->isDeleted( Revision::DELETED_TEXT ),
						'sha1' => $revision->isDeleted( Revision::DELETED_TEXT ),
						'comment' => $revision->isDeleted( Revision::DELETED_COMMENT ),
						'user' => $revision->isDeleted( Revision::DELETED_USER )
					],
					'user_id' => $userId,
					'user_text' => $userText
				];
				$events[] = self::createEvent( self::getArticleURL( $title ),
						'mediawiki.revision_visibility_set', $attrs );
			}
		}

		DeferredUpdates::addCallableUpdate( function() use ( $events ) {
			EventBus::getInstance()->send( $events );
		} );
	}

	/**
	 * Callback for article purge.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ArticlePurge
	 *
	 * @param WikiPage $wikiPage
	 */
	public static function onArticlePurge( $wikiPage ) {
		$event = self::createEvent( self::getArticleURL( $wikiPage->getTitle() ), 'resource_change', [
			'tags' => [ 'purge' ]
		] );

		DeferredUpdates::addCallableUpdate( function() use ( $event ) {
			EventBus::getInstance()->send( [ $event ] );
		} );
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

			$event = self::createEvent( self::getArticleURL( $article->getTitle() ), 'resource_change', [
				'tags' => [ 'null_edit' ]
			] );

			DeferredUpdates::addCallableUpdate( function() use ( $event ) {
				EventBus::getInstance()->send( [ $event ] );
			} );
		}
	}

	/**
	 * Occurs after the request to block an IP or user has been processed
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/BlockIpComplete
	 *
	 * @param Block $block the Block object that was saved
	 * @param User $user the user who did the block (not the one being blocked)
	 */
	public static function onBlockIpComplete( $block, $user ) {
		$attrs = [];
		$user_blocked = is_string( $block->getTarget() )
			? $block->getTarget() : $block->getTarget()->getName();
		$attrs['user_blocked'] = $user_blocked;
		if ( $block->mExpiry != 'infinity' ) {
			$attrs['expiry'] = $block->mExpiry;
		}

		$attrs['blocks'] = [
			'name' => $block->mHideName,
			'email' => $block->prevents( 'sendemail' ),
			'user_talk' => $block->prevents( 'editownusertalk' ),
			'account_create' => $block->prevents( 'createaccount' ),
		];
		if ( !is_null( $block->mReason ) ) {
			$attrs['reason'] = $block->mReason;
		}
		$attrs['user_id'] = $user->getId();
		$attrs['user_text'] = $user->getName();

		$event = self::createEvent( self::getUserPageURL( $user_blocked ),
			'mediawiki.user_block', $attrs );

		DeferredUpdates::addCallableUpdate( function() use ( $event ) {
			EventBus::getInstance()->send( [ $event ] );
		} );
	}

	/**
	 * Load our unit tests
	 */
	public static function onUnitTestsList( array &$files ) {
		$files = array_merge( $files, glob( __DIR__ . '/tests/*Test.php' ) );
	}

}
