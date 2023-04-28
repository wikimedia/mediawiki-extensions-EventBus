<?php

use MediaWiki\Extension\EventBus\Redirects\RedirectTarget;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\PageEntitySerializer;
use MediaWiki\Linker\LinkTarget;
use PHPUnit\Framework\MockObject\Invocation;
use PHPUnit\Framework\MockObject\Stub\Stub;

/**
 * @coversDefaultClass \MediaWiki\Extension\EventBus\Serializers\MediaWiki\PageEntitySerializer
 * @group EventBus
 */
class PageEntitySerializerTest extends MediaWikiUnitTestCase {
	private const MOCK_CANONICAL_SERVER = 'http://my_wiki.org';
	private const MOCK_ARTICLE_PATH = '/wiki/$1';
	private const MOCK_PAGE_TITLE = 'MyPage';
	private const MOCK_PAGE_ID = 50;
	private const MOCK_NAMESPACE_ID = 0;
	private const MOCK_IS_REDIRECT = false;
	private const MOCK_EXISTS = true;
	private const MOCK_REDIRECT_PAGE_ID = 51;
	private const MOCK_REDIRECT_PAGE_TITLE = 'MyPage_Redirect';
	private const MOCK_REDIRECT_TEXT = 'MyPage Redirect';
	private const MOCK_REDIRECT_NAMESPACE_ID = 1;
	private const MOCK_REDIRECT_INTERWIKI = 'OtherWiki';

	/**
	 * System under test.
	 * @var PageEntitySerializer
	 */
	private PageEntitySerializer $pageEntitySerializer;
	/**
	 * @var WikiPage|PHPUnit\Framework\MockObject\MockObject
	 */
	private WikiPage $wikiPage;

	/**
	 * We need to use setUp to have access to MediaWikiUnitTestCase methods,
	 * but we only need to initialize things once.
	 * @var bool
	 */
	private bool $setUpHasRun = false;

	public function setUp(): void {
		if ( $this->setUpHasRun ) {
			return;
		}

		$config = new HashConfig( [
			'CanonicalServer' => self::MOCK_CANONICAL_SERVER,
			'ArticlePath' => self::MOCK_ARTICLE_PATH
		] );

		$titleFormatter = $this->createMock( TitleFormatter::Class );
		$titleFormatter->method( 'getPrefixedDBkey' )->will( new class() implements Stub {
			public function invoke( Invocation $invocation ) {
				if ( $invocation->getParameters()[0] instanceof LinkTarget ) {
					return $invocation->getParameters()[0]->getDBkey();
				}
				throw new Exception( "Unknown link target: " . $invocation->getParameters()[0] );
			}

			public function toString(): string {
				return "Stub returning link target DB key";
			}
		} );

		$this->pageEntitySerializer = new PageEntitySerializer(
			$config,
			$titleFormatter
		);

		$this->wikiPage = $this->createMock( WikiPage::Class );
		$this->wikiPage->method( 'getId' )->willReturn( self::MOCK_PAGE_ID );
		$this->wikiPage->method( 'getTitle' )->willReturn(
			Title::newFromLinkTarget( new TitleValue( 0, self::MOCK_PAGE_TITLE ) )
		);
		$this->wikiPage->method( 'getNamespace' )->willReturn( self::MOCK_NAMESPACE_ID );
		$this->wikiPage->method( 'isRedirect' )->willReturn( self::MOCK_IS_REDIRECT );
		$this->wikiPage->method( 'exists' )->willReturn( self::MOCK_EXISTS );

		$this->setUpHasRun = true;
	}

	/**
	 * @covers ::__construct
	 */
	public function testConstruct() {
		$this->assertInstanceOf( PageEntitySerializer::class, $this->pageEntitySerializer );
	}

	/**
	 * @covers ::toArray
	 * @covers ::formatLinkTarget
	 * @covers ::formatWikiPageTitle
	 */
	public function testToArray() {
		$expected = [
			'page_id' => self::MOCK_PAGE_ID,
			'page_title' => self::MOCK_PAGE_TITLE,
			'namespace_id' => self::MOCK_NAMESPACE_ID,
			'is_redirect' => self::MOCK_IS_REDIRECT
		];

		$wikiPage = $this->wikiPage;

		$actual = $this->pageEntitySerializer->toArray( $wikiPage );
		$this->assertEquals( $expected, $actual, 'Should convert WikiPage to Page entity array' );
	}

	/**
	 * @covers ::toArray
	 * @covers ::formatLinkTarget
	 * @covers ::formatWikiPageTitle
	 */
	public function testToArrayWithRedirectTarget() {
		$expected = [
			'page_id' => self::MOCK_REDIRECT_PAGE_ID,
			'page_title' => self::MOCK_REDIRECT_PAGE_TITLE,
			'namespace_id' => self::MOCK_REDIRECT_NAMESPACE_ID,
			'is_redirect' => true,
			'redirect_page_link' => [
				'page_title' => self::MOCK_PAGE_TITLE,
				'namespace_id' => self::MOCK_NAMESPACE_ID,
				'interwiki_prefix' => self::MOCK_REDIRECT_INTERWIKI,
				'page_id' => self::MOCK_PAGE_ID,
				'is_redirect' => false
			],
		];

		$wikiPage = $this->wikiPage;

		$wikiPageRedirectPage = $this->createMock( WikiPage::Class );
		$wikiPageRedirectPage->method( 'getId' )->willReturn( self::MOCK_REDIRECT_PAGE_ID );
		$wikiPageRedirectPage->method( 'getTitle' )->willReturn(
			Title::newFromLinkTarget( new TitleValue( 0, self::MOCK_REDIRECT_PAGE_TITLE ) )
		);
		$wikiPageRedirectPage->method( 'getNamespace' )->willReturn( self::MOCK_REDIRECT_NAMESPACE_ID );
		$wikiPageRedirectPage->method( 'isRedirect' )->willReturn( true );

		$wikiPageLinkTarget = $this->createMock( LinkTarget::class );
		$wikiPageLinkTarget->method( 'getDBkey' )->willReturn( self::MOCK_PAGE_TITLE );
		$wikiPageLinkTarget->method( 'getText' )->willReturn( self::MOCK_REDIRECT_TEXT );
		$wikiPageLinkTarget->method( 'getNamespace' )->willReturn( self::MOCK_NAMESPACE_ID );
		$wikiPageLinkTarget->method( 'getInterwiki' )->willReturn( self::MOCK_REDIRECT_INTERWIKI );

		$wikiPageRedirectTarget = $this->createMock( RedirectTarget::class );
		$wikiPageRedirectTarget->method( 'getPage' )->willReturn( $wikiPage );
		$wikiPageRedirectTarget->method( 'getLink' )->willReturn( $wikiPageLinkTarget );

		$actual = $this->pageEntitySerializer->toArray( $wikiPageRedirectPage, $wikiPageRedirectTarget );
		$this->assertEquals( $expected, $actual, 'Should convert WikiPage to Page entity array' );
	}

	/**
	 * @covers ::canonicalPageURL
	 */
	public function testCanonicalPageURL() {
		$expected = self::MOCK_CANONICAL_SERVER . '/wiki/' . self::MOCK_PAGE_TITLE;
		$actual = $this->pageEntitySerializer->canonicalPageURL( $this->wikiPage );
		$this->assertEquals( $expected, $actual, 'Should convert WikiPage to canonical page URL' );
	}

}
