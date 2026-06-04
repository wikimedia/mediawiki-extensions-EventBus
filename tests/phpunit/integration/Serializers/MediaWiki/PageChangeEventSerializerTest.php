<?php

use MediaWiki\Extension\EventBus\Entity\PageLink;
use MediaWiki\Extension\EventBus\GlobalEditCountLookup;
use MediaWiki\Extension\EventBus\Serializers\EventSerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\PageChangeEventSerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\PageEntitySerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\PageLinkEntitySerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\RevisionEntitySerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\RevisionSlotsEntitySerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\UserEntitySerializer;
use MediaWiki\Extension\EventBus\WikibaseItemIdLookup;
use MediaWiki\Http\Telemetry;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Page\WikiPage;
use MediaWiki\Revision\MutableRevisionRecord;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Storage\EditResult;
use MediaWiki\Tests\MockWikiMapTrait;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\UUID\GlobalIdGenerator;

/**
 * @coversDefaultClass \MediaWiki\Extension\EventBus\Serializers\MediaWiki\PageChangeEventSerializer
 * @group Database
 * @group EventBus
 */
class PageChangeEventSerializerTest extends MediaWikiIntegrationTestCase {
	use MockWikiMapTrait;

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
	 * @var PageLinkEntitySerializer
	 */
	private PageLinkEntitySerializer $pageLinkEntitySerializer;
	/**
	 * @var UserEntitySerializer
	 */
	private UserEntitySerializer $userEntitySerializer;
	/**
	 * @var GlobalEditCountLookup
	 */
	private GlobalEditCountLookup $globalEditCountLookup;
	/**
	 * @var WikibaseItemIdLookup
	 */
	private WikibaseItemIdLookup $wikibaseItemIdLookup;
	/**
	 * @var RevisionEntitySerializer
	 */
	private RevisionEntitySerializer $revisionEntitySerializer;
	/**
	 * @var RevisionSlotsEntitySerializer
	 */
	private RevisionSlotsEntitySerializer $revisionSlotsEntitySerializer;
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
		$this->mockWikiMap();

		$globalIdGenerator = $this->createMock( GlobalIdGenerator::class );
		$globalIdGenerator->method( 'newUUIDv4' )->willReturn( self::MOCK_UUID );

		$services = $this->getServiceContainer();

		$this->userFactory = $services->getUserFactory();
		$this->revisionStore = $services->getRevisionStore();

		// Use a custom (not MediaWiki Service) EventSerializer
		// so we can override the $globalIdGenerator.
		$this->eventSerializer = new EventSerializer( $globalIdGenerator );
		// Tests will MediaWiki Service instance of entity serializers.
		// so ServiceWiring.php is exercised.
		$this->pageEntitySerializer = $services->get( 'EventBus.PageEntitySerializer' );
		$this->pageLinkEntitySerializer = $services->get( 'EventBus.PageLinkEntitySerializer' );
		$this->userEntitySerializer = $services->get( 'EventBus.UserEntitySerializer' );
		$this->globalEditCountLookup = $services->get( 'EventBus.GlobalEditCountLookup' );
		$this->wikibaseItemIdLookup = $services->get( 'EventBus.WikibaseItemIdLookup' );
		$this->revisionEntitySerializer = $services->get( 'EventBus.RevisionEntitySerializer' );
		$this->revisionSlotsEntitySerializer = $services->get( 'EventBus.RevisionSlotsEntitySerializer' );

		$this->pageChangeEventSerializer = new PageChangeEventSerializer(
			$this->eventSerializer,
			$this->pageEntitySerializer,
			$this->pageLinkEntitySerializer,
			$this->userEntitySerializer,
			$this->globalEditCountLookup,
			$this->wikibaseItemIdLookup,
			$this->revisionEntitySerializer,
			$this->revisionSlotsEntitySerializer,
			$this->revisionStore,
		);

		$this->setUpHasRun = true;
	}

	/**
	 * Mirrors {@link PageChangeEventSerializer} private toUserAttrs() for expected payloads.
	 */
	private function expectedUserInPageChangeEvent( UserIdentity $user ): array {
		$attrs = $this->userEntitySerializer->toArray(
			$user,
			PageChangeEventSerializer::USER_ENTITY_SCHEMA_VERSION
		);
		if ( isset( $attrs['user_central_id'] ) ) {
			$globalEditCount = $this->globalEditCountLookup->getGlobalEditCount( $attrs['user_central_id'] );
			if ( $globalEditCount !== null ) {
				$attrs['edit_global_count'] = $globalEditCount;
			}
		}
		return $attrs;
	}

	/**
	 * Mirrors {@link PageChangeEventSerializer} private toPageAttrs() for expected payloads.
	 */
	private function expectedPageInPageChangeEvent( WikiPage $wikiPage ): array {
		$attrs = $this->pageEntitySerializer->toArray(
			$wikiPage,
			PageChangeEventSerializer::PAGE_ENTITY_SCHEMA_VERSION
		);
		$wikibaseItemId = $this->wikibaseItemIdLookup->getWikibaseItemIdForPage( $wikiPage );
		if ( $wikibaseItemId !== null ) {
			$attrs['wikibase_item_id'] = $wikibaseItemId;
		}
		return $attrs;
	}

	/**
	 * Mirrors {@link PageChangeEventSerializer} private toRevisionAttrs() for expected payloads.
	 */
	private function expectedRevisionInPageChangeEvent( RevisionRecord $revisionRecord ): array {
		$attrs = $this->revisionEntitySerializer->toArray(
			$revisionRecord,
			PageChangeEventSerializer::REVISION_ENTITY_SCHEMA_VERSION
		);
		if ( $revisionRecord->getUser() ) {
			$attrs['editor'] = $this->expectedUserInPageChangeEvent( $revisionRecord->getUser() );
		}
		$revisionSlots = $revisionRecord->getSlots();
		$slotsAttrs = $this->revisionSlotsEntitySerializer->toArray( $revisionSlots );
		if ( $slotsAttrs ) {
			$attrs['content_slots'] = $slotsAttrs;
		}
		return $attrs;
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
		$currentRevision ??= $wikiPage->getRevisionRecord();
		$eventTimestamp ??= $wikiPage->getRevisionRecord()->getTimestamp();

		$commentAttrs = [];
		if ( $comment !== null ) {
			$commentAttrs['comment'] = $comment;
		}

		# If performer is not set, don't set performer in expected result.
		$performerArray = $performer ?
			[ 'performer' => $this->expectedUserInPageChangeEvent( $performer ) ] :
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
						'page' => $this->expectedPageInPageChangeEvent( $wikiPage ),
						'revision' => $this->expectedRevisionInPageChangeEvent( $currentRevision ),
					],
					$performerArray
				),
				WikiMap::getCurrentWikiId(),
				null,
				Telemetry::getInstance()->getRequestId(),
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
	 * Returns a UserEntitySerializer that reports $centralId as every user
	 * entity's user_central_id, or omits the field when $centralId is null.
	 *
	 * Whether a real user has a central id depends on CentralAuth attachment
	 * state, so the edit_global_count tests below mock it to stay deterministic.
	 */
	private function mockUserEntitySerializer( ?int $centralId ): UserEntitySerializer {
		$mockUserEntitySerializer = $this->createMock( UserEntitySerializer::class );
		$mockUserEntitySerializer
			->method( 'toArray' )
			->willReturnCallback( static function ( $userIdentity ) use ( $centralId ): array {
				$userAttrs = [
					'user_text' => $userIdentity->getName(),
					'groups' => [],
					'is_temp' => false,
				];
				if ( $centralId !== null ) {
					$userAttrs['user_central_id'] = $centralId;
				}
				return $userAttrs;
			} );

		return $mockUserEntitySerializer;
	}

	/**
	 * Builds a PageChangeEventSerializer with the given user entity serializer
	 * and global edit count lookup, and returns a create event for $wikiPage.
	 */
	private function createEventWithGlobalEditCountLookup(
		WikiPage $wikiPage,
		UserEntitySerializer $userEntitySerializer,
		GlobalEditCountLookup $globalEditCountLookup
	): array {
		$serializer = new PageChangeEventSerializer(
			$this->eventSerializer,
			$this->pageEntitySerializer,
			$this->pageLinkEntitySerializer,
			$userEntitySerializer,
			$globalEditCountLookup,
			$this->wikibaseItemIdLookup,
			$this->revisionEntitySerializer,
			$this->revisionSlotsEntitySerializer,
			$this->revisionStore,
		);

		return $serializer->toCreateEvent(
			self::MOCK_STREAM_NAME,
			$wikiPage,
			$this->userFactory->newFromUserIdentity( $wikiPage->getRevisionRecord()->getUser() ),
			$wikiPage->getRevisionRecord(),
			null
		);
	}

	/**
	 * Every user entity the producer emits (performer and revision.editor) gets
	 * edit_global_count set from the user's user_central_id.
	 * @covers ::toCreateEvent
	 * @covers ::toCommonAttrs
	 * @covers ::toRevisionAttrs
	 * @covers ::toUserAttrs
	 */
	public function testSetsEditGlobalCountOnUsers() {
		$wikiPage = $this->getExistingTestPage(
			Title::makeTitle( $this->getDefaultWikitextNS(), 'MyPageGlobalEditCountTest' )
		);

		$lookup = $this->createMock( GlobalEditCountLookup::class );
		$lookup->method( 'getGlobalEditCount' )
			->with( 111 )
			->willReturn( 4242 );

		$event = $this->createEventWithGlobalEditCountLookup(
			$wikiPage,
			$this->mockUserEntitySerializer( 111 ),
			$lookup
		);

		$this->assertSame( 4242, $event['performer']['edit_global_count'] );
		$this->assertSame( 4242, $event['revision']['editor']['edit_global_count'] );
	}

	/**
	 * When the global edit count is unavailable (CentralAuth absent, or the
	 * count not yet initialized), edit_global_count is omitted.
	 * @covers ::toCreateEvent
	 * @covers ::toUserAttrs
	 */
	public function testOmitsEditGlobalCountWhenLookupReturnsNull() {
		$wikiPage = $this->getExistingTestPage(
			Title::makeTitle( $this->getDefaultWikitextNS(), 'MyPageNullGlobalEditCountTest' )
		);

		$lookup = $this->createMock( GlobalEditCountLookup::class );
		$lookup->method( 'getGlobalEditCount' )->willReturn( null );

		$event = $this->createEventWithGlobalEditCountLookup(
			$wikiPage,
			$this->mockUserEntitySerializer( 111 ),
			$lookup
		);

		$this->assertArrayNotHasKey( 'edit_global_count', $event['performer'] );
		$this->assertArrayNotHasKey( 'edit_global_count', $event['revision']['editor'] );
	}

	/**
	 * A user with no central account has no user_central_id, so there is nothing
	 * to look the global edit count up by.
	 * @covers ::toCreateEvent
	 * @covers ::toUserAttrs
	 */
	public function testSkipsLookupWhenNoUserCentralId() {
		$wikiPage = $this->getExistingTestPage(
			Title::makeTitle( $this->getDefaultWikitextNS(), 'MyPageNoCentralIdTest' )
		);

		$lookup = $this->createMock( GlobalEditCountLookup::class );
		$lookup->expects( $this->never() )->method( 'getGlobalEditCount' );

		$event = $this->createEventWithGlobalEditCountLookup(
			$wikiPage,
			$this->mockUserEntitySerializer( null ),
			$lookup
		);

		$this->assertArrayNotHasKey( 'edit_global_count', $event['performer'] );
		$this->assertArrayNotHasKey( 'edit_global_count', $event['revision']['editor'] );
	}

	/**
	 * @covers ::toCreateEvent
	 */
	public function testCreatePageChangeCreateEvent() {
		$wikiPage0 = $this->getExistingTestPage( Title::makeTitle( $this->getDefaultWikitextNS(), 'MyPageToEdit' ) );

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
		$wikiPage0 = $this->getExistingTestPage(
			Title::makeTitle( $this->getDefaultWikitextNS(), 'MyPageToCreate' )
		);

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
					'revision' => $this->expectedRevisionInPageChangeEvent(
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
	 * @covers ::toEditEvent
	 */
	public function testCreatePageChangeEditEventWithRevertMetadata() {
		$wikiPage0 = $this->getExistingTestPage(
			Title::makeTitle( $this->getDefaultWikitextNS(), 'MyPageRevertMeta' )
		);

		$this->editPage(
			$wikiPage0,
			$wikiPage0->getContent()->getText() . ' edit1',
			'test edit summary',
			$this->getTestUser()->getUser(),
		);

		$currentRevision = $wikiPage0->getRevisionRecord();
		$parentRevision = $this->revisionStore->getRevisionById(
			$currentRevision->getParentId()
		);
		$this->assertNotNull( $parentRevision );

		$editResult = new EditResult(
			false,
			$parentRevision->getId(),
			EditResult::REVERT_MANUAL,
			$currentRevision->getId(),
			$currentRevision->getId(),
			true,
			false,
			[],
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
					'revision' => $this->expectedRevisionInPageChangeEvent( $parentRevision ),
				],
				'revision' => [
					'revert' => [
						'is_exact' => true,
						'method' => 'manual',
						'rev_original_id' => $parentRevision->getId(),
						'rev_original_dt' => EventSerializer::timestampToDt( $parentRevision->getTimestamp() ),
						'rev_reverted_oldest_id' => $currentRevision->getId(),
						'rev_reverted_newest_id' => $currentRevision->getId(),
					],
				],
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
			$parentRevision,
			$editResult,
		);

		$this->assertEventEquals( $expected, $actual );
	}

	/**
	 * Original revision id unknown: omit rev_original_id and rev_original_dt.
	 *
	 * @covers ::toEditEvent
	 */
	public function testCreatePageChangeEditEventWithRevertMetadataWithoutOriginalRevisionId() {
		$wikiPage0 = $this->getExistingTestPage(
			Title::makeTitle( $this->getDefaultWikitextNS(), 'MyPageRevertMetaNoOriginal' )
		);

		$this->editPage(
			$wikiPage0,
			$wikiPage0->getContent()->getText() . ' edit1',
			'test edit summary',
			$this->getTestUser()->getUser(),
		);

		$currentRevision = $wikiPage0->getRevisionRecord();
		$parentRevision = $this->revisionStore->getRevisionById(
			$currentRevision->getParentId()
		);
		$this->assertNotNull( $parentRevision );

		$editResult = new EditResult(
			false,
			false,
			EditResult::REVERT_ROLLBACK,
			$currentRevision->getId(),
			$currentRevision->getId(),
			false,
			false,
			[],
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
					'revision' => $this->expectedRevisionInPageChangeEvent( $parentRevision ),
				],
				'revision' => [
					'revert' => [
						'is_exact' => false,
						'method' => 'rollback',
						'rev_reverted_oldest_id' => $currentRevision->getId(),
						'rev_reverted_newest_id' => $currentRevision->getId(),
					],
				],
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
			$parentRevision,
			$editResult,
		);

		$this->assertEventEquals( $expected, $actual );
	}

	/**
	 * @covers ::toMoveEvent
	 */
	public function testCreatePageChangeMoveEvent() {
		$wikiPage0 = $this->getExistingTestPage(
			Title::makeTitle( $this->getDefaultWikitextNS(), 'Renamed_MyPageToMove' )
		);

		// Make an edit to the 'moved page', to make it look like a revision was created
		// due to a page move.
		$this->editPage(
			$wikiPage0,
			$wikiPage0->getContent()->getText() . ' premove edit',
			'test premove edit summary',
			$this->getTestUser()->getUser(),
		);

		// Prior title for the move: same page ID as after the move, but the pre-move DB key.
		$defaultNs = $this->getDefaultWikitextNS();
		$oldTitle = PageIdentityValue::localIdentity(
			$wikiPage0->getId(),
			$defaultNs,
			'MyPageToMove'
		);

		// Move the page!
		$reason = 'test move event';

		$createdRedirectPage = $this->getExistingTestPage(
			Title::makeTitle( $defaultNs, 'MyPageToMove' )
		);

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
				'created_redirect_page' => $this->pageEntitySerializer->toArray(
					$createdRedirectPage,
					PageChangeEventSerializer::PAGE_ENTITY_SCHEMA_VERSION
				),
				'prior_state' => [
					'page' => [
						'page_title' => $this->pageEntitySerializer->formatPageTitle( $oldTitle ),
					],
					'revision' => $this->expectedRevisionInPageChangeEvent( $parentRevisionRecord )
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
		$wikiPage0 = $this->getExistingTestPage( Title::makeTitle( $this->getDefaultWikitextNS(), 'MyDeletedPage' ) );
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
		$wikiPage0 = $this->getExistingTestPage(
			Title::makeTitle( $this->getDefaultWikitextNS(), 'MyDeletedAndSuppressedPage' )
		);
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
		$wikiPage0 = $this->getExistingTestPage(
			Title::makeTitle( $this->getDefaultWikitextNS(), 'MyUndeletedPage' )
		);
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
	 * Builds a PageChangeEventSerializer whose WikibaseItemIdLookup reports
	 * $itemId for the page and $linkItemId for a redirect target link.
	 */
	private function newSerializerWithWikibaseItemIds(
		?string $itemId,
		?string $linkItemId = null
	): PageChangeEventSerializer {
		$lookup = $this->createMock( WikibaseItemIdLookup::class );
		$lookup->method( 'getWikibaseItemIdForPage' )->willReturn( $itemId );
		$lookup->method( 'getWikibaseItemIdForLinkTarget' )->willReturn( $linkItemId );

		return new PageChangeEventSerializer(
			$this->eventSerializer,
			$this->pageEntitySerializer,
			$this->pageLinkEntitySerializer,
			$this->userEntitySerializer,
			$this->globalEditCountLookup,
			$lookup,
			$this->revisionEntitySerializer,
			$this->revisionSlotsEntitySerializer,
			$this->revisionStore,
		);
	}

	/**
	 * page.wikibase_item_id is set from the Wikibase item linked to the page.
	 *
	 * An edit event is used here since that is the steady-state case in which
	 * wikibase_item_id is expected to be set. On page create events the
	 * wikibase_item page prop will usually not (yet) exist.
	 *
	 * @covers ::toEditEvent
	 * @covers ::toCommonAttrs
	 * @covers ::toPageAttrs
	 */
	public function testSetsWikibaseItemIdOnPage() {
		$wikiPage0 = $this->getExistingTestPage(
			Title::makeTitle( $this->getDefaultWikitextNS(), 'MyPageWithWikibaseItem' )
		);

		// Make an edit so the page has at least 2 revisions, so the parent revision
		// will be represented properly.
		$this->editPage(
			$wikiPage0,
			$wikiPage0->getContent()->getText() . ' edit1',
			'test edit summary',
			$this->getTestUser()->getUser(),
		);

		$actual = $this->newSerializerWithWikibaseItemIds( 'Q42' )->toEditEvent(
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

		$this->assertSame( 'Q42', $actual['page']['wikibase_item_id'] );
	}

	/**
	 * When the page has no linked Wikibase item (or Wikibase Client is not
	 * loaded), wikibase_item_id is omitted.
	 *
	 * @covers ::toCreateEvent
	 * @covers ::toPageAttrs
	 */
	public function testOmitsWikibaseItemIdWhenLookupReturnsNull() {
		$wikiPage = $this->getExistingTestPage(
			Title::makeTitle( $this->getDefaultWikitextNS(), 'MyPageWithoutWikibaseItem' )
		);

		$actual = $this->newSerializerWithWikibaseItemIds( null )->toCreateEvent(
			self::MOCK_STREAM_NAME,
			$wikiPage,
			$this->userFactory->newFromUserIdentity(
				$wikiPage->getRevisionRecord()->getUser()
			),
			$wikiPage->getRevisionRecord(),
			null
		);

		$this->assertArrayNotHasKey( 'wikibase_item_id', $actual['page'] );
	}

	/**
	 * A redirect target's own Wikibase item is set at
	 * page.redirect_page_link.wikibase_item_id.
	 *
	 * @covers ::toCreateEvent
	 * @covers ::toCommonAttrs
	 * @covers ::toPageLinkAttrs
	 */
	public function testSetsWikibaseItemIdOnRedirectPageLink() {
		$wikiPage = $this->getExistingTestPage(
			Title::makeTitle( $this->getDefaultWikitextNS(), 'MyRedirectWithWikibaseItem' )
		);
		$redirectTarget = new PageLink(
			Title::makeTitle( $this->getDefaultWikitextNS(), 'MyRedirectTarget' )
		);

		$actual = $this->newSerializerWithWikibaseItemIds( 'Q42', 'Q937' )->toCreateEvent(
			self::MOCK_STREAM_NAME,
			$wikiPage,
			$this->userFactory->newFromUserIdentity(
				$wikiPage->getRevisionRecord()->getUser()
			),
			$wikiPage->getRevisionRecord(),
			$redirectTarget
		);

		$this->assertSame( 'Q42', $actual['page']['wikibase_item_id'] );
		$this->assertSame( 'Q937', $actual['page']['redirect_page_link']['wikibase_item_id'] );
	}

	/**
	 * @covers ::toVisibilityChangeEvent
	 */
	public function testCreatePageChangeVisibilityEvent() {
		// No need to actually delete and undelete to run test.
		$wikiPage0 = $this->getExistingTestPage(
			Title::makeTitle( $this->getDefaultWikitextNS(), 'MyPageToChangeVisibility' )
		);

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
