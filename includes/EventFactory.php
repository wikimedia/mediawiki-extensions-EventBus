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
	 * Adds a meta subobject to $attrs based on uri and topic and returns it.
	 *
	 * @param string $uri
	 * @param string $topic
	 * @param array $attrs
	 * @param string|bool $wiki
	 *
	 * @return array $attrs + meta subobject
	 */
	public static function createEvent(
		$uri,
		$topic,
		array $attrs,
		$wiki = false
	) {
		global $wgServerName;

		if ( $wiki ) {
			$wikiRef = WikiMap::getWiki( $wiki );
			if ( is_null( $wikiRef ) ) {
				$domain = $wgServerName;
			} else {
				$domain = $wikiRef->getDisplayName();
			}
		} else {
			$domain = $wgServerName;
		}

		$event = [
			'meta' => [
				'uri'        => $uri,
				'topic'      => $topic,
				'request_id' => self::getRequestId(),
				'id'         => self::newId(),
				'dt'         => gmdate( 'c' ),
				'domain'     => $domain ?: $wgServerName,
			],
		];

		return $event + $attrs;
	}

	/**
	 * Given a RevisionRecord $revision, returns an array suitable for
	 * use in mediawiki/revision entity schemas.
	 *
	 * @param RevisionRecord $revision
	 * @param User|null $performer
	 * @return array
	 */
	public static function createRevisionRecordAttrs(
		RevisionRecord $revision,
		User $performer = null
	) {
		global $wgDBname;

		$linkTarget = $revision->getPageAsLinkTarget();
		$titleFormatter = MediaWikiServices::getInstance()->getTitleFormatter();
		$attrs = [
			// Common Mediawiki entity fields
			'database'           => $wgDBname,

			// revision entity fields
			'page_id'            => $revision->getPageId(),
			'page_title'         => $titleFormatter->getPrefixedDBkey( $linkTarget ),
			'page_namespace'     => $linkTarget->getNamespace(),
			'rev_id'             => $revision->getId(),
			'rev_timestamp'      => wfTimestamp( TS_ISO_8601, $revision->getTimestamp() ),
			'rev_sha1'           => $revision->getSha1(),
			'rev_minor_edit'     => $revision->isMinor(),
			'rev_len'            => $revision->getSize(),
		];

		$attrs['rev_content_model'] = $contentModel = $revision->getSlot( 'main' )->getModel();

		$contentFormat = $revision->getSlot( 'main' )->getFormat();
		if ( is_null( $contentFormat ) ) {
			try {
				$contentFormat = ContentHandler::getForModelID( $contentModel )->getDefaultFormat();
			}
			catch ( MWException $e ) {
				// Ignore, the `rev_content_format` is not required.
			}
		}
		if ( !is_null( $contentFormat ) ) {
			$attrs['rev_content_format'] = $contentFormat;
		}

		if ( !is_null( $performer ) ) {
			$attrs['performer'] = self::createPerformerAttrs( $performer );
		} elseif ( !is_null( $revision->getUser() ) ) {
			$performer = User::newFromId( $revision->getUser()->getId() );
			$performer->loadFromId();
			$attrs['performer'] = self::createPerformerAttrs( $performer );
		}

		// It is possible that the $revision object does not have any content
		// at the time of RevisionInsertComplete.  This might happen during
		// a page restore, if the revision 'created' during the restore
		// has its content hidden.
		// TODO: In MCR Content::isRedirect should not be used to derive a redirect directly.
		try {
			$content = $revision->getContent( 'main' );
			if ( !is_null( $content ) ) {
				$attrs['page_is_redirect'] = $content->isRedirect();
			} else {
				$attrs['page_is_redirect'] = false;
			}
		} catch ( SuppressedDataException $e ) {
			$attrs['page_is_redirect'] = false;
		}

		if ( !is_null( $revision->getComment() ) && strlen( $revision->getComment()->text ) ) {
			$attrs['comment'] = $revision->getComment()->text;
			$attrs['parsedcomment'] = Linker::formatComment( $revision->getComment()->text );
		}

		// The rev_parent_id attribute is not required, but when supplied
		// must have a minimum value of 1, so omit it entirely when there is no
		// parent revision (i.e. page creation).
		if ( !is_null( $revision->getParentId() ) && $revision->getParentId() > 0 ) {
			$attrs['rev_parent_id'] = $revision->getParentId();
		}

		return $attrs;
	}

	/**
	 * Given a User $user, returns an array suitable for
	 * use as the performer JSON object in various Mediawiki
	 * entity schemas.
	 * @param User $user
	 * @return array
	 */
	public static function createPerformerAttrs( User $user ) {
		$performerAttrs = [
			'user_text'   => $user->getName(),
			'user_groups' => $user->getEffectiveGroups(),
			'user_is_bot' => $user->getId() ? $user->isBot() : false,
		];
		if ( $user->getId() ) {
			$performerAttrs['user_id'] = $user->getId();
		}
		if ( $user->getRegistration() ) {
			$performerAttrs['user_registration_dt'] = wfTimestamp(
				TS_ISO_8601, $user->getRegistration()
			);
		}
		if ( $user->getEditCount() !== null ) {
			$performerAttrs['user_edit_count'] = $user->getEditCount();
		}

		return $performerAttrs;
	}

	/**
	 * @param LinkTarget $oldTitle
	 * @param LinkTarget $newTitle
	 * @param RevisionRecord $newRevision
	 * @param User $user the user who made a tags change
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
			'performer'          => self::createPerformerAttrs( $user ),

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

		return self::createEvent(
			EventBus::getArticleURL( $newTitle ),
			'mediawiki.page-move',
			$attrs
		);
	}

	/**
	 * @param RevisionRecord $revisionRecord the revision record affected by the change.
	 * @param array $prevTags an array of previous tags
	 * @param array $addedTags an array of added tags
	 * @param array $removedTags an array of removed tags
	 * @param User|null $user the user who made a tags change
	 * @return array
	 */
	public function createRevisionTagsChangeEvent(
		RevisionRecord $revisionRecord,
		array $prevTags,
		array $addedTags,
		array $removedTags,
		User $user = null
	) {
		$attrs = self::createRevisionRecordAttrs( $revisionRecord );

		// If the user changing the tags is provided, override the performer in the event
		if ( !is_null( $user ) ) {
			$attrs['performer'] = self::createPerformerAttrs( $user );
		}

		$newTags = array_values(
			array_unique( array_diff( array_merge( $prevTags, $addedTags ), $removedTags ) )
		);
		$attrs['tags'] = $newTags;
		$attrs['prior_state'] = [
			'tags' => $prevTags
		];

		return self::createEvent(
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
		User $performer = null,
		array $visibilityChanges
	) {
		$attrs = self::createRevisionRecordAttrs( $revisionRecord, $performer );
		$attrs['visibility'] = self::bitsToVisibilityObject( $visibilityChanges['newBits'] );
		$attrs['prior_state'] = [
			'visibility' => self::bitsToVisibilityObject( $visibilityChanges['oldBits'] )
		];

		return self::createEvent(
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
	public function createPageCreateEvent(
		RevisionRecord $revisionRecord,
		LinkTarget $title
	) {
		return self::createEvent(
			EventBus::getArticleURL( $title ),
			'mediawiki.page-create',
			self::createRevisionRecordAttrs( $revisionRecord )
		);
	}

	/**
	 * @param RevisionRecord $revisionRecord the revision record affected by the change.
	 * @return array
	 */
	public function createRevisionCreateEvent(
		RevisionRecord $revisionRecord
	) {
		$attrs = self::createRevisionRecordAttrs( $revisionRecord );

		// The parent_revision_id attribute is not required, but when supplied
		// must have a minimum value of 1, so omit it entirely when there is no
		// parent revision (i.e. page creation).
		$parentId = $revisionRecord->getParentId();
		if ( !is_null( $parentId ) && $parentId !== 0 ) {
			$parentRev = MediaWikiServices::getInstance()
				->getRevisionLookup()
				->getRevisionById( $parentId );
			if ( !is_null( $parentRev ) ) {
				$attrs['rev_content_changed'] =
					$parentRev->getSha1() !== $revisionRecord->getSha1();
			}
		}

		return self::createEvent(
			EventBus::getArticleURL( $revisionRecord->getPageAsLinkTarget() ),
			'mediawiki.revision-create',
			$attrs
		);
	}

	/**
	 * @param Title $title
	 * @param array|null $addedProps
	 * @param array|null $removedProps
	 * @param User|null $user the user who made a tags change
	 * @param int $revId
	 * @param int $pageId
	 * @return array
	 */
	public function createPagePropertiesChangeEvent(
		Title $title,
		array $addedProps = null,
		array $removedProps = null,
		User $user = null,
		$revId,
		$pageId
	) {
		global $wgDBname;

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
			$attrs['performer'] = self::createPerformerAttrs( $user );
		}

		if ( !empty( $addedProps ) ) {
			$attrs['added_properties'] = array_map( 'EventBus::replaceBinaryValues', $addedProps );
		}

		if ( !empty( $removedProps ) ) {
			$attrs['removed_properties'] = array_map(
				'EventBus::replaceBinaryValues',
				$removedProps
			);
		}

		return self::createEvent(
			EventBus::getArticleURL( $title ),
			'mediawiki.page-properties-change',
			$attrs
		);
	}

	/**
	 * @param Title $title
	 * @param array|null $addedLinks
	 * @param array|null $addedExternalLinks
	 * @param array|null $removedLinks
	 * @param array|null $removedExternalLinks
	 * @param User|null $user the user who made a tags change
	 * @param int $revId
	 * @param int $pageId
	 * @return array
	 */
	public function createPageLinksChangeEvent(
		Title $title,
		array $addedLinks = null,
		array $addedExternalLinks = null,
		array $removedLinks = null,
		array $removedExternalLinks = null,
		User $user = null,
		$revId,
		$pageId
	) {
		global $wgDBname;

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
			$attrs['performer'] = self::createPerformerAttrs( $user );
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

		if ( !empty( $addedLinks ) || !empty( $addedExternalLinks ) ) {
			$addedLinks = is_null( $addedLinks ) ? [] : $addedLinks;
			$addedExternalLinks = is_null( $addedExternalLinks ) ? [] : $addedExternalLinks;

			$addedLinks = array_map(
				$getLinkData,
				array_merge( $addedLinks, $addedExternalLinks ) );

			$attrs['added_links'] = $addedLinks;
		}

		if ( !empty( $removedLinks ) || !empty( $removedExternalLinks ) ) {
			$removedLinks = is_null( $removedLinks ) ? [] : $removedLinks;
			$removedExternalLinks = is_null( $removedExternalLinks ) ? [] : $removedExternalLinks;
			$removedLinks = array_map(
				$getLinkData,
				array_merge( $removedLinks, $removedExternalLinks ) );

			$attrs['removed_links'] = $removedLinks;
		}

		return self::createEvent(
			EventBus::getArticleURL( $title ),
			'mediawiki.page-links-change',
			$attrs
		);
	}
}
