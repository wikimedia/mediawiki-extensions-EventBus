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

/**
 * Converts a RevisionRecord to an array matching the fragment/mediawiki/state/entity/revision schema
 */
class RevisionEntitySerializer {
	/**
	 * The earliest schema version supported by this serializer.
	 */
	private const SCHEMA_VERSION_EARLIEST = '2.0.0';

	/**
	 * @param RevisionRecord $revisionRecord
	 * @param string $schemaVersion
	 * @return array
	 */
	public function toArray(
		RevisionRecord $revisionRecord,
		string $schemaVersion = self::SCHEMA_VERSION_EARLIEST
	): array {
		$revAttrs = [
			'rev_id' => $revisionRecord->getId(),
			'rev_dt' => EventSerializer::timestampToDt( $revisionRecord->getTimestamp() ),
			'is_minor_edit' => $revisionRecord->isMinor(),
			'rev_sha1' => $revisionRecord->getSha1(),
			'rev_size' => $revisionRecord->getSize()
		];

		// Set rev_parent_id unless getParentId is null.
		// A rev_parent_id value of 0 indicates that there is no parent revision,
		// while null indicates that the parent revision is unknown.
		// See:
		// https://gerrit.wikimedia.org/r/plugins/gitiles/mediawiki/core/+/b8ce64464379bff6fbc00d992e1946e4a155b7e9/includes/Revision/RevisionStoreRecord.php#82
		// https://phabricator.wikimedia.org/T420974#11887286
		if ( $revisionRecord->getParentId() !== null ) {
			$revAttrs['rev_parent_id'] = $revisionRecord->getParentId();
		}

		// If getComment is null, don't set it.  However, we still set comment is an
		// empty string, to differentiate between a hidden comment and an empty comment
		// left by the editor.
		if ( $revisionRecord->getComment() !== null ) {
			$revAttrs['comment'] = $revisionRecord->getComment()->text;
		}

		// Include this revision's visibility settings.
		$revAttrs += self::bitsToVisibilityAttrs( $revisionRecord->getVisibility() );

		return $revAttrs;
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
