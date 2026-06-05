<?php
/**
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
 * @author Andrew Otto <otto@wikimedia.org>
 */

namespace MediaWiki\Extension\EventBus\Serializers\MediaWiki;

use MediaWiki\Extension\EventBus\Entity\PageLink;
use MediaWiki\Extension\EventBus\Serializers\EventSerializer;
use MediaWiki\Http\Telemetry;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\User\UserIdentity;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Assert\Assert;

/**
 * Methods to convert from incoming page state changes (via Hooks)
 * to a mediawiki/page/change event.
 */
class PageChangeEventSerializer {

	/**
	 * All page change events will have their $schema URI set to this.
	 * https://phabricator.wikimedia.org/T308017
	 */
	public const PAGE_CHANGE_SCHEMA_URI = '/mediawiki/page/change/1.8.0';

	/**
	 * The schema version of the user entity used when serializing users.
	 */
	public const USER_ENTITY_SCHEMA_VERSION = '1.3.0';

	/**
	 * The schema version of the revision entity used when serializing revisions.
	 */
	public const REVISION_ENTITY_SCHEMA_VERSION = '2.0.0';

	/**
	 * The schema version of the revision slots entity used when serializing revision slots.
	 */
	public const REVISION_SLOTS_ENTITY_SCHEMA_VERSION = '2.0.1';

	/**
	 * The schema version of the page link entity used when serializing page link entities.
	 */
	public const PAGE_LINK_ENTITY_SCHEMA_VERSION = '1.0.0';

	/**
	 * The schema version of the page entity used when serializing page entities.
	 */
	public const PAGE_ENTITY_SCHEMA_VERSION = '2.1.0';

	/**
	 * There are many kinds of changes that can happen to a MediaWiki pages,
	 * but only a few kinds of changes in a 'changelog' stream.
	 * This maps from a MediaWiki page change kind to a changelog kind.
	 */
	private const PAGE_CHANGE_KIND_TO_CHANGELOG_KIND_MAP = [
		'create' => 'insert',
		'edit' => 'update',
		'move' => 'update',
		'visibility_change' => 'update',
		'delete' => 'delete',
		'undelete' => 'insert',
	];

	/**
	 * @var EventSerializer
	 */
	private EventSerializer $eventSerializer;

	/**
	 * @var PageEntitySerializer
	 */
	private PageEntitySerializer $pageEntitySerializer;

	/**
	 * @var PageLinkEntitySerializer
	 */
	private PageLinkEntitySerializer $pageLinkEntitySerializer;

	/**
	 * @var UserEntitySerializer
	 */
	private UserEntitySerializer $userEntitySerializer;

	/**
	 * @var RevisionEntitySerializer
	 */
	private RevisionEntitySerializer $revisionEntitySerializer;

	/**
	 * @var RevisionSlotsEntitySerializer
	 */
	private RevisionSlotsEntitySerializer $revisionSlotsEntitySerializer;

	/**
	 * @param EventSerializer $eventSerializer
	 * @param PageEntitySerializer $pageEntitySerializer
	 * @param PageLinkEntitySerializer $pageLinkEntitySerializer
	 * @param UserEntitySerializer $userEntitySerializer
	 * @param RevisionEntitySerializer $revisionEntitySerializer
	 * @param RevisionSlotsEntitySerializer $revisionSlotsEntitySerializer
	 */
	public function __construct(
		EventSerializer $eventSerializer,
		PageEntitySerializer $pageEntitySerializer,
		PageLinkEntitySerializer $pageLinkEntitySerializer,
		UserEntitySerializer $userEntitySerializer,
		RevisionEntitySerializer $revisionEntitySerializer,
		RevisionSlotsEntitySerializer $revisionSlotsEntitySerializer
	) {
		$this->eventSerializer = $eventSerializer;
		$this->pageEntitySerializer = $pageEntitySerializer;
		$this->pageLinkEntitySerializer = $pageLinkEntitySerializer;
		$this->userEntitySerializer = $userEntitySerializer;
		$this->revisionEntitySerializer = $revisionEntitySerializer;
		$this->revisionSlotsEntitySerializer = $revisionSlotsEntitySerializer;
	}

	/**
	 * Uses EventSerializer to create the mediawiki/page/change event for the given $eventAttrs
	 * @param string $stream
	 * @param ProperPageIdentity $page
	 * @param array $eventAttrs
	 * @return array
	 */
	private function toEvent(
		string $stream,
		ProperPageIdentity $page,
		array $eventAttrs,
	): array {
		// NOTE: It would be better if wiki domain name was fetched and passed into createEvent,
		// rather than forcing EventSerializer->createEvent to look up the domain itself.
		// However, this would require changing the createEvent method signature, which is used
		// by CirrusSearch extension.
		// See: https://phabricator.wikimedia.org/T392516
		return $this->eventSerializer->createEvent(
			self::PAGE_CHANGE_SCHEMA_URI,
			$stream,
			$this->pageEntitySerializer->canonicalPageURL( $page ),
			$eventAttrs,
			self::getWikiId( $page ),
			null,
			// NOTE: This is using a global Telemetry instance, and it might be better to
			// pass $requestId here as a parameter (obtaining the value of it in
			// the PageChangeEventIngress handler instead of here in the serializer).
			// We'd have to make every event handler here also accept $requestId as a parameter.
			// This might be a good refactor for later, but for now this is fine.
			Telemetry::getInstance()->getRequestId(),
		);
	}

	/**
	 * Returns the appropriate changelog kind given a pageChangeKind.
	 * @param string $pageChangeKind
	 * @return string
	 */
	private static function getChangelogKind( string $pageChangeKind ): string {
		Assert::parameter(
			array_key_exists( $pageChangeKind, self::PAGE_CHANGE_KIND_TO_CHANGELOG_KIND_MAP ),
			'$pageChangeKind',
			"Unsupported pageChangeKind '$pageChangeKind'.  Must be one of " .
			implode( ',', array_keys( self::PAGE_CHANGE_KIND_TO_CHANGELOG_KIND_MAP ) )
		);

		return self::PAGE_CHANGE_KIND_TO_CHANGELOG_KIND_MAP[$pageChangeKind];
	}

	/**
	 * Return the page's wikiId, or if that returns false,
	 * return WikiMap::getCurrentWikiId.
	 *
	 * @param ProperPageIdentity $page
	 * @return string
	 */
	private static function getWikiId( ProperPageIdentity $page ): string {
		// Get the wikiId.  page's getWikiId can return false.
		// Fallback to global WikiMap.
		return $page->getWikiId() ?: WikiMap::getCurrentWikiId();
	}

	/**
	 * DRY helper to set event fields common to all page change events.
	 * @param string $page_change_kind
	 * @param string $dt
	 * @param ProperPageIdentity $page
	 * @param UserIdentity|null $performer
	 * @param RevisionRecord|null $currentRevision
	 * @param PageLink|null $redirectTarget
	 * @param string|null $comment
	 * @return array
	 */
	private function toCommonAttrs(
		string $page_change_kind,
		string $dt,
		ProperPageIdentity $page,
		?UserIdentity $performer,
		?RevisionRecord $currentRevision = null,
		?PageLink $redirectTarget = null,
		?string $comment = null
	): array {
		$eventAttrs = [
			'changelog_kind' => self::getChangelogKind( $page_change_kind ),
			'page_change_kind' => $page_change_kind,
			'dt' => $dt,
			'wiki_id' => self::getWikiId( $page ),
			'page' => $this->pageEntitySerializer->toArray( $page, self::PAGE_ENTITY_SCHEMA_VERSION ),
		];

		if ( $redirectTarget !== null ) {
			$eventAttrs['page']['redirect_page_link'] = $this->pageLinkEntitySerializer->toArray(
				$redirectTarget,
				self::PAGE_LINK_ENTITY_SCHEMA_VERSION
			);
		}

		if ( $performer !== null ) {
			$eventAttrs['performer'] = $this->userEntitySerializer->toArray(
				$performer,
				self::USER_ENTITY_SCHEMA_VERSION
			);
		}

		if ( $comment !== null ) {
			$eventAttrs['comment'] = $comment;
		}

		if ( $currentRevision !== null ) {
			$eventAttrs['revision'] = $this->toRevisionAttrs( $currentRevision );
		}

		return $eventAttrs;
	}

	/**
	 * Since mediawiki/page/change 1.3.1, editor is no longer a field of the revision entity schema.
	 * It is added to revision fields in page change schema instead.
	 * So, we DRY setting revision and revision.editor here.
	 * This allows us to control the user entity schema version used for serializing the editor here.
	 */
	private function toRevisionAttrs( RevisionRecord $revisionRecord ): array {
		$revisionAttrs = $this->revisionEntitySerializer->toArray(
			$revisionRecord,
			self::REVISION_ENTITY_SCHEMA_VERSION,
		);

		// Add revision.editor
		if ( $revisionRecord->getUser() ) {
			$revisionAttrs['editor'] = $this->userEntitySerializer->toArray(
				$revisionRecord->getUser(),
				self::USER_ENTITY_SCHEMA_VERSION
			);
		}

		// Add revision.content_slots as long as slots are not empty.
		// Note that this DOES NOT include actual content bodies,
		// just metadata about the content in each slot role.
		$slotsAttrs = $this->revisionSlotsEntitySerializer->toArray(
			$revisionRecord->getSlots(),
			self::REVISION_SLOTS_ENTITY_SCHEMA_VERSION
		);
		if ( $slotsAttrs ) {
			$revisionAttrs['content_slots'] = $slotsAttrs;
		}
		return $revisionAttrs;
	}

	/**
	 * Converts from the given page and RevisionRecord to a page_change_kind: create event.
	 *
	 * @param string $stream
	 * @param ProperPageIdentity $page
	 * @param UserIdentity $performer
	 * @param RevisionRecord $currentRevision
	 * @param PageLink|null $redirectTarget
	 * @return array
	 */
	public function toCreateEvent(
		string $stream,
		ProperPageIdentity $page,
		UserIdentity $performer,
		RevisionRecord $currentRevision,
		?PageLink $redirectTarget = null
	): array {
		$eventAttrs = $this->toCommonAttrs(
			'create',
			$this->eventSerializer->timestampToDt( $currentRevision->getTimestamp() ),
			$page,
			$performer,
			$currentRevision,
			$redirectTarget,
			null
		);

		return $this->toEvent( $stream, $page, $eventAttrs );
	}

	/**
	 * Converts from the given page and RevisionRecord to a page_change_kind: edit event.
	 *
	 * @param string $stream
	 * @param ProperPageIdentity $page
	 * @param UserIdentity $performer
	 * @param RevisionRecord $currentRevision
	 * @param PageLink|null $redirectTarget
	 * @param RevisionRecord|null $parentRevision
	 * @return array
	 */
	public function toEditEvent(
		string $stream,
		ProperPageIdentity $page,
		UserIdentity $performer,
		RevisionRecord $currentRevision,
		?PageLink $redirectTarget = null,
		?RevisionRecord $parentRevision = null
	): array {
		$eventAttrs = $this->toCommonAttrs(
			'edit',
			$this->eventSerializer->timestampToDt( $currentRevision->getTimestamp() ),
			$page,
			$performer,
			$currentRevision,
			$redirectTarget,
			null
		);

		// On edit, the prior state is all about the previous revision.
		if ( $parentRevision !== null ) {
			$eventAttrs['prior_state'] = [
				'revision' => $this->toRevisionAttrs( $parentRevision ),
			];
		}

		return $this->toEvent( $stream, $page, $eventAttrs );
	}

	/**
	 * Converts from the given page, RevisionRecord
	 * and old title ProperPageIdentity to a page_change_kind: move event.
	 *
	 * @param string $stream
	 * @param ProperPageIdentity $page
	 * @param UserIdentity $performer
	 * @param RevisionRecord $currentRevision
	 * @param RevisionRecord $parentRevision
	 * @param ProperPageIdentity $oldTitle Page identity at the title/namespace before the move
	 * @param string $reason
	 * @param ProperPageIdentity|null $createdRedirectWikiPage
	 * @param PageLink|null $redirectTarget
	 * @return array
	 */
	public function toMoveEvent(
		string $stream,
		ProperPageIdentity $page,
		UserIdentity $performer,
		RevisionRecord $currentRevision,
		RevisionRecord $parentRevision,
		ProperPageIdentity $oldTitle,
		string $reason,
		?ProperPageIdentity $createdRedirectWikiPage = null,
		?PageLink $redirectTarget = null
	): array {
		$eventAttrs = $this->toCommonAttrs(
			'move',
			// NOTE: This uses the newly created revision's timestamp as the page move event time,
			// for lack of a better 'move time'.
			$this->eventSerializer->timestampToDt( $currentRevision->getTimestamp() ),
			$page,
			$performer,
			$currentRevision,
			$redirectTarget,
			// NOTE: the reason for the page move is used to generate the comment
			// on the revision created by the page move, but it is not the same!
			$reason
		);

		// If a new redirect page was created during this move, then include
		// some information about it.
		if ( $createdRedirectWikiPage ) {
			$eventAttrs['created_redirect_page'] = $this->pageEntitySerializer
				->toArray( $createdRedirectWikiPage, self::PAGE_ENTITY_SCHEMA_VERSION );
		}

		// On move, prior_state.page lists only page entity fields that differ from the current page;
		// prior_state.revision is the revision before the move (a move creates a new revision).
		$priorStateAttrs = [];

		// Only keep fields that differ from the current page.
		$priorStateAttrs['page'] = array_diff_assoc(
			$this->pageEntitySerializer->toArray( $oldTitle, self::PAGE_ENTITY_SCHEMA_VERSION ),
			$eventAttrs['page']
		);

		// add parent revision info in prior_state, since a page move creates a new revision.
		$priorStateAttrs['revision'] = $this->toRevisionAttrs( $parentRevision );

		$eventAttrs['prior_state'] = $priorStateAttrs;

		return $this->toEvent( $stream, $page, $eventAttrs );
	}

	/**
	 * Converts from the given page, RevisionRecord to a page_change_kind: delete event.
	 *
	 * NOTE: If $isSuppression is true, the current revision info emitted by this even will have
	 * all of its visibility settings set to false.
	 * A consumer of this event probably doesn't care, because they should delete the page
	 * and revision in response to this event anyway.
	 *
	 * @param string $stream
	 * @param ProperPageIdentity $page
	 * @param UserIdentity|null $performer
	 * @param RevisionRecord $currentRevision
	 * @param string $reason
	 * @param string|null $eventTimestamp
	 * @param int|null $archivedRevisionCount
	 * @param PageLink|null $redirectTarget
	 * @param bool $isSuppression
	 *  If true, the current revision info emitted by this even will have
	 *  all of its visibility settings set to false.
	 *  A consumer of this event probably doesn't care, because they should delete the page
	 *  and revision in response to this event anyway.
	 * @return array
	 */
	public function toDeleteEvent(
		string $stream,
		ProperPageIdentity $page,
		?UserIdentity $performer,
		RevisionRecord $currentRevision,
		string $reason,
		?string $eventTimestamp = null,
		?int $archivedRevisionCount = null,
		?PageLink $redirectTarget = null,
		bool $isSuppression = false
	): array {
		$eventAttrs = $this->toCommonAttrs(
			'delete',
			$this->eventSerializer->timestampToDt( $eventTimestamp ),
			$page,
			$performer,
			$currentRevision,
			$redirectTarget,
			$reason
		);

		// page delete specific fields:
		if ( $archivedRevisionCount !== null ) {
			$eventAttrs['page']['revision_count'] = $archivedRevisionCount;
		}

		// If this is a full page suppression, then we need to represent that fact that
		// the current revision (and also all revisions of this page) is having its visibility changed
		// to fully hidden, AKA SUPPRESSED_ALL, and delete any fields that might contain information
		// that has been suppressed.
		// NOTE: It would be better if $currentRevision itself had its visibility settings
		// set to the same as the 'deleted/archived' revision, but it is not because
		// MediaWiki is pretty weird with archived revisions.
		// See: https://phabricator.wikimedia.org/T308017#8339347
		if ( $isSuppression ) {
			$eventAttrs['revision'] = array_merge(
				$eventAttrs['revision'],
				$this->revisionEntitySerializer->bitsToVisibilityAttrs( RevisionRecord::SUPPRESSED_ALL )
			);

			unset( $eventAttrs['revision']['rev_size'] );
			unset( $eventAttrs['revision']['rev_sha1'] );
			unset( $eventAttrs['revision']['comment'] );
			unset( $eventAttrs['revision']['editor'] );
			unset( $eventAttrs['revision']['content_slots'] );

			// $currentRevision actually has the prior revision visibility info in the case of page suppression.
			$eventAttrs['prior_state']['revision'] = $this->revisionEntitySerializer->bitsToVisibilityAttrs(
				$currentRevision->getVisibility()
			);
		}

		return $this->toEvent( $stream, $page, $eventAttrs );
	}

	/**
	 * Converts from the given page, RevisionRecord to a page_change_kind: undelete event.
	 *
	 * @param string $stream
	 * @param ProperPageIdentity $page
	 * @param UserIdentity $performer
	 * @param RevisionRecord $currentRevision
	 * @param string $reason
	 * @param PageLink|null $redirectTarget
	 * @param string|null $eventTimestamp
	 * @param int|null $oldPageID
	 * @return array
	 */
	public function toUndeleteEvent(
		string $stream,
		ProperPageIdentity $page,
		UserIdentity $performer,
		RevisionRecord $currentRevision,
		string $reason,
		?PageLink $redirectTarget = null,
		?string $eventTimestamp = null,
		?int $oldPageID = null
	): array {
		$eventAttrs = $this->toCommonAttrs(
			'undelete',
			$this->eventSerializer->timestampToDt( $eventTimestamp ),
			$page,
			$performer,
			$currentRevision,
			$redirectTarget,
			$reason
		);

		// If this page had a different id in the archive table,
		// then save it as the prior_state page_id.  This will
		// be the page_id that the page had before it was deleted,
		// which is the same as the page_id that it had while it was
		// in the archive table.
		// Usually page_id will be the same, but there are some historical
		// edge cases where a new page_id is created as part of an undelete.
		if ( $oldPageID && $oldPageID != $page->getId() ) {
			$eventAttrs['prior_state'] = [
				'page' => [
					'page_id' => $oldPageID
				]
			];
		}

		return $this->toEvent( $stream, $page, $eventAttrs );
	}

	/**
	 * Converts from the given page, RevisionRecord and previous RevisionRecord's visibility (deleted)
	 * bitfield to a page_change_kind: visibility_change event.
	 *
	 * @param string $stream
	 * @param ProperPageIdentity $page
	 * @param UserIdentity|null $performer
	 * @param RevisionRecord $currentRevision
	 * @param int $priorVisibilityBitfield
	 * @param string|null $eventTimestamp
	 * @return array
	 */
	public function toVisibilityChangeEvent(
		string $stream,
		ProperPageIdentity $page,
		?UserIdentity $performer,
		RevisionRecord $currentRevision,
		int $priorVisibilityBitfield,
		?string $eventTimestamp = null
	) {
		$eventAttrs = $this->toCommonAttrs(
			'visibility_change',
			$this->eventSerializer->timestampToDt( $eventTimestamp ),
			$page,
			$performer,
			$currentRevision,
			# NOTE: ArticleRevisionVisibilitySet does not give us the 'reason' (comment)
			# the visibility has been changed.  This info is provided in the UI by the user,
			# where does it go?
			# https://phabricator.wikimedia.org/T321411
			null
		);

		// During a visibility change, we are only representing the change to the revision's
		// visibility. The rev_id that is being modified is at revision.rev_id.
		// The rev_id has not changed. The prior_state.revision object will not contain
		// any duplicate information about this revision. It will only contain the
		// prior visibility fields for this revision that have been changed
		$priorVisibilityFields = $this->revisionEntitySerializer->bitsToVisibilityAttrs( $priorVisibilityBitfield );
		$eventAttrs['prior_state']['revision'] = [];
		foreach ( $priorVisibilityFields as $key => $value ) {
			// Only set the old visibility field in prior state if it has changed.
			if ( $eventAttrs['revision'][$key] !== $value ) {
				$eventAttrs['prior_state']['revision'][$key] = $value;
			}
		}

		return $this->toEvent( $stream, $page, $eventAttrs );
	}
}
