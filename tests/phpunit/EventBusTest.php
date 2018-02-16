<?php

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

	function newTestRevision( $text, $title = "Test",
							  $model = CONTENT_MODEL_WIKITEXT, $format = null
	) {
		if ( is_string( $title ) ) {
			$title = Title::newFromText( $title );
		}

		$content = ContentHandler::makeContent( $text, $title, $model, $format );

		$rev = new Revision(
			[
				'id' => 42,
				'page' => 23,
				'title' => $title,

				'content' => $content,
				'length' => $content->getSize(),
				'comment' => "testing",
				'minor_edit' => false,

				'content_format' => $format,
			]
		);

		return $rev;
	}

	public function testCreateRevisionAttrs() {
		global $wgDBname;

		$title = Title::newFromText( 'Test' );
		$content = ContentHandler::makeContent(
			'Bla bla bla',
			$title,
			CONTENT_MODEL_WIKITEXT,
			null );

		$rev = new Revision(
			[
				'id' => 42,
				'page' => 23,
				'title' => $title,
				'rev_user' => $this->getTestUser( [ 'testers' ] ),

				'content' => $content,
				'length' => $content->getSize(),
				'comment' => "testing",
				'minor_edit' => false,

				'content_format' => null,
			]
		);

		$revisionAttrs = EventBus::createRevisionAttrs( $rev );

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
		$this->assertEquals( 'wikitext', $revisionAttrs['rev_content_format'] );
		$this->assertEquals( false, $revisionAttrs['page_is_redirect'] );
		$this->assertEquals( true, $revisionAttrs['rev_content_changed'] );
	}
}
