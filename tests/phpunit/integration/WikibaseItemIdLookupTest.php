<?php

use MediaWiki\Extension\EventBus\WikibaseItemIdLookup;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Title\Title;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\Lib\Store\EntityIdLookup;

/**
 * @coversDefaultClass \MediaWiki\Extension\EventBus\WikibaseItemIdLookup
 * @group EventBus
 */
class WikibaseItemIdLookupTest extends MediaWikiIntegrationTestCase {

	/**
	 * Skips the test when Wikibase is not installed. Tests that mock Wikibase
	 * classes require the extension's autoloader to be registered.
	 */
	private function requireWikibase(): void {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'WikibaseClient' ) ) {
			$this->markTestSkipped( 'WikibaseClient is not loaded.' );
		}
	}

	private function newEntityIdLookup( ?string $serialization ): EntityIdLookup {
		$entityId = null;
		if ( $serialization !== null ) {
			$entityId = $this->createMock( EntityId::class );
			$entityId->method( 'getSerialization' )->willReturn( $serialization );
		}

		$entityIdLookup = $this->createMock( EntityIdLookup::class );
		$entityIdLookup->method( 'getEntityIdForTitle' )->willReturn( $entityId );

		return $entityIdLookup;
	}

	/**
	 * @covers ::getWikibaseItemIdForPage
	 */
	public function testReturnsItemIdForPage(): void {
		$this->requireWikibase();

		$lookup = new WikibaseItemIdLookup(
			$this->getServiceContainer()->getTitleFactory(),
			$this->newEntityIdLookup( 'Q937' )
		);

		$this->assertSame(
			'Q937',
			$lookup->getWikibaseItemIdForPage( Title::makeTitle( NS_MAIN, 'Foo' ) )
		);
	}

	/**
	 * @covers ::getWikibaseItemIdForPage
	 */
	public function testReturnsNullWhenPageHasNoItem(): void {
		$this->requireWikibase();

		$lookup = new WikibaseItemIdLookup(
			$this->getServiceContainer()->getTitleFactory(),
			$this->newEntityIdLookup( null )
		);

		$this->assertNull(
			$lookup->getWikibaseItemIdForPage( Title::makeTitle( NS_MAIN, 'Foo' ) )
		);
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

		$lookup = new WikibaseItemIdLookup(
			$this->getServiceContainer()->getTitleFactory(),
			$entityIdLookup
		);
		$foreignPage = new PageIdentityValue( 7, NS_MAIN, 'Foo', 'foreignwiki' );

		$this->assertNull( $lookup->getWikibaseItemIdForPage( $foreignPage ) );
	}

	/**
	 * @covers ::getWikibaseItemIdForLinkTarget
	 */
	public function testReturnsItemIdForLinkTarget(): void {
		$this->requireWikibase();

		$lookup = new WikibaseItemIdLookup(
			$this->getServiceContainer()->getTitleFactory(),
			$this->newEntityIdLookup( 'Q937' )
		);

		$this->assertSame(
			'Q937',
			$lookup->getWikibaseItemIdForLinkTarget( Title::makeTitle( NS_MAIN, 'Foo' ) )
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

		$lookup = new WikibaseItemIdLookup(
			$this->getServiceContainer()->getTitleFactory(),
			$entityIdLookup
		);

		$this->assertNull( $lookup->getWikibaseItemIdForLinkTarget(
			Title::makeTitle( NS_MAIN, 'Foo', '', 'dewiki' )
		) );
	}

	/**
	 * With no Wikibase Client service (extension absent), lookups always return
	 * null. This does not require Wikibase to be loaded, since it passes a null
	 * dependency.
	 *
	 * @covers ::getWikibaseItemIdForPage
	 * @covers ::getWikibaseItemIdForLinkTarget
	 */
	public function testReturnsNullWithoutWikibaseClient(): void {
		$lookup = new WikibaseItemIdLookup(
			$this->getServiceContainer()->getTitleFactory()
		);
		$title = Title::makeTitle( NS_MAIN, 'Foo' );

		$this->assertNull( $lookup->getWikibaseItemIdForPage( $title ) );
		$this->assertNull( $lookup->getWikibaseItemIdForLinkTarget( $title ) );
	}
}
