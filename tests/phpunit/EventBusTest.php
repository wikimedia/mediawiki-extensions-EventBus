<?php

/**
 * @covers EventBus
 * @group EventBus
 */
class EventBusTest extends MediaWikiTestCase {

	public function testGetArticleURL() {
		$url = EventBus::getArticleURL( Title::newFromDBkey( 'Main_Page' ) );
		$this->assertStringEndsWith( 'Main_Page', $url );
	}
}
