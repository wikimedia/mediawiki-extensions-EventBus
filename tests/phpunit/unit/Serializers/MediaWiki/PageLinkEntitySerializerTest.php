<?php

use MediaWiki\Extension\EventBus\Entity\PageLink;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\PageLinkEntitySerializer;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Page\WikiPage;
use MediaWiki\Title\TitleFormatter;

/**
 * @coversDefaultClass \MediaWiki\Extension\EventBus\Serializers\MediaWiki\PageLinkEntitySerializer
 * @group EventBus
 */
class PageLinkEntitySerializerTest extends MediaWikiUnitTestCase {
	/**
	 * @covers ::toArray
	 */
	public function testToArrayFromRedirectTarget(): void {
		$titleFormatter = $this->createMock( TitleFormatter::class );
		$titleFormatter->method( 'getPrefixedDBkey' )->willReturnCallback( static function ( $target ) {
			return $target->getDBkey();
		} );

		$serializer = new PageLinkEntitySerializer( $titleFormatter );

		$targetPage = $this->createMock( WikiPage::class );
		$targetPage->method( 'exists' )->willReturn( true );
		$targetPage->method( 'getId' )->willReturn( 50 );
		$targetPage->method( 'isRedirect' )->willReturn( false );

		$linkTarget = $this->createMock( LinkTarget::class );
		$linkTarget->method( 'getDBkey' )->willReturn( 'MyPage' );
		$linkTarget->method( 'getNamespace' )->willReturn( 0 );
		$linkTarget->method( 'getInterwiki' )->willReturn( 'OtherWiki' );

		$pageLink = $this->createMock( PageLink::class );
		$pageLink->method( 'getPage' )->willReturn( $targetPage );
		$pageLink->method( 'getLink' )->willReturn( $linkTarget );

		$expected = [
			'page_title' => 'MyPage',
			'namespace_id' => 0,
			'page_id' => 50,
			'is_redirect' => false,
			'interwiki_prefix' => 'OtherWiki',
		];

		$this->assertSame( $expected, $serializer->toArray( $pageLink ) );
	}
}
