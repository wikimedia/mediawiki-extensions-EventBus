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
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\WikiMap\WikiMap;
use Wikibase\Client\RepoLinker;
use Wikibase\DataAccess\DatabaseEntitySource;
use Wikibase\DataAccess\EntitySourceDefinitions;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\Item;
use Wikibase\Lib\Store\EntityIdLookup;

/**
 * Used to Look up the Wikibase item id (e.g. "Q937") associated with a local wiki page,
 * the wiki_id of the Wikibase repository hosting that item (e.g.
 * "wikidatawiki"), and that item's concept URI (e.g.
 * "http://www.wikidata.org/entity/Q937").
 *
 * The association is usually a sitelink, recorded in the page's wikibase_item
 * page property by Wikibase Client, which is an optional extension. When
 * Wikibase Client is not loaded, $entityIdLookup is null and all lookups
 * return null.
 *
 * Only items are reported, any other entity type returns null.
 * E.g. extensions can register entity types with it client side. WikibaseMediaInfo does, so on
 * Commons every file page resolves to the MediaInfo entity hosted in one of its
 * slots; a page hosting an entity is not a page associated with a wikibase item, so
 * non-items are filtered out here.
 *
 * See https://phabricator.wikimedia.org/T428176
 */
class WikibaseItemLookup {

	public function __construct(
		private readonly TitleFactory $titleFactory,
		private readonly ?EntityIdLookup $entityIdLookup = null,
		private readonly ?EntitySourceDefinitions $entitySourceDefinitions = null,
		private readonly ?RepoLinker $repoLinker = null,
	) {
	}

	/**
	 * Returns the Wikibase item id linked to $page, or null if there is none.
	 *
	 * @param PageReference $page
	 * @return string|null
	 */
	public function getWikibaseItemIdForPage( PageReference $page ): ?string {
		return $this->getItemForPage( $page )?->getSerialization();
	}

	/**
	 * Returns the wiki id of the Wikibase wiki hosting the item linked to
	 * $page, or null if $page has no linked item.
	 *
	 * @param PageReference $page
	 * @return string|null
	 */
	public function getWikibaseWikiIdForPage( PageReference $page ): ?string {
		$itemId = $this->getItemForPage( $page );

		return $itemId === null ? null : $this->getWikibaseWikiId( $itemId );
	}

	/**
	 * Returns the concept URI of the item linked to $page, e.g.
	 * "http://www.wikidata.org/entity/Q937", or null if $page has no linked item.
	 *
	 * @param PageReference $page
	 * @return string|null
	 */
	public function getWikibaseConceptUriForPage( PageReference $page ): ?string {
		$itemId = $this->getItemForPage( $page );

		return $itemId === null ? null : $this->getWikibaseConceptUri( $itemId );
	}

	/**
	 * Returns the Wikibase item id linked to the page $linkTarget points at, or
	 * null if there is none.
	 *
	 * @param LinkTarget $linkTarget
	 * @return string|null
	 */
	public function getWikibaseItemIdForLinkTarget( LinkTarget $linkTarget ): ?string {
		return $this->getItemForLinkTarget( $linkTarget )?->getSerialization();
	}

	/**
	 * Returns the wiki id of the Wikibase repository hosting the item linked to
	 * the page $linkTarget points at, or null if that page has no linked item.
	 *
	 * @param LinkTarget $linkTarget
	 * @return string|null
	 */
	public function getWikibaseWikiIdForLinkTarget( LinkTarget $linkTarget ): ?string {
		$itemId = $this->getItemForLinkTarget( $linkTarget );

		return $itemId === null ? null : $this->getWikibaseWikiId( $itemId );
	}

	/**
	 * Returns the concept URI of the item linked to the page $linkTarget points
	 * at, or null if that page has no linked item.
	 *
	 * @param LinkTarget $linkTarget
	 * @return string|null
	 */
	public function getWikibaseConceptUriForLinkTarget( LinkTarget $linkTarget ): ?string {
		$itemId = $this->getItemForLinkTarget( $linkTarget );

		return $itemId === null ? null : $this->getWikibaseConceptUri( $itemId );
	}

	private function getItemForPage( PageReference $page ): ?EntityId {
		// wikibase_item is a local page property, so a page belonging to another
		// wiki has none to read here. Bail before newFromPageReference(), which
		// throws for non-local pages.
		if ( $this->entityIdLookup === null || $page->getWikiId() !== WikiAwareEntity::LOCAL ) {
			return null;
		}

		return $this->getItemForTitle( $this->titleFactory->newFromPageReference( $page ) );
	}

	private function getItemForLinkTarget( LinkTarget $linkTarget ): ?EntityId {
		// Interwiki targets are not pages on this wiki, so they have no local
		// wikibase_item page property to read.
		if ( $this->entityIdLookup === null || $linkTarget->isExternal() ) {
			return null;
		}

		return $this->getItemForTitle( $this->titleFactory->newFromLinkTarget( $linkTarget ) );
	}

	private function getItemForTitle( Title $title ): ?EntityId {
		$entityId = $this->entityIdLookup->getEntityIdForTitle( $title );

		// EntityIdLookup resolves whichever Wikibase entity a page is associated
		// with, dispatching on the page's content model before falling back to
		// the wikibase_item page property. On a repository that includes entities
		// the page hosts rather than links to, e.g. the MediaInfo id of every
		// Commons file page, so anything that is not an item is dropped here.
		if ( $entityId === null || $entityId->getEntityType() !== Item::ENTITY_TYPE ) {
			return null;
		}

		return $entityId;
	}

	/**
	 * Returns the database entity source that hosts $itemId, or null if this
	 * wiki has none for its entity type.
	 */
	private function getDatabaseEntitySource( EntityId $itemId ): ?DatabaseEntitySource {
		// Which repository a wiki's Wikibase Client is connected to is configurable,
		// so the source is resolved from the id rather than assumed to be Wikidata.
		// This is the database half of what Wikibase's own EntitySourceLookup does;
		// its other half matches federated API sources, which have no database
		// name, so there is nothing to report for them here.
		return $this->entitySourceDefinitions?->getDatabaseSourceForEntityType(
			$itemId->getEntityType()
		);
	}

	/**
	 * Returns the wiki id of the Wikibase repository that hosts $itemId, or null
	 * if this wiki has no database entity source for it.
	 */
	private function getWikibaseWikiId( EntityId $itemId ): ?string {
		$entitySource = $this->getDatabaseEntitySource( $itemId );
		if ( $entitySource === null ) {
			return null;
		}

		// getDatabaseName() returns false for the local database, i.e. when this
		// wiki is itself the Wikibase repository hosting the items.
		$databaseName = $entitySource->getDatabaseName();

		// If local wiki, use current wiki_id, else lookup wiki_id from $databaseName
		return $databaseName === false
			? WikiMap::getCurrentWikiId()
			: WikiMap::getWikiIdFromDbDomain( $databaseName );
	}

	/**
	 * Returns the concept URI of $itemId, or null if this wiki has no database
	 * entity source for it.
	 */
	private function getWikibaseConceptUri( EntityId $itemId ): ?string {
		// RepoLinker resolves the concept base URI from the same database entity
		// source as getWikibaseWikiId(), but throws when there is none, so the
		// source is checked here first.
		if ( $this->repoLinker === null || $this->getDatabaseEntitySource( $itemId ) === null ) {
			return null;
		}

		return $this->repoLinker->getEntityConceptUri( $itemId );
	}
}
