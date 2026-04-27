<?php

use MediaWiki\Extension\EventBus\Serializers\EventSerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\UserChangeEventSerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\UserEntitySerializer;
use MediaWiki\Tests\MockWikiMapTrait;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\UserFactory;
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
			$this->titleFactory
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

}
