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

use MediaWiki\Extension\EventBus\Serializers\EventSerializer;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Revision\RevisionRecord;
use MWException;
use User;
use WikiMap;
use Wikimedia\Assert\Assert;
use WikiPage;

/**
 * Methods to convert from incoming page state changes (via Hooks)
 * to a mediawiki/page/change event.
 */
class PageChangeEventSerializer {

	/**
	 * All page change events will have their $schema URI set to this.
	 * https://phabricator.wikimedia.org/T308017
	 */
	public const PAGE_CHANGE_SCHEMA_URI = '/mediawiki/page/change/1.0.0';

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
	 * @var UserEntitySerializer
	 */
	private UserEntitySerializer $userEntitySerializer;

	/**
	 * @var RevisionEntitySerializer
	 */
	private RevisionEntitySerializer $revisionEntitySerializer;

	/**
	 * @param EventSerializer $eventSerializer
	 * @param PageEntitySerializer $pageEntitySerializer
	 * @param UserEntitySerializer $userEntitySerializer
	 * @param RevisionEntitySerializer $revisionEntitySerializer
	 */
	public function __construct(
		EventSerializer $eventSerializer,
		PageEntitySerializer $pageEntitySerializer,
		UserEntitySerializer $userEntitySerializer,
		RevisionEntitySerializer $revisionEntitySerializer
	) {
		$this->eventSerializer = $eventSerializer;
		$this->pageEntitySerializer = $pageEntitySerializer;
		$this->userEntitySerializer = $userEntitySerializer;
		$this->revisionEntitySerializer = $revisionEntitySerializer;
	}

	/**
	 * Uses EventSerializer to create the mediawiki/page/change event for the given $eventAttrs
	 * @param string $stream
	 * @param WikiPage $wikiPage
	 * @param array $eventAttrs
	 * @return array
	 */
	private function toEvent( string $stream, WikiPage $wikiPage, array $eventAttrs ): array {
		return $this->eventSerializer->createEvent(
			self::PAGE_CHANGE_SCHEMA_URI,
			$stream,
			$this->pageEntitySerializer->canonicalPageURL( $wikiPage ),
			$eventAttrs
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
	 * DRY helper to set event fields common to all page change events.
	 * @param string $page_change_kind
	 * @param string $dt
	 * @param WikiPage $wikiPage
	 * @param User $performer
	 * @param RevisionRecord|null $currentRevision
	 * @param string|null $comment
	 * @return array
	 * @throws MWException
	 */
	private function toCommonAttrs(
		string $page_change_kind,
		string $dt,
		WikiPage $wikiPage,
		User $performer,
		?RevisionRecord $currentRevision = null,
		?string $comment = null
	): array {
		$eventAttrs = [
			'changelog_kind' => self::getChangelogKind( $page_change_kind ),
			'page_change_kind' => $page_change_kind,
			'dt' => $dt,
			# Ideally, wiki_id would come from a dependency injected MediaWikiService,
			# But for now, the best place to get it is from WikiMap, which ultimately uses globals.
			'wiki_id' => WikiMap::getCurrentWikiId(),
			'page' => $this->pageEntitySerializer->toArray( $wikiPage ),
			'performer' => $this->userEntitySerializer->toArray( $performer )
		];

		if ( $comment !== null ) {
			$eventAttrs['comment'] = $comment;
		}

		if ( $currentRevision !== null ) {
			$eventAttrs['revision'] = $this->revisionEntitySerializer->toArray( $currentRevision );
		}

		return $eventAttrs;
	}

	/**
	 * Converts from the given WikiPage and RevisionRecord to a page_change_kind: create event.
	 *
	 * @param string $stream
	 * @param WikiPage $wikiPage
	 * @param User $performer
	 * @param RevisionRecord $currentRevision
	 * @return array
	 * @throws MWException
	 */
	public function toCreateEvent(
		string $stream,
		WikiPage $wikiPage,
		User $performer,
		RevisionRecord $currentRevision
	): array {
		$eventAttrs = $this->toCommonAttrs(
			'create',
			$this->eventSerializer->timestampToDt( $currentRevision->getTimestamp() ),
			$wikiPage,
			$performer,
			$currentRevision,
			null
		);

		return $this->toEvent( $stream, $wikiPage, $eventAttrs );
	}

	/**
	 * Converts from the given WikiPage and RevisionRecord to a page_change_kind: edit event.
	 *
	 * @param string $stream
	 * @param WikiPage $wikiPage
	 * @param User $performer
	 * @param RevisionRecord $currentRevision
	 * @param RevisionRecord|null $parentRevision
	 * @return array
	 */
	public function toEditEvent(
		string $stream,
		WikiPage $wikiPage,
		User $performer,
		RevisionRecord $currentRevision,
		?RevisionRecord $parentRevision = null
	): array {
		$eventAttrs = $this->toCommonAttrs(
			'edit',
			$this->eventSerializer->timestampToDt( $currentRevision->getTimestamp() ),
			$wikiPage,
			$performer,
			$currentRevision,
			null
		);

		// On edit, the prior state is all about the previous revision.
		if ( $parentRevision !== null ) {
			$priorStateAttrs = [];
			$priorStateAttrs['revision'] = $this->revisionEntitySerializer->toArray( $parentRevision );
			$eventAttrs['prior_state'] = $priorStateAttrs;
		}

		return $this->toEvent( $stream, $wikiPage, $eventAttrs );
	}

	/**
	 * Converts from the given WikiPage, RevisionRecord
	 * and old title LinkTarget to a page_change_kind: move event.
	 *
	 * @param string $stream
	 * @param WikiPage $wikiPage
	 * @param User $performer
	 * @param RevisionRecord $currentRevision
	 * @param RevisionRecord $parentRevision
	 * @param LinkTarget $oldTitle
	 * @param string $reason
	 * @param WikiPage|null $createdRedirectWikiPage
	 * @return array
	 * @throws MWException
	 */
	public function toMoveEvent(
		string $stream,
		WikiPage $wikiPage,
		User $performer,
		RevisionRecord $currentRevision,
		RevisionRecord $parentRevision,
		LinkTarget $oldTitle,
		string $reason,
		?WikiPage $createdRedirectWikiPage = null
	): array {
		$eventAttrs = $this->toCommonAttrs(
			'move',
			// NOTE: This uses the newly created revision's timestamp as the page move event time,
			// for lack of a better 'move time'.
			$this->eventSerializer->timestampToDt( $currentRevision->getTimestamp() ),
			$wikiPage,
			$performer,
			$currentRevision,
			// NOTE: the reason for the page move is used to generate the comment
			// on the revision created by the page move, but it is not the same!
			$reason
		);

		// If a new redirect page was created during this move, then include
		// some information about it.
		if ( $createdRedirectWikiPage ) {
			$eventAttrs['created_redirect_page'] = $this->pageEntitySerializer->toArray( $createdRedirectWikiPage );
		}

		// On move, the prior state is about page title, namespace, and also the previous revision.
		// (Page moves create a new revision of a page).
		$priorStateAttrs = [];

		// Add page.page_title prior_state info.
		// Only add the old page_title to prior_state,
		// as the page_id and namespace have not changed.
		$priorStateAttrs['page'] = [
			'page_title' => $this->pageEntitySerializer->formatLinkTarget( $oldTitle )
		];

		// add parent revision info in prior_state, since a page move creates a new revision.
		$priorStateAttrs['revision'] = $this->revisionEntitySerializer->toArray( $parentRevision );

		$eventAttrs['prior_state'] = $priorStateAttrs;

		return $this->toEvent( $stream, $wikiPage, $eventAttrs );
	}

	/**
	 * Converts from the given WikiPage, RevisionRecord to a page_change_kind: delete event.
	 *
	 * NOTE: If $isSuppression is true, the current revision info emitted by this even will have
	 * all of its visibility settings set to false.
	 * A consumer of this event probably doesn't care, because they should delete the page
	 * and revision in response to this event anyway.
	 *
	 * @param string $stream
	 * @param WikiPage $wikiPage
	 * @param User $performer
	 * @param RevisionRecord $currentRevision
	 * @param string $reason
	 * @param string|null $eventTimestamp
	 * @param int|null $archivedRevisionCount
	 * @param bool $isSuppression
	 *  If true, the current revision info emitted by this even will have
	 *  all of its visibility settings set to false.
	 *  A consumer of this event probably doesn't care, because they should delete the page
	 *  and revision in response to this event anyway.
	 * @return array
	 * @throws MWException
	 */
	public function toDeleteEvent(
		string $stream,
		WikiPage $wikiPage,
		User $performer,
		RevisionRecord $currentRevision,
		string $reason,
		?string $eventTimestamp = null,
		?int $archivedRevisionCount = null,
		bool $isSuppression = false
	): array {
		$eventAttrs = $this->toCommonAttrs(
			'delete',
			$this->eventSerializer->timestampToDt( $eventTimestamp ),
			$wikiPage,
			$performer,
			$currentRevision,
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

		return $this->toEvent( $stream, $wikiPage, $eventAttrs );
	}

	/**
	 * Converts from the given WikiPage, RevisionRecord to a page_change_kind: undelete event.
	 *
	 * @param string $stream
	 * @param WikiPage $wikiPage
	 * @param User $performer
	 * @param RevisionRecord $currentRevision
	 * @param string $reason
	 * @param string|null $eventTimestamp
	 * @param int|null $oldPageID
	 * @return array
	 * @throws MWException
	 */
	public function toUndeleteEvent(
		string $stream,
		WikiPage $wikiPage,
		User $performer,
		RevisionRecord $currentRevision,
		string $reason,
		?string $eventTimestamp = null,
		?int $oldPageID = null
	): array {
		$eventAttrs = $this->toCommonAttrs(
			'undelete',
			$this->eventSerializer->timestampToDt( $eventTimestamp ),
			$wikiPage,
			$performer,
			$currentRevision,
			$reason
		);

		// If this page had a different id in the archive table,
		// then save it as the prior_state page_id.  This will
		// be the page_id that the page had before it was deleted,
		// which is the same as the page_id that it had while it was
		// in the archive table.
		// Usually page_id will be the same, but there are some historical
		// edge cases where a new page_id is created as part of an undelete.
		if ( $oldPageID && $oldPageID != $wikiPage->getId() ) {
			$eventAttrs['prior_state'] = [
				'page' => [
					'page_id' => $oldPageID
				]
			];
		}

		return $this->toEvent( $stream, $wikiPage, $eventAttrs );
	}

	/**
	 * Converts from the given WikiPage, RevisionRecord and previous RevisionRecord's visibility (deleted)
	 * bitfield to a page_change_kind: visibility_change event.
	 *
	 * @param string $stream
	 * @param WikiPage $wikiPage
	 * @param User $performer
	 * @param RevisionRecord $currentRevision
	 * @param int $priorVisibilityBitfield
	 * @param string|null $eventTimestamp
	 * @return array
	 * @throws MWException
	 */
	public function toVisibilityChangeEvent(
		string $stream,
		WikiPage $wikiPage,
		User $performer,
		RevisionRecord $currentRevision,
		int $priorVisibilityBitfield,
		?string $eventTimestamp = null
	) {
		$eventAttrs = $this->toCommonAttrs(
			'visibility_change',
			$this->eventSerializer->timestampToDt( $eventTimestamp ),
			$wikiPage,
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

		return $this->toEvent( $stream, $wikiPage, $eventAttrs );
	}
}
