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
		$this->assertEquals( EventFactory::createDTAttr( $row['timestamp'] ),
			$event['rev_timestamp'], 'rev_timestamp' );
		$this->assertEquals( $row['sha1'], $event['rev_sha1'], 'rev_sha1' );
		$this->assertEquals( $row['len'], $event['rev_len'], 'rev_len' );
		$this->assertEquals( $row['minor_edit'], $event['rev_minor_edit'], 'rev_minor_edit' );
		$this->assertEquals( $row['content']->getModel(), $event['rev_content_model'],
			'rev_content_model' );
	}

	private function assertCommonCentralNoticeCampaignEventProperties(
		$event,
		$campaignName,
		User $user,
		$summary,
		$campaignUrl
	) {
		$this->assertSame( $campaignName, $event[ 'campaign_name' ] );
		$this->assertSame( $user->getName(), $event[ 'performer' ][ 'user_text' ] );
		$this->assertSame( $summary, $event[ 'summary'] );
		$this->assertSame( $campaignUrl, $event[ 'meta' ][ 'uri' ] );
	}

	private function assertCentralNoticeSettings( $settingsFromEvent, $settings ) {
		$this->assertSame( EventFactory::createDTAttr( $settings[ 'start' ] ),
			$settingsFromEvent[ 'start_dt' ] );

		$this->assertSame( EventFactory::createDTAttr( $settings[ 'end' ] ),
			$settingsFromEvent[ 'end_dt' ] );

		$this->assertSame( $settings[ 'enabled' ], $settingsFromEvent[ 'enabled' ] );
		$this->assertSame( $settings[ 'archived' ], $settingsFromEvent[ 'archived' ] );
		$this->assertArrayEquals( $settings[ 'banners' ], $settingsFromEvent[ 'banners' ] );
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
				Title::newFromText( self::MOCK_PAGE_TITLE )
		);
	}

	public function provideLinkAdditionChange() {
		yield 'Add new links' => [
			[ Title::newFromText( 'added_link_1' ), Title::newFromText( 'added_link_2' ) ],
			[],
			[],
			[],
			[
				[ 'link' => '/index.php/Added_link_1', 'external' => false ],
				[ 'link' => '/index.php/Added_link_2', 'external' => false ],
			],
			[]
		];
		yield 'Add new links and external links' => [
			[ Title::newFromText( 'added_link_1' ), Title::newFromText( 'added_link_2' ) ],
			[ 'added_ext_link_1', 'added_ext_link_2' ],
			[],
			[],
			[
				[ 'link' => '/index.php/Added_link_1', 'external' => false ],
				[ 'link' => '/index.php/Added_link_2', 'external' => false ],
				[ 'link' => 'added_ext_link_1', 'external' => true ],
				[ 'link' => 'added_ext_link_2', 'external' => true ]
			],
			[]
		];
		yield 'Add external link only' => [
			[],
			[ 'added_ext_link_1' ],
			[],
			[],
			[
				[ 'link' => 'added_ext_link_1', 'external' => true ],
			],
			[]
		];
	}

	public function provideLinkRemovalChange() {
		yield 'Removed links' => [
			[],
			[],
			[ Title::newFromText( 'removed_link_1' ), Title::newFromText( 'removed_link_2' ) ],
			[],
			[],
			[
				[ 'link' => '/index.php/Removed_link_1', 'external' => false ],
				[ 'link' => '/index.php/Removed_link_2', 'external' => false ],
			]
		];
		yield 'Removed external links only' => [
			[],
			[],
			[],
			[ 'removed_ext_link_1', 'removed_ext_link_2' ],
			[],
			[
				[ 'link' => 'removed_ext_link_1', 'external' => true ],
				[ 'link' => 'removed_ext_link_2', 'external' => true ],
			]
		];
		yield 'Removed links and external links' => [
			[],
			[],
			[ Title::newFromText( 'removed_link_1' ), Title::newFromText( 'removed_link_2' ) ],
			[ 'remove_ext_link_1', 'remove_ext_link_2' ],
			[],
			[
				[ 'link' => '/index.php/Removed_link_1', 'external' => false ],
				[ 'link' => '/index.php/Removed_link_2', 'external' => false ],
				[ 'link' => 'remove_ext_link_1', 'external' => true ],
				[ 'link' => 'remove_ext_link_2', 'external' => true ]
			]
		];
	}

	public function provideLinkAdditionAndRemovalChange() {
		yield 'Add new links and external links' => [
			[ Title::newFromText( 'added_link_1? =' ), Title::newFromText( 'added_link_2' ) ],
			[ 'added_ext_link_1', 'added_ext_link_2' ],
			[ Title::newFromText( 'removed_link_1? =' ), Title::newFromText( 'removed_link_2' ) ],
			[ 'remove_ext_link_1', 'remove_ext_link_2' ],
			[
				[ 'link' => '/index.php/Added_link_1%253F_%253D', 'external' => false ],
				[ 'link' => '/index.php/Added_link_2', 'external' => false ],
				[ 'link' => 'added_ext_link_1', 'external' => true ],
				[ 'link' => 'added_ext_link_2', 'external' => true ]
			],
			[
				[ 'link' => '/index.php/Removed_link_1%253F_%253D', 'external' => false ],
				[ 'link' => '/index.php/Removed_link_2', 'external' => false ],
				[ 'link' => 'remove_ext_link_1', 'external' => true ],
				[ 'link' => 'remove_ext_link_2', 'external' => true ]
			]
		];
	}

	/**
	 * @dataProvider provideLinkAdditionChange
	 */
	public function testAddedLinksChange(
		$addedLinks,
		$addedExternalLinks,
		$removedLinks,
		$removedExternalLinks,
		$expectedAddedLinks,
		$expectedRemovedLinks
	) {
			$event = self::$eventFactory->createPageLinksChangeEvent(
				Title::newFromText( self::MOCK_PAGE_TITLE ),
				$addedLinks,
				$addedExternalLinks,
				$removedLinks,
				$removedExternalLinks,
				User::newFromName( 'Test_User' ),
				1,
				1
		);

		$this->assertArrayEquals( $expectedAddedLinks, $event['added_links'] );
	}

	/**
	 * @dataProvider provideLinkRemovalChange
	 */
	public function testRemovedLinksChange(
		$addedLinks,
		$addedExternalLinks,
		$removedLinks,
		$removedExternalLinks,
		$expectedAddedLinks,
		$expectedRemovedLinks
	) {
			$event = self::$eventFactory->createPageLinksChangeEvent(
				Title::newFromText( self::MOCK_PAGE_TITLE ),
				$addedLinks,
				$addedExternalLinks,
				$removedLinks,
				$removedExternalLinks,
				User::newFromName( 'Test_User' ),
				1,
				1
		);

		$this->assertArrayEquals( $expectedRemovedLinks, $event['removed_links'] );
	}

	/**
	 * @dataProvider provideLinkAdditionAndRemovalChange
	 */
	public function testAddedAndRemovedLinksChange(
		$addedLinks,
		$addedExternalLinks,
		$removedLinks,
		$removedExternalLinks,
		$expectedAddedLinks,
		$expectedRemovedLinks
	) {
			$event = self::$eventFactory->createPageLinksChangeEvent(
				Title::newFromText( self::MOCK_PAGE_TITLE ),
				$addedLinks,
				$addedExternalLinks,
				$removedLinks,
				$removedExternalLinks,
				User::newFromName( 'Test_User' ),
				1,
				1
		);

		$this->assertArrayEquals( $expectedAddedLinks, $event['added_links'] );
		$this->assertArrayEquals( $expectedRemovedLinks, $event['removed_links'] );
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
			$removedTags
		);
	}

	public function testRevisionTagsChangeWithoutUser() {
		$revisionRecord = $this->createMutableRevisionFromArray();
		$event = self::$eventFactory->createRevisionTagsChangeEvent(
			$revisionRecord,
			[],
			[ 'added_tag_1', 'added_tag_2' ],
			[]
		);

		$this->assertTrue(
			array_key_exists( 'performer', $event ),
			'performer should NOT be present'
		);
	}

	public function testRevisionTagsChangeWithUser() {
		$revisionRecord = $this->createMutableRevisionFromArray();
		$event = self::$eventFactory->createRevisionTagsChangeEvent(
			$revisionRecord,
			[],
			[ 'added_tag_1', 'added_tag_2' ],
			[],
			User::newFromName( 'Test_User' )
		);

		$this->assertArrayHasKey( 'performer', $event );
		$this->assertEquals( "Test User", $event['performer']['user_text'] );
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
			Title::newFromText( 'Old_Title' ),
			Title::newFromText( self::MOCK_PAGE_TITLE ),
			$this->createMutableRevisionFromArray(),
			User::newFromName( 'Test_User' ),
			'Comment'
		);
		$this->assertPageProperties( $event );
		// TODO: more assertions
	}

	public function testPageMoveEventWithRedirectPageId() {
		$event = self::$eventFactory->createPageMoveEvent(
			Title::newFromText( 'Old_Title' ),
			Title::newFromText( self::MOCK_PAGE_TITLE ),
			$this->createMutableRevisionFromArray(),
			User::newFromName( 'Test_User' ),
			'Comment',
			1
		);
		$this->assertArrayHasKey( 'new_redirect_page', $event );
	}

	public function testPageCreationEvent() {
		$event = self::$eventFactory->createPageCreateEvent(
			$this->createMutableRevisionFromArray(),
			Title::newFromText( self::MOCK_PAGE_TITLE )
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

	public function provideCentralNoticeCampaignEvents() {
		yield 'CentralNotice campaign event' => [
			'Test_Campaign',
			User::newFromName( 'Test_User' ),
			[
				'start' => '1546300800',
				'end' => '1548979200',
				'enabled' => true,
				'archived' => false,
				'banners' => [ 'TestBanner' ]
			],
			'Test summary',
			'//localhost/wiki/Special:CentralNotice?subaction=notice&detail=Test_Campaign'
		];
	}

	/**
	 * @dataProvider provideCentralNoticeCampaignEvents
	 */
	public function testCentralNoticeCampaignCreateEvent(
		$campaignName,
		User $user,
		$settings,
		$summary,
		$campaignUrl
	) {
		$event = self::$eventFactory->createCentralNoticeCampaignCreateEvent(
			$campaignName,
			$user,
			$settings,
			$summary,
			$campaignUrl
		);

		$this->assertCommonCentralNoticeCampaignEventProperties(
			$event,
			$campaignName,
			$user,
			$summary,
			$campaignUrl
		);

		$this->assertCentralNoticeSettings( $event, $settings );
	}

	/**
	 * @dataProvider provideCentralNoticeCampaignEvents
	 */
	public function testCentralNoticeCampaignChangeEvent(
		$campaignName,
		User $user,
		$settings,
		$summary,
		$campaignUrl
	) {
		$priorState = $settings;
		$priorState[ 'enabled'] = false;

		$event = self::$eventFactory->createCentralNoticeCampaignChangeEvent(
			$campaignName,
			$user,
			$settings,
			$priorState,
			$summary,
			$campaignUrl
		);

		$this->assertCommonCentralNoticeCampaignEventProperties(
			$event,
			$campaignName,
			$user,
			$summary,
			$campaignUrl
		);

		$this->assertCentralNoticeSettings( $event, $settings );
		$this->assertCentralNoticeSettings( $event[ 'prior_state'], $priorState );
	}

	/**
	 * @dataProvider provideCentralNoticeCampaignEvents
	 */
	public function testCentralNoticeCampaignDeleteEvent(
		$campaignName,
		User $user,
		$settings,
		$summary,
		$campaignUrl
	) {
		$event = self::$eventFactory->createCentralNoticeCampaignDeleteEvent(
			$campaignName,
			$user,
			$settings,
			$summary,
			$campaignUrl
		);

		$this->assertCommonCentralNoticeCampaignEventProperties(
			$event,
			$campaignName,
			$user,
			$summary,
			$campaignUrl
		);

		$this->assertCentralNoticeSettings( $event[ 'prior_state' ], $settings );
	}

	public function testCreateEvent() {
		$event = EventFactory::createEvent(
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

	public function testCreatePerformerAttrs() {
		$user = $this->getTestUser( [ 'testers' ] )->getUser();
		$performerAttrs = EventFactory::createPerformerAttrs( $user );

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
		$revisionAttrs = EventFactory::createRevisionRecordAttrs( $revisionRecord );
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
