<?php

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\EventBus\Serializers\EventSerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\PageChangeEventSerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\PageEntitySerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\RevisionEntitySerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\RevisionSlotEntitySerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\UserEntitySerializer;
use MediaWiki\Http\Telemetry;
use MediaWiki\Revision\MutableRevisionRecord;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\UUID\GlobalIdGenerator;

/**
 * @coversDefaultClass \MediaWiki\Extension\EventBus\Serializers\MediaWiki\PageChangeEventSerializer
 * @group Database
 * @group EventBus
 */
class PageChangeEventSerializerTest extends MediaWikiIntegrationTestCase {
	private const MOCK_CANONICAL_SERVER = 'http://my_wiki.org';
	private const MOCK_ARTICLE_PATH = '/wiki/$1';
	private const MOCK_SERVER_NAME = 'my_wiki';

	private const MOCK_UUID = 'b14a2ee4-f5df-40f3-b995-ce6c954e29e3';
	private const MOCK_STREAM_NAME = 'test.mediawiki.page_change';

	/**
	 * @var EventSerializer
	 */
	private EventSerializer $eventSerializer;
	/**
	 * @var PageEntitySerializer
	 */
	private PageEntitySerializer $pageEntitySerializer;
	/**
	 * @var UserEntitySerializer
	 */
	private UserEntitySerializer $userEntitySerializer;
	/**
	 * @var RevisionEntitySerializer
	 */
	private RevisionEntitySerializer $revisionEntitySerializer;
	/**
	 * @var PageChangeEventSerializer
	 */
	private PageChangeEventSerializer $pageChangeEventSerializer;
	/**
	 * @var UserFactory
	 */
	private UserFactory $userFactory;
	/**
	 * @var RevisionStore
	 */
	private RevisionStore $revisionStore;

	/**
	 * We need to use setUp to have access to MediaWikiIntegrationTestCase methods,
	 * but we only need to initialize things once.
	 * @var bool
	 */
	private bool $setUpHasRun = false;

	/**
	 * @throws Exception
	 */
	public function setUp(): void {
		if ( $this->setUpHasRun ) {
			return;
		}

		$config = new HashConfig( [
			'ServerName' => self::MOCK_SERVER_NAME,
			'CanonicalServer' => self::MOCK_CANONICAL_SERVER,
			'ArticlePath' => self::MOCK_ARTICLE_PATH
		] );
		$globalIdGenerator = $this->createMock( GlobalIdGenerator::class );
		$globalIdGenerator->method( 'newUUIDv4' )->willReturn( self::MOCK_UUID );

		$telemetry = $this->createMock( Telemetry::class );
		$telemetry->method( 'getRequestId' )->willReturn( 'requestid' );

		$this->userFactory = $this->getServiceContainer()->getUserFactory();
		$this->revisionStore = $this->getServiceContainer()->getRevisionStore();

		$this->eventSerializer = new EventSerializer(
			$config,
			$globalIdGenerator,
			$telemetry
		);

		$this->pageEntitySerializer = new PageEntitySerializer(
			$config,
			$this->getServiceContainer()->getTitleFormatter()
		);

		$this->userEntitySerializer = new UserEntitySerializer(
			$this->userFactory,
			$this->getServiceContainer()->getUserGroupManager()
		);

		$revisionSlotEntitySerializer = new RevisionSlotEntitySerializer(
			$this->getServiceContainer()->getContentHandlerFactory()
		);

		$this->revisionEntitySerializer = new RevisionEntitySerializer(
			$revisionSlotEntitySerializer,
			$this->userEntitySerializer
		);

		$this->pageChangeEventSerializer = new PageChangeEventSerializer(
			$this->eventSerializer,
			$this->pageEntitySerializer,
			$this->userEntitySerializer,
			$this->revisionEntitySerializer
		);

		$this->setUpHasRun = true;
	}

	/**
	 * DRY helper function to help dynamically generate some common
	 * event attributes we are expecting to have on a page change event
	 * for the $wikiPage.
	 *
	 * If $performer is null, the revision author will be used.
	 * @param WikiPage $wikiPage
	 * @param User|null $performer
	 * @param RevisionRecord|null $currentRevision
	 * @param string|null $eventTimestamp
	 * @param string|null $comment
	 * @param array|null $eventAttrs
	 * @return array
	 */
	private function createExpectedPageChangeEvent(
		WikiPage $wikiPage,
		?User $performer = null,
		?RevisionRecord $currentRevision = null,
		?string $eventTimestamp = null,
		?string $comment = null,
		?array $eventAttrs = null
	): array {
		$currentRevision = $currentRevision ?? $wikiPage->getRevisionRecord();
		$eventTimestamp = $eventTimestamp ?? $wikiPage->getRevisionRecord()->getTimestamp();

		$commentAttrs = [];
		if ( $comment !== null ) {
			$commentAttrs['comment'] = $comment;
		}

		# If performer is not set, don't set performer in expected result.
		$performerArray = $performer ?
			[ 'performer' => $this->userEntitySerializer->toArray( $performer ) ] :
			[];

		return array_merge_recursive(
			$this->eventSerializer->createEvent(
				PageChangeEventSerializer::PAGE_CHANGE_SCHEMA_URI,
				self::MOCK_STREAM_NAME,
				$this->pageEntitySerializer->canonicalPageURL( $wikiPage ),
				array_merge_recursive(
					[
						'wiki_id' => WikiMap::getCurrentWikiId(),
						'dt' => EventSerializer::timestampToDt( $eventTimestamp ),
						'page' => $this->pageEntitySerializer->toArray( $wikiPage ),
						'revision' => $this->revisionEntitySerializer->toArray( $currentRevision ),
					],
					$performerArray
				),
			),
			$commentAttrs,
			$eventAttrs
		);
	}

	/**
	 * DRY helper to assert two events are equal
	 * (minus meta.dt, which is not deterministcally generated).
	 * @param array $expected
	 * @param arrray $actual
	 * @param string|null $message
	 * @return void
	 */
	private function assertEventEquals( array $expected, array $actual, ?string $message = null ): void {
		// remove meta.dt from expected and actual,
		// since it is dynamically set to current timestamp.
		unset( $expected['meta']['dt'] );
		unset( $actual['meta']['dt'] );

		if ( $message === null ) {
			$this->assertEquals( $expected, $actual );
		} else {
			$this->assertEquals( $expected, $actual, $message );
		}
	}

	/**
	 * @covers ::__construct
	 */
	public function testConstruct() {
		$this->assertInstanceOf( PageChangeEventSerializer::class, $this->pageChangeEventSerializer );
	}

	/**
	 * @covers ::toCreateEvent
	 */
	public function testCreatePageChangeCreateEvent() {
		$wikiPage0 = $this->getExistingTestPage( 'MyPageToEdit' );

		$expected = $this->createExpectedPageChangeEvent(
			$wikiPage0,
			$wikiPage0->getRevisionRecord()->getUser(),
			null,
			null,
			null,
			[
				'page_change_kind' => 'create',
				'changelog_kind' => 'insert',
			]
		);

		$actual = $this->pageChangeEventSerializer->toCreateEvent(
			self::MOCK_STREAM_NAME,
			$wikiPage0,
			$this->userFactory->newFromUserIdentity(
				$wikiPage0->getRevisionRecord()->getUser()
			),
			$wikiPage0->getRevisionRecord(),
			null
		);

		$this->assertEventEquals( $expected, $actual );
	}

	/**
	 * @covers ::toEditEvent
	 */
	public function testCreatePageChangeEditEvent() {
		$wikiPage0 = $this->getExistingTestPage( 'MyPageToCreate' );

		// Make an edit so the page has at least 2 revisions, so the parent revision
		// will be represented properly.
		$this->editPage(
			$wikiPage0,
			$wikiPage0->getContent()->getText() . ' edit1',
			'test edit summary',
			$this->getTestUser()->getUser(),
		);

		$expected = $this->createExpectedPageChangeEvent(
			$wikiPage0,
			$wikiPage0->getRevisionRecord()->getUser(),
			null,
			null,
			null,
			[
				'page_change_kind' => 'edit',
				'changelog_kind' => 'update',
				'prior_state' => [
					'revision' => $this->revisionEntitySerializer->toArray(
						$this->revisionStore->getRevisionById(
							$wikiPage0->getRevisionRecord()->getParentId()
						)
					)
				]
			]
		);

		$actual = $this->pageChangeEventSerializer->toEditEvent(
			self::MOCK_STREAM_NAME,
			$wikiPage0,
			$this->userFactory->newFromUserIdentity(
				$wikiPage0->getRevisionRecord()->getUser()
			),
			$wikiPage0->getRevisionRecord(),
			null,
			$this->revisionStore->getRevisionById(
				$wikiPage0->getRevisionRecord()->getParentId()
			)
		);

		$this->assertEventEquals( $expected, $actual );
	}

	/**
	 * @covers ::toMoveEvent
	 */
	public function testCreatePageChangeMoveEvent() {
		$oldTitleText = 'MyPageToMove';
		$newTitleText = 'Renamed_MyPageToMove';
		$wikiPage0 = $this->getExistingTestPage( $newTitleText );

		// Make an edit to the 'moved page', to make it look like a revision was created
		// due to a page move.
		$this->editPage(
			$wikiPage0,
			$wikiPage0->getContent()->getText() . ' premove edit',
			'test premove edit summary',
			$this->getTestUser()->getUser(),
		);

		$oldTitle = $wikiPage0->getTitle();

		// Move the page!
		$reason = 'test move event';

		$createdRedirectPage = $this->getExistingTestPage( $oldTitleText );

		$parentRevisionRecord = $this->revisionStore->getRevisionById(
			$wikiPage0->getRevisionRecord()->getParentId()
		);

		$expected = $this->createExpectedPageChangeEvent(
			$wikiPage0,
			$this->getTestUser()->getUser(),
			null,
			null,
			$reason,
			[
				'page_change_kind' => 'move',
				'changelog_kind' => 'update',
				'created_redirect_page' => $this->pageEntitySerializer->toArray( $createdRedirectPage ),
				'prior_state' => [
					'page' => [
						'page_title' => $this->pageEntitySerializer->formatLinkTarget( $oldTitle ),
					],
					'revision' => $this->revisionEntitySerializer->toArray( $parentRevisionRecord )
				]

			]
		);

		$actual = $this->pageChangeEventSerializer->toMoveEvent(
			self::MOCK_STREAM_NAME,
			$wikiPage0,
			$this->getTestUser()->getUser(),
			$wikiPage0->getRevisionRecord(),
			$parentRevisionRecord,
			$oldTitle,
			$reason,
			$createdRedirectPage
		);

		$this->assertEventEquals( $expected, $actual );
	}

	/**
	 * @covers ::toDeleteEvent
	 */
	public function testCreatePageChangeDeleteEvent() {
		$wikiPage0 = $this->getExistingTestPage( 'MyDeletedPage' );
		$reason = 'test delete event';

		// Use the current revision timestamp just for having a timestamp to test.
		$eventTimestamp = $wikiPage0->getRevisionRecord()->getTimestamp();
		$mockRevisionCount = 1;

		$currentRevisionRecord = $wikiPage0->getRevisionRecord();

		$expected = $this->createExpectedPageChangeEvent(
			$wikiPage0,
			$this->getTestUser()->getUser(),
			null,
			$eventTimestamp,
			$reason,
			[
				'page_change_kind' => 'delete',
				'changelog_kind' => 'delete',
				'page' => [
					'revision_count' => $mockRevisionCount
				],
			]
		);

		$actual = $this->pageChangeEventSerializer->toDeleteEvent(
			self::MOCK_STREAM_NAME,
			$wikiPage0,
			$this->getTestUser()->getUser(),
			$currentRevisionRecord,
			$reason,
			$eventTimestamp,
			$mockRevisionCount,
			null,
			false
		);

		$this->assertEventEquals( $expected, $actual );
	}

	/**
	 * @covers ::toDeleteEvent
	 */
	public function testCreatePageChangeDeleteEventWithPageSuppression() {
		$wikiPage0 = $this->getExistingTestPage( 'MyDeletedAndSuppressedPage' );
		$reason = 'test delete event with page suppression';

		// Use the current revision timestamp just for having a timestamp to test.
		$eventTimestamp = $wikiPage0->getRevisionRecord()->getTimestamp();
		$mockRevisionCount = 1;

		$currentRevisionRecord = $wikiPage0->getRevisionRecord();

		$expected = $this->createExpectedPageChangeEvent(
			$wikiPage0,
			null,
			null,
			$eventTimestamp,
			$reason,
			[
				'page_change_kind' => 'delete',
				'changelog_kind' => 'delete',
				'page' => [
					'revision_count' => $mockRevisionCount
				],
			]
		);

		// We will call toDeleteEvent with isSuppression = true;
		// revision visibility settings should all be false,
		// and the prior 'visibility' is in the current revision.
		$expected['prior_state']['revision']['is_content_visible'] = $expected['revision']['is_content_visible'];
		$expected['prior_state']['revision']['is_editor_visible'] = $expected['revision']['is_editor_visible'];
		$expected['prior_state']['revision']['is_comment_visible'] = $expected['revision']['is_comment_visible'];

		$expected['revision']['is_content_visible'] = false;
		$expected['revision']['is_editor_visible'] = false;
		$expected['revision']['is_comment_visible'] = false;
		// Suppressible fields should be removed too.
		unset( $expected['revision']['rev_size'] );
		unset( $expected['revision']['rev_sha1'] );
		unset( $expected['revision']['comment'] );
		unset( $expected['revision']['editor'] );
		unset( $expected['revision']['content_slots'] );

		$actual = $this->pageChangeEventSerializer->toDeleteEvent(
			self::MOCK_STREAM_NAME,
			$wikiPage0,
			null,
			$currentRevisionRecord,
			$reason,
			$eventTimestamp,
			$mockRevisionCount,
			null,
			true
		);

		$this->assertEventEquals(
			$expected,
			$actual,
			'revision.is_*_visible settings should all be false on page suppression'
		);
	}

	/**
	 * @covers ::toUndeleteEvent
	 */
	public function testCreatePageChangeUndeleteEvent() {
		// No need to actually delete and undelete to run test.
		$wikiPage0 = $this->getExistingTestPage( 'MyUndeletedPage' );
		$reason = 'test undelete event';

		// For testing purposes, assume the pageId as changed.
		// In recent mediawiki versions, this shouldn't happen, but just in case!
		$oldPageId = $wikiPage0->getId() + 100;
		// Use the current revision timestamp just for having a timestamp to test.
		$eventTimestamp = $wikiPage0->getRevisionRecord()->getTimestamp();

		$expected = $this->createExpectedPageChangeEvent(
			$wikiPage0,
			$this->getTestUser()->getUser(),
			null,
			$eventTimestamp,
			$reason,
			[
				'page_change_kind' => 'undelete',
				'changelog_kind' => 'insert',
				'prior_state' => [
					'page' => [
						'page_id' => $oldPageId
					],
				]
			]
		);

		$actual = $this->pageChangeEventSerializer->toUndeleteEvent(
			self::MOCK_STREAM_NAME,
			$wikiPage0,
			$this->getTestUser()->getUser(),
			$wikiPage0->getRevisionRecord(),
			$reason,
			null,
			$eventTimestamp,
			$oldPageId,
		);

		$this->assertEventEquals( $expected, $actual );
	}

	/**
	 * @covers ::toVisibilityChangeEvent
	 */
	public function testCreatePageChangeVisibilityEvent() {
		// No need to actually delete and undelete to run test.
		$wikiPage0 = $this->getExistingTestPage( 'MyPageToChangeVisibility' );

		// Use the current revision timestamp for the event just for having a timestamp in it.
		$eventTimestamp = $wikiPage0->getRevisionRecord()->getTimestamp();

		$oldDeleted = $wikiPage0->getRevisionRecord()->getVisibility();
		$revisionRecord = MutableRevisionRecord::newUpdatedRevisionRecord(
			$wikiPage0->getRevisionRecord(),
			$wikiPage0->getRevisionRecord()->getSlots()->getSlots()
		);
		# Use whatever timestamp just to have a consistent timestamp.
		$revisionRecord->setTimestamp( $eventTimestamp );

		$revisionRecord->setVisibility( RevisionRecord::DELETED_COMMENT | RevisionRecord::DELETED_USER );
		$newDeleted = $revisionRecord->getVisibility();

		// NOTE: This is the logic that PageChangeHooks uses to decide if performer
		// should be in the event.  We don't have a great integration test for hooks
		// right now.
		// If we make one, this test should be moved there, so the actual code is tested.
		$performerForEvent = $newDeleted & RevisionRecord::DELETED_RESTRICTED ?
			null : $this->getTestUser()->getUser();

		// NOTE: This is the logic that EventBusHooks uses to decide if performer
		// should be in the event.  We don't have a great integration test for hooks
		// right now.
		// If we make one, this test should be moved there, so the actual code is tested.
		$isSecretChange =
			$newDeleted & RevisionRecord::DELETED_RESTRICTED ||
			$oldDeleted & RevisionRecord::DELETED_RESTRICTED;

		$performerForEvent = $isSecretChange ?
			null :
			$this->getTestUser()->getUser();

		$expected = $this->createExpectedPageChangeEvent(
			$wikiPage0,
			$performerForEvent,
			$revisionRecord,
			$eventTimestamp,
			null,
			[
				'page_change_kind' => 'visibility_change',
				'changelog_kind' => 'update',
				'prior_state' => [
					'revision' => [
						'is_comment_visible' => true,
						'is_editor_visible' => true
					],
				]
			]
		);

		$actual = $this->pageChangeEventSerializer->toVisibilityChangeEvent(
			self::MOCK_STREAM_NAME,
			$wikiPage0,
			$performerForEvent,
			$revisionRecord,
			$oldDeleted,
			$eventTimestamp
		);

		$this->assertEventEquals( $expected, $actual );
	}
}
