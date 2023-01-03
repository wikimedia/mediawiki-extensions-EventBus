<?php

namespace MediaWiki\Extension\EventBus;

use IJobSpecification;
use Language;
use MediaWiki\Block\DatabaseBlock;
use MediaWiki\Block\Restriction\Restriction;
use MediaWiki\CommentFormatter\CommentFormatter;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Content\IContentHandlerFactory;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionSlots;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Revision\SuppressedDataException;
use MediaWiki\User\UserEditTracker;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentity;
use MWException;
use Psr\Log\LoggerInterface;
use Title;
use TitleFormatter;
use UIDGenerator;
use WebRequest;
use WikiMap;

/**
 * Used to create events of particular types.
 *
 * @deprecated since EventBus 0.5.0. Use EventSerializer and specific Serializer instances instead.
 *
 */
class EventFactory {

	public const CONSTRUCTOR_OPTIONS = [
		'ArticlePath',
		'CanonicalServer',
		'ServerName',
		'SecretKey'
	];

	/** @var ServiceOptions */
	private $options;

	/** @var Language */
	private $contentLanguage;

	/** @var TitleFormatter */
	private $titleFormatter;

	/** @var RevisionStore */
	private $revisionStore;

	/** @var UserGroupManager */
	private $userGroupManager;

	/** @var UserEditTracker */
	private $userEditTracker;

	/** @var UserFactory */
	private $userFactory;

	/** @var string */
	private $dbDomain;

	/** @var WikiPageFactory */
	private $wikiPageFactory;

	/** @var CommentFormatter */
	private $commentFormatter;

	/** @var IContentHandlerFactory */
	private $contentHandlerFactory;

	/** @var LoggerInterface */
	private $logger;

	/**
	 * @param ServiceOptions $serviceOptions
	 * @param string $dbDomain
	 * @param Language $contentLanguage
	 * @param RevisionStore $revisionStore
	 * @param TitleFormatter $titleFormatter
	 * @param UserGroupManager $userGroupManager
	 * @param UserEditTracker $userEditTracker
	 * @param WikiPageFactory $wikiPageFactory
	 * @param UserFactory $userFactory
	 * @param CommentFormatter $commentFormatter
	 * @param IContentHandlerFactory $contentHandlerFactory
	 * @param LoggerInterface $logger
	 */
	public function __construct(
		ServiceOptions $serviceOptions,
		string $dbDomain,
		Language $contentLanguage,
		RevisionStore $revisionStore,
		TitleFormatter $titleFormatter,
		UserGroupManager $userGroupManager,
		UserEditTracker $userEditTracker,
		WikiPageFactory $wikiPageFactory,
		UserFactory $userFactory,
		CommentFormatter $commentFormatter,
		IContentHandlerFactory $contentHandlerFactory,
		LoggerInterface $logger
	) {
		$serviceOptions->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->options = $serviceOptions;
		$this->dbDomain = $dbDomain;
		$this->contentLanguage = $contentLanguage;
		$this->titleFormatter = $titleFormatter;
		$this->revisionStore = $revisionStore;
		$this->userGroupManager = $userGroupManager;
		$this->userEditTracker = $userEditTracker;
		$this->wikiPageFactory = $wikiPageFactory;
		$this->userFactory = $userFactory;
		$this->commentFormatter = $commentFormatter;
		$this->contentHandlerFactory = $contentHandlerFactory;
		$this->logger = $logger;
	}

	/**
	 * Creates a full user page path
	 *
	 * @param string $userName userName
	 * @return string
	 */
	private function getUserPageURL( $userName ) {
		$prefixedUserURL = $this->contentLanguage->getNsText( NS_USER ) . ':' . $userName;
		$encodedUserURL = wfUrlencode( strtr( $prefixedUserURL, ' ', '_' ) );
		// The ArticlePath contains '$1' string where the article title should appear.
		return $this->options->get( 'CanonicalServer' ) .
			str_replace( '$1', $encodedUserURL, $this->options->get( 'ArticlePath' ) );
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
	 * Creates a full article path
	 *
	 * @param LinkTarget $target article title object
	 * @return string
	 */
	private function getArticleURL( $target ) {
		$titleURL = wfUrlencode( $this->titleFormatter->getPrefixedDBkey( $target ) );
		// The ArticlePath contains '$1' string where the article title should appear.
		return $this->options->get( 'CanonicalServer' ) .
			str_replace( '$1', $titleURL, $this->options->get( 'ArticlePath' ) );
	}

	/**
	 * Given a RevisionRecord $revision, returns an array suitable for
	 * use in mediawiki/revision entity schemas.
	 *
	 * @param RevisionRecord $revision
	 * @param UserIdentity|null $performer
	 * @return array
	 */
	private function createRevisionRecordAttrs(
		RevisionRecord $revision,
		UserIdentity $performer = null
	) {
		$linkTarget = $revision->getPageAsLinkTarget();
		$attrs = [
			// Common Mediawiki entity fields
			'database'           => $this->dbDomain,

			// revision entity fields
			'page_id'            => $revision->getPageId(),
			'page_title'         => $this->titleFormatter->getPrefixedDBkey( $linkTarget ),
			'page_namespace'     => $linkTarget->getNamespace(),
			'rev_id'             => $revision->getId(),
			'rev_timestamp'      => self::createDTAttr( $revision->getTimestamp() ),
			'rev_sha1'           => $revision->getSha1(),
			'rev_minor_edit'     => $revision->isMinor(),
			'rev_len'            => $revision->getSize(),
		];

		$attrs['rev_content_model'] = $contentModel = $revision->getSlot( 'main' )->getModel();

		$contentFormat = $revision->getSlot( 'main' )->getFormat();
		if ( $contentFormat === null ) {
			try {
				$contentFormat = $this->contentHandlerFactory->getContentHandler( $contentModel )->getDefaultFormat();
			} catch ( MWException $e ) {
				// Ignore, the `rev_content_format` is not required.
			}
		}
		if ( $contentFormat !== null ) {
			$attrs['rev_content_format'] = $contentFormat;
		}

		if ( $performer ) {
			$attrs['performer'] = $this->createPerformerAttrs( $performer );
		} elseif ( $revision->getUser() ) {
			$attrs['performer'] = $this->createPerformerAttrs( $revision->getUser() );
		}

		// It is possible that the $revision object does not have any content
		// at the time of RevisionRecordInserted.  This might happen during
		// a page restore, if the revision 'created' during the restore
		// has its content hidden.
		// TODO: In MCR Content::isRedirect should not be used to derive a redirect directly.
		try {
			$content = $revision->getContent( 'main' );
			if ( $content !== null ) {
				$attrs['page_is_redirect'] = $content->isRedirect();
			} else {
				$attrs['page_is_redirect'] = false;
			}
		} catch ( SuppressedDataException $e ) {
			$attrs['page_is_redirect'] = false;
		}

		if ( $revision->getComment() !== null && strlen( $revision->getComment()->text ) ) {
			$attrs['comment'] = $revision->getComment()->text;
			$attrs['parsedcomment'] = $this->commentFormatter->format( $revision->getComment()->text );
		}

		// The rev_parent_id attribute is not required, but when supplied
		// must have a minimum value of 1, so omit it entirely when there is no
		// parent revision (i.e. page creation).
		if ( $revision->getParentId() !== null && $revision->getParentId() > 0 ) {
			$attrs['rev_parent_id'] = $revision->getParentId();
		}

		return $attrs;
	}

	/**
	 * @param RevisionSlots $slots
	 * @return array
	 */
	private function createSlotRecordsAttrs( RevisionSlots $slots ): array {
		$attrs = [];
		foreach ( $slots->getSlots() as $slotRecord ) {
			$slotAttr = [
				'rev_slot_content_model' => $slotRecord->getModel(),
				'rev_slot_sha1' => $slotRecord->getSha1(),
				'rev_slot_size' => $slotRecord->getSize()
			];
			if ( $slotRecord->hasOrigin() ) {
				// unclear if necessary to guard against missing origin in this context but since it
				// might fail on unsaved content we are better safe than sorry
				$slotAttr['rev_slot_origin_rev_id'] = $slotRecord->getOrigin();
			}
			$attrs[$slotRecord->getRole()] = $slotAttr;
		}
		return $attrs;
	}

	/**
	 * Given a UserIdentity $user, returns an array suitable for
	 * use as the performer JSON object in various Mediawiki
	 * entity schemas.
	 * @param UserIdentity $user
	 * @return array
	 */
	private function createPerformerAttrs( UserIdentity $user ) {
		$legacyUser = $this->userFactory->newFromUserIdentity( $user );
		$performerAttrs = [
			'user_text'   => $user->getName(),
			'user_groups' => $this->userGroupManager->getUserEffectiveGroups( $user ),
			'user_is_bot' => $user->isRegistered() && $legacyUser->isBot(),
		];
		if ( $user->getId() ) {
			$performerAttrs['user_id'] = $user->getId();
		}
		if ( $legacyUser->getRegistration() ) {
			$performerAttrs['user_registration_dt'] =
				self::createDTAttr( $legacyUser->getRegistration() );
		}
		if ( $user->isRegistered() ) {
			$performerAttrs['user_edit_count'] = $this->userEditTracker->getUserEditCount( $user );
		}

		return $performerAttrs;
	}

	/**
	 * Adds a meta subobject to $attrs based on uri and topic and returns it.
	 *
	 * @param string $uri
	 * @param string $schema
	 * @param string $stream
	 * @param array $attrs
	 * @param string|null $wiki wikiId if provided
	 * @param string|null $dt
	 * @return array $attrs + meta sub object
	 */
	public function createEvent(
		$uri,
		$schema,
		$stream,
		array $attrs,
		string $wiki = null,
		string $dt = null
	) {
		if ( $wiki !== null ) {
			$wikiRef = WikiMap::getWiki( $wiki );
			if ( $wikiRef === null ) {
				$domain = $this->options->get( 'ServerName' );
			} else {
				$domain = $wikiRef->getDisplayName();
			}
		} else {
			$domain = $this->options->get( 'ServerName' );
		}

		$event = [
			'$schema' => $schema,
			'meta' => [
				'uri'        => $uri,
				'request_id' => WebRequest::getRequestId(),
				'id'         => UIDGenerator::newUUIDv4(),
				'dt'         => $dt ?? wfTimestamp( TS_ISO_8601 ),
				'domain'     => $domain,
				'stream'     => $stream,
			],
		];

		return $event + $attrs;
	}

	/**
	 * Creates an event fragment suitable for the fragment/mediawiki/common schema fragment.
	 * @param UserIdentity $user
	 * @return array
	 */
	public function createMediaWikiCommonAttrs( UserIdentity $user ): array {
		return [
			'database'  => $this->dbDomain,
			'performer' => $this->createPerformerAttrs( $user ),
		];
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
	 * @param UserIdentity $user The user who performed the action on the campaign.
	 * @param string $summary Change summary provided by the user, or empty string if none
	 *   was provided.
	 * @return array
	 */
	private function createCommonCentralNoticeAttrs(
		$campaignName,
		UserIdentity $user,
		$summary
	) {
		$attrs = [
			'database'           => $this->dbDomain,
			'performer'          => $this->createPerformerAttrs( $user ),
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
	private static function createCentralNoticeCampignSettingsAttrs( array $settings ) {
		return [
			'start_dt'       => self::createDTAttr( $settings[ 'start' ] ),
			'end_dt'         => self::createDTAttr( $settings[ 'end' ] ),
			'enabled'        => $settings[ 'enabled' ],
			'archived'       => $settings[ 'archived' ],
			'banners'        => $settings[ 'banners' ]
		];
	}

	/**
	 * Given a DatabaseBlock $block, returns an array suitable for use
	 * as a 'blocks' object in the user/blocks-change event schema.
	 *
	 * @param DatabaseBlock $block
	 * @return array
	 */
	private static function getUserBlocksChangeAttributes( DatabaseBlock $block ) {
		$blockAttrs = [
			# Block properties are sometimes a string/int like '0'.
			# Cast to int then to bool to make sure it is a proper bool.
			'name'           => (bool)(int)$block->getHideName(),
			'email'          => (bool)(int)$block->isEmailBlocked(),
			'user_talk'      => !(bool)(int)$block->isUsertalkEditAllowed(),
			'account_create' => (bool)(int)$block->isCreateAccountBlocked(),
			'sitewide'       => $block->isSitewide(),
		];
		$blockAttrs['restrictions'] = array_map( static function ( Restriction $restriction ) {
			return [
				'type'  => $restriction::getType(),
				'value' => $restriction->getValue()
			];
		}, $block->getRestrictions() );
		if ( $block->getExpiry() != 'infinity' ) {
			$blockAttrs['expiry_dt'] = self::createDTAttr( $block->getExpiry() );
		}
		return $blockAttrs;
	}

	/**
	 * Creates a cryptographic signature for the event
	 *
	 * @param array &$event the serialized event to sign
	 */
	private function signEvent( &$event ) {
		// Sign the event with mediawiki secret key
		$serialized_event = EventBus::serializeEvents( $event );
		if ( $serialized_event === null ) {
			$event['mediawiki_signature'] = null;
			return;
		}

		$signature = self::getEventSignature(
			$serialized_event,
			$this->options->get( 'SecretKey' )
		);

		$event['mediawiki_signature'] = $signature;
	}

	/**
	 * @param string $serialized_event
	 * @param string $secretKey
	 * @return string
	 */
	public static function getEventSignature( $serialized_event, $secretKey ) {
		return hash_hmac( 'sha1', $serialized_event, $secretKey );
	}

	/**
	 * Create a page delete event message
	 * @param string $stream the stream to send an event to
	 * @param UserIdentity $user
	 * @param int $id
	 * @param LinkTarget $title
	 * @param bool $is_redirect
	 * @param int $archivedRevisionCount
	 * @param RevisionRecord|null $headRevision
	 * @param string $reason
	 * @return array
	 */
	public function createPageDeleteEvent(
		$stream,
		UserIdentity $user,
		$id,
		LinkTarget $title,
		$is_redirect,
		$archivedRevisionCount,
		?RevisionRecord $headRevision,
		$reason
	) {
		// Create a mediawiki page delete event.
		$attrs = [
			// Common Mediawiki entity fields
			'database'           => $this->dbDomain,
			'performer'          => $this->createPerformerAttrs( $user ),

			// page entity fields
			'page_id'            => $id,
			'page_title'         => $this->titleFormatter->getPrefixedDBkey( $title ),
			'page_namespace'     => $title->getNamespace(),
			'page_is_redirect'   => $is_redirect,
		];

		if ( $headRevision !== null && $headRevision->getId() !== null ) {
			$attrs['rev_id'] = $headRevision->getId();
		}

		// page delete specific fields:
		if ( $archivedRevisionCount !== null ) {
			$attrs['rev_count'] = $archivedRevisionCount;
		}

		if ( $reason !== null && strlen( $reason ) ) {
			$attrs['comment'] = $reason;
			$attrs['parsedcomment'] = $this->commentFormatter->format( $reason, $title );
		}

		return $this->createEvent(
			$this->getArticleURL( $title ),
			'/mediawiki/page/delete/1.0.0',
			$stream,
			$attrs
		);
	}

	/**
	 * Create a page undelete message
	 * @param string $stream the stream to send an event to
	 * @param UserIdentity $performer
	 * @param Title $title
	 * @param string $comment
	 * @param int $oldPageId
	 * @return array
	 */
	public function createPageUndeleteEvent(
		$stream,
		UserIdentity $performer,
		Title $title,
		$comment,
		$oldPageId
	) {
		// Create a mediawiki page undelete event.
		$attrs = [
			// Common Mediawiki entity fields
			'database'           => $this->dbDomain,
			'performer'          => $this->createPerformerAttrs( $performer ),

			// page entity fields
			'page_id'            => $title->getArticleID(),
			'page_title'         => $this->titleFormatter->getPrefixedDBkey( $title ),
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

		if ( $comment !== null && strlen( $comment ) ) {
			$attrs['comment'] = $comment;
			$attrs['parsedcomment'] = $this->commentFormatter->format( $comment, $title );
		}

		return $this->createEvent(
			$this->getArticleURL( $title ),
			'/mediawiki/page/undelete/1.0.0',
			$stream,
			$attrs
		);
	}

	/**
	 * @param string $stream the stream to send an event to
	 * @param LinkTarget $oldTitle
	 * @param LinkTarget $newTitle
	 * @param RevisionRecord $newRevision
	 * @param UserIdentity $user the user who made a tags change
	 * @param string $reason
	 * @param int $redirectPageId
	 * @return array
	 */
	public function createPageMoveEvent(
		$stream,
		LinkTarget $oldTitle,
		LinkTarget $newTitle,
		RevisionRecord $newRevision,
		UserIdentity $user,
		$reason,
		$redirectPageId = 0
	) {
		// TODO: In MCR Content::isRedirect should not be used to derive a redirect directly.
		$newPageIsRedirect = false;
		try {
			$content = $newRevision->getContent( 'main' );
			if ( $content !== null ) {
				$newPageIsRedirect = $content->isRedirect();
			}
		} catch ( SuppressedDataException $e ) {
		}

		$attrs = [
			// Common Mediawiki entity fields
			'database'           => $this->dbDomain,
			'performer'          => $this->createPerformerAttrs( $user ),

			// page entity fields
			'page_id'            => $newRevision->getPageId(),
			'page_title'         => $this->titleFormatter->getPrefixedDBkey( $newTitle ),
			'page_namespace'     => $newTitle->getNamespace(),
			'page_is_redirect'   => $newPageIsRedirect,
			'rev_id'             => $newRevision->getId(),

			// page move specific fields:
			'prior_state'        => [
				'page_title'     => $this->titleFormatter->getPrefixedDBkey( $oldTitle ),
				'page_namespace' => $oldTitle->getNamespace(),
				'rev_id'         => $newRevision->getParentId(),
			],
		];

		// If a new redirect page was created during this move, then include
		// some information about it.
		if ( $redirectPageId ) {
			$redirectWikiPage = $this->wikiPageFactory->newFromID( $redirectPageId );
			if ( $redirectWikiPage !== null ) {
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

		if ( $reason !== null && strlen( $reason ) ) {
			$attrs['comment'] = $reason;
			$attrs['parsedcomment'] = $this->commentFormatter->format( $reason, $newTitle );
		}

		return $this->createEvent(
			$this->getArticleURL( $newTitle ),
			'/mediawiki/page/move/1.0.0',
			$stream,
			$attrs
		);
	}

	/**
	 * Create an resource change message
	 * @param string $stream the stream to send an event to
	 * @param LinkTarget $title
	 * @param array $tags
	 * @return array
	 */
	public function createResourceChangeEvent(
		$stream,
		LinkTarget $title,
		array $tags
	) {
		return $this->createEvent(
			$this->getArticleURL( $title ),
			'/resource_change/1.0.0',
			$stream,
			[ 'tags' => $tags ]
		);
	}

	/**
	 * @param string $stream the stream to send an event to
	 * @param RevisionRecord $revisionRecord the revision record affected by the change.
	 * @param array $prevTags an array of previous tags
	 * @param array $addedTags an array of added tags
	 * @param array $removedTags an array of removed tags
	 * @param UserIdentity|null $user the user who made a tags change
	 * @return array
	 */
	public function createRevisionTagsChangeEvent(
		$stream,
		RevisionRecord $revisionRecord,
		array $prevTags,
		array $addedTags,
		array $removedTags,
		?UserIdentity $user
	) {
		$attrs = $this->createRevisionRecordAttrs( $revisionRecord );

		// If the user changing the tags is provided, override the performer in the event
		if ( $user !== null ) {
			$attrs['performer'] = $this->createPerformerAttrs( $user );
		}

		$newTags = array_values(
			array_unique( array_diff( array_merge( $prevTags, $addedTags ), $removedTags ) )
		);
		$attrs['tags'] = $newTags;
		$attrs['prior_state'] = [ 'tags' => $prevTags ];

		return $this->createEvent(
			$this->getArticleURL( $revisionRecord->getPageAsLinkTarget() ),
			'/mediawiki/revision/tags-change/1.0.0',
			$stream,
			$attrs
		);
	}

	/**
	 * @param string $stream the stream to send an event to
	 * @param RevisionRecord $revisionRecord the revision record affected by the change.
	 * @param UserIdentity|null $performer the user who made a tags change
	 * @param array $visibilityChanges
	 * @return array
	 */
	public function createRevisionVisibilityChangeEvent(
		$stream,
		RevisionRecord $revisionRecord,
		?UserIdentity $performer,
		array $visibilityChanges
	) {
		$attrs = $this->createRevisionRecordAttrs(
			$revisionRecord,
			$performer
		);
		$attrs['visibility'] = self::bitsToVisibilityObject( $visibilityChanges['newBits'] );
		$attrs['prior_state'] = [
			'visibility' => self::bitsToVisibilityObject( $visibilityChanges['oldBits'] )
		];

		return $this->createEvent(
			$this->getArticleURL( $revisionRecord->getPageAsLinkTarget() ),
			'/mediawiki/revision/visibility-change/1.0.0',
			$stream,
			$attrs
		);
	}

	/**
	 * @param string $stream the stream to send an event to
	 * @param RevisionRecord $revisionRecord the revision record affected by the change.
	 * @return array
	 */
	public function createRevisionCreateEvent(
		$stream,
		RevisionRecord $revisionRecord
	) {
		$attrs = $this->createRevisionRecordAttrs( $revisionRecord );
		// Only add to revision-create for now
		$attrs['rev_slots'] = $this->createSlotRecordsAttrs( $revisionRecord->getSlots() );
		// The parent_revision_id attribute is not required, but when supplied
		// must have a minimum value of 1, so omit it entirely when there is no
		// parent revision (i.e. page creation).
		$parentId = $revisionRecord->getParentId();
		if ( $parentId !== null && $parentId !== 0 ) {
			$parentRev = $this->revisionStore->getRevisionById( $parentId );
			if ( $parentRev !== null ) {
				$attrs['rev_content_changed'] =
					$parentRev->getSha1() !== $revisionRecord->getSha1();
			}
		}

		return $this->createEvent(
			$this->getArticleURL( $revisionRecord->getPageAsLinkTarget() ),
			'/mediawiki/revision/create/1.1.0',
			$stream,
			$attrs
		);
	}

	/**
	 * @param string $stream the stream to send an event to
	 * @param Title $title
	 * @param array|null $addedProps
	 * @param array|null $removedProps
	 * @param UserIdentity|null $user the user who made a tags change
	 * @param int|null $revId
	 * @param int $pageId
	 * @return array
	 */
	public function createPagePropertiesChangeEvent(
		$stream,
		Title $title,
		?array $addedProps,
		?array $removedProps,
		?UserIdentity $user,
		$revId,
		$pageId
	) {
		// Create a mediawiki page delete event.
		$attrs = [
			// Common Mediawiki entity fields
			'database'           => $this->dbDomain,

			// page entity fields
			'page_id'            => $pageId,
			'page_title'         => $this->titleFormatter->getPrefixedDBkey( $title ),
			'page_namespace'     => $title->getNamespace(),
			'page_is_redirect'   => $title->isRedirect(),
			'rev_id'             => $revId
		];

		if ( $user !== null ) {
			$attrs['performer'] = $this->createPerformerAttrs( $user );
		}

		if ( !empty( $addedProps ) ) {
			$attrs['added_properties'] = array_map(
				[ EventBus::class, 'replaceBinaryValues' ],
				$addedProps
			);
		}

		if ( !empty( $removedProps ) ) {
			$attrs['removed_properties'] = array_map(
				[ EventBus::class, 'replaceBinaryValues' ],
				$removedProps
			);
		}

		return $this->createEvent(
			$this->getArticleURL( $title ),
			'/mediawiki/page/properties-change/1.0.0',
			$stream,
			$attrs
		);
	}

	/**
	 * @param string $stream the stream to send an event to
	 * @param Title $title
	 * @param array|null $addedLinks
	 * @param array|null $addedExternalLinks
	 * @param array|null $removedLinks
	 * @param array|null $removedExternalLinks
	 * @param UserIdentity|null $user the user who made a tags change
	 * @param int|null $revId
	 * @param int $pageId
	 * @return array
	 */
	public function createPageLinksChangeEvent(
		$stream,
		Title $title,
		?array $addedLinks,
		?array $addedExternalLinks,
		?array $removedLinks,
		?array $removedExternalLinks,
		?UserIdentity $user,
		$revId,
		$pageId
	) {
		// Create a mediawiki page delete event.
		$attrs = [
			// Common Mediawiki entity fields
			'database'           => $this->dbDomain,

			// page entity fields
			'page_id'            => $pageId,
			'page_title'         => $this->titleFormatter->getPrefixedDBkey( $title ),
			'page_namespace'     => $title->getNamespace(),
			'page_is_redirect'   => $title->isRedirect(),
			'rev_id'             => $revId
		];

		if ( $user !== null ) {
			$attrs['performer'] = $this->createPerformerAttrs( $user );
		}

		/**
		 * Extract URL encoded link and whether it's external
		 * @param Title|String $t External links are strings, internal
		 *   links are Titles
		 * @return array
		 */
		$getLinkData = static function ( $t ) {
			$isExternal = is_string( $t );
			$link = $isExternal ? $t : $t->getLinkURL();
			return [
				'link' => wfUrlencode( $link ),
				'external' => $isExternal
			];
		};

		if ( !empty( $addedLinks ) || !empty( $addedExternalLinks ) ) {
			$addedLinks = $addedLinks === null ? [] : $addedLinks;
			$addedExternalLinks = $addedExternalLinks === null ? [] : $addedExternalLinks;

			$addedLinks = array_map(
				$getLinkData,
				array_merge( $addedLinks, $addedExternalLinks ) );

			$attrs['added_links'] = $addedLinks;
		}

		if ( !empty( $removedLinks ) || !empty( $removedExternalLinks ) ) {
			$removedLinks = $removedLinks === null ? [] : $removedLinks;
			$removedExternalLinks = $removedExternalLinks === null ? [] : $removedExternalLinks;
			$removedLinks = array_map(
				$getLinkData,
				array_merge( $removedLinks, $removedExternalLinks ) );

			$attrs['removed_links'] = $removedLinks;
		}

		return $this->createEvent(
			$this->getArticleURL( $title ),
			'/mediawiki/page/links-change/1.0.0',
			$stream,
			$attrs
		);
	}

	/**
	 * Create a user or IP block change event message
	 * @param string $stream the stream to send an event to
	 * @param UserIdentity $user
	 * @param DatabaseBlock $block
	 * @param DatabaseBlock|null $previousBlock
	 * @return array
	 */
	public function createUserBlockChangeEvent(
		$stream,
		UserIdentity $user,
		DatabaseBlock $block,
		?DatabaseBlock $previousBlock
	) {
		$attrs = [
			// Common Mediawiki entity fields:
			'database'           => $this->dbDomain,
			'performer'          => $this->createPerformerAttrs( $user ),
		];

		$attrs['comment'] = $block->getReasonComment()->text;

		// user entity fields:

		// Note that, except for null, it is always safe to treat the target
		// as a string; for UserIdentity objects this will return
		// UserIdentity::getName()
		$attrs['user_text'] = $block->getTargetName();

		$blockTargetIdentity = $block->getTargetUserIdentity();
		// if the $blockTargetIdentity is a UserIdentity, then set user_id.
		if ( $blockTargetIdentity ) {
			// set user_id if the target UserIdentity has a user_id
			if ( $blockTargetIdentity->getId() ) {
				$attrs['user_id'] = $blockTargetIdentity->getId();
			}

			// set user_groups, all UserIdentities will have this.
			$attrs['user_groups'] = $this->userGroupManager->getUserEffectiveGroups( $blockTargetIdentity );
		}

		// blocks-change specific fields:
		$attrs['blocks'] = self::getUserBlocksChangeAttributes( $block );

		// If we had a prior block settings, emit them as prior_state.blocks.
		if ( $previousBlock ) {
			$attrs['prior_state'] = [
				'blocks' => self::getUserBlocksChangeAttributes( $previousBlock )
			];
		}

		return $this->createEvent(
			$this->getUserPageURL( $block->getTargetName() ),
			'/mediawiki/user/blocks-change/1.1.0',
			$stream,
			$attrs
		);
	}

	/**
	 * Create a page restrictions change event message
	 * @param string $stream the stream to send an event to
	 * @param UserIdentity $user
	 * @param LinkTarget $title
	 * @param int $pageId
	 * @param RevisionRecord|null $revision
	 * @param bool $is_redirect
	 * @param string $reason
	 * @param string[] $protect
	 * @return array
	 */
	public function createPageRestrictionsChangeEvent(
		$stream,
		UserIdentity $user,
		LinkTarget $title,
		$pageId,
		?RevisionRecord $revision,
		$is_redirect,
		$reason,
		array $protect
	) {
		// Create a mediawiki page restrictions change event.
		$attrs = [
			// Common Mediawiki entity fields
			'database'           => $this->dbDomain,
			'performer'          => $this->createPerformerAttrs( $user ),

			// page entity fields
			'page_id'            => $pageId,
			'page_title'         => $this->titleFormatter->getPrefixedDBkey( $title ),
			'page_namespace'     => $title->getNamespace(),
			'page_is_redirect'   => $is_redirect,

			// page restrictions change specific fields:
			'reason'             => $reason,
			'page_restrictions'  => $protect
		];

		if ( $revision !== null && $revision->getId() !== null ) {
			$attrs['rev_id'] = $revision->getId();
		}

		return $this->createEvent(
			$this->getArticleURL( $title ),
			'/mediawiki/page/restrictions-change/1.0.0',
			$stream,
			$attrs
		);
	}

	/**
	 * Create a recent change event message
	 * @param string $stream the stream to send an event to
	 * @param LinkTarget $title
	 * @param array $attrs
	 * @return array
	 */
	public function createRecentChangeEvent( $stream, LinkTarget $title, $attrs ) {
		if ( isset( $attrs['comment'] ) ) {
			$attrs['parsedcomment'] = $this->commentFormatter->format( $attrs['comment'], $title );
		}

		$event = $this->createEvent(
			$this->getArticleURL( $title ),
			'/mediawiki/recentchange/1.0.0',
			$stream,
			$attrs
		);

		// If timestamp exists on the recentchange event (it should),
		// then use it as the meta.dt event datetime.
		if ( array_key_exists( 'timestamp', $event ) ) {
			$event['meta']['dt'] = wfTimestamp( TS_ISO_8601, $event['timestamp'] );
		}

		return $event;
	}

	/**
	 * Creates an event representing a job specification.
	 * @param string $stream the stream to send an event to
	 * @param string $wiki wikiId
	 * @param IJobSpecification $job the job specification
	 * @return array
	 */
	public function createJobEvent(
		$stream,
		$wiki,
		IJobSpecification $job
	) {
		$attrs = [
			'database' => $wiki ?: $this->dbDomain,
			'type' => $job->getType(),
		];

		if ( $job->getReleaseTimestamp() !== null ) {
			$attrs['delay_until'] = wfTimestamp( TS_ISO_8601, $job->getReleaseTimestamp() );
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

		$event = $this->createEvent(
			$url,
			'/mediawiki/job/1.0.0',
			$stream,
			$attrs,
			$wiki
		);

		// If the job provides a requestId - use it, otherwise try to get one ourselves
		if ( isset( $event['params']['requestId'] ) ) {
			$event['meta']['request_id'] = $event['params']['requestId'];
		} else {
			$event['meta']['request_id'] = WebRequest::getRequestId();
		}

		$this->signEvent( $event );

		return $event;
	}

	public function createCentralNoticeCampaignCreateEvent(
		$stream,
		$campaignName,
		UserIdentity $user,
		array $settings,
		$summary,
		$campaignUrl
	) {
		$attrs = $this->createCommonCentralNoticeAttrs( $campaignName, $user, $summary );
		$attrs += self::createCentralNoticeCampignSettingsAttrs( $settings );

		return $this->createEvent(
			$campaignUrl,
			'/mediawiki/centralnotice/campaign/create/1.0.0',
			$stream,
			$attrs
		);
	}

	public function createCentralNoticeCampaignChangeEvent(
		$stream,
		$campaignName,
		UserIdentity $user,
		array $settings,
		array $priorState,
		$summary,
		$campaignUrl
	) {
		$attrs = $this->createCommonCentralNoticeAttrs( $campaignName, $user, $summary );

		$attrs += self::createCentralNoticeCampignSettingsAttrs( $settings );
		$attrs[ 'prior_state' ] =
			$priorState ? self::createCentralNoticeCampignSettingsAttrs( $priorState ) : [];

		return $this->createEvent(
			$campaignUrl,
			'/mediawiki/centralnotice/campaign/change/1.0.0',
			$stream,
			$attrs
		);
	}

	public function createCentralNoticeCampaignDeleteEvent(
		$stream,
		$campaignName,
		UserIdentity $user,
		array $priorState,
		$summary,
		$campaignUrl
	) {
		$attrs = $this->createCommonCentralNoticeAttrs( $campaignName, $user, $summary );
		// As of 2019-06-07 the $beginSettings are *never* set in \Campaign::removeCampaignByName()
		// in the CentralNotice extension where the CentralNoticeCampaignChange hook is fired!
		$attrs[ 'prior_state' ] =
			$priorState ? self::createCentralNoticeCampignSettingsAttrs( $priorState ) : [];

		return $this->createEvent(
			$campaignUrl,
			'/mediawiki/centralnotice/campaign/delete/1.0.0',
			$stream,
			$attrs
		);
	}

	/**
	 * Creates a mediawiki/revision/recommendation-create event. Called by other extensions (for
	 * now, just GrowthExperiments) whenever they generate recommendations; the event will be used
	 * to keep the search infrastructure informed about available recommendations.
	 * @param string $stream
	 * @param string $recommendationType A type, such as 'link' or 'image'.
	 * @param RevisionRecord $revisionRecord The revision which the recommendation is based on.
	 * @return array
	 */
	public function createRecommendationCreateEvent(
		$stream,
		$recommendationType,
		RevisionRecord $revisionRecord
	) {
		$attrs = $this->createRevisionRecordAttrs( $revisionRecord );
		$attrs['recommendation_type'] = $recommendationType;

		return $this->createEvent(
			$this->getArticleURL( $revisionRecord->getPageAsLinkTarget() ),
			'/mediawiki/revision/recommendation-create/1.0.0',
			$stream,
			$attrs
		);
	}
}
