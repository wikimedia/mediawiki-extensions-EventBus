<?php

use MediaWiki\Extension\EventBus\Serializers\MediaWiki\PageEntitySerializer;

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

	/**
	 * @var PageEntitySerializer
	 */
	private PageEntitySerializer $pageEntitySerializer;
	/**
	 * @var WikiPage|\#K#C\WikiPage.Class|\#K#C\WikiPage.Class&\PHPUnit\Framework\MockObject\MockObject|\PHPUnit\Framework\MockObject\MockObject
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
		$titleFormatter->method( 'getPrefixedDBKey' )->willReturn( self::MOCK_PAGE_TITLE );

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
		$actual = $this->pageEntitySerializer->toArray( $this->wikiPage );
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
