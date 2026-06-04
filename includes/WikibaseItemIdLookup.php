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
 */

namespace MediaWiki\Extension\EventBus;

use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Page\PageReference;
use MediaWiki\Title\TitleFactory;
use Wikibase\Lib\Store\EntityIdLookup;

/**
 * Looks up the Wikibase item id (e.g. "Q937") linked to a local wiki page.
 *
 * The link is stored in the page's wikibase_item page property by Wikibase
 * Client, which is an optional extension. When Wikibase Client is not loaded,
 * $entityIdLookup is null and all lookups return null.
 *
 * See https://phabricator.wikimedia.org/T428176
 */
class WikibaseItemIdLookup {

	public function __construct(
		private readonly TitleFactory $titleFactory,
		private readonly ?EntityIdLookup $entityIdLookup = null,
	) {
	}

	/**
	 * Returns the Wikibase item id linked to $page, or null if there is none.
	 *
	 * @param PageReference $page
	 * @return string|null
	 */
	public function getWikibaseItemIdForPage( PageReference $page ): ?string {
		// wikibase_item is a local page property, so a page belonging to another
		// wiki has none to read here. Bail before newFromPageReference(), which
		// throws for non-local pages.
		if ( $this->entityIdLookup === null || $page->getWikiId() !== WikiAwareEntity::LOCAL ) {
			return null;
		}

		$entityId = $this->entityIdLookup->getEntityIdForTitle(
			$this->titleFactory->newFromPageReference( $page )
		);

		return $entityId?->getSerialization();
	}

	/**
	 * Returns the Wikibase item id linked to the page $linkTarget points at, or
	 * null if there is none.
	 *
	 * @param LinkTarget $linkTarget
	 * @return string|null
	 */
	public function getWikibaseItemIdForLinkTarget( LinkTarget $linkTarget ): ?string {
		// Interwiki targets are not pages on this wiki, so they have no local
		// wikibase_item page property to read.
		if ( $this->entityIdLookup === null || $linkTarget->isExternal() ) {
			return null;
		}

		$entityId = $this->entityIdLookup->getEntityIdForTitle(
			$this->titleFactory->newFromLinkTarget( $linkTarget )
		);

		return $entityId?->getSerialization();
	}
}
