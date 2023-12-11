<?php

use MediaWiki\Block\DatabaseBlock;
use MediaWiki\Block\Restriction\NamespaceRestriction;
use MediaWiki\Block\Restriction\PageRestriction;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Extension\EventBus\EventFactory;
use MediaWiki\Extension\EventBus\Serializers\EventSerializer;
use MediaWiki\Page\PageReference;
use MediaWiki\Page\PageReferenceValue;
use MediaWiki\Revision\MutableRevisionRecord;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleValue;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityValue;

/**
 * @covers \MediaWiki\Extension\EventBus\EventFactory
 * @group Database
 * @group EventBus
 */
class EventFactoryTest extends MediaWikiIntegrationTestCase {

	private const MOCK_PAGE_TITLE = 'EventFactoryTest';
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

	private function assertSlotRecords( array $event ) {
		$this->assertIsArray( $event['rev_slots'] );
		$this->assertCount( 2, $event['rev_slots'] );
		$this->assertArrayEquals(
			[
				SlotRecord::MAIN => [
					'rev_slot_content_model' => 'wikitext',
					'rev_slot_sha1' => 'a3kvjf7vqh9qchzi5sl1q87q9hx48pk',
					'rev_slot_size' => 12
				],
				'sidetext' => [
					'rev_slot_content_model' => 'text',
					'rev_slot_sha1' => 'hj5t2yi95v1hhdjtzfn6itv3efu4ltg',
					'rev_slot_size' => 14,
					'rev_slot_origin_rev_id' => 42
				]

			],
			$event['rev_slots'], false, true );
	}

	private function assertStream( $event, $expectedStream ) {
		$this->assertArrayHasKey( 'meta', $event, "'meta' key missing" );
		$this->assertArrayHasKey( 'stream', $event['meta'], "'.meta.stream' key missing" );
		$this->assertEquals( $expectedStream, $event['meta']['stream'],
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
		UserIdentity $user,
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

	protected function setUp(): void {
		parent::setUp();
		$this->setMwGlobals( [ 'wgArticlePath' => '/wiki/$1' ] );
	}

	/**
	 * Creates a new instance of RevisionRecord with mock values.
	 * @param array $rowOverrides
	 * @return RevisionRecord
	 */
	public function createMutableRevisionFromArray( $rowOverrides = [] ) {
		$revision = new MutableRevisionRecord( Title::newFromText( self::MOCK_PAGE_TITLE ) );
		$revId = $rowOverrides['id'] ?? 42;
		$revision->setContent( SlotRecord::MAIN, new WikitextContent( 'Some Content' ) );
		$slot = SlotRecord::newUnsaved( 'sidetext', new TextContent( 'some side text' ) );
		$slot = SlotRecord::newSaved( $revId, null, "unknown", $slot );
		$revision->setId( $revId );
		$revision->setSlot( $slot );

		$revision->setSha1( 'rdqbbzs3pkhihgbs8qf2q9jsvheag5z' );
		$revision->setTimestamp( '20171017114835' );
		$revision->setPageId( self::MOCK_PAGE_ID );
		$revision->setSize( 46 );
		$revision->setUser( UserIdentityValue::newAnonymous( '111.0.1.2' ) );

		if ( isset( $rowOverrides['parent_id'] ) ) {
			$revision->setParentId( $rowOverrides['parent_id'] );
		}

		$comment = CommentStoreComment::newUnsavedComment(
			'testing',
			null
		);
		$revision->setComment( $comment );

		return $revision;
	}

	public static function providePageLinks() {
		yield 'Add new links' => [
			[
				new PageReferenceValue( NS_MAIN, 'Added_link_1', PageReference::LOCAL ),
				new PageReferenceValue( NS_MAIN, 'Added_link_2', PageReference::LOCAL )
			],
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
			[
				new PageReferenceValue( NS_MAIN, 'Added_link_1', PageReference::LOCAL ),
				new PageReferenceValue( NS_MAIN, 'Added_link_2', PageReference::LOCAL )
			],
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
			[
				new PageReferenceValue( NS_MAIN, 'Removed_link_1', PageReference::LOCAL ),
				new PageReferenceValue( NS_MAIN, 'Removed_link_2', PageReference::LOCAL )
			],
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
			[
				new PageReferenceValue( NS_MAIN, 'Removed_link_1', PageReference::LOCAL ),
				new PageReferenceValue( NS_MAIN, 'Removed_link_2', PageReference::LOCAL )
			],
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
			[
				new PageReferenceValue( NS_MAIN, 'Added_link_1? =', PageReference::LOCAL ),
				new PageReferenceValue( NS_MAIN, 'Added_link_2', PageReference::LOCAL )
			],
			[ 'added_ext_link_1', 'added_ext_link_2' ],
			[
				new PageReferenceValue( NS_MAIN, 'Removed_link_1? =', PageReference::LOCAL ),
				new PageReferenceValue( NS_MAIN, 'Removed_link_2', PageReference::LOCAL )
			],
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
		array $addedLinks,
		array $addedExternalLinks,
		array $removedLinks,
		array $removedExternalLinks,
		array $expectedAddedLinks,
		array $expectedRemovedLinks
	) {
		$eventFactory = $this->getServiceContainer()->get( 'EventBus.EventFactory' );
		$event = $eventFactory->createPageLinksChangeEvent(
			'mediawiki.page-links-change',
			Title::newFromText( self::MOCK_PAGE_TITLE ),
			$addedLinks,
			$addedExternalLinks,
			$removedLinks,
			$removedExternalLinks,
			UserIdentityValue::newRegistered( 1, 'Test_User' ),
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

	public static function provideRevisionTagsChange() {
		yield 'Add new tags to empty tags' => [
			[],
			[ 'added_tag_1', 'added_tag_2' ],
			[],
			[ 'added_tag_1', 'added_tag_2' ],
			new UserIdentityValue( 1, 'Test_User' ),
		];
		yield 'Add new tags to existing tags' => [
			[ 'existing_tag_1' ],
			[ 'added_tag_1' ],
			[],
			[ 'existing_tag_1', 'added_tag_1' ],
			new UserIdentityValue( 1, 'Test_User' ),
		];
		yield 'Remove tags from existing tags' => [
			[ 'existing_tag_1', 'existing_tag_2' ],
			[],
			[ 'existing_tag_2' ],
			[ 'existing_tag_1' ],
			new UserIdentityValue( 1, 'Test_User' ),
		];
		yield 'Duplicated tags' => [
			[ 'existing_tag_1' ],
			[ 'existing_tag_1' ],
			[],
			[ 'existing_tag_1' ],
			new UserIdentityValue( 1, 'Test_User' ),
		];
		yield 'Explicit user' => [
			[ 'existing_tag_1' ],
			[ 'existing_tag_1' ],
			[],
			[ 'existing_tag_1' ],
			new UserIdentityValue( 1, 'Test_User' ),
		];
	}

	/**
	 * @dataProvider provideRevisionTagsChange
	 */
	public function testRevisionTagsChange(
		array $prevTags,
		array $addedTags,
		array $removedTags,
		array $expectedTags,
		?UserIdentity $user
	) {
		$eventFactory = $this->getServiceContainer()->get( 'EventBus.EventFactory' );
		$revisionRecord = $this->createMutableRevisionFromArray();
			$event = $eventFactory->createRevisionTagsChangeEvent(
			'mediawiki.revision-tags-change', $revisionRecord, $prevTags, $addedTags, $removedTags, $user
		);
		$this->assertIsArray( $event, 'Returned event should be of type array' );
		$this->assertRevisionProperties( $event );
		$this->assertNotNull( $event['prior_state'], "'prior_state' null" );
		$this->assertArrayEquals( $prevTags, $event['prior_state']['tags'], "'prior_state' incorrect value" );
		$this->assertArrayEquals( $expectedTags, $event['tags'], "'tags' incorrect values" );
		$this->assertArrayHasKey( 'performer', $event, "'performer' missing" );
	}

	public static function provideRevisionVisibilityChange() {
		yield 'Add all suppression, restricting to oversighters only' => [
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
		$eventFactory = $this->getServiceContainer()->get( 'EventBus.EventFactory' );
		$revisionRecord = $this->createMutableRevisionFromArray();

		// NOTE: This is the logic that EventBusHooks uses to decide if performer
		// should be in the event.  We don't have a great integration test for hooks
		// right now.
		// If we make one, this test should be moved there, so the actual code is tested.
		$isSecretChange =
			$visibilityChanges['newBits'] & RevisionRecord::DELETED_RESTRICTED ||
			$visibilityChanges['oldBits'] & RevisionRecord::DELETED_RESTRICTED;

		$performerForEvent = $isSecretChange ?
			null :
			UserIdentityValue::newRegistered( 2, 'Real_Performer' );

		$event = $eventFactory->createRevisionVisibilityChangeEvent(
			'mediawiki.revision-visibility-change',
			$revisionRecord,
			$performerForEvent,
			$visibilityChanges
		);

		$this->assertIsArray( $event, 'Returned event should be of type array' );
		$this->assertRevisionProperties( $event );
		$this->assertNotNull( $event['prior_state'], "'prior_state' exist'" );
		$this->assertArrayEquals( $expectedVisibilityObject, $event['visibility'] );
		$this->assertArrayEquals( $expectedPriorVisibility, $event['prior_state']['visibility'] );

		// If revision is suppressed, performer should not be present in event.
		// https://phabricator.wikimedia.org/T342487
		if (
			$visibilityChanges['newBits'] & RevisionRecord::DELETED_RESTRICTED ||
			$visibilityChanges['oldBits'] & RevisionRecord::DELETED_RESTRICTED
		) {
			$this->assertArrayNotHasKey(
				'performer',
				$event,
				"'performer' should not be set for suppressed/restricted revisions"
			);
		} else {
			$this->assertEquals( $performerForEvent->getName(), $event['performer']['user_text'],
				"'user_text' incorrect value" );
		}
	}

	public function testPageMoveEvent() {
		/** @var EventFactory $eventFactory */
		$eventFactory = $this->getServiceContainer()->get( 'EventBus.EventFactory' );
		$eventFactory->setCommentFormatter( $this->getServiceContainer()->getCommentFormatter() );
		$event = $eventFactory->createPageMoveEvent(
			'mediawiki.page-move',
			Title::newFromText( 'Old_Title' ),
			Title::newFromText( self::MOCK_PAGE_TITLE ),
			$this->createMutableRevisionFromArray(),
			UserIdentityValue::newRegistered( 1, 'Test_User' ),
			'Comment'
		);

		$this->assertIsArray( $event, 'Returned event should be of type array' );
		$this->assertPageProperties( $event );
		$this->assertArrayHasKey( 'prior_state', $event, "'prior_state' key missing" );
		$this->assertIsArray( $event['prior_state'], "'prior_state' should be of type array" );
		$this->assertEquals( 'Old_Title', $event['prior_state']['page_title'],
			"'prior_state/page_title' incorrect value" );
		$this->assertArrayHasKey( 'comment', $event, "'comment' key missing" );
		$this->assertArrayHasKey( 'parsedcomment', $event, "'parsedcomment' key missing" );
		$this->assertEquals( 'Comment', $event['comment'], "'comment' incorrect value" );
	}

	public function testPageMoveEventWithRedirectPageId() {
		$redirect = $this->getExistingTestPage( __FUNCTION__ . '/redirect' );
		$eventFactory = $this->getServiceContainer()->get( 'EventBus.EventFactory' );
		$event = $eventFactory->createPageMoveEvent(
			'mediawiki.page-move',
			Title::newFromText( 'Old_Title' ),
			Title::newFromText( self::MOCK_PAGE_TITLE ),
			$this->createMutableRevisionFromArray(),
			UserIdentityValue::newRegistered( 1, 'Test_User' ),
			'Comment',
			$redirect->getId()
		);

		$this->assertIsArray( $event, 'Returned event should be of type array' );
		$this->assertArrayHasKey( 'new_redirect_page', $event, "'new_redirect_page' key missing" );
	}

	public function testPagePropertiesChangeEvent() {
		$eventFactory = $this->getServiceContainer()->get( 'EventBus.EventFactory' );
		$event = $eventFactory->createPagePropertiesChangeEvent(
			'mediawiki.page-properties-change',
			Title::newFromText( self::MOCK_PAGE_TITLE ),
			null,
			null,
			UserIdentityValue::newRegistered( 1, 'Test_User' ),
			1,
			23
		);

		$this->assertIsArray( $event, 'Returned event should be of type array' );
		$this->assertPageProperties( $event );
		$this->assertSame( 1, $event['rev_id'], "'rev_id' incorrect value" );
	}

	public function testPagePropertiesChangeEventAddedAndRemovedProperties() {
		$eventFactory = $this->getServiceContainer()->get( 'EventBus.EventFactory' );
		$event = $eventFactory->createPagePropertiesChangeEvent(
			'mediawiki.page-properties-change',
			Title::newFromText( self::MOCK_PAGE_TITLE ),
			[ 'addedAttr' ],
			[ 'removedAttr' ],
			UserIdentityValue::newRegistered( 1, 'Test_User' ),
			1,
			23
		);

		$this->assertIsArray( $event, 'Returned event should be of type array' );
		$this->assertArrayHasKey( 'added_properties', $event, "'added_properties' key missing" );
		$this->assertArrayHasKey( 'removed_properties', $event, "'removed_properties' key missing" );
	}

	public function testPagePropertiesChangeEventNoPerformer() {
		$eventFactory = $this->getServiceContainer()->get( 'EventBus.EventFactory' );
		$event = $eventFactory->createPagePropertiesChangeEvent(
			'mediawiki.page-properties-change',
			Title::newFromText( self::MOCK_PAGE_TITLE ),
			null,
			null,
			null,
			1,
			23
		);

		$this->assertIsArray( $event, 'Returned event should be of type array' );
		$this->assertArrayNotHasKey( 'performer', $event,
			"'performer' key should not be present" );
	}

	public function testPageLinksChangeEvent() {
		$eventFactory = $this->getServiceContainer()->get( 'EventBus.EventFactory' );
		$event = $eventFactory->createPageLinksChangeEvent(
			'mediawiki.page-links-change',
			Title::newFromText( self::MOCK_PAGE_TITLE ),
			null,
			null,
			null,
			null,
			UserIdentityValue::newRegistered( 1, 'Test_User' ),
			1,
			23
		);

		$this->assertIsArray( $event, 'Returned event should be of type array' );
		$this->assertPageProperties( $event );
		$this->assertSame( 1, $event['rev_id'], "'rev_id' incorrect value" );
	}

	public function testPageLinksChangeEventAddedAndRemovedProperties() {
		$eventFactory = $this->getServiceContainer()->get( 'EventBus.EventFactory' );
		$event = $eventFactory->createPageLinksChangeEvent(
			'mediawiki.page-links-change',
			Title::newFromText( self::MOCK_PAGE_TITLE ),
			[ 'addedLinks' ],
			[ 'addedExtLinks' ],
			[ 'removedLinks' ],
			[ 'removedExtLinks' ],
			UserIdentityValue::newRegistered( 1, 'Test_User' ),
			1,
			23
		);

		$this->assertIsArray( $event, 'Returned event should be of type array' );
		$this->assertArrayHasKey( 'added_links', $event, "'added_links' key missing" );
		$this->assertArrayHasKey( 'removed_links', $event, "'removed_links' key missing" );
	}

	public function testPageLinksChangeEventNoPerformer() {
		$eventFactory = $this->getServiceContainer()->get( 'EventBus.EventFactory' );
		$event = $eventFactory->createPageLinksChangeEvent(
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

		$this->assertIsArray( $event, 'Returned event should be of type array' );
		$this->assertArrayNotHasKey( 'performer', $event,
			"'performer' key should not be present" );
	}

	public function testRevisionCreationEvent() {
		$eventFactory = $this->getServiceContainer()->get( 'EventBus.EventFactory' );
		$revision = $this->createMutableRevisionFromArray();
		$event = $eventFactory->createRevisionCreateEvent(
			'mediawiki.revision-create',
			$this->createMutableRevisionFromArray()
		);

		$this->assertIsArray( $event, 'Returned event should be of type array' );
		$this->assertRevisionProperties( $event );
		$this->assertSlotRecords( $event );
		$this->assertSame( EventSerializer::timestampToDt( $revision->getTimestamp() ), $event['dt'] );
		$this->assertSame( "/mediawiki/revision/create/2.0.0", $event['$schema'] );
	}

	public function testRevisionCreationEventDoesNotContainRevParentId() {
		$eventFactory = $this->getServiceContainer()->get( 'EventBus.EventFactory' );
		$event = $eventFactory->createRevisionCreateEvent(
			'mediawiki.revision-create',
			$this->createMutableRevisionFromArray( [
				'parent_id' => null
			] )
		);

		$this->assertIsArray( $event, 'Returned event should be of type array' );
		$this->assertArrayNotHasKey( 'rev_parent_id', $event,
			"'rev_parent_id' should not be present" );
	}

	public function testRevisionCreationEventContainsRevParentId() {
		$eventFactory = $this->getServiceContainer()->get( 'EventBus.EventFactory' );
		$event = $eventFactory->createRevisionCreateEvent(
			'mediawiki.revision-create',
			$this->createMutableRevisionFromArray( [ 'parent_id' => 123456 ] )
		);

		$this->assertIsArray( $event, 'Returned event should be of type array' );
		$this->assertArrayHasKey( 'rev_parent_id', $event, "'rev_parent_id' should be present" );
	}

	public function testRevisionCreationEventContentChangeExists() {
		// Make sure that the page exists, so we can use its latest revision as parent.
		$page = $this->getExistingTestPage( self::MOCK_PAGE_TITLE );
		$eventFactory = $this->getServiceContainer()->get( 'EventBus.EventFactory' );
		$event = $eventFactory->createRevisionCreateEvent(
			'mediawiki.revision-create',
			$this->createMutableRevisionFromArray( [ 'parent_id' => $page->getLatest() ] )
		);

		$this->assertIsArray( $event, 'Returned event should be of type array' );
		$this->assertArrayHasKey(
			'rev_content_changed', $event,
			'rev_content_changed should be present'
		);
		$this->assertTrue( $event['rev_content_changed'], "'rev_content_changed' incorrect value" );
	}

	public static function provideCentralNoticeCampaignEvents() {
		yield 'CentralNotice campaign event' => [
			'Test_Campaign',
			new UserIdentityValue( 1, 'Test_User' ),
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
		string $campaignName,
		UserIdentity $user,
		array $settings,
		string $summary,
		string $campaignUrl
	) {
		$eventFactory = $this->getServiceContainer()->get( 'EventBus.EventFactory' );
		$event = $eventFactory->createCentralNoticeCampaignCreateEvent(
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
		string $campaignName,
		UserIdentity $user,
		array $settings,
		string $summary,
		string $campaignUrl
	) {
		$eventFactory = $this->getServiceContainer()->get( 'EventBus.EventFactory' );
		$priorState = $settings;
		$priorState['enabled'] = false;

		$event = $eventFactory->createCentralNoticeCampaignChangeEvent(
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
		$this->assertCentralNoticeSettings( $event['prior_state'], $priorState );
	}

	/**
	 * @dataProvider provideCentralNoticeCampaignEvents
	 */
	public function testCentralNoticeCampaignDeleteEvent(
		string $campaignName,
		UserIdentity $user,
		array $settings,
		string $summary,
		string $campaignUrl
	) {
		$eventFactory = $this->getServiceContainer()->get( 'EventBus.EventFactory' );
		$event = $eventFactory->createCentralNoticeCampaignDeleteEvent(
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

		$this->assertCentralNoticeSettings( $event['prior_state'], $settings );
	}

	public function testPageDeleteEvent() {
		$revisionRecord = self::createMutableRevisionFromArray();
		$eventFactory = $this->getServiceContainer()->get( 'EventBus.EventFactory' );
		$event = $eventFactory->createPageDeleteEvent(
			'mediawiki.page-delete',
			UserIdentityValue::newRegistered( 1, 'Test_User' ),
			self::MOCK_PAGE_ID,
			Title::newFromText( self::MOCK_PAGE_TITLE ),
			true,
			2,
			$revisionRecord,
			'testreason'
		);
		$this->assertIsArray( $event, 'Returned event should be of type array' );
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
		$page = $this->getExistingTestPage( self::MOCK_PAGE_TITLE );
		$expectedRevId = 123;
		$revisionRecord = $this->createMutableRevisionFromArray( [
			'id' => $expectedRevId,
			'parent_id' => $page->getLatest()
		] );
		/** @var EventFactory $eventFactory */
		$eventFactory = $this->getServiceContainer()->get( 'EventBus.EventFactory' );
		$oldPageID = $page->getId() + 42;
		$event = $eventFactory->createPageUndeleteEvent(
			'mediawiki.page-undelete',
			UserIdentityValue::newRegistered( 1, 'Test_User' ),
			$page->getTitle(),
			'testreason',
			$oldPageID,
			$revisionRecord
		);
		$this->assertIsArray( $event, 'array', 'Returned event should be of type array' );
		$this->assertArrayHasKey( 'page_title', $event, "'page_title' key missing" );
		$this->assertArrayHasKey( 'rev_id', $event, "'rev_id' key missing" );
		$this->assertSame( $expectedRevId, $event['rev_id'], "'rev_id' mismatch" );
		$this->assertEquals( self::MOCK_PAGE_TITLE, $event['page_title'],
			"'page_title' incorrect value" );
		$this->assertArrayHasKey( 'prior_state', $event, "'prior_state' key missing" );
		$this->assertArrayHasKey( 'page_id', $event['prior_state'], "'page_id' key missing" );
		$this->assertSame( $oldPageID, $event['prior_state']['page_id'],
			"'prior_state/page_id' incorrect value" );
		$this->assertArrayHasKey( 'comment', $event, "'comment' key missing" );
		$this->assertEquals( 'testreason', $event['comment'],
			"'comment' incorrect value" );
		$this->assertStream( $event, 'mediawiki.page-undelete' );
	}

	public function testResourceChangeEvent() {
		$eventFactory = $this->getServiceContainer()->get( 'EventBus.EventFactory' );
		$event = $eventFactory->createResourceChangeEvent(
			'resource_change',
			new TitleValue( 0, self::MOCK_PAGE_TITLE ),
			[ '0' => 'tag0', '1' => 'tag1' ]
		);
		$this->assertIsArray( $event, 'array', 'Returned event should be of type array' );
		$this->assertArrayHasKey( 'tags', $event, "'tags' key missing" );
		$this->assertArrayHasKey( '0', $event['tags'], "'tags/0' key missing" );
		$this->assertArrayHasKey( '1', $event['tags'],  "'tags/1' key missing" );
		$this->assertEquals( 'tag0', $event['tags']['0'], "'tags/0' incorrect value" );
		$this->assertEquals( 'tag1', $event['tags']['1'], "'tags/1' incorrect value" );
		$this->assertStream( $event, 'resource_change' );
	}

	public static function provideNonUserBlocks() {
		yield [
			self::blockProperties( [ 'address' => "127.0.0.0/24" ] ),
			self::blockProperties( [ 'address' => "128.0.0.0/24" ] ),
		];
	}

	public static function provideNullOldBlock() {
		yield [
			self::blockProperties( [
				'address' => UserIdentityValue::newRegistered( 1, 'TestUser1' ),
			] ),
		];
	}

	public function testUserBlockChangeEvent() {
		$oldBlock = new DatabaseBlock( self::blockProperties( [
			'address' => UserIdentityValue::newRegistered( 1, 'TestUser1' ),
		] ) );
		$oldBlock->setRestrictions( [
			new NamespaceRestriction( 0, NS_USER ),
			new PageRestriction( 0, 1 )
		] );
		$newBlock = new DatabaseBlock( self::blockProperties( [
			'address' => UserIdentityValue::newRegistered( 1, 'TestUser2' ),
		] ) );
		$eventFactory = $this->getServiceContainer()->get( 'EventBus.EventFactory' );
		$event = $eventFactory->createUserBlockChangeEvent(
			'mediawiki.user-blocks-change',
			UserIdentityValue::newRegistered( 3, "Test_User" ),
			$newBlock,
			$oldBlock
		);

		$this->assertIsArray( $event, 'Returned event should be of type array' );
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
	public function testNonUserTargetsUserBlockChangeEvent(
		array $oldBlockAttrs,
		array $newBlockAttrs
	) {
		$eventFactory = $this->getServiceContainer()->get( 'EventBus.EventFactory' );
		$oldBlock = new DatabaseBlock( $oldBlockAttrs );
		$newBlock = new DatabaseBlock( $newBlockAttrs );
		$event = $eventFactory->createUserBlockChangeEvent(
			'mediawiki.user-blocks-change',
			UserIdentityValue::newRegistered( 1, 'Test_User' ),
			$newBlock,
			$oldBlock
		);

		$this->assertIsArray( $event, 'Returned event should be of type array' );
		$this->assertStream( $event, 'mediawiki.user-blocks-change' );
		$this->assertArrayNotHasKey( "user_groups", $event, "'user_groups' should not be present" );
	}

	/**
	 * @dataProvider provideNullOldBlock
	 */
	public function testNullOldBlockUseBlockChangeEvent(
		array $newBlockAttrs
	) {
		$eventFactory = $this->getServiceContainer()->get( 'EventBus.EventFactory' );
		$newBlock = new DatabaseBlock( $newBlockAttrs );
		$event = $eventFactory->createUserBlockChangeEvent(
			'mediawiki.user-blocks-change',
			UserIdentityValue::newRegistered( 1, "Test_User" ),
			$newBlock,
			null
		);

		$this->assertIsArray( $event, 'Returned event should be of type array' );
		$this->assertArrayHasKey( 'blocks', $event, "'blocks' key missing" );
		$this->assertArrayNotHasKey( 'prior_state', $event, "'prior_state' key must not be present" );
		$this->assertStream( $event, 'mediawiki.user-blocks-change' );
		$this->assertArrayHasKey( 'user_groups', $event, "'user_groups' should be present" );
	}

	public function testPageRestrictionsChangeEvent() {
		$rec = $this->createMutableRevisionFromArray();
		$title = new TitleValue( 0, self::MOCK_PAGE_TITLE );
		$user = $this->getTestUser()->getUser();

		$eventFactory = $this->getServiceContainer()->get( 'EventBus.EventFactory' );
		$event = $eventFactory->createPageRestrictionsChangeEvent(
			'mediawiki.page-restrictions-change',
			$user,
			$title,
			23,
			$rec,
			true,
			'testreason',
			[ 'testprotection' ]
		);

		$this->assertIsArray( $event, 'Returned event should be of type array' );
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
		/** @var EventFactory $eventFactory */
		$eventFactory = $this->getServiceContainer()->get( 'EventBus.EventFactory' );
		$eventFactory->setCommentFormatter( $this->getServiceContainer()->getCommentFormatter() );
		$event = $eventFactory->createRecentChangeEvent(
			'mediawiki.recentchange',
			new TitleValue( 0, self::MOCK_PAGE_TITLE ),
			[ 'comment' => 'tag0', '1' => 'tag1' ]
		);

		$this->assertIsArray( $event, 'Returned event should be of type array' );
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
		$services = $this->getServiceContainer();
		$job = $services->getJobFactory()->newJob( $command, [
			'namespace' => $title->getNamespace(),
			'title' => $title->getDBkey()
		] );

		$eventFactory = $services->get( 'EventBus.EventFactory' );
		$event = $eventFactory->createJobEvent(
			$stream,
			$wgDBname,
			$job
		);

		$this->assertIsArray( $event, 'Returned event should be of type array' );
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
		$services = $this->getServiceContainer();
		$job = $services->getJobFactory()->newJob( $command, [
			'urls' => [ $url ],
			'jobReleaseTimestamp' => $releaseTimestamp
		] );
		$eventFactory = $services->get( 'EventBus.EventFactory' );
		$event = $eventFactory->createJobEvent(
			$stream,
			$wgDBname,
			$job
		);

		$this->assertIsArray( $event, 'Returned event should be of type array' );
		$this->assertStream( $event, $stream );
		$this->assertArrayHasKey( 'mediawiki_signature', $event, "'mediawiki_signature' key missing" );
		$this->assertEquals( $event['meta']['domain'], $wgServerName );
		$this->assertSame( $event['delay_until'], wfTimestamp( TS_ISO_8601, $releaseTimestamp ) );
	}

	public function testRecommendationCreateEvent() {
		$eventFactory = $this->getServiceContainer()->get( 'EventBus.EventFactory' );
		$event = $eventFactory->createRecommendationCreateEvent(
			'mediawiki.revision-recommendation-create',
			'link',
			$this->createMutableRevisionFromArray()
		);

		$this->assertIsArray( $event, 'Returned event should be of type array' );
		$this->assertPageProperties( $event );
		$this->assertSame( 'link', $event['recommendation_type'] ?? 'recommendation type missing' );
	}
}
