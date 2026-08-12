<?php

use MediaWiki\Extension\EventBus\WikibaseItemLookup;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Title\Title;
use MediaWiki\WikiMap\WikiMap;
use Wikibase\Client\RepoLinker;
use Wikibase\DataAccess\DatabaseEntitySource;
use Wikibase\DataAccess\EntitySourceDefinitions;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\Lib\Store\EntityIdLookup;

/**
 * @coversDefaultClass \MediaWiki\Extension\EventBus\WikibaseItemLookup
 * @group EventBus
 */
class WikibaseItemLookupTest extends MediaWikiIntegrationTestCase {

	/**
	 * Skips the test when Wikibase is not installed. Tests that mock Wikibase
	 * classes require the extension's autoloader to be registered.
	 */
	private function requireWikibase(): void {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'WikibaseClient' ) ) {
			$this->markTestSkipped( 'WikibaseClient is not loaded.' );
		}
	}

	/**
	 * An EntityIdLookup resolving every title to $serialization of $entityType,
	 * or to no entity at all when $serialization is null.
	 */
	private function newEntityIdLookup(
		?string $serialization,
		string $entityType = 'item'
	): EntityIdLookup {
		$entityId = null;
		if ( $serialization !== null ) {
			$entityId = $this->createMock( EntityId::class );
			$entityId->method( 'getSerialization' )->willReturn( $serialization );
			$entityId->method( 'getEntityType' )->willReturn( $entityType );
		}

		$entityIdLookup = $this->createMock( EntityIdLookup::class );
		$entityIdLookup->method( 'getEntityIdForTitle' )->willReturn( $entityId );

		return $entityIdLookup;
	}

	/**
	 * Entity source definitions with an item source stored in $databaseName, or
	 * false for the local database.
	 *
	 * @param string|false $databaseName
	 */
	private function newEntitySourceDefinitions( $databaseName ): EntitySourceDefinitions {
		$itemSource = $this->createMock( DatabaseEntitySource::class );
		$itemSource->method( 'getDatabaseName' )->willReturn( $databaseName );

		$entitySourceDefinitions = $this->createMock( EntitySourceDefinitions::class );
		$entitySourceDefinitions->method( 'getDatabaseSourceForEntityType' )
			->with( 'item' )
			->willReturn( $itemSource );

		return $entitySourceDefinitions;
	}

	/**
	 * A RepoLinker building Wikidata-shaped concept URIs from the item id.
	 */
	private function newRepoLinker(): RepoLinker {
		$repoLinker = $this->createMock( RepoLinker::class );
		$repoLinker->method( 'getEntityConceptUri' )->willReturnCallback(
			static fn ( EntityId $entityId ): string =>
				'http://www.wikidata.org/entity/' . $entityId->getSerialization()
		);

		return $repoLinker;
	}

	/**
	 * $entityIdLookup null stands for Wikibase Client not being loaded, in which
	 * case there is no RepoLinker either.
	 */
	private function newLookup(
		?EntityIdLookup $entityIdLookup = null,
		?EntitySourceDefinitions $entitySourceDefinitions = null
	): WikibaseItemLookup {
		return new WikibaseItemLookup(
			$this->getServiceContainer()->getTitleFactory(),
			$entityIdLookup,
			$entitySourceDefinitions,
			$entityIdLookup === null ? null : $this->newRepoLinker()
		);
	}

	/**
	 * @covers ::getWikibaseItemIdForPage
	 * @covers ::getWikibaseWikiIdForPage
	 * @covers ::getWikibaseConceptUriForPage
	 * @covers ::getItemForTitle
	 * @covers ::getDatabaseEntitySource
	 * @covers ::getWikibaseWikiId
	 * @covers ::getWikibaseConceptUri
	 */
	public function testReturnsItemIdAndWikiIdForPage(): void {
		$this->requireWikibase();

		$lookup = $this->newLookup(
			$this->newEntityIdLookup( 'Q937' ),
			$this->newEntitySourceDefinitions( 'wikidatawiki' )
		);
		$title = Title::makeTitle( NS_MAIN, 'Foo' );

		$this->assertSame( 'Q937', $lookup->getWikibaseItemIdForPage( $title ) );
		$this->assertSame( 'wikidatawiki', $lookup->getWikibaseWikiIdForPage( $title ) );
		$this->assertSame(
			'http://www.wikidata.org/entity/Q937',
			$lookup->getWikibaseConceptUriForPage( $title )
		);
	}

	/**
	 * An item source on the local database means this wiki is itself the
	 * Wikibase repository, so the item's wiki id is this wiki's id.
	 *
	 * @covers ::getWikibaseWikiId
	 */
	public function testReturnsCurrentWikiIdForLocalItemSource(): void {
		$this->requireWikibase();

		$lookup = $this->newLookup(
			$this->newEntityIdLookup( 'Q937' ),
			$this->newEntitySourceDefinitions( false )
		);

		$this->assertSame(
			WikiMap::getCurrentWikiId(),
			$lookup->getWikibaseWikiIdForPage( Title::makeTitle( NS_MAIN, 'Foo' ) )
		);
	}

	/**
	 * Without a database entity source for the item's entity type, e.g. when the
	 * items are federated from an API source, there is no wiki id and no concept
	 * URI to report, but the item id is still returned.
	 *
	 * @covers ::getWikibaseWikiId
	 * @covers ::getWikibaseConceptUri
	 */
	public function testReturnsNullWikiIdWithoutDatabaseEntitySource(): void {
		$this->requireWikibase();

		$entitySourceDefinitions = $this->createMock( EntitySourceDefinitions::class );
		$entitySourceDefinitions->method( 'getDatabaseSourceForEntityType' )->willReturn( null );

		$lookup = $this->newLookup( $this->newEntityIdLookup( 'Q937' ), $entitySourceDefinitions );
		$title = Title::makeTitle( NS_MAIN, 'Foo' );

		$this->assertSame( 'Q937', $lookup->getWikibaseItemIdForPage( $title ) );
		$this->assertNull( $lookup->getWikibaseWikiIdForPage( $title ) );
		$this->assertNull( $lookup->getWikibaseConceptUriForPage( $title ) );
	}

	/**
	 * EntityIdLookup also resolves entities a page hosts rather than links to,
	 * e.g. the MediaInfo id of a Commons file page. Only items belong in
	 * wikibase_item_id, so those are dropped.
	 *
	 * @covers ::getItemForTitle
	 */
	public function testReturnsNullForNonItemEntity(): void {
		$this->requireWikibase();

		$lookup = $this->newLookup(
			$this->newEntityIdLookup( 'M12345', 'mediainfo' ),
			$this->newEntitySourceDefinitions( 'wikidatawiki' )
		);
		$filePage = Title::makeTitle( NS_FILE, 'Foo.jpg' );

		$this->assertNull( $lookup->getWikibaseItemIdForPage( $filePage ) );
		$this->assertNull( $lookup->getWikibaseWikiIdForPage( $filePage ) );
		$this->assertNull( $lookup->getWikibaseConceptUriForPage( $filePage ) );
	}

	/**
	 * @covers ::getWikibaseItemIdForPage
	 * @covers ::getWikibaseWikiIdForPage
	 * @covers ::getWikibaseConceptUriForPage
	 */
	public function testReturnsNullWhenPageHasNoItem(): void {
		$this->requireWikibase();

		$lookup = $this->newLookup(
			$this->newEntityIdLookup( null ),
			$this->newEntitySourceDefinitions( 'wikidatawiki' )
		);
		$title = Title::makeTitle( NS_MAIN, 'Foo' );

		$this->assertNull( $lookup->getWikibaseItemIdForPage( $title ) );
		$this->assertNull( $lookup->getWikibaseWikiIdForPage( $title ) );
		$this->assertNull( $lookup->getWikibaseConceptUriForPage( $title ) );
	}

	/**
	 * wikibase_item is a local page property, so a page belonging to a foreign
	 * wiki is skipped. Title::newFromPageReference() would throw for it.
	 *
	 * @covers ::getWikibaseItemIdForPage
	 */
	public function testReturnsNullForForeignWikiPage(): void {
		$this->requireWikibase();

		$entityIdLookup = $this->createMock( EntityIdLookup::class );
		$entityIdLookup->expects( $this->never() )->method( 'getEntityIdForTitle' );

		$lookup = $this->newLookup( $entityIdLookup, $this->newEntitySourceDefinitions( 'wikidatawiki' ) );
		$foreignPage = new PageIdentityValue( 7, NS_MAIN, 'Foo', 'foreignwiki' );

		$this->assertNull( $lookup->getWikibaseItemIdForPage( $foreignPage ) );
		$this->assertNull( $lookup->getWikibaseWikiIdForPage( $foreignPage ) );
		$this->assertNull( $lookup->getWikibaseConceptUriForPage( $foreignPage ) );
	}

	/**
	 * @covers ::getWikibaseItemIdForLinkTarget
	 * @covers ::getWikibaseWikiIdForLinkTarget
	 * @covers ::getWikibaseConceptUriForLinkTarget
	 */
	public function testReturnsItemIdAndWikiIdForLinkTarget(): void {
		$this->requireWikibase();

		$lookup = $this->newLookup(
			$this->newEntityIdLookup( 'Q937' ),
			$this->newEntitySourceDefinitions( 'wikidatawiki' )
		);
		$title = Title::makeTitle( NS_MAIN, 'Foo' );

		$this->assertSame( 'Q937', $lookup->getWikibaseItemIdForLinkTarget( $title ) );
		$this->assertSame( 'wikidatawiki', $lookup->getWikibaseWikiIdForLinkTarget( $title ) );
		$this->assertSame(
			'http://www.wikidata.org/entity/Q937',
			$lookup->getWikibaseConceptUriForLinkTarget( $title )
		);
	}

	/**
	 * Interwiki link targets are not local pages, so they are skipped.
	 *
	 * @covers ::getWikibaseItemIdForLinkTarget
	 */
	public function testReturnsNullForInterwikiLinkTarget(): void {
		$this->requireWikibase();

		$entityIdLookup = $this->createMock( EntityIdLookup::class );
		$entityIdLookup->expects( $this->never() )->method( 'getEntityIdForTitle' );

		$lookup = $this->newLookup( $entityIdLookup, $this->newEntitySourceDefinitions( 'wikidatawiki' ) );
		$interwikiTarget = Title::makeTitle( NS_MAIN, 'Foo', '', 'dewiki' );

		$this->assertNull( $lookup->getWikibaseItemIdForLinkTarget( $interwikiTarget ) );
		$this->assertNull( $lookup->getWikibaseWikiIdForLinkTarget( $interwikiTarget ) );
		$this->assertNull( $lookup->getWikibaseConceptUriForLinkTarget( $interwikiTarget ) );
	}

	/**
	 * With no Wikibase Client services (extension absent), lookups always return
	 * null. This does not require Wikibase to be loaded, since it passes null
	 * dependencies.
	 *
	 * @covers ::getWikibaseItemIdForPage
	 * @covers ::getWikibaseItemIdForLinkTarget
	 * @covers ::getWikibaseConceptUriForPage
	 * @covers ::getWikibaseConceptUriForLinkTarget
	 */
	public function testReturnsNullWithoutWikibaseClient(): void {
		$lookup = $this->newLookup();
		$title = Title::makeTitle( NS_MAIN, 'Foo' );

		$this->assertNull( $lookup->getWikibaseItemIdForPage( $title ) );
		$this->assertNull( $lookup->getWikibaseWikiIdForPage( $title ) );
		$this->assertNull( $lookup->getWikibaseItemIdForLinkTarget( $title ) );
		$this->assertNull( $lookup->getWikibaseWikiIdForLinkTarget( $title ) );
		$this->assertNull( $lookup->getWikibaseConceptUriForPage( $title ) );
		$this->assertNull( $lookup->getWikibaseConceptUriForLinkTarget( $title ) );
	}
}
