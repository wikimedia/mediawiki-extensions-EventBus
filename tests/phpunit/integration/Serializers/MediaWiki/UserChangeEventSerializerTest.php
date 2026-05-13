<?php

use MediaWiki\Extension\EventBus\Serializers\EventSerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\UserChangeEventSerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\UserEntitySerializer;
use MediaWiki\Tests\MockWikiMapTrait;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentityValue;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Assert\ParameterAssertionException;
use Wikimedia\UUID\GlobalIdGenerator;

/**
 * @coversDefaultClass \MediaWiki\Extension\EventBus\Serializers\MediaWiki\UserChangeEventSerializer
 * @group Database
 * @group EventBus
 */
class UserChangeEventSerializerTest extends MediaWikiIntegrationTestCase {
	use MockWikiMapTrait;

	private const MOCK_UUID = '00000000-0000-4000-8000-000000000000';
	private const MOCK_STREAM_NAME = 'mediawiki.user_change';

	private EventSerializer $eventSerializer;
	private UserEntitySerializer $userEntitySerializer;
	private TitleFactory $titleFactory;
	private UserChangeEventSerializer $serializer;
	private UserFactory $userFactory;

	public function setUp(): void {
		parent::setUp();

		$this->mockWikiMap();

		$globalIdGenerator = $this->createMock( GlobalIdGenerator::class );
		$globalIdGenerator->method( 'newUUIDv4' )->willReturn( self::MOCK_UUID );
		$this->eventSerializer = new EventSerializer( $globalIdGenerator );

		$services = $this->getServiceContainer();
		$this->userFactory = $services->getUserFactory();
		// Exercise ServiceWiring.php like PageChangeEventSerializerTest does.
		$this->userEntitySerializer = $services->get( 'EventBus.UserEntitySerializer' );
		$this->titleFactory = $services->getTitleFactory();

		$this->serializer = new UserChangeEventSerializer(
			$this->eventSerializer,
			$this->userEntitySerializer,
			$this->titleFactory,
			$services->getUserIdentityUtils()
		);
	}

	/**
	 * @covers ::toCreateEvent
	 */
	public function testToCreateEvent(): void {
		$user = $this->getTestUser()->getUser();

		$event = $this->serializer->toCreateEvent( self::MOCK_STREAM_NAME, '20250101000000', $user, null, false );

		$this->assertSame( UserChangeEventSerializer::USER_CHANGE_SCHEMA_URI, $event['$schema'] );
		$this->assertSame( self::MOCK_STREAM_NAME, $event['meta']['stream'] );
		$this->assertSame( self::MOCK_UUID, $event['meta']['id'] );
		$this->assertArrayHasKey( 'domain', $event['meta'] );
		$this->assertSame( $user->getName(), $event['user']['user_text'] );
		$this->assertSame( 'create', $event['user_change_kind'] );
		$this->assertSame( 'insert', $event['changelog_kind'] );
		$this->assertArrayNotHasKey( 'performer', $event );
	}

	/**
	 * @covers ::toCreateEvent
	 */
	public function testToCreateEventWithPerformer(): void {
		$user = $this->getTestUser()->getUser();
		$performer = $this->getTestSysop()->getUser();

		$event = $this->serializer->toCreateEvent( self::MOCK_STREAM_NAME, '20250101000000', $user, $performer, false );

		$this->assertArrayHasKey( 'performer', $event );
		$this->assertSame( $performer->getName(), $event['performer']['user_text'] );
	}

	/**
	 * @covers ::toRenameEvent
	 */
	public function testToRenameEvent(): void {
		$user = $this->getTestUser()->getUser();

		$event = $this->serializer->toRenameEvent(
			self::MOCK_STREAM_NAME,
			'20250101000000',
			$user,
			null,
			$user->getName(),
			'OldName',
			null
		);

		$this->assertSame( 'rename', $event['user_change_kind'] );
		$this->assertSame( 'update', $event['changelog_kind'] );
		$this->assertSame( 'OldName', $event['prior_state']['user']['user_text'] );
		$this->assertSame( $user->getName(), $event['user']['user_text'] );
		$this->assertArrayNotHasKey( 'performer', $event );
		$this->assertArrayNotHasKey( 'comment', $event );
	}

	/**
	 * @covers ::toRenameEvent
	 */
	public function testToRenameEventWithComment(): void {
		$user = $this->getTestUser()->getUser();

		$event = $this->serializer->toRenameEvent(
			self::MOCK_STREAM_NAME,
			'20250101000000',
			$user,
			null,
			$user->getName(),
			'OldName',
			'rename reason'
		);

		$this->assertSame( 'rename', $event['user_change_kind'] );
		$this->assertSame( 'rename reason', $event['comment'] );
	}

	/**
	 * @covers ::toGroupsChangedEvent
	 */
	public function testToGroupsChangedEvent(): void {
		$user = $this->getTestUser()->getUser();
		$performer = $this->getTestSysop()->getUser();

		$event = $this->serializer->toGroupsChangedEvent(
			self::MOCK_STREAM_NAME,
			'20250101000000',
			$user,
			$performer,
			[ 'rollbacker', 'user' ],
			[ 'user' ],
			'Test groups change reason'
		);

		$this->assertSame( 'groups_change', $event['user_change_kind'] );
		$this->assertSame( 'update', $event['changelog_kind'] );
		$this->assertSame( [ 'user' ], $event['prior_state']['user']['groups'] );
		$this->assertSame( [ 'rollbacker', 'user' ], $event['user']['groups'] );
		$this->assertArrayHasKey( 'performer', $event );
		$this->assertSame( $performer->getName(), $event['performer']['user_text'] );
		$this->assertSame( 'Test groups change reason', $event['comment'] );
	}

	/**
	 * @covers ::toGroupsChangedEvent
	 */
	public function testToGroupsChangedEventWithoutPerformer(): void {
		$user = $this->getTestUser()->getUser();

		$event = $this->serializer->toGroupsChangedEvent(
			self::MOCK_STREAM_NAME,
			'20250101000000',
			$user,
			null,
			[ 'rollbacker', 'user' ],
			[ 'user' ],
			null
		);

		$this->assertArrayNotHasKey( 'performer', $event );
		$this->assertArrayNotHasKey( 'comment', $event );
	}

	/**
	 * @covers ::toCommonAttrs
	 */
	public function testToCreateEventRejectsAnonymousUser(): void {
		$anonUser = $this->userFactory->newAnonymous( '1.2.3.4' );

		$this->expectException( ParameterAssertionException::class );
		$this->serializer->toCreateEvent( self::MOCK_STREAM_NAME, '20250101000000', $anonUser, null, false );
	}

	/**
	 * @covers ::toCommonAttrs
	 */
	public function testToCreateEventRejectsAnonymousPerformer(): void {
		$user = $this->getTestUser()->getUser();
		$anonPerformer = $this->userFactory->newAnonymous( '1.2.3.4' );

		$this->expectException( ParameterAssertionException::class );
		$this->serializer->toCreateEvent( self::MOCK_STREAM_NAME, '20250101000000', $user, $anonPerformer, false );
	}

	/**
	 * UserChangeEventSerializer is expected to serialize user entities at
	 * USER_ENTITY_SCHEMA_VERSION, which is currently 1.2.0.
	 * The 1.2.0 user entity schema adds a wiki_id field, so we should see it
	 * in event['user'] (and event['performer'] when present).
	 *
	 * @covers ::toCommonAttrs
	 */
	public function testUserEntitySchemaVersionIs_1_2_0(): void {
		$this->assertSame( '1.2.0', UserChangeEventSerializer::USER_ENTITY_SCHEMA_VERSION );
	}

	/**
	 * @covers ::toCreateEvent
	 * @covers ::toCommonAttrs
	 */
	public function testToCreateEventSerializesUserAtVersion_1_2_0(): void {
		$user = $this->getTestUser()->getUser();
		$performer = $this->getTestSysop()->getUser();
		$expectedWikiId = WikiMap::getCurrentWikiId();

		$event = $this->serializer->toCreateEvent(
			self::MOCK_STREAM_NAME, '20250101000000', $user, $performer, false
		);

		// The user entity is serialized at version 1.2.0, which includes wiki_id.
		$this->assertArrayHasKey( 'wiki_id', $event['user'] );
		$this->assertSame( $expectedWikiId, $event['user']['wiki_id'] );
		$this->assertArrayHasKey( 'wiki_id', $event['performer'] );
		$this->assertSame( $expectedWikiId, $event['performer']['wiki_id'] );
	}

	/**
	 * @covers ::toRenameEvent
	 * @covers ::toCommonAttrs
	 */
	public function testToRenameEventSerializesUserAtVersion_1_2_0(): void {
		$user = $this->getTestUser()->getUser();
		$performer = $this->getTestSysop()->getUser();
		$expectedWikiId = WikiMap::getCurrentWikiId();

		$event = $this->serializer->toRenameEvent(
			self::MOCK_STREAM_NAME,
			'20250101000000',
			$user,
			$performer,
			$user->getName(),
			'OldName',
			null
		);

		$this->assertArrayHasKey( 'wiki_id', $event['user'] );
		$this->assertSame( $expectedWikiId, $event['user']['wiki_id'] );
		$this->assertArrayHasKey( 'wiki_id', $event['performer'] );
		$this->assertSame( $expectedWikiId, $event['performer']['wiki_id'] );
	}

	/**
	 * @covers ::toGroupsChangedEvent
	 * @covers ::toCommonAttrs
	 */
	public function testToGroupsChangedEventSerializesUserAtVersion_1_2_0(): void {
		$user = $this->getTestUser()->getUser();
		$performer = $this->getTestSysop()->getUser();
		$expectedWikiId = WikiMap::getCurrentWikiId();

		$event = $this->serializer->toGroupsChangedEvent(
			self::MOCK_STREAM_NAME,
			'20250101000000',
			$user,
			$performer,
			[ 'rollbacker', 'user' ],
			[ 'user' ],
			null
		);

		$this->assertArrayHasKey( 'wiki_id', $event['user'] );
		$this->assertSame( $expectedWikiId, $event['user']['wiki_id'] );
		$this->assertArrayHasKey( 'wiki_id', $event['performer'] );
		$this->assertSame( $expectedWikiId, $event['performer']['wiki_id'] );
	}

	/**
	 * Interwiki Special:UserRights changes deliver a foreign UserIdentity to
	 * the UserGroupsChanged hook. UserChangeEventSerializer must forward that
	 * foreign identity to UserEntitySerializer so that event['user']['wiki_id']
	 * carries the foreign wiki id (while the event-level event['wiki_id'] still
	 * reflects the wiki on which the change was processed).
	 *
	 * Uses a mocked UserEntitySerializer to isolate this class' behaviour from
	 * the wiki-aware lookups exercised in UserEntitySerializerTest.
	 *
	 * @covers ::toGroupsChangedEvent
	 * @covers ::toCommonAttrs
	 */
	public function testToGroupsChangedEventWithForeignUserIdentity(): void {
		$foreignWikiId = 'foreignwiki';
		$foreignUser = new UserIdentityValue( 7, 'ForeignUser', $foreignWikiId );

		$mockUserEntitySerializer = $this->createMock( UserEntitySerializer::class );
		$mockUserEntitySerializer
			->method( 'toArray' )
			->willReturnCallback( static function ( $userIdentity ): array {
				return [
					'user_text' => $userIdentity->getName(),
					'user_id' => $userIdentity->getId( $userIdentity->getWikiId() ),
					'wiki_id' => $userIdentity->getWikiId() ?: WikiMap::getCurrentWikiId(),
					'groups' => [],
					'is_temp' => false,
				];
			} );
		$mockUserEntitySerializer
			->method( 'getFirstRegistrationTimestamp' )
			->willReturn( null );

		$serializer = new UserChangeEventSerializer(
			$this->eventSerializer,
			$mockUserEntitySerializer,
			$this->titleFactory,
			$this->getServiceContainer()->getUserIdentityUtils()
		);

		$event = $serializer->toGroupsChangedEvent(
			self::MOCK_STREAM_NAME,
			'20250101000000',
			$foreignUser,
			null,
			[ '*', 'user', 'sysop' ],
			[ '*', 'user' ],
			'interwiki rights change'
		);

		// event-level wiki_id always reflects the current wiki (where the change happened).
		$this->assertSame( WikiMap::getCurrentWikiId(), $event['wiki_id'] );
		// user.wiki_id reflects the home wiki of the affected user.
		$this->assertSame( $foreignWikiId, $event['user']['wiki_id'] );
		$this->assertSame( 'ForeignUser', $event['user']['user_text'] );
		$this->assertSame( 7, $event['user']['user_id'] );
		// toGroupsChangedEvent sorts both group arrays.
		$this->assertSame( [ '*', 'sysop', 'user' ], $event['user']['groups'] );
		$this->assertSame( [ '*', 'user' ], $event['prior_state']['user']['groups'] );
		$this->assertSame( 'interwiki rights change', $event['comment'] );

		// The foreign wiki isn't registered in WikiMap for this test, so
		// canonicalUserPageURL falls back to a local-wiki Title URL.
		$this->assertStringContainsString( 'User:ForeignUser', $event['meta']['uri'] );
	}

	/**
	 * When the affected user belongs to a foreign wiki that is registered
	 * in WikiMap (here, via SiteLookup), meta.uri should resolve to a URL
	 * on the foreign wiki's canonical server rather than the local one.
	 *
	 * @covers ::toGroupsChangedEvent
	 * @covers ::toCommonAttrs
	 */
	public function testForeignUserChangeEventUsesForeignCanonicalUrl(): void {
		$foreignWikiId = 'foreignwiki';
		$foreignServer = 'https://foreign.example.org';

		// Re-mock WikiMap to register an extra foreign wiki.
		$this->mockWikiMap(
			'https://example.com',
			[ [ 'wikiId' => $foreignWikiId, 'server' => $foreignServer ] ]
		);

		$foreignUser = new UserIdentityValue( 7, 'ForeignUser', $foreignWikiId );

		// Mock UserEntitySerializer to keep this test focused on URL resolution
		// and avoid foreign-DB lookups.
		$mockUserEntitySerializer = $this->createMock( UserEntitySerializer::class );
		$mockUserEntitySerializer
			->method( 'toArray' )
			->willReturn( [
				'user_text' => 'ForeignUser',
				'wiki_id' => $foreignWikiId,
				'groups' => [],
				'is_temp' => false,
			] );
		$mockUserEntitySerializer
			->method( 'getFirstRegistrationTimestamp' )
			->willReturn( null );

		$serializer = new UserChangeEventSerializer(
			$this->eventSerializer,
			$mockUserEntitySerializer,
			$this->titleFactory,
			$this->getServiceContainer()->getUserIdentityUtils()
		);

		$event = $serializer->toGroupsChangedEvent(
			self::MOCK_STREAM_NAME,
			'20250101000000',
			$foreignUser,
			null,
			[ 'user' ],
			[],
			null
		);

		$this->assertStringStartsWith( $foreignServer . '/wiki/', $event['meta']['uri'] );
		$this->assertStringContainsString( 'ForeignUser', $event['meta']['uri'] );
		// Event-level wiki_id still reflects the wiki on which the change happened.
		$this->assertSame( WikiMap::getCurrentWikiId(), $event['wiki_id'] );
	}

}
