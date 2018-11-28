<?php

use MediaWiki\MediaWikiServices;

/**
 * @covers EventBus
 * @group EventBus
 */
class EventBusTest extends MediaWikiTestCase {

	public function testCreateEvent() {
		$event = EventBus::createEvent(
			'http://test.wikipedia.org/wiki/TestPage',
			'test_topic',
			[
				'test_property_string' => 'test_value',
				'test_property_int'    => 42
			]
		);

		$this->assertNotNull( $event );

		// Meta property checks
		$this->assertNotNull( $event['meta'] );
		$this->assertEquals( 'http://test.wikipedia.org/wiki/TestPage', $event['meta']['uri'] );
		$this->assertEquals( 'test_topic', $event['meta']['topic'] );
		$this->assertNotNull( $event['meta']['request_id'] );
		$this->assertNotNull( $event['meta']['id'] );
		$this->assertNotNull( $event['meta']['dt'] );
		$this->assertNotNull( $event['meta']['domain'] );

		// Event properties checks
		$this->assertEquals( 'test_value', $event['test_property_string'] );
		$this->assertEquals( 42, $event['test_property_int'] );
	}

	public function testGetArticleURL() {
		$url = EventBus::getArticleURL( Title::newFromDBkey( 'Main_Page' ) );
		$this->assertStringEndsWith( 'Main_Page', $url );
	}

	public function testCreatePerformerAttrs() {
		$user = $this->getTestUser( [ 'testers' ] )->getUser();
		$performerAttrs = EventBus::createPerformerAttrs( $user );

		$this->assertEquals( $user->getName(), $performerAttrs['user_text'] );
		$this->assertContains( 'testers', $performerAttrs['user_groups'] );
		$this->assertFalse( $performerAttrs['user_is_bot'] );
		$this->assertEquals( 0, $performerAttrs['user_edit_count'] );
		$this->assertEquals( $user->getId(), $performerAttrs['user_id'] );
	}

	public function provideNewMutableRevisionFromArray() {
		yield 'Basic mutable revision' => [
			[
				'id' => 42,
				'page' => 23,
				'timestamp' => '20171017114835',
				'user_text' => '111.0.1.2',
				'user' => 0,
				'minor_edit' => false,
				'deleted' => 0,
				'len' => 46,
				'parent_id' => 1,
				'sha1' => 'rdqbbzs3pkhihgbs8qf2q9jsvheag5z',
				'comment' => 'testing',
				'content' => new WikitextContent( 'Some Content' ),
			]
		];
	}

	/**
	 * @dataProvider provideNewMutableRevisionFromArray
	 */
	public function testCreateRevisionAttrs( $revisionRecordRow ) {
		global $wgDBname;
		$revisionRecord = MediaWikiServices::getInstance()->
			getRevisionStore()->
			newMutableRevisionFromArray( $revisionRecordRow,
			0,
			Title::newFromText( 'Test' )
		);
		$revisionAttrs = EventBus::createRevisionRecordAttrs( $revisionRecord );
		$this->assertNotNull( $revisionAttrs );
		$this->assertEquals( $revisionAttrs['database'], $wgDBname );
		$this->assertNotNull( $revisionAttrs['performer'] );
		$this->assertEquals( 'testing', $revisionAttrs['comment'] );
		$this->assertEquals( 'testing', $revisionAttrs['parsedcomment'] );
		$this->assertEquals( 23, $revisionAttrs['page_id'] );
		$this->assertEquals( 'Test', $revisionAttrs['page_title'] );
		$this->assertEquals( 0, $revisionAttrs['page_namespace'] );
		$this->assertEquals( 42, $revisionAttrs['rev_id'] );
		$this->assertEquals( false, $revisionAttrs['rev_minor_edit'] );
		$this->assertEquals( 'wikitext', $revisionAttrs['rev_content_model'] );
		$this->assertEquals( false, $revisionAttrs['page_is_redirect'] );
	}
}
