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

use MediaWiki\Content\IContentHandlerFactory;
use MediaWiki\Content\UnknownContentModelException;
use MediaWiki\Revision\RevisionSlots;
use MediaWiki\Revision\SlotRecord;

/**
 * Converts a {@link RevisionSlots} value object into a
 * fragment/mediawiki/state/entity/revision_slots map field.
 */
class RevisionSlotsEntitySerializer {
	/**
	 * The earliest schema version supported by this serializer.
	 */
	private const SCHEMA_VERSION_EARLIEST = '2.0.1';

	/**
	 * @var IContentHandlerFactory
	 */
	private IContentHandlerFactory $contentHandlerFactory;

	/**
	 * @param IContentHandlerFactory $contentHandlerFactory
	 */
	public function __construct(
		IContentHandlerFactory $contentHandlerFactory
	) {
		$this->contentHandlerFactory = $contentHandlerFactory;
	}

	/**
	 * @param RevisionSlots $revisionSlots
	 * @param string $schemaVersion
	 * @return array<string,array> Slot role name → serialized slot entity
	 */
	public function toArray(
		RevisionSlots $revisionSlots,
		string $schemaVersion = self::SCHEMA_VERSION_EARLIEST
	): array {
		$slotsAttrs = [];
		foreach ( $revisionSlots->getSlots() as $slotRole => $slotRecord ) {
			$slotsAttrs[$slotRole] = $this->slotToArray( $slotRecord );
		}
		return $slotsAttrs;
	}

	/**
	 * @param SlotRecord $slotRecord
	 * @return array
	 */
	private function slotToArray( SlotRecord $slotRecord ): array {
		$contentModel = $slotRecord->getModel();
		$contentFormat = $slotRecord->getFormat();

		if ( $contentFormat === null ) {
			try {
				$contentHandler = $this->contentHandlerFactory->getContentHandler( $contentModel );
				$contentFormat = $contentHandler->getDefaultFormat();
			} catch ( UnknownContentModelException ) {
				// Ignore, `content_format` is not required.
			}
		}

		$slotAttrs = [
			'slot_role' => $slotRecord->getRole(),
			'content_model' => $contentModel,
			'content_sha1' => $slotRecord->getSha1(),
			'content_size' => $slotRecord->getSize(),
		];

		if ( $contentFormat !== null ) {
			$slotAttrs['content_format'] = $contentFormat;
		}

		if ( $slotRecord->hasOrigin() ) {
			$slotAttrs['origin_rev_id'] = $slotRecord->getOrigin();
		}

		return $slotAttrs;
	}
}
