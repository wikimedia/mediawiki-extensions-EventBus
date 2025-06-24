<?php

use MediaWiki\Extension\EventBus\MediaWikiEventSubscribers\PageChangeEventIngress;
use MediaWiki\Extension\EventBus\Redirects\RedirectTarget;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Page\PageLookup;
use MediaWiki\Page\RedirectLookup;
use PHPUnit\Framework\MockObject\MockObject;

include __DIR__ . '/DBKeyLookupStub.php';

/**
 * @coversDefaultClass \MediaWiki\Extension\EventBus\MediaWikiEventSubscribers\PageChangeEventIngress
 * @group EventBus
 */
class RedirectTargetLookupTest extends MediaWikiUnitTestCase {
	/**
	 * @var PageLookup&MockObject
	 */
	private PageLookup $pageLookup;
	/**
	 * @var RedirectLookup&MockObject
	 */
	private RedirectLookup $redirectLookup;
	private array $source2TargetPageRecordMap = [];
	private array $source2TargetLinkTargetMap = [];
	private array $source2SourcePageRecordMap = [];
	private int $lastId = 0;

	protected function setUp(): void {
		$this->setUpPageLookup();
		$this->setUpRedirectLookup();
	}

	/**
	 * @covers \MediaWiki\Extension\EventBus\MediaWikiEventSubscribers\PageChangeEventIngress::lookupRedirectTarget
	 */
	public function testReturnNullForRegularWikiPage() {
		$sourcePage = $this->createWikiPage( self::asDBKey( "Not a Redirect" ) );
		$redirectTarget =
			PageChangeEventIngress::lookupRedirectTarget( $sourcePage, $this->pageLookup,
				$this->redirectLookup );
		$this->assertNull( $redirectTarget );
	}

	/**
	 * @covers \MediaWiki\Extension\EventBus\MediaWikiEventSubscribers\PageChangeEventIngress::lookupRedirectTarget
	 */
	public function testReturnNullForNonExistingSourcePage() {
		$sourcePage = $this->createWikiPage( self::asDBKey( "Not a Redirect" ) );
		unset( $this->source2SourcePageRecordMap[$sourcePage->getDBkey()] );
		$redirectTarget =
			PageChangeEventIngress::lookupRedirectTarget( $sourcePage, $this->pageLookup,
				$this->redirectLookup );
		$this->assertNull( $redirectTarget );
	}

	/**
	 * @covers \MediaWiki\Extension\EventBus\MediaWikiEventSubscribers\PageChangeEventIngress::lookupRedirectTarget
	 */
	public function testReturnNullForImproperSourcePage() {
		$sourcePage = $this->createWikiPage( self::asDBKey( "Not a Redirect" ) );
		$invalidArgumentException = new InvalidArgumentException( "Not a proper page" );
		$this->source2SourcePageRecordMap[$sourcePage->getDBkey()] = $invalidArgumentException;
		try {
			PageChangeEventIngress::lookupRedirectTarget( $sourcePage, $this->pageLookup,
				$this->redirectLookup );
			$this->fail( "Expected lookup to fail because of $invalidArgumentException" );
		} catch ( InvalidArgumentException $e ) {
			$this->assertEquals( $e, $invalidArgumentException );
		}
	}

	/**
	 * @covers \MediaWiki\Extension\EventBus\MediaWikiEventSubscribers\PageChangeEventIngress::lookupRedirectTarget
	 */
	public function testRedirectToExistingLocalPage() {
		$targetPage = $this->createWikiPage( self::asDBKey( "Target Page" ) );
		$redirectPage = $this->createWikiPage( self::asDBKey( "Target Page Redirect" ), $targetPage );

		$redirectTarget =
			PageChangeEventIngress::lookupRedirectTarget( $redirectPage, $this->pageLookup,
				$this->redirectLookup );

		$this->assertNotNull( $redirectTarget );
		$this->assertInstanceOf( RedirectTarget::class, $redirectTarget );
		$this->assertEquals( $targetPage, $redirectTarget->getPage() );
		$this->assertEquals( $targetPage->getDBkey(), $redirectTarget->getLink()->getDBkey() );
	}

	/**
	 * @covers \MediaWiki\Extension\EventBus\MediaWikiEventSubscribers\PageChangeEventIngress::lookupRedirectTarget
	 */
	public function testRedirectToExternalPage() {
		$targetPage = $this->createWikiPage( self::asDBKey( "External Target Page" ) );
		$targetPage->method( "getWikiId" )->willReturn( "meta" );

		$redirectPage = $this->createWikiPage( self::asDBKey( "Target Page Redirect" ), $targetPage );

		$redirectTarget =
			PageChangeEventIngress::lookupRedirectTarget( $redirectPage, $this->pageLookup,
				$this->redirectLookup );

		$this->assertNotNull( $redirectTarget );
		$this->assertInstanceOf( RedirectTarget::class, $redirectTarget );
		$this->assertNull( $redirectTarget->getPage() );
	}

	/**
	 * @covers \MediaWiki\Extension\EventBus\MediaWikiEventSubscribers\PageChangeEventIngress::lookupRedirectTarget
	 */
	public function testRegularPage() {
		$otherPage = $this->createWikiPage( self::asDBKey( "Other Page" ) );
		$this->assertNull( PageChangeEventIngress::lookupRedirectTarget( $otherPage,
			$this->pageLookup, $this->redirectLookup )
		);
	}

	/**
	 * @param string $dbKey
	 * @param PageIdentity|null $redirectTo
	 * @param PageIdentity&MockObject|null $page
	 * @param int $namespace
	 * @return PageIdentity&MockObject
	 */
	private function createWikiPage(
		string $dbKey, ?PageIdentity $redirectTo = null, ?PageIdentity $page = null, int $namespace = 0
	): PageIdentity {
		$page = $page == null ? $this->createMock( ExistingPageRecord::class ) : $page;
		$page->method( "getId" )->willReturn( $this->lastId++ );
		$page->method( "isRedirect" )->willReturn( $redirectTo != null );
		$page->method( "getNamespace" )->willReturn( $namespace );
		$page->method( "getDBkey" )->willReturn( $dbKey );

		$this->source2SourcePageRecordMap[$dbKey] = $page;

		if ( $redirectTo != null ) {
			$linkTarget = $this->createMock( LinkTarget::class );
			$linkTarget->method( "getDBkey" )->willReturn( $redirectTo->getDBkey() );
			$linkTarget->method( "getNamespace" )->willReturn( $redirectTo->getNamespace() );
			$interwiki = is_string( $redirectTo->getWikiId() ) && strlen( $redirectTo->getWikiId() ) > 0
				? $redirectTo->getWikiId()
				: "";
			$linkTarget->method( "getInterwiki" )->willReturn( $interwiki );
			$linkTarget->method( "isExternal" )->willReturn( strlen( $interwiki ) > 0 );
			$this->source2TargetLinkTargetMap[$page->getDBkey()] = $linkTarget;
			$this->source2TargetPageRecordMap[$redirectTo->getDBkey()] = $redirectTo;
		}

		return $page;
	}

	private function setUpRedirectLookup(): void {
		$this->redirectLookup = $this->createMock( RedirectLookup::class );
		$this->redirectLookup->method( "getRedirectTarget" )->will(
			new DBKeyLookupStub( $this->source2TargetLinkTargetMap,
				"Simple DB key lookup based target mapping" )
		);
	}

	private function setUpPageLookup(): void {
		$this->pageLookup = $this->createMock( PageLookup::class );
		$this->pageLookup->method( "getPageByReference" )->will(
			new DBKeyLookupStub( $this->source2SourcePageRecordMap,
				"Simple DB key lookup based source wiki page mapping" )
		);
		$this->pageLookup->method( "getPageForLink" )->will(
			new DBKeyLookupStub( $this->source2TargetPageRecordMap,
				"Simple DB key lookup based target wiki page mapping" )
		);
	}

	/**
	 * @param string $title
	 * @return string
	 */
	private static function asDBKey( string $title ): string {
		return str_replace( " ", "_", $title );
	}

}
