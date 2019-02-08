<?php

use MediaWiki\Linker\LinkTarget;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SuppressedDataException;

/**
 * Used to create events of particular types.
 */
class EventFactory {
	/**
	 * Converts a revision visibility hidden bitfield to an array with keys
	 * of each of the possible visibility settings name mapped to a boolean.
	 *
	 * @param int $bits revision visibility bitfield
	 * @return array
	 */
	private static function bitsToVisibilityObject( $bits ) {
		return [
			'text'    => !self::isHidden( $bits, RevisionRecord::DELETED_TEXT ),
			'user'    => !self::isHidden( $bits, RevisionRecord::DELETED_USER ),
			'comment' => !self::isHidden( $bits, RevisionRecord::DELETED_COMMENT ),
		];
	}

	/**
	 * Checks if RevisionRecord::DELETED_* field is set in the $hiddenBits
	 *
	 * @param int $hiddenBits revision visibility bitfield
	 * @param int $field RevisionRecord::DELETED_* field to check
	 * @return bool
	 */
	private static function isHidden( $hiddenBits, $field ) {
		return ( $hiddenBits & $field ) == $field;
	}

	/**
	 * @param LinkTarget $oldTitle
	 * @param LinkTarget $newTitle
	 * @param RevisionRecord $newRevision
	 * @param User|null $user the user who made a tags change
	 * @param string $reason
	 * @param int $redirectPageId
	 * @return array
	 */
	public function createPageMoveEvent(
		LinkTarget $oldTitle,
		LinkTarget $newTitle,
		RevisionRecord $newRevision,
		User $user,
		$reason,
		$redirectPageId = 0
	) {
		global $wgDBname;
		$titleFormatter = MediaWikiServices::getInstance()->getTitleFormatter();

		// TODO: In MCR Content::isRedirect should not be used to derive a redirect directly.
		$newPageIsRedirect = false;
		try {
			$content = $newRevision->getContent( 'main' );
			if ( !is_null( $content ) ) {
				$newPageIsRedirect = $content->isRedirect();
			}
		} catch ( SuppressedDataException $e ) {
		}

		$attrs = [
			// Common Mediawiki entity fields
			'database'           => $wgDBname,
			'performer'          => EventBus::createPerformerAttrs( $user ),

			// page entity fields
			'page_id'            => $newRevision->getPageId(),
			'page_title'         => $titleFormatter->getPrefixedDBkey( $newTitle ),
			'page_namespace'     => $newTitle->getNamespace(),
			'page_is_redirect'   => $newPageIsRedirect,
			'rev_id'             => $newRevision->getId(),

			// page move specific fields:
			'prior_state'        => [
				'page_title'     => $titleFormatter->getPrefixedDBkey( $oldTitle ),
				'page_namespace' => $oldTitle->getNamespace(),
				'rev_id'         => $newRevision->getParentId(),
			],
		];

		// If a new redirect page was created during this move, then include
		// some information about it.
		if ( $redirectPageId ) {
			$redirectWikiPage = WikiPage::newFromID( $redirectPageId );
			if ( !is_null( $redirectWikiPage ) ) {
				$attrs['new_redirect_page'] = [
					'page_id' => $redirectPageId,
					// Redirect pages created as part of a page move
					// will have the same title and namespace that
					// the target page had before the move.
					'page_title' => $attrs['prior_state']['page_title'],
					'page_namespace' => $attrs['prior_state']['page_namespace'],
					'rev_id' => $redirectWikiPage->getRevisionRecord()->getId()
				];
			}
		}

		if ( !is_null( $reason ) && strlen( $reason ) ) {
			$attrs['comment'] = $reason;
			$attrs['parsedcomment'] = Linker::formatComment( $reason, $newTitle );
		}

		return EventBus::createEvent(
			EventBus::getArticleURL( $newTitle ),
			'mediawiki.page-move',
			$attrs
		);
	}

	/**
	 * @param RevisionRecord $revisionRecord the revision record affected by the change.
	 * @param array $prevTags an array of previous tags
	 * @param array $addedTags an array of added 		// global $wgDBname;tags
	 * @param array $removedTags an array of removed tags
	 * @param User|null $user the user who made a tags change
	 * @return array
	 */
	public function createRevisionTagsChangeEvent(
		RevisionRecord $revisionRecord,
		$prevTags,
		$addedTags,
		$removedTags,
		User $user = null
	) {
		$attrs = EventBus::createRevisionRecordAttrs( $revisionRecord );

		// If the user changing the tags is provided, override the performer in the event
		if ( !is_null( $user ) ) {
			$attrs['performer'] = EventBus::createPerformerAttrs( $user );
		}

		$newTags = array_values(
			array_unique( array_diff( array_merge( $prevTags, $addedTags ), $removedTags ) )
		);
		$attrs['tags'] = $newTags;
		$attrs['prior_state'] = [
			'tags' => $prevTags
		];

		return EventBus::createEvent(
			EventBus::getArticleURL( $revisionRecord->getPageAsLinkTarget() ),
			'mediawiki.revision-tags-change',
			$attrs
		);
	}

	/**
	 * @param RevisionRecord $revisionRecord the revision record affected by the change.
	 * @param User|null $performer the user who made a tags change
	 * @param array $visibilityChanges
	 * @return array
	 */
	public function createRevisionVisibilityChangeEvent(
		RevisionRecord $revisionRecord,
		User $performer,
		$visibilityChanges
	) {
		$attrs = EventBus::createRevisionRecordAttrs( $revisionRecord, $performer );
		$attrs['visibility'] = self::bitsToVisibilityObject( $visibilityChanges['newBits'] );
		$attrs['prior_state'] = [
			'visibility' => self::bitsToVisibilityObject( $visibilityChanges['oldBits'] )
		];

		// The parent_revision_id attribute is not required, but when supplied
		// must have a minimum value of 1, so omit it entirely when there is no
		// parent revision (i.e. page creation).
		$parentId = $revisionRecord->getParentId();
		if ( !is_null( $parentId ) && $parentId > 0 ) {
			$attrs['rev_parent_id'] = $parentId;
		}

		return EventBus::createEvent(
			EventBus::getArticleURL( $revisionRecord->getPageAsLinkTarget() ),
			'mediawiki.revision-visibility-change',
			$attrs
		);
	}

	/**
	 * @param RevisionRecord $revisionRecord the revision record affected by the change.
	 * @param LinkTarget $title title of new page
	 * @return array
	 */
	public function createPageCreationEvent( $revisionRecord, $title ) {
		$attrs = EventBus::createRevisionRecordAttrs( $revisionRecord );
		$url = EventBus::getArticleURL( $title );

		return EventBus::createEvent(
			$url,
			'mediawiki.page-create',
			$attrs
		);
	}

	/**
	 * @param RevisionRecord $revisionRecord the revision record affected by the change.
	 * @param int|null $parentId Id of prarent revision
	 * @param bool $revContentChanged indicates whether the content changed between revisions.
	 * @return array
	 */
	public function createRevisionCreateEvent( $revisionRecord, $parentId, $revContentChanged ) {
		$attrs = EventBus::createRevisionRecordAttrs( $revisionRecord );

		$attrs['rev_content_changed'] = $revContentChanged;
		if ( !is_null( $parentId ) ) {
			$attrs['rev_parent_id'] = $parentId;
		}

		return EventBus::createEvent(
			EventBus::getArticleURL( $revisionRecord->getPageAsLinkTarget() ),
			'mediawiki.revision-create',
			$attrs
		);
	}

	/**
	 * @param LinkTarget $title
	 * @param array $addedProps
	 * @param array $removedProps
	 * @param User|null $user the user who made a tags change
	 * @param int $revId
	 * @param int $pageId
	 * @return array
	 */
	public function createPagePropertiesChangeEvent(
		$title,
		$addedProps,
		$removedProps,
		$user,
		$revId,
		$pageId
	) {
		global $wgDBname;

		$arePropsEmpty = empty( $removedProps ) && empty( $addedProps );

		// Create a mediawiki page delete event.
		$attrs = [
			// Common Mediawiki entity fields
			'database'           => $wgDBname,

			// page entity fields
			'page_id'            => $pageId,
			'page_title'         => $title->getPrefixedDBkey(),
			'page_namespace'     => $title->getNamespace(),
			'page_is_redirect'   => $title->isRedirect(),
			'rev_id'			 => $revId
		];

		if ( !is_null( $user ) ) {
			$attrs['performer'] = EventBus::createPerformerAttrs( $user );
		}

		if ( !$arePropsEmpty ) {
			if ( !empty( $addedProps ) ) {
				$attrs['added_properties'] = array_map( 'EventBus::replaceBinaryValues', $addedProps );
			}

			if ( !empty( $removedProps ) ) {
				$attrs['removed_properties'] = array_map(
					'EventBus::replaceBinaryValues',
					$removedProps
				);
			}
		}

		return EventBus::createEvent(
			EventBus::getArticleURL( $title ),
			'mediawiki.page-properties-change',
			$attrs
		);
	}

	/**
	 * @param LinkTarget $title
	 * @param array $addedLinks
	 * @param array $addedExternalLinks
	 * @param array $removedLinks
	 * @param array $removedExternalLinks
	 * @param User|null $user the user who made a tags change
	 * @param int $revId
	 * @param int $pageId
	 * @return array
	 */
	public function createPageLinksChangeEvent(
		$title,
		$addedLinks,
		$addedExternalLinks,
		$removedLinks,
		$removedExternalLinks,
		$user,
		$revId,
		$pageId
	) {
		global $wgDBname;

		$areLinksEmpty = empty( $removedLinks ) && empty( $addedLinks )
		&& empty( $removedExternalLinks ) && empty( $addedExternalLinks );

		// Create a mediawiki page delete event.
		$attrs = [
			// Common Mediawiki entity fields
			'database'           => $wgDBname,

			// page entity fields
			'page_id'            => $pageId,
			'page_title'         => $title->getPrefixedDBkey(),
			'page_namespace'     => $title->getNamespace(),
			'page_is_redirect'   => $title->isRedirect(),
			'rev_id'			 => $revId
		];

		if ( !is_null( $user ) ) {
			$attrs['performer'] = EventBus::createPerformerAttrs( $user );
		}

		/**
		 * Extract URL encoded link and whether it's external
		 * @param Title|String $t External links are strings, internal
		 *   links are Titles
		 * @return array
		 */
		$getLinkData = function ( $t ) {
			$isExternal = is_string( $t );
			$link = $isExternal ? $t : $t->getLinkURL();
			return [
				'link' => wfUrlencode( $link ),
				'external' => $isExternal
			];
		};

		if ( !$areLinksEmpty ) {
			$addedLinks = array_map(
				$getLinkData,
				array_merge( $addedLinks, $addedExternalLinks ) );
			$removedLinks = array_map(
				$getLinkData,
				array_merge( $removedLinks, $removedExternalLinks ) );

			if ( !empty( $addedLinks ) ) {
				$attrs['added_links'] = $addedLinks;
			}

			if ( !empty( $removedLinks ) ) {
				$attrs['removed_links'] = $removedLinks;
			}
		}

		return EventBus::createEvent(
			EventBus::getArticleURL( $title ),
			'mediawiki.page-links-change',
			$attrs
		);
	}
}
