<?php

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\PageEntitySerializer;
use MediaWiki\MainConfigNames;
use MediaWiki\Page\WikiPage;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFormatter;
use MediaWiki\Title\TitleValue;

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
	private const MOCK_CONTENT_NAMESPACE_ID = 42;
	private const MOCK_IS_REDIRECT = false;
	private const MOCK_EXISTS = true;

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
			MainConfigNames::CanonicalServer => self::MOCK_CANONICAL_SERVER,
			MainConfigNames::ArticlePath => self::MOCK_ARTICLE_PATH,
			MainConfigNames::ContentNamespaces => [ self::MOCK_NAMESPACE_ID, self::MOCK_CONTENT_NAMESPACE_ID ],
		] );

		$titleFormatter = $this->createMock( TitleFormatter::class );
		$titleFormatter->method( 'getPrefixedDBkey' )->willReturnCallback( static function ( $target ) {
			return $target->getDBkey();
		} );

		$this->pageEntitySerializer = new PageEntitySerializer(
			$config,
			$titleFormatter
		);

		$this->wikiPage = $this->createMock( WikiPage::class );
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
	 * @covers ::formatPageTitle
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
	 */
	public function testToArray_schema21_contentNamespace() {
		$contentPage = $this->createMock( WikiPage::class );
		$contentPage->method( 'getId' )->willReturn( self::MOCK_PAGE_ID );
		$contentPage->method( 'getTitle' )->willReturn(
			Title::newFromLinkTarget( new TitleValue( self::MOCK_CONTENT_NAMESPACE_ID, self::MOCK_PAGE_TITLE ) )
		);
		$contentPage->method( 'getNamespace' )->willReturn( self::MOCK_CONTENT_NAMESPACE_ID );
		$contentPage->method( 'isRedirect' )->willReturn( self::MOCK_IS_REDIRECT );
		$contentPage->method( 'exists' )->willReturn( self::MOCK_EXISTS );

		$actual = $this->pageEntitySerializer->toArray( $this->wikiPage, '2.1.0' );
		$this->assertArrayHasKey( 'namespace_is_content', $actual );
		$this->assertTrue( $actual['namespace_is_content'] );
	}

	/**
	 * @covers ::toArray
	 */
	public function testToArray_schema21_notContentNamespace() {
		$talkPage = $this->createMock( WikiPage::class );
		$talkPage->method( 'getId' )->willReturn( self::MOCK_PAGE_ID );
		$talkPage->method( 'getTitle' )->willReturn(
			Title::newFromLinkTarget( new TitleValue( NS_TALK, self::MOCK_PAGE_TITLE ) )
		);
		$talkPage->method( 'getNamespace' )->willReturn( NS_TALK );
		$talkPage->method( 'isRedirect' )->willReturn( self::MOCK_IS_REDIRECT );
		$talkPage->method( 'exists' )->willReturn( self::MOCK_EXISTS );

		$actual = $this->pageEntitySerializer->toArray( $talkPage, '2.1.0' );
		$this->assertArrayHasKey( 'namespace_is_content', $actual );
		$this->assertFalse( $actual['namespace_is_content'] );
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
