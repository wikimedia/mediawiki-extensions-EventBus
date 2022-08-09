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
use MediaWiki\Revision\SlotRecord;
use MWException;
use MWUnknownContentModelException;

/**
 * Converts a SlotRecord into an array that matches the
 * fragment/mediawiki/state/entity/revision_slot schema,
 * without content bodies.
 */
class RevisionSlotEntitySerializer {
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
	 * @param SlotRecord $slotRecord
	 * @return array
	 * @throws MWException
	 */
	public function toArray( SlotRecord $slotRecord ): array {
		$contentModel = $slotRecord->getModel();
		$contentFormat = $slotRecord->getFormat();

		if ( $contentFormat === null ) {
			try {
				$contentHandler = $this->contentHandlerFactory->getContentHandler( $contentModel );
				$contentFormat = $contentHandler->getDefaultFormat();
			} catch ( MWUnknownContentModelException $e ) {
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
			// unclear if necessary to guard against missing origin in this context but since it
			// might fail on unsaved content we are better safe than sorry
			$slotAttrs['origin_rev_id'] = $slotRecord->getOrigin();
		}

		return $slotAttrs;
	}
}
