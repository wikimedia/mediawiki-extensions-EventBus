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
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionSlots;

/**
 * Converts a RevisionRecord to an array matching the fragment/mediawiki/state/entity/revision schema
 */
class RevisionEntitySerializer {

	/**
	 * @var RevisionSlotEntitySerializer
	 */
	private RevisionSlotEntitySerializer $revisionSlotEntitySerializer;

	/**
	 * @var UserEntitySerializer
	 */
	private UserEntitySerializer $userEntitySerializer;

	/**
	 * @param RevisionSlotEntitySerializer $contentEntitySerializer
	 * @param UserEntitySerializer $userEntitySerializer
	 */
	public function __construct(
		RevisionSlotEntitySerializer $contentEntitySerializer,
		UserEntitySerializer $userEntitySerializer
	) {
		$this->userEntitySerializer = $userEntitySerializer;
		$this->revisionSlotEntitySerializer = $contentEntitySerializer;
	}

	/**
	 * @param RevisionRecord $revisionRecord
	 * @return array
	 */
	public function toArray( RevisionRecord $revisionRecord ): array {
		$revAttrs = [
			'rev_id' => $revisionRecord->getId(),
			'rev_dt' => EventSerializer::timestampToDt( $revisionRecord->getTimestamp() ),
			'is_minor_edit' => $revisionRecord->isMinor(),
			'rev_sha1' => $revisionRecord->getSha1(),
			'rev_size' => $revisionRecord->getSize()
		];

		// The rev_parent_id attribute is not required, but when supplied
		// must have a minimum value of 1, so omit it entirely when there is no
		// parent revision (i.e. page creation).
		if ( $revisionRecord->getParentId() !== null && $revisionRecord->getParentId() > 0 ) {
			$revAttrs['rev_parent_id'] = $revisionRecord->getParentId();
		}

		// If getComment is null, don't set it.  However, we still set comment is an
		// empty string, to differentiate between a hidden comment and an empty comment
		// left by the editor.
		if ( $revisionRecord->getComment() !== null ) {
			$revAttrs['comment'] = $revisionRecord->getComment()->text;
		}

		if ( $revisionRecord->getUser() ) {
			$revAttrs['editor'] = $this->userEntitySerializer->toArray( $revisionRecord->getUser() );
		}

		// Include this revision's visibility settings.
		$revAttrs += self::bitsToVisibilityAttrs( $revisionRecord->getVisibility() );

		// Include info about the revision content slots, as long as slots are not empty.
		// Note that this DOES NOT include actual content bodies,
		// just metadata about the content in each slot.
		$contentSlots = $this->revisionSlotsToArray( $revisionRecord->getSlots() );
		if ( !empty( $contentSlots ) ) {
			$revAttrs['content_slots'] = $contentSlots;
		}

		return $revAttrs;
	}

	/**
	 * Converts RevisionSlots to a fragment/mediawiki/state/entity/revision_slots map type entity
	 * @param RevisionSlots $revisionSlots
	 * @return array
	 */
	public function revisionSlotsToArray( RevisionSlots $revisionSlots ): array {
		$slotsAttrs = [];
		foreach ( $revisionSlots->getSlots() as $slotRole => $slotRecord ) {
			$slotsAttrs[$slotRole] = $this->revisionSlotEntitySerializer->toArray( $slotRecord );
		}
		return $slotsAttrs;
	}

	/**
	 * Converts a revision visibility hidden bitfield to an array with keys
	 * of each of the possible visibility settings name mapped to a boolean.
	 *
	 * @param int $bitfield RevisionRecord $mDeleted bitfield.
	 * @return array
	 */
	public static function bitsToVisibilityAttrs( int $bitfield ): array {
		return [
			'is_content_visible' => self::isVisible( $bitfield, RevisionRecord::DELETED_TEXT ),
			'is_editor_visible'  => self::isVisible( $bitfield, RevisionRecord::DELETED_USER ),
			'is_comment_visible' => self::isVisible( $bitfield, RevisionRecord::DELETED_COMMENT ),
		];
	}

	/**
	 * Checks if RevisionRecord::DELETED_* field is set in the $hiddenBits
	 *
	 * @param int $hiddenBits revision visibility bitfield
	 * @param int $field RevisionRecord::DELETED_* field to check
	 * @return bool
	 */
	private static function isVisible( int $hiddenBits, int $field ): bool {
		// It would probably be better to use the $revisionRecord-audienceCan method
		// than use our custom logic here to compare the bits with RevisionRecord constants,
		// But then we wouldn't have a way of computing the visibleness of a revision
		// without a RevisionRecord instance, which is something we have to do in the case
		// of ArticleRevisionVisibilitySetHook, where we only get the $oldBits, but not the
		// old RevisionRecord.
		return ( $hiddenBits & $field ) != $field;
	}

}
