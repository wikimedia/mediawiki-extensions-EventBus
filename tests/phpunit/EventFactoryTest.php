<?php

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;

/**
 * @covers EventFactory
 * @group EventBus
 */
class EventFactoryTest extends MediaWikiTestCase {
	protected static $eventFactory;

	const MOCK_PAGE_TITLE = 'Test';

	private static function revisionProperties( $rowOverrides = [] ) {
		$row = [
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
		];
		array_merge( $row, $rowOverrides );
		return $row;
	}

	private function assertPageProperties( $event, $rowOverrides = [] ) {
		$row = self::revisionProperties( $rowOverrides );
		$this->assertEquals( $row['page'],  $event['page_id'], 'page_id' );
		$this->assertEquals( self::MOCK_PAGE_TITLE, $event['page_title'], 'page_title' );
		$this->assertEquals( 0, $event['page_namespace'], 'page_namespace' );
	}

	private function assertRevisionProperties( $event, $rowOverrides = [] ) {
		$this->assertPageProperties( $event, $rowOverrides );
		$row = self::revisionProperties( $rowOverrides );
		$this->assertEquals( $row['id'], $event['rev_id'], 'rev_id' );
		$this->assertEquals( wfTimestamp( TS_ISO_8601, $row['timestamp'] ), $event['rev_timestamp'],
			'rev_timestamp' );
		$this->assertEquals( $row['sha1'], $event['rev_sha1'], 'rev_sha1' );
		$this->assertEquals( $row['len'], $event['rev_len'], 'rev_len' );
		$this->assertEquals( $row['minor_edit'], $event['rev_minor_edit'], 'rev_minor_edit' );
		$this->assertEquals( $row['content']->getModel(), $event['rev_content_model'],
			'rev_content_model' );
	}

	// fixture setup
	public static function setUpBeforeClass() {
		self::$eventFactory = new EventFactory();
	}

	// fixture tear-down
	public static function tearDownAfterClass() {
		self::$eventFactory = null;
	}

	/**
	 * Creates a new instance of RevisionRecord with mock values.
	 * @param array $rowOverrides
	 * @return RevisionRecord
	 * @throws MWException
	 */
	public function createMutableRevisionFromArray( $rowOverrides = [] ) {
		$row = self::revisionProperties( $rowOverrides );
		return MediaWikiServices::getInstance()->
			getRevisionStore()->
			newMutableRevisionFromArray( $row,
				0,
				Title::newFromText( self::MOCK_PAGE_TITLE
			)
		);
	}

	public function provideRevisionTagsChange() {
		yield 'Add new tags to empty tags' => [
			[],
			[ 'added_tag_1', 'added_tag_2' ],
			[],
			[ 'added_tag_1', 'added_tag_2' ]
		];
		yield 'Add new tags to existing tags' => [
			[ 'existing_tag_1' ],
			[ 'added_tag_1' ],
			[],
			[ 'existing_tag_1', 'added_tag_1' ]
		];
		yield 'Remove tags from existing tags' => [
			[ 'existing_tag_1', 'existing_tag_2' ],
			[],
			[ 'existing_tag_2' ],
			[ 'existing_tag_1' ]
		];
		yield 'Duplicated tags' => [
			[ 'existing_tag_1' ],
			[ 'existing_tag_1' ],
			[],
			[ 'existing_tag_1' ]
		];
	}

	/**
	 * @dataProvider provideRevisionTagsChange
	 */
	public function testRevisionTagsChange( $prevTags, $addedTags, $removedTags, $expectedTags ) {
		$revisionRecord = $this->createMutableRevisionFromArray();
		$event = self::$eventFactory->createRevisionTagsChangeEvent(
			$revisionRecord,
			$prevTags,
			$addedTags,
			$removedTags
		);

		$this->assertRevisionProperties( $event );
		$this->assertNotNull( $event['prior_state'] );
		$this->assertArrayEquals( $prevTags, $event['prior_state']['tags'] );
		$this->assertArrayEquals( $expectedTags, $event['tags'] );

		$event = self::$eventFactory->createRevisionTagsChangeEvent(
			$revisionRecord,
			$prevTags,
			$addedTags,
			$removedTags,
			User::newFromName( 'Test_User' )
		);

		$this->assertArrayHasKey( 'performer', $event );
	}

	public function provideRevisionVisibilityChange() {
		yield 'Add all suppression' => [
			[
				'newBits' => RevisionRecord::SUPPRESSED_ALL,
				'oldBits' => 0
			],
			[
				'text' => false,
				'user' => false,
				'comment' => false
			],
			[
				'text' => true,
				'user' => true,
				'comment' => true
			]
		];
		yield 'Remove all suppression' => [
			[
				'newBits' => 0,
				'oldBits' => RevisionRecord::SUPPRESSED_ALL
			],
			[
				'text' => true,
				'user' => true,
				'comment' => true
			],
			[
				'text' => false,
				'user' => false,
				'comment' => false
			]
		];
		yield 'Change some suppression' => [
			[
				'newBits' => RevisionRecord::DELETED_USER,
				'oldBits' => RevisionRecord::DELETED_TEXT
			],
			[
				'text' => true,
				'user' => false,
				'comment' => true
			],
			[
				'text' => false,
				'user' => true,
				'comment' => true
			]
		];
	}

	/**
	 * @dataProvider provideRevisionVisibilityChange
	 */
	public function testRevisionVisibilityChange(
		$visibilityChanges,
		$expectedVisibilityObject,
		$expectedPriorVisibility
	) {
		$revisionRecord = $this->createMutableRevisionFromArray();
		$performer = User::newFromName( 'Real_Performer' );
		$event = self::$eventFactory->createRevisionVisibilityChangeEvent(
			$revisionRecord,
			$performer,
			$visibilityChanges
		);

		$this->assertRevisionProperties( $event );
		$this->assertNotNull( $event['prior_state'], 'prior_state exist' );
		$this->assertArrayEquals( $expectedVisibilityObject,
			$event['visibility'],
			'New visibility' );
		$this->assertArrayEquals( $expectedPriorVisibility,
			$event['prior_state']['visibility'],
			'Prior visibility' );
		$this->assertEquals( $performer->getName(), $event['performer']['user_text'] );
	}

	public function testPageMoveEvent() {
		$event = self::$eventFactory->createPageMoveEvent(
			new TitleValue( 0, 'Old_Title' ),
			new TitleValue( 0, self::MOCK_PAGE_TITLE ),
			$this->createMutableRevisionFromArray(),
			User::newFromName( 'Test_User' ),
			'Comment'
		);
		$this->assertPageProperties( $event );
		// TODO: more assertions
	}

	public function testPageMoveEventWithRedirectPageId() {
		$event = self::$eventFactory->createPageMoveEvent(
			new TitleValue( 0, 'Old_Title' ),
			new TitleValue( 0, self::MOCK_PAGE_TITLE ),
			$this->createMutableRevisionFromArray(),
			User::newFromName( 'Test_User' ),
			'Comment',
			1
		);
		$this->assertArrayHasKey( 'new_redirect_page', $event );
	}

	public function testPageCreationEvent() {
		$event = self::$eventFactory->createPageCreationEvent(
			$this->createMutableRevisionFromArray(),
			new TitleValue( 0, self::MOCK_PAGE_TITLE )
		);

		$this->assertPageProperties( $event );
	}

	public function testPagePropertiesChangeEvent() {
		$event = self::$eventFactory->createPagePropertiesChangeEvent(
			Title::newFromText( self::MOCK_PAGE_TITLE ),
			null,
			null,
			User::newFromName( 'Test_User' ),
			1,
			23
		);

		$this->assertPageProperties( $event );
		$this->assertEquals( $event['rev_id'], 1, 'rev_id should be 1' );
	}

	public function testPagePropertiesChangeEventAddedAndRemovedProperties() {
		$event = self::$eventFactory->createPagePropertiesChangeEvent(
			Title::newFromText( self::MOCK_PAGE_TITLE ),
			[ 'addedAttr' ],
			[ 'removedAttr' ],
			User::newFromName( 'Test_User' ),
			1,
			23
		);

		$this->assertArrayHasKey( 'added_properties', $event, 'Missing added_properties' );
		$this->assertArrayHasKey( 'removed_properties', $event, 'Missing removed_properties' );
	}

	public function testPagePropertiesChangeEventNoPerformer() {
		$event = self::$eventFactory->createPagePropertiesChangeEvent(
			Title::newFromText( self::MOCK_PAGE_TITLE ),
			null,
			null,
			null,
			1,
			23
		);

		$this->assertFalse(
			array_key_exists( 'performer', $event ),
			'Performer should not be present'
		);
	}

	public function testPageLinksChangeEvent() {
		$event = self::$eventFactory->createPageLinksChangeEvent(
			Title::newFromText( self::MOCK_PAGE_TITLE ),
			null,
			null,
			null,
			null,
			User::newFromName( 'Test_User' ),
			1,
			23
		);

		$this->assertPageProperties( $event );
		$this->assertEquals( $event['rev_id'], 1, 'rev_id should be 1' );
	}

	public function testPageLinksChangeEventAddedAndRemovedProperties() {
		$event = self::$eventFactory->createPageLinksChangeEvent(
			Title::newFromText( self::MOCK_PAGE_TITLE ),
			[ 'addedLinks' ],
			[ 'addedExtLinks' ],
			[ 'removedLinks' ],
			[ 'removedExtLinks' ],
			User::newFromName( 'Test_User' ),
			1,
			23
		);

		$this->assertArrayHasKey( 'added_links', $event, 'Missing added_links' );
		$this->assertArrayHasKey( 'removed_links', $event, 'Missing removed_links' );
	}

	public function testPageLinksChangeEventNoPerformer() {
		$event = self::$eventFactory->createPageLinksChangeEvent(
			Title::newFromText( self::MOCK_PAGE_TITLE ),
			null,
			null,
			null,
			null,
			null,
			1,
			23
		);

		$this->assertFalse(
			array_key_exists( 'performer', $event ),
			'Performer should not be present'
		);
	}

	public function testRevisionCreationEvent() {
		$event = self::$eventFactory->createRevisionCreateEvent(
			$this->createMutableRevisionFromArray(),
			null,
			true
		);

		$this->assertPageProperties( $event );
	}

	public function testRevisionCreationEventDoesNotContainRevParentId() {
		$event = self::$eventFactory->createRevisionCreateEvent(
			$this->createMutableRevisionFromArray(),
			null,
			true
		);

		$this->assertFalse(
			array_key_exists( 'rev_parent_id', $event ),
			'rev_parent_id should not be present'
		);
	}

	public function testRevisionCreationEventContainsRevParentId() {
		$event = self::$eventFactory->createRevisionCreateEvent(
			$this->createMutableRevisionFromArray(),
			1,
			true
		);

		$this->assertArrayHasKey( 'rev_parent_id', $event, 'rev_parent_id should be present' );
	}

	public function testRevisionCreationEventContentChangeExists() {
		$event = self::$eventFactory->createRevisionCreateEvent(
			$this->createMutableRevisionFromArray(),
			null,
			false
		);

		$this->assertTrue(
			array_key_exists( 'rev_content_changed', $event ),
			'rev_content_changed should be present'
		);
		$this->assertFalse( $event['rev_content_changed'], 'rev_content_changed should be false' );
	}
}
