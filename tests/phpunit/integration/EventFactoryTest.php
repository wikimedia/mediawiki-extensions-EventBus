<?php

use MediaWiki\Block\DatabaseBlock;
use MediaWiki\Block\Restriction\NamespaceRestriction;
use MediaWiki\Block\Restriction\PageRestriction;
use MediaWiki\Extension\EventBus\EventFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\MutableRevisionRecord;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Storage\EditResult;
use MediaWiki\User\UserIdentityValue;

/**
 * @covers \MediaWiki\Extension\EventBus\EventFactory
 * @group Database
 * @group EventBus
 */
class EventFactoryTest extends MediaWikiIntegrationTestCase {

	/**
	 * @var EventFactory
	 */
	protected static $eventFactory;

	private const MOCK_PAGE_TITLE = 'Test';
	private const MOCK_PAGE_ID = 23;

	private static function revisionProperties( $rowOverrides = [] ) {
		$row = [
			'id' => 42,
			'page' => self::MOCK_PAGE_ID,
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
		return array_merge( $row, $rowOverrides );
	}

	private static function blockProperties( $optionOverrides = [] ) {
		$options = [
			'address' => '127.0.0.0/24',
			'reason' => 'crosswiki block...',
			'timestamp' => wfTimestampNow(),
			'expiry' => wfTimestampNow(),
			'createAccount' => false,
			'enableAutoblock' => true,
			'hideName' => true,
			'blockEmail' => true,
			'by' => UserIdentityValue::newExternal( 'm', 'MetaWikiUser' ),
		];

		return array_merge( $options, $optionOverrides );
	}

	private function assertPageProperties( $event, $rowOverrides = [] ) {
		$row = self::revisionProperties( $rowOverrides );
		$this->assertEquals( $row['page'],  $event['page_id'], "'page_id' incorrect value" );
		$this->assertEquals( self::MOCK_PAGE_TITLE, $event['page_title'],
			"'page_title' incorrect value" );
		$this->assertSame( 0, $event['page_namespace'], "'page_namespace' incorrect value" );
	}

	private function assertStream( $event, $expectedStream ) {
		$this->assertArrayHasKey( 'meta', $event, "'meta' key missing" );
		$this->assertArrayHasKey( 'stream', $event['meta'], "'.meta.stream' key missing" );
		$this->assertEquals( $event['meta']['stream'], $expectedStream,
			"'.meta.stream' incorrect value" );
	}

	private function assertRevisionProperties( $event, $rowOverrides = [] ) {
		$this->assertPageProperties( $event, $rowOverrides );
		$row = self::revisionProperties( $rowOverrides );
		$this->assertEquals( $row['id'], $event['rev_id'], "'rev_id' incorrect value" );
		$this->assertEquals( EventFactory::createDTAttr( $row['timestamp'] ),
			$event['rev_timestamp'], "'rev_timestamp' incorrect value" );
		$this->assertEquals( $row['sha1'], $event['rev_sha1'], "'rev_sha1' incorrect value" );
		$this->assertEquals( $row['len'], $event['rev_len'], "'rev_len' incorrect value" );
		$this->assertEquals( $row['minor_edit'], $event['rev_minor_edit'],
			"'rev_minor_edit' incorrect value" );
		$this->assertEquals( $row['content']->getModel(), $event['rev_content_model'],
			"'rev_content_model' incorrect value" );
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
	public static function setUpBeforeClass() : void {
		self::$eventFactory = MediaWikiServices::getInstance()->get( 'EventBus.EventFactory' );
	}

	// fixture tear-down
	public static function tearDownAfterClass() : void {
		self::$eventFactory = null;
	}

	protected function setUp() : void {
		parent::setUp();
		$this->setMwGlobals( [ 'wgArticlePath' => '/wiki/$1' ] );
	}

	/**
	 * Creates a new instance of RevisionRecord with mock values.
	 * @param array $rowOverrides
	 * @return RevisionRecord
	 * @throws MWException
	 */
	public function createMutableRevisionFromArray( $rowOverrides = [] ) {
		// $row = self::revisionProperties( $rowOverrides );

		// $row = [
		// 	'id' => 42,
		// 	'page' => self::MOCK_PAGE_ID,
		// 	'timestamp' => '20171017114835',
		// 	'user_text' => '111.0.1.2',
		// 	'user' => 0,
		// 	'minor_edit' => false,
		// 	'deleted' => 0,
		// 	'len' => 46,
		// 	'parent_id' => 1,
		// 	'sha1' => 'rdqbbzs3pkhihgbs8qf2q9jsvheag5z',
		// 	'comment' => 'testing',
		// 	'content' => new WikitextContent( 'Some Content' ),
		// ];
		// return array_merge( $row, $rowOverrides );

		$revision = new MutableRevisionRecord( Title::newFromText( self::MOCK_PAGE_TITLE ) );

		$revision->setContent( SlotRecord::MAIN, new WikitextContent( 'Some Content' ) );

		$revision->setSha1( 'rdqbbzs3pkhihgbs8qf2q9jsvheag5z' );
		$revision->setTimestamp( '20171017114835' );
		$revision->setPageId( self::MOCK_PAGE_ID );
		$revision->setId(
			isset( $rowOverrides['id'] ) ? $rowOverrides['id'] : 42
		);
		$revision->setSize( 46 );
		$revision->setUser(
			User::newFromAnyId( 0, '111.0.1.2', null )
		);

		if ( array_key_exists( 'parent_id', $rowOverrides ) ) {
			if ( $rowOverrides['parent_id'] !== null ) {
				$revision->setParentId( $rowOverrides['parent_id'] );
			}
		} else {
			$revision->setParentId( 1 );
		}

		$comment = CommentStoreComment::newUnsavedComment(
			'testing',
			null
		);
		$revision->setComment( $comment );

		return $revision;
	}

	/**
	 * Creates a mock of EditResult that will return values specified in the parameter.
	 * This also avoids using EditResult's constructor that is for internal use only.
	 *
	 * @param array $values array mapping method names in EditResult to the values they
	 *        should return.
	 * @return EditResult
	 */
	private function createEditResultMockFromArray( array $values ) : EditResult {
		$editResult = $this->createMock( EditResult::class );
		foreach ( $values as $method => $value ) {
			$editResult->method( $method )->willReturn( $value );
		}

		return $editResult;
	}

	public function providePageLinks() {
		yield 'Add new links' => [
			[ Title::newFromText( 'added_link_1' ), Title::newFromText( 'added_link_2' ) ],
			[],
			[],
			[],
			[
				[ 'link' => '/wiki/Added_link_1', 'external' => false ],
				[ 'link' => '/wiki/Added_link_2', 'external' => false ],
			],
			[]
		];
		yield 'Add new links and external links' => [
			[ Title::newFromText( 'added_link_1' ), Title::newFromText( 'added_link_2' ) ],
			[ 'added_ext_link_1', 'added_ext_link_2' ],
			[],
			[],
			[
				[ 'link' => '/wiki/Added_link_1', 'external' => false ],
				[ 'link' => '/wiki/Added_link_2', 'external' => false ],
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
		yield 'Removed links' => [
			[],
			[],
			[ Title::newFromText( 'removed_link_1' ), Title::newFromText( 'removed_link_2' ) ],
			[],
			[],
			[
				[ 'link' => '/wiki/Removed_link_1', 'external' => false ],
				[ 'link' => '/wiki/Removed_link_2', 'external' => false ],
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
				[ 'link' => '/wiki/Removed_link_1', 'external' => false ],
				[ 'link' => '/wiki/Removed_link_2', 'external' => false ],
				[ 'link' => 'remove_ext_link_1', 'external' => true ],
				[ 'link' => 'remove_ext_link_2', 'external' => true ]
			]
		];
		yield 'Add/remove new links and external links' => [
			[ Title::newFromText( 'added_link_1? =' ), Title::newFromText( 'added_link_2' ) ],
			[ 'added_ext_link_1', 'added_ext_link_2' ],
			[ Title::newFromText( 'removed_link_1? =' ), Title::newFromText( 'removed_link_2' ) ],
			[ 'remove_ext_link_1', 'remove_ext_link_2' ],
			[
				[ 'link' => '/wiki/Added_link_1%253F_%253D', 'external' => false ],
				[ 'link' => '/wiki/Added_link_2', 'external' => false ],
				[ 'link' => 'added_ext_link_1', 'external' => true ],
				[ 'link' => 'added_ext_link_2', 'external' => true ]
			],
			[
				[ 'link' => '/wiki/Removed_link_1%253F_%253D', 'external' => false ],
				[ 'link' => '/wiki/Removed_link_2', 'external' => false ],
				[ 'link' => 'remove_ext_link_1', 'external' => true ],
				[ 'link' => 'remove_ext_link_2', 'external' => true ]
			]
		];
	}

	/**
	 * @dataProvider providePageLinks
	 */
	public function testCreateLinksChange(
		$addedLinks,
		$addedExternalLinks,
		$removedLinks,
		$removedExternalLinks,
		$expectedAddedLinks,
		$expectedRemovedLinks
	) {
		$event = self::$eventFactory->createPageLinksChangeEvent(
			'mediawiki.page-links-change',
			Title::newFromText( self::MOCK_PAGE_TITLE ),
			$addedLinks,
			$addedExternalLinks,
			$removedLinks,
			$removedExternalLinks,
			User::newFromName( 'Test_User' ),
			1,
			self::MOCK_PAGE_ID
		);

		$this->assertStream( $event, 'mediawiki.page-links-change' );
		$this->assertPageProperties( $event );
		if ( empty( $expectedAddedLinks ) ) {
			$this->assertArrayNotHasKey( 'added_links', $event,
				"must not have 'added_links'" );
		} else {
			$this->assertArrayEquals( $expectedAddedLinks, $event['added_links'],
				"'added_links' incorrect value" );
		}
		if ( empty( $expectedRemovedLinks ) ) {
			$this->assertArrayNotHasKey( 'removed_links', $event,
				"must not have 'removed_links'" );
		} else {
			$this->assertArrayEquals( $expectedRemovedLinks, $event['removed_links'],
				"'removed_links' incorrect value" );
		}
	}

	public function provideRevisionTagsChange() {
		yield 'Add new tags to empty tags' => [
			[],
			[ 'added_tag_1', 'added_tag_2' ],
			[],
			[ 'added_tag_1', 'added_tag_2' ],
			null
		];
		yield 'Add new tags to existing tags' => [
			[ 'existing_tag_1' ],
			[ 'added_tag_1' ],
			[],
			[ 'existing_tag_1', 'added_tag_1' ],
			null
		];
		yield 'Remove tags from existing tags' => [
			[ 'existing_tag_1', 'existing_tag_2' ],
			[],
			[ 'existing_tag_2' ],
			[ 'existing_tag_1' ],
			null
		];
		yield 'Duplicated tags' => [
			[ 'existing_tag_1' ],
			[ 'existing_tag_1' ],
			[],
			[ 'existing_tag_1' ],
			null
		];
		yield 'Explicit user' => [
			[ 'existing_tag_1' ],
			[ 'existing_tag_1' ],
			[],
			[ 'existing_tag_1' ],
			User::newFromName( 'Test_User' )
		];
	}

	/**
	 * @dataProvider provideRevisionTagsChange
	 * @throws MWException
	 */
	public function testRevisionTagsChange( $prevTags, $addedTags, $removedTags, $expectedTags,
											$user ) {
		$revisionRecord = $this->createMutableRevisionFromArray();
		$event = self::$eventFactory->createRevisionTagsChangeEvent(
			'mediawiki.revision-tags-change',
			$revisionRecord,
			$prevTags,
			$addedTags,
			$removedTags,
			$user
		);

		$this->assertEquals( 'array', gettype( $event ), 'Returned event should be of type array' );
		$this->assertRevisionProperties( $event );
		$this->assertNotNull( $event['prior_state'], "'prior_state' null" );
		$this->assertArrayEquals( $prevTags, $event['prior_state']['tags'],
			"'prior_state' incorrect value" );
		$this->assertArrayEquals( $expectedTags, $event['tags'],
			"'tags' incorrect values" );
		$this->assertArrayHasKey( 'performer', $event, "'performer' missing" );
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
			'mediawiki.revision-visibility-change',
			$revisionRecord,
			$performer,
			$visibilityChanges
		);

		$this->assertEquals( 'array', gettype( $event ), 'Returned event should be of type array' );
		$this->assertRevisionProperties( $event );
		$this->assertNotNull( $event['prior_state'], "'prior_state' exist'" );
		$this->assertArrayEquals( $expectedVisibilityObject, $event['visibility'] );
		$this->assertArrayEquals( $expectedPriorVisibility, $event['prior_state']['visibility'] );
		$this->assertEquals( $performer->getName(), $event['performer']['user_text'],
			"'user_text' inccorect value" );
	}

	public function testPageMoveEvent() {
		$event = self::$eventFactory->createPageMoveEvent(
			'mediawiki.page-move',
			Title::newFromText( 'Old_Title' ),
			Title::newFromText( self::MOCK_PAGE_TITLE ),
			$this->createMutableRevisionFromArray(),
			User::newFromName( 'Test_User' ),
			'Comment'
		);

		$this->assertEquals( 'array', gettype( $event ), 'Returned event should be of type array' );
		$this->assertPageProperties( $event );
		$this->assertArrayHasKey( 'prior_state', $event, "'prior_state' key missing" );
		$this->assertEquals( 'array', gettype( $event['prior_state'] ),
			"'prior_state' should be of type array" );
		$this->assertEquals( 'Old_Title', $event['prior_state']['page_title'],
			"'prior_state/page_title' incorrect value" );
		$this->assertArrayHasKey( 'comment', $event, "'comment' key missing" );
		$this->assertArrayHasKey( 'parsedcomment', $event, "'parsedcomment' key missing" );
		$this->assertEquals( 'Comment', $event['comment'], "'comment' incorrect value" );
	}

	public function testPageMoveEventWithRedirectPageId() {
		$redirect = $this->getExistingTestPage( __FUNCTION__ . '/redirect' );

		$event = self::$eventFactory->createPageMoveEvent(
			'mediawiki.page-move',
			Title::newFromText( 'Old_Title' ),
			Title::newFromText( self::MOCK_PAGE_TITLE ),
			$this->createMutableRevisionFromArray(),
			User::newFromName( 'Test_User' ),
			'Comment',
			$redirect->getId()
		);

		$this->assertEquals( 'array', gettype( $event ), 'Returned event should be of type array' );
		$this->assertArrayHasKey( 'new_redirect_page', $event, "'new_redirect_page' key missing" );
	}

	public function testPagePropertiesChangeEvent() {
		$event = self::$eventFactory->createPagePropertiesChangeEvent(
			'mediawiki.page-properties-change',
			Title::newFromText( self::MOCK_PAGE_TITLE ),
			null,
			null,
			User::newFromName( 'Test_User' ),
			1,
			23
		);

		$this->assertEquals( 'array', gettype( $event ), 'Returned event should be of type array' );
		$this->assertPageProperties( $event );
		$this->assertSame( 1, $event['rev_id'], "'rev_id' incorrect value" );
	}

	public function testPagePropertiesChangeEventAddedAndRemovedProperties() {
		$event = self::$eventFactory->createPagePropertiesChangeEvent(
			'mediawiki.page-properties-change',
			Title::newFromText( self::MOCK_PAGE_TITLE ),
			[ 'addedAttr' ],
			[ 'removedAttr' ],
			User::newFromName( 'Test_User' ),
			1,
			23
		);

		$this->assertEquals( 'array', gettype( $event ), 'Returned event should be of type array' );
		$this->assertArrayHasKey( 'added_properties', $event, "'added_properties' key missing" );
		$this->assertArrayHasKey( 'removed_properties', $event, "'removed_properties' key missing" );
	}

	public function testPagePropertiesChangeEventNoPerformer() {
		$event = self::$eventFactory->createPagePropertiesChangeEvent(
			'mediawiki.page-properties-change',
			Title::newFromText( self::MOCK_PAGE_TITLE ),
			null,
			null,
			null,
			1,
			23
		);

		$this->assertEquals( 'array', gettype( $event ), 'Returned event should be of type array' );
		$this->assertArrayNotHasKey( 'performer', $event,
			"'performer' key should not be present" );
	}

	public function testPageLinksChangeEvent() {
		$event = self::$eventFactory->createPageLinksChangeEvent(
			'mediawiki.page-links-change',
			Title::newFromText( self::MOCK_PAGE_TITLE ),
			null,
			null,
			null,
			null,
			User::newFromName( 'Test_User' ),
			1,
			23
		);

		$this->assertEquals( 'array', gettype( $event ), 'Returned event should be of type array' );
		$this->assertPageProperties( $event );
		$this->assertSame( 1, $event['rev_id'], "'rev_id' incorrect value" );
	}

	public function testPageLinksChangeEventAddedAndRemovedProperties() {
		$event = self::$eventFactory->createPageLinksChangeEvent(
			'mediawiki.page-links-change',
			Title::newFromText( self::MOCK_PAGE_TITLE ),
			[ 'addedLinks' ],
			[ 'addedExtLinks' ],
			[ 'removedLinks' ],
			[ 'removedExtLinks' ],
			User::newFromName( 'Test_User' ),
			1,
			23
		);

		$this->assertEquals( 'array', gettype( $event ), 'Returned event should be of type array' );
		$this->assertArrayHasKey( 'added_links', $event, "'added_links' key missing" );
		$this->assertArrayHasKey( 'removed_links', $event, "'removed_links' key missing" );
	}

	public function testPageLinksChangeEventNoPerformer() {
		$event = self::$eventFactory->createPageLinksChangeEvent(
			'mediawiki.page-links-change',
			Title::newFromText( self::MOCK_PAGE_TITLE ),
			null,
			null,
			null,
			null,
			null,
			1,
			23
		);

		$this->assertEquals( 'array', gettype( $event ), 'Returned event should be of type array' );
		$this->assertArrayNotHasKey( 'performer', $event,
			"'performer' key should not be present" );
	}

	public function testRevisionCreationEvent() {
		$event = self::$eventFactory->createRevisionCreateEvent(
			'mediawiki.revision-create',
			$this->createMutableRevisionFromArray(),
			$this->createMock( EditResult::class )
		);

		$this->assertEquals( 'array', gettype( $event ), 'Returned event should be of type array' );
		$this->assertPageProperties( $event );
	}

	public function testRevisionCreationEventDoesNotContainRevParentId() {
		$event = self::$eventFactory->createRevisionCreateEvent(
			'mediawiki.revision-create',
			$this->createMutableRevisionFromArray( [
				'parent_id' => null
			] ),
			$this->createMock( EditResult::class )
		);

		$this->assertEquals( 'array', gettype( $event ), 'Returned event should be of type array' );
		$this->assertArrayNotHasKey( 'rev_parent_id', $event,
			"'rev_parent_id' should not be present" );
	}

	public function testRevisionCreationEventContainsRevParentId() {
		$event = self::$eventFactory->createRevisionCreateEvent(
			'mediawiki.revision-create',
			$this->createMutableRevisionFromArray(),
			$this->createMock( EditResult::class )
		);

		$this->assertEquals( 'array', gettype( $event ), 'Returned event should be of type array' );
		$this->assertArrayHasKey( 'rev_parent_id', $event, "'rev_parent_id' should be present" );
	}

	public function testRevisionCreationEventContentChangeExists() {
		$event = self::$eventFactory->createRevisionCreateEvent(
			'mediawiki.revision-create',
			$this->createMutableRevisionFromArray(),
			$this->createMock( EditResult::class )
		);

		$this->assertEquals( 'array', gettype( $event ), 'Returned event should be of type array' );
		$this->assertArrayHasKey(
			'rev_content_changed', $event,
			'rev_content_changed should be present'
		);
		$this->assertTrue( $event['rev_content_changed'], "'rev_content_changed' incorrect value" );
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
		array $settings,
		$summary,
		$campaignUrl
	) {
		$event = self::$eventFactory->createCentralNoticeCampaignCreateEvent(
			'mediawiki.centralnotice.campaign-create',
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
		array $settings,
		$summary,
		$campaignUrl
	) {
		$priorState = $settings;
		$priorState[ 'enabled'] = false;

		$event = self::$eventFactory->createCentralNoticeCampaignChangeEvent(
			'mediawiki.centralnotice.campaign-change',
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
		array $settings,
		$summary,
		$campaignUrl
	) {
		$event = self::$eventFactory->createCentralNoticeCampaignDeleteEvent(
			'mediawiki.centralnotice.campaign-delete',
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

	/**
	 * @throws MWException
	 */
	public function testPageDeleteEvent() {
		$revisionRecord = self::createMutableRevisionFromArray();

		$event = self::$eventFactory->createPageDeleteEvent(
			'mediawiki.page-delete',
			User::newFromName( 'Test_User' ),
			self::MOCK_PAGE_ID,
			Title::newFromText( self::MOCK_PAGE_TITLE ),
			true,
			2,
			$revisionRecord,
			'testreason'
		);
		$this->assertEquals( 'array', gettype( $event ), 'Returned event should be of type array' );
		$this->assertPageProperties( $event );
		$this->assertArrayHasKey( 'rev_id', $event, "'rev_id' key missing" );
		$this->assertEquals( 42, $event['rev_id'], "'rev_id' has incorrect value" );
		$this->assertArrayHasKey( 'rev_count', $event, "'rev_cound' key missing" );
		$this->assertEquals( 2, $event['rev_count'], "'rev_count' has incorrect value" );
		$this->assertArrayHasKey( 'comment', $event, "'comment' key missing" );
		$this->assertEquals( 'testreason', $event['comment'], "'comment' has incorrect value" );
		$this->assertStream( $event, 'mediawiki.page-delete' );
	}

	public function testPageUndeleteEvent() {
		$event = self::$eventFactory->createPageUndeleteEvent(
			'mediawiki.page-undelete',
			User::newFromName( 'Test_User' ),
			Title::newFromText( self::MOCK_PAGE_TITLE ),
			'testreason',
			1
		);
		$this->assertEquals( gettype( $event ), 'array', 'Returned event should be of type array' );
		$this->assertArrayHasKey( 'page_title', $event, "'page_title' key missing" );
		$this->assertEquals( $event['page_title'], self::MOCK_PAGE_TITLE,
			"'page_title' incorrect value" );
		$this->assertArrayHasKey( 'prior_state', $event, "'prior_state' key missing" );
		$this->assertArrayHasKey( 'page_id', $event['prior_state'], "'page_id' key missing" );
		$this->assertEquals( $event['prior_state']['page_id'], 1,
			"'prior_state/page_id' incorrect value" );
		$this->assertArrayHasKey( 'comment', $event, "'comment' key missing" );
		$this->assertEquals( $event['comment'], 'testreason',
			"'comment' incorrect value" );
		$this->assertStream( $event, 'mediawiki.page-undelete' );
	}

	public function testResourceChangeEvent() {
		$event = self::$eventFactory->createResourceChangeEvent(
			'resource_change',
			new TitleValue( 0, self::MOCK_PAGE_TITLE ),
			[ '0' => 'tag0', '1' => 'tag1' ]
		);
		$this->assertEquals( gettype( $event ), 'array', 'Returned event should be of type array' );
		$this->assertArrayHasKey( 'tags', $event, "'tags' key missing" );
		$this->assertArrayHasKey( '0', $event['tags'], "'tags/0' key missing" );
		$this->assertArrayHasKey( '1', $event['tags'],  "'tags/1' key missing" );
		$this->assertEquals( $event['tags']['0'], 'tag0', "'tags/0' incorrect value" );
		$this->assertEquals( $event['tags']['1'], 'tag1', "'tags/1' incorrect value" );
		$this->assertStream( $event, 'resource_change' );
	}

	public function provideNonUserBlocks() {
		return [ [ new DatabaseBlock( self::blockProperties( [ 'address' => "127.0.0.0/24" ] ) ),
			new DatabaseBlock( self::blockProperties( [ 'address' => "128.0.0.0/24" ] ) ) ]
		];
	}

	public function provideNullOldBlock() {
		return [
			[ null, new DatabaseBlock( self::blockProperties( [
				'address' => UserIdentityValue::newRegistered( 1, 'TestUser1' ),
			] ) ) ]
		];
	}

	public function testUserBlockChangeEvent() {
		$oldBlock = new DatabaseBlock( self::blockProperties( [
			'address' => User::newFromName( 'TestUser1' ),
		] ) );
		$oldBlock->setRestrictions( [
			new NamespaceRestriction( 0, NS_USER ),
			new PageRestriction( 0, 1 )
		] );
		$newBlock = new DatabaseBlock( self::blockProperties( [
			'address' => User::newFromName( 'TestUser2' ),
		] ) );
		$event = self::$eventFactory->createUserBlockChangeEvent(
			'mediawiki.user-blocks-change',
			UserIdentityValue::newRegistered( 3, "Test_User" ),
			$newBlock,
			$oldBlock
		);

		$this->assertEquals( 'array', gettype( $event ), 'Returned event should be of type array' );
		$this->assertArrayHasKey( 'blocks', $event, "'blocks' key missing" );
		$this->assertArrayHasKey( 'sitewide', $event['blocks'] );
		$this->assertArrayHasKey( 'prior_state', $event, "'prior_state' key missing" );
		$this->assertStream( $event, 'mediawiki.user-blocks-change' );
		$this->assertArrayHasKey( 'user_groups', $event, "'user_groups' should be present" );
		$this->assertSame(
			wfTimestamp( TS_ISO_8601, $newBlock->getExpiry() ),
			$event['blocks']['expiry_dt']
		);
		$this->assertArrayHasKey( 'blocks', $event['prior_state'] );
		$this->assertArrayHasKey( 'restrictions', $event['prior_state']['blocks'] );
		$this->assertArrayHasKey( 'sitewide', $event['prior_state']['blocks'] );
		$this->assertArrayEquals( [
			[ 'type' => 'ns', 'value' => NS_USER ],
			[ 'type' => 'page', 'value' => 1 ],
		], $event['prior_state']['blocks']['restrictions'] );
	}

	/**
	 * @dataProvider provideNonUserBlocks
	 */
	public function testNonUserTargetsUserBlockChangeEvent( $oldBlock, $newBlock ) {
		$event = self::$eventFactory->createUserBlockChangeEvent(
			'mediawiki.user-blocks-change',
			User::newFromName( "Test User" ),
			$newBlock,
			$oldBlock
		);

		$this->assertEquals( 'array', gettype( $event ), 'Returned event should be of type array' );
		$this->assertStream( $event, 'mediawiki.user-blocks-change' );
		$this->assertArrayNotHasKey( "user_groups", $event, "'user_groups' should not be present" );
	}

	/**
	 * @dataProvider provideNullOldBlock
	 */
	public function testNullOldBlockUseBlockChangeEvent( $oldBlock, $newBlock ) {
		$event = self::$eventFactory->createUserBlockChangeEvent(
			'mediawiki.user-blocks-change',
			UserIdentityValue::newRegistered( 1, "Test_User" ),
			$newBlock,
			$oldBlock
		);

		$this->assertEquals( 'array', gettype( $event ), 'Returned event should be of type array' );
		$this->assertArrayHasKey( 'blocks', $event, "'blocks' key missing" );
		$this->assertArrayNotHasKey( 'prior_state', $event, "'prior_state' key must not be present" );
		$this->assertStream( $event, 'mediawiki.user-blocks-change' );
		$this->assertArrayHasKey( 'user_groups', $event, "'user_groups' should be present" );
	}

	public function testPageRestrictionsChangeEvent() {
		$rec = self::createMutableRevisionFromArray();
		$title = new TitleValue( 0, self::MOCK_PAGE_TITLE );
		$user = $this->getTestUser()->getUser();

		$event = self::$eventFactory->createPageRestrictionsChangeEvent(
			'mediawiki.page-restrictions-change',
			$user,
			$title,
			23,
			$rec,
			true,
			'testreason',
			[ 'testprotection' ]
		);

		$this->assertEquals( 'array', gettype( $event ), 'Returned event should be of type array' );
		$this->assertPageProperties( $event );
		$this->assertArrayHasKey( 'rev_id', $event, "'rev_id' key missing" );
		$this->assertEquals( 42, $event['rev_id'], "'rev_id' incorrect value" );
		$this->assertArrayHasKey( 'page_is_redirect', $event, "'page_is_redirect' key missing" );
		$this->assertTrue( $event['page_is_redirect'], "'page_is_redirect' incorrect value" );
		$this->assertArrayHasKey( 'reason', $event, "'reason' key missing" );
		$this->assertEquals( 'testreason', $event['reason'], "'rev_id' incorrect value" );
		$this->assertArrayHasKey( 'page_restrictions', $event, "'reason' key missing" );
		$this->assertSame( [ 'testprotection' ], $event['page_restrictions'],
			"'page_restrictions' incorrect value" );
		$this->assertStream( $event, 'mediawiki.page-restrictions-change' );
	}

	public function testCreateRecentChangeEvent() {
		$event = self::$eventFactory->createRecentChangeEvent(
			'mediawiki.recentchange',
			new TitleValue( 0, self::MOCK_PAGE_TITLE ),
			[ 'comment' => 'tag0', '1' => 'tag1' ]
		);

		$this->assertEquals( 'array', gettype( $event ), 'Returned event should be of type array' );
		$this->assertArrayHasKey( 'comment', $event, "'comment' key missing" );
		$this->assertArrayHasKey( 'parsedcomment', $event, "'parsedcomment' key missing" );
		$this->assertArrayHasKey( '1', $event, "'1' key missing" );
		$this->assertEquals( 'tag0', $event['comment'], "'comment' incorrect value" );
		$this->assertEquals( 'tag0', $event['parsedcomment'], "'parsedcomment' incorrect value" );
		$this->assertEquals( 'tag1', $event['1'], "'1' incorrect value" );
		$this->assertStream( $event, 'mediawiki.recentchange' );
	}

	public function testCreateJobEvent() {
		global $wgDBname, $wgServerName;
		$command = 'deletePage';
		$title = Title::newFromText( self::MOCK_PAGE_TITLE );
		$stream = 'mediawiki.job.' . $command;
		$job = Job::factory( $command, [
			'namespace' => $title->getNamespace(),
			'title' => $title->getDBkey()
		] );
		$event = self::$eventFactory->createJobEvent(
			$stream,
			$wgDBname,
			$job
		);

		$this->assertEquals( 'array', gettype( $event ), 'Returned event should be of type array' );
		$this->assertStream( $event, $stream );
		$this->assertArrayHasKey( 'mediawiki_signature', $event, "'mediawiki_signature' key missing" );
		$this->assertEquals( $event['meta']['domain'], $wgServerName );
	}

	public function testCreateDelayedJobEvent() {
		global $wgDBname, $wgServerName;
		$command = 'cdnPurge';
		$url = 'https://en.wikipedia.org/wiki/Main_Page';
		$releaseTimestamp = time() + 10000;
		$stream = 'mediawiki.job.' . $command;
		$job = Job::factory( $command, [
			'urls' => [ $url ],
			'jobReleaseTimestamp' => $releaseTimestamp
		] );
		$event = self::$eventFactory->createJobEvent(
			$stream,
			$wgDBname,
			$job
		);

		$this->assertEquals( 'array', gettype( $event ), 'Returned event should be of type array' );
		$this->assertStream( $event, $stream );
		$this->assertArrayHasKey( 'mediawiki_signature', $event, "'mediawiki_signature' key missing" );
		$this->assertEquals( $event['meta']['domain'], $wgServerName );
		$this->assertSame( $event['delay_until'], wfTimestamp( TS_ISO_8601, $releaseTimestamp ) );
	}

	public function testRecommendationCreateEvent() {
		$event = self::$eventFactory->createRecommendationCreateEvent(
			'mediawiki.revision-recommendation-create',
			'link',
			$this->createMutableRevisionFromArray()
		);

		$this->assertEquals( 'array', gettype( $event ), 'Returned event should be of type array' );
		$this->assertPageProperties( $event );
		$this->assertSame( 'link', $event['recommendation_type'] ?? 'recommendation type missing' );
	}
}
