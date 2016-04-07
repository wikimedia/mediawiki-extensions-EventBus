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
	private static function createEvent( $uri, $topic, $attrs ) {
		global $wgServerName;
		$event = array(
			'meta' => array(
				'uri' => $uri,
				'topic' => $topic,
				'request_id' => self::getRequestId(),
				'id' => self::newId(),
				'dt' => date( 'c' ),
				'domain' => $wgServerName ?: "unknown",
			),
		);
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
	 * Occurs after a revision is inserted into the DB
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/RevisionInsertComplete
	 *
	 * @param Revision $revision
	 * @param string $data
	 * @param integer $flags
	 */
	public static function onRevisionInsertComplete( $revision, $data, $flags ) {
		$attrs = array();
		$attrs['page_title'] = $revision->getTitle()->getText();
		$attrs['page_id'] = $revision->getPage();
		$attrs['page_namespace'] = $revision->getTitle()->getNamespace();
		$attrs['rev_id'] = $revision->getId();
		$attrs['rev_timestamp'] = wfTimestamp( TS_ISO_8601, $revision->getTimestamp() );
		$attrs['user_id'] = $revision->getUser();
		$attrs['user_text'] = $revision->getUserText();
		$attrs['comment'] = $revision->getComment();

		// The parent_revision_id attribute is not required, but when supplied
		// must have a minimum value of 1, so omit it entirely when there is no
		// parent revision (i.e. page creation).
		$parentId = $revision->getParentId();
		if ( !is_null( $parentId ) && $parentId !== 0 ) {
			$attrs['rev_parent_id'] = $parentId;
		}

		$event = self::createEvent( '/edit/uri', 'mediawiki.revision_create', $attrs );

		DeferredUpdates::addCallableUpdate( function() use ( $event ) {
			EventBus::getInstance()->send( array( $event ) );
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
		$attrs = array();
		$attrs['title'] = $article->getTitle()->getText();
		$attrs['page_id'] = $id;
		$attrs['user_id'] = $user->getId();
		$attrs['user_text'] = $user->getName();
		$attrs['summary'] = $reason;

		$event = self::createEvent( '/delete/uri', 'mediawiki.page_delete', $attrs );

		DeferredUpdates::addCallableUpdate( function() use ( $event ) {
			EventBus::getInstance()->send( array( $event ) );
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
		$attrs = array();
		$attrs['title'] = $title->getText();
		$attrs['new_page_id'] = $title->getArticleID();
		if ( !is_null( $oldPageId ) && $oldPageId !== 0 ) {
		    $attrs['old_page_id'] = $oldPageId;
		}
		$attrs['namespace'] = $title->getNamespace();
		$attrs['summary'] = $comment;

		$context = RequestContext::getMain();
		$attrs['user_id'] = $context->getUser()->getId();
		$attrs['user_text'] = $context->getUser()->getName();

		$event = self::createEvent( '/restore/uri', 'mediawiki.page_restore', $attrs );

		DeferredUpdates::addCallableUpdate( function() use ( $event ) {
			EventBus::getInstance()->send( array( $event ) );
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
		$attrs = array();
		$attrs['new_title'] = $newtitle->getText();
		$attrs['old_title'] = $title->getText();
		$attrs['page_id'] = $oldid;
		$attrs['new_revision_id'] = $newRevision->getId();
		$attrs['old_revision_id'] = $newRevision->getParentId();
		$attrs['user_id'] = $user->getId();
		$attrs['user_text'] = $user->getName();
		$attrs['summary'] = $reason;

		$event = self::createEvent( '/move/uri', 'mediawiki.page_move', $attrs );

		DeferredUpdates::addCallableUpdate( function() use ( $event ) {
			EventBus::getInstance()->send( array( $event ) );
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

		$events = array();
		foreach ( $revIds as $revId ) {
			$revision = Revision::newFromId( $revId );

			// If the page gets deleted simultaneously with this code
			// we can't access the revision any more, so can't send a
			// meaningful event.
			if ( !is_null( $revision ) ) {
				$attrs =  array(
					'revision_id' => (int)$revId,
					'hidden' => array(
						'text' => $revision->isDeleted( Revision::DELETED_TEXT ),
						'sha1' => $revision->isDeleted( Revision::DELETED_TEXT ),
						'comment' => $revision->isDeleted( Revision::DELETED_COMMENT ),
						'user' => $revision->isDeleted( Revision::DELETED_USER )
					),
					'user_id' => $userId,
					'user_text' => $userText
				);
				$events[] = self::createEvent( '/visibility_set/uri',
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
		global $wgCanonicalServer, $wgArticlePath;
		// The $wgArticlePath contains '$1' string where the article title should appear.
		$uri = $wgCanonicalServer . str_replace( '$1', $wikiPage->getTitle()->getText(), $wgArticlePath );
		$event = self::createEvent( $uri, 'resource_change', array(
			'tags' => array( 'purge' )
		) );

		DeferredUpdates::addCallableUpdate( function() use ( $event ) {
			EventBus::getInstance()->send( array( $event ) );
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
		global $wgCanonicalServer, $wgArticlePath;

		// In case of a null edit the status revision value will be null
		if ( is_null( $status->getValue()['revision'] ) ) {
			// The $wgArticlePath contains '$1' string where the article title should appear.
			$uri = $wgCanonicalServer . str_replace( '$1', $article->getTitle()->getText(), $wgArticlePath );
			$event = self::createEvent( $uri, 'resource_change', array(
				'tags' => array( 'null_edit' )
			) );

			DeferredUpdates::addCallableUpdate( function() use ( $event ) {
				EventBus::getInstance()->send( array( $event ) );
			} );
		}
	}

	/**
	 * Load our unit tests
	 */
	public static function onUnitTestsList( array &$files ) {
		$files = array_merge( $files, glob( __DIR__ . '/tests/*Test.php' ) );
	}

}
