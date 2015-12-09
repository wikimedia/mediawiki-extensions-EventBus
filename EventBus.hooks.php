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
		return UIDGenerator::newUUIDv4();
	}

	/**
	 * Occurs after the save page request has been processed.
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
		// A null edit; Someone mashed 'Save', but no changes were recorded (no
		// revision was created).
		if ( is_null( $revision ) ) {
			return;
		}

		$attrs = array();
		$attrs['title'] = $article->getTitle()->getText();
		$attrs['page_id'] = $article->getId();
		$attrs['namespace'] = $article->getTitle()->getNamespace();
		$attrs['revision_id'] = $revision->getId();
		$attrs['parent_revision_id'] = $baseRevId ? $baseRevId : $revision->getParentId();
		$attrs['save_dt'] = wfTimestamp( TS_ISO_8601, $revision->getTimestamp() );
		$attrs['user_id'] = $user->getId();
		$attrs['user_text'] = $user->getName();
		$attrs['summary'] = $summary;

		$event = self::createEvent( '/edit/uri', 'mediawiki.page_edit', $attrs );

		EventBus::getInstance()->send( array( $event ) );
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

		EventBus::getInstance()->send( array( $event ) );
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
		$attrs['old_page_id'] = $oldPageId;
		$attrs['namespace'] = $title->getNamespace();
		$attrs['summary'] = $comment;

		$context = RequestContext::getMain();
		$attrs['user_id'] = $context->getUser()->getId();
		$attrs['user_text'] = $context->getUser()->getName();

		$event = self::createEvent( '/restore/uri', 'mediawiki.page_restore', $attrs );

		EventBus::getInstance()->send( array( $event ) );
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
	 */
	public static function onTitleMoveComplete( Title $title, Title $newtitle, User $user, $oldid,
			$newid, $reason = null
	) {
		$attrs = array();
		$attrs['new_title'] = $newtitle->getText();
		$attrs['old_title'] = $title->getText();
		$attrs['page_id'] = $oldid;
		$attrs['old_revision_id'] = $newtitle->getLatestRevId();
		$attrs['new_revision_id'] = $newtitle->getNextRevisionId( $attrs['old_revision_id'] );
		$attrs['user_id'] = $user->getId();
		$attrs['user_text'] = $user->getName();
		$attrs['summary'] = $reason;

		$event = self::createEvent( '/move/uri', 'mediawiki.page_move', $attrs );

		EventBus::getInstance()->send( array( $event ) );
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
		$context = RequestContext::getMain();
		$userId = $context->getUser()->getId();
		$userText = $context->getUser()->getName();

		$events = array();
		$revCount = count( $revIds );
		for ( $i = 0; $i < $revCount; $i++ ) {
			$revision = Revision::newFromId( $revIds[$i] );
			$attrs =  array(
				'revision_id' => (int)$revIds[$i],
				'hidden' => array(
					'text' => $revision->isDeleted( Revision::DELETED_TEXT ),
					'sha1' => $revision->isDeleted( Revision::DELETED_TEXT ),
					'comment' => $revision->isDeleted( Revision::DELETED_COMMENT ),
					'user' => $revision->isDeleted( Revision::DELETED_USER )
				)
			);
			$attrs['user_id'] = $userId;
			$attrs['user_text'] = $userText;
			$events[$i] = self::createEvent( '/visibility_set/uri',
				'mediawiki.revision_visibility_set', $attrs );
		}

		EventBus::getInstance()->send( $events );
	}

	/**
	 * Load our unit tests
	 */
	public static function onUnitTestsList( array &$files ) {
		$files = array_merge( $files, glob( __DIR__ . '/tests/*Test.php' ) );
	}

}
