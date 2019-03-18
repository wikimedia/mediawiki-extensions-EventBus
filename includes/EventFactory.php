<?php

use Firebase\JWT\JWT;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SuppressedDataException;

/**
 * Used to create events of particular types.
 */
class EventFactory {
	/**
	 * Return a title formatter instance
	 * @return TitleFormatter
	 */
	private static function getTitleFormatter() {
		return MediaWikiServices::getInstance()->getTitleFormatter();
	}

	/**
	 * Creates a full user page path
	 *
	 * @param string $userName userName
	 * @return string
	 */
	private static function getUserPageURL( $userName ) {
		global $wgCanonicalServer, $wgArticlePath, $wgContLang;
		$prefixedUserURL = $wgContLang->getNsText( NS_USER ) . ':' . $userName;
		$encodedUserURL = wfUrlencode( strtr( $prefixedUserURL, ' ', '_' ) );
		// The $wgArticlePath contains '$1' string where the article title should appear.
		return $wgCanonicalServer . str_replace( '$1', $encodedUserURL, $wgArticlePath );
	}

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
	 * @param LinkTarget $target article title object
	 * @return string
	 */
	private static function getArticleURL( $target ) {
		global $wgCanonicalServer, $wgArticlePath;

		$titleURL = wfUrlencode( self::getTitleFormatter()->getPrefixedDBkey( $target ) );
		// The $wgArticlePath contains '$1' string where the article title should appear.
		return $wgCanonicalServer . str_replace( '$1', $titleURL, $wgArticlePath );
	}

	/**
	 * Adds a meta subobject to $attrs based on uri and topic and returns it.
	 *
	 * @param string $uri
	 * @param string $topic
	 * @param array $attrs
	 * @param string|null $wiki wikiId if provided
	 *
	 * @return array $attrs + meta sub object
	 */
	private static function createEvent(
		$uri,
		$topic,
		array $attrs,
		$wiki = null
	) {
		global $wgServerName;

		if ( !is_null( $wiki ) ) {
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
				'request_id' => WebRequest::getRequestId(),
				'id'         => self::newId(),
				'dt'         => gmdate( 'c' ),
				'domain'     => $domain,
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
	private static function createRevisionRecordAttrs(
		RevisionRecord $revision,
		User $performer = null
	) {
		global $wgDBname;

		$linkTarget = $revision->getPageAsLinkTarget();
		$attrs = [
			// Common Mediawiki entity fields
			'database'           => $wgDBname,

			// revision entity fields
			'page_id'            => $revision->getPageId(),
			'page_title'         => self::getTitleFormatter()->getPrefixedDBkey( $linkTarget ),
			'page_namespace'     => $linkTarget->getNamespace(),
			'rev_id'             => $revision->getId(),
			'rev_timestamp'      => self::createDTAttr( $revision->getTimestamp() ),
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
	private static function createPerformerAttrs( User $user ) {
		$performerAttrs = [
			'user_text'   => $user->getName(),
			'user_groups' => $user->getEffectiveGroups(),
			'user_is_bot' => $user->getId() ? $user->isBot() : false,
		];
		if ( $user->getId() ) {
			$performerAttrs['user_id'] = $user->getId();
		}
		if ( $user->getRegistration() ) {
			$performerAttrs['user_registration_dt'] =
				self::createDTAttr( $user->getRegistration() );
		}
		if ( $user->getEditCount() !== null ) {
			$performerAttrs['user_edit_count'] = $user->getEditCount();
		}

		return $performerAttrs;
	}

	/**
	 * Format a timestamp for a date-time attribute in an event.
	 *
	 * @param string $timestamp Timestamp, in a format supported by wfTimestamp()
	 * @return string|bool
	 */
	public static function createDTAttr( $timestamp ) {
		return wfTimestamp( TS_ISO_8601, $timestamp );
	}

	/**
	 * Provides the event attributes common to all CentralNotice events.
	 *
	 * @param string $campaignName The name of the campaign affected.
	 * @param User $user The user who performed the action on the campaign.
	 * @param string $summary Change summary provided by the user, or empty string if none
	 *   was provided.
	 * @return array
	 */
	private static function createCommonCentralNoticeAttrs(
		$campaignName,
		User $user,
		$summary
	) {
		global $wgDBname;

		$attrs = [
			'database'           => $wgDBname,
			'performer'          => self::createPerformerAttrs( $user ),
			'campaign_name'      => $campaignName
		];

		if ( $summary ) {
			$attrs[ 'summary' ] = $summary;
		}

		return $attrs;
	}

	/**
	 * Takes an array of CentralNotice campaign settings, as provided by the
	 * CentralNoticeCampaignChange hook, and outputs an array of settings for use in
	 * centralnotice/campaign events.
	 *
	 * @param array $settings
	 * @return array
	 */
	private static function createCentralNoticeCampignSettingsAttrs( $settings ) {
		return [
			'start_dt'       => self::createDTAttr( $settings[ 'start' ] ),
			'end_dt'         => self::createDTAttr( $settings[ 'end' ] ),
			'enabled'        => $settings[ 'enabled' ],
			'archived'       => $settings[ 'archived' ],
			'banners'        => $settings[ 'banners' ]
		];
	}

	/**
	 * Given a Block $block, returns an array suitable for use
	 * as a 'blocks' object in the user/blocks-change event schema.
	 *
	 * @param Block $block
	 * @return array
	 */
	private static function getUserBlocksChangeAttributes( Block $block ) {
		$blockAttrs = [
			# Block properties are sometimes a string/int like '0'.
			# Cast to int then to bool to make sure it is a proper bool.
			'name'           => (bool)(int)$block->mHideName,
			'email'          => (bool)(int)$block->isEmailBlocked(),
			'user_talk'      => !(bool)(int)$block->isUsertalkEditAllowed(),
			'account_create' => (bool)(int)$block->isCreateAccountBlocked(),
		];
		if ( $block->getExpiry() != 'infinity' ) {
			$blockAttrs['expiry_dt'] = $block->getExpiry();
		}
		return $blockAttrs;
	}

	/**
	 * Creates a cryptographic signature for the event
	 *
	 * @param array $event the serialized event to sign
	 * @throws ConfigException
	 */
	private static function signEvent( &$event ) {
		// Sign the event with mediawiki secret key
		$serialized_event = EventBus::serializeEvents( $event );
		if ( is_null( $serialized_event ) ) {
			$event['mediawiki_signature'] = null;
			return;
		}

		$signingSecret = MediaWikiServices::getInstance()->getMainConfig()->get( 'SecretKey' );
		$signature = hash( 'sha256', JWT::sign( $serialized_event, $signingSecret ) );
		$event['mediawiki_signature'] = $signature;
	}

	/**
	 * Create a page delete event message
	 * @param User $user
	 * @param int $id
	 * @param LinkTarget $title
	 * @param bool $is_redirect
	 * @param int $archivedRevisionCount
	 * @param RevisionRecord|null $headRevision
	 * @param string $reason
	 * @return array
	 */
	public function createPageDeleteEvent(
		User $user,
		$id,
		LinkTarget $title,
		$is_redirect,
		$archivedRevisionCount,
		RevisionRecord $headRevision = null,
		$reason
	) {
		global $wgDBname;

		// Create a mediawiki page delete event.
		$attrs = [
			// Common Mediawiki entity fields
			'database'           => $wgDBname,
			'performer'          => self::createPerformerAttrs( $user ),

			// page entity fields
			'page_id'            => $id,
			'page_title'         => self::getTitleFormatter()->getPrefixedDBkey( $title ),
			'page_namespace'     => $title->getNamespace(),
			'page_is_redirect'   => $is_redirect,
		];

		if ( !is_null( $headRevision ) && !is_null( $headRevision->getId() ) ) {
			$attrs['rev_id'] = $headRevision->getId();
		}

		// page delete specific fields:
		if ( !is_null( $archivedRevisionCount ) ) {
			$attrs['rev_count'] = $archivedRevisionCount;
		}

		if ( !is_null( $reason ) && strlen( $reason ) ) {
			$attrs['comment'] = $reason;
			$attrs['parsedcomment'] = Linker::formatComment( $reason, $title );
		}

		return self::createEvent(
			self::getArticleURL( $title ),
			'mediawiki.page-delete',
			$attrs
		);
	}

	/**
	 * Create a page undelete message
	 * @param User $performer
	 * @param Title $title
	 * @param string $comment
	 * @param int $oldPageId
	 * @return array
	 */
	public function createPageUndeleteEvent(
		User $performer,
		Title $title,
		$comment,
		$oldPageId
	) {
		global $wgDBname;

		// Create a mediawiki page undelete event.
		$attrs = [
			// Common Mediawiki entity fields
			'database'           => $wgDBname,
			'performer'          => self::createPerformerAttrs( $performer ),

			// page entity fields
			'page_id'            => $title->getArticleID(),
			'page_title'         => self::getTitleFormatter()->getPrefixedDBkey( $title ),
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

		if ( !is_null( $comment ) && strlen( $comment ) ) {
			$attrs['comment'] = $comment;
			$attrs['parsedcomment'] = Linker::formatComment( $comment, $title );
		}

		return self::createEvent(
			self::getArticleURL( $title ),
			'mediawiki.page-undelete',
			$attrs
		);
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
			self::getArticleURL( $newTitle ),
			'mediawiki.page-move',
			$attrs
		);
	}

	/**
	 * Create an resource change message
	 * @param LinkTarget $title
	 * @param array $tags
	 * @return array
	 */
	public function createResourceChangeEvent(
		LinkTarget $title,
		array $tags
	) {
		return self::createEvent(
			self::getArticleURL( $title ),
			'resource_change',
			[ 'tags' => $tags ]
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
		$attrs['prior_state'] = [ 'tags' => $prevTags ];

		return self::createEvent(
			self::getArticleURL( $revisionRecord->getPageAsLinkTarget() ),
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
		$attrs = self::createRevisionRecordAttrs(
			$revisionRecord,
			$performer
		);
		$attrs['visibility'] = self::bitsToVisibilityObject( $visibilityChanges['newBits'] );
		$attrs['prior_state'] = [
			'visibility' => self::bitsToVisibilityObject( $visibilityChanges['oldBits'] )
		];

		return self::createEvent(
			self::getArticleURL( $revisionRecord->getPageAsLinkTarget() ),
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
			self::getArticleURL( $title ),
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
			self::getArticleURL( $revisionRecord->getPageAsLinkTarget() ),
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
			'page_title'         => self::getTitleFormatter()->getPrefixedDBkey( $title ),
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
			self::getArticleURL( $title ),
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
			'page_title'         => self::getTitleFormatter()->getPrefixedDBkey( $title ),
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
			self::getArticleURL( $title ),
			'mediawiki.page-links-change',
			$attrs
		);
	}

	/**
	 * Create a user or IP block change event message
	 *
	 * @param User $user
	 * @param Block $block
	 * @param Block|null $previousBlock
	 * @return array
	 */
	public function createUserBlockChangeEvent(
		User $user,
		Block $block,
		Block $previousBlock = null
	) {
		global $wgDBname;

		// This could be a User, a user_id, or a string (IP, etc.)
		$blockTarget = $block->getTarget();

		$attrs = [
			// Common Mediawiki entity fields:
			'database'           => $wgDBname,
			'performer'          => self::createPerformerAttrs( $user ),
		];

		if ( !is_null( $block->mReason ) ) {
			$attrs['comment'] = $block->mReason;
		}

		// user entity fields:

		// Note that, except for null, it is always safe to treat the target
		// as a string; for User objects this will return User::__toString()
		// which in turn gives User::getName().
		$attrs['user_text'] = (string)$blockTarget;

		// if the blockTarget is a user, then set user_id.
		if ( $blockTarget instanceof User ) {
			// set user_id if the target User has a user_id
			if ( $blockTarget->getId() ) {
				$attrs['user_id'] = $blockTarget->getId();
			}

			// set user_groups, all Users will have this.
			$attrs['user_groups'] = $blockTarget->getEffectiveGroups();
		}

		// blocks-change specific fields:
		$attrs['blocks'] = self::getUserBlocksChangeAttributes( $block );

		// If we had a prior block settings, emit them as prior_state.blocks.
		if ( $previousBlock ) {
			$attrs['prior_state'] = [
				'blocks' => self::getUserBlocksChangeAttributes( $previousBlock )
			];
		}

		return self::createEvent(
			self::getUserPageURL( $block->getTarget() ),
			'mediawiki.user-blocks-change',
			$attrs
		);
	}

	/**
	 * Create a page restrictions change event message
	 *
	 * @param User $user
	 * @param LinkTarget $title
	 * @param int $pageId
	 * @param RevisionRecord|null $revision
	 * @param bool $is_redirect
	 * @param string $reason
	 * @param array $protect
	 * @return array
	 */
	public function createPageRestrictionsChangeEvent(
		User $user,
		LinkTarget $title,
		$pageId,
		RevisionRecord $revision = null,
		$is_redirect,
		$reason,
		$protect
	) {
		global $wgDBname;

		// Create a mediawiki page restrictions change event.
		$attrs = [
			// Common Mediawiki entity fields
			'database'           => $wgDBname,
			'performer'          => self::createPerformerAttrs( $user ),

			// page entity fields
			'page_id'            => $pageId,
			'page_title'         => self::getTitleFormatter()->getPrefixedDBkey( $title ),
			'page_namespace'     => $title->getNamespace(),
			'page_is_redirect'   => $is_redirect,

			// page restrictions change specific fields:
			'reason'             => $reason,
			'page_restrictions'  => $protect
		];

		if ( !is_null( $revision ) && !is_null( $revision->getId() ) ) {
			$attrs['rev_id'] = $revision->getId();
		}

		return self::createEvent(
			self::getArticleURL( $title ),
			'mediawiki.page-restrictions-change',
			$attrs
		);
	}

	/**
	 * Create a recent change event message
	 *
	 * @param LinkTarget $title
	 * @param array $attrs
	 * @return array
	 */
	public function createRecentChangeEvent( LinkTarget $title, $attrs ) {
		if ( isset( $attrs['comment'] ) ) {
			$attrs['parsedcomment'] = Linker::formatComment( $attrs['comment'], $title );
		}

		$event = self::createEvent(
			self::getArticleURL( $title ),
			'mediawiki.recentchange',
			$attrs
		);

		// If timestamp exists on the recentchange event (it should),
		// then use it as the meta.dt event datetime.
		if ( array_key_exists( 'timestamp', $event ) ) {
			$event['meta']['dt'] = gmdate( 'c', $event['timestamp'] );
		}

		return $event;
	}

	/**
	 * @param string $wiki wikiId
	 * @param IJobSpecification $job the job specification
	 * @return array
	 * @throws ConfigException
	 */
	public function createJobEvent( $wiki, IJobSpecification $job ) {
		global $wgDBname;

		$attrs = [
			'database' => $wiki ?: $wgDBname,
			'type' => $job->getType(),
			// Deprecated, no longer used.
			// Hardcode a dummy until it can be safely removed from the schema. (T221368)
			// - page_namespace: integer
			// - page_title: non-empty string that is not a valid title
			'page_namespace' => -1,
			'page_title' => ':',
		];

		if ( !is_null( $job->getReleaseTimestamp() ) ) {
			$attrs['delay_until'] = $job->getReleaseTimestamp();
		}

		if ( $job->ignoreDuplicates() ) {
			$attrs['sha1'] = sha1( serialize( $job->getDeduplicationInfo() ) );
		}

		$params = $job->getParams();

		if ( isset( $params['rootJobTimestamp'] ) && isset( $params['rootJobSignature'] ) ) {
			$attrs['root_event'] = [
				'signature' => $params['rootJobSignature'],
				'dt'        => wfTimestamp( TS_ISO_8601, $params['rootJobTimestamp'] )
			];
		}

		$attrs['params'] = $params;

		// Deprecated, not used. To be removed from the schema. (T221368)
		$url = 'https://placeholder.invalid/wiki/Special:Badtitle';

		$event = self::createEvent(
			$url,
			'mediawiki.job.' . $job->getType(),
			$attrs,
			$wiki
		);

		// If the job provides a requestId - use it, otherwise try to get one ourselves
		if ( isset( $event['params']['requestId'] ) ) {
			$event['meta']['request_id'] = $event['params']['requestId'];
		} else {
			$event['meta']['request_id'] = WebRequest::getRequestId();
		}

		self::signEvent( $event );

		return $event;
	}

	public function createCentralNoticeCampaignCreateEvent(
		$campaignName,
		User $user,
		array $settings,
		$summary,
		$campaignUrl
	) {
		$attrs = self::createCommonCentralNoticeAttrs( $campaignName, $user, $summary );
		$attrs += self::createCentralNoticeCampignSettingsAttrs( $settings );

		return self::createEvent(
			$campaignUrl,
			'mediawiki.centralnotice.campaign-create',
			$attrs
		);
	}

	public function createCentralNoticeCampaignChangeEvent(
		$campaignName,
		User $user,
		array $settings,
		array $priorState,
		$summary,
		$campaignUrl
	) {
		$attrs = self::createCommonCentralNoticeAttrs( $campaignName, $user, $summary );

		$attrs += self::createCentralNoticeCampignSettingsAttrs( $settings );
		$attrs[ 'prior_state' ] =
					self::createCentralNoticeCampignSettingsAttrs( $priorState );

		return self::createEvent(
			$campaignUrl,
			'mediawiki.centralnotice.campaign-change',
			$attrs
		);
	}

	public function createCentralNoticeCampaignDeleteEvent(
		$campaignName,
		User $user,
		array $priorState,
		$summary,
		$campaignUrl
	) {
		$attrs = self::createCommonCentralNoticeAttrs( $campaignName, $user, $summary );
		$attrs[ 'prior_state' ] =
					self::createCentralNoticeCampignSettingsAttrs( $priorState );

		return self::createEvent(
			$campaignUrl,
			'mediawiki.centralnotice.campaign-delete',
			$attrs
		);
	}
}
