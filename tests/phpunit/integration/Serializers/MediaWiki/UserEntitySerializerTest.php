<?php

use MediaWiki\Extension\EventBus\Serializers\EventSerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\UserEntitySerializer;
use MediaWiki\MainConfigNames;
use MediaWiki\User\CentralId\CentralIdLookup;
use MediaWiki\User\Registration\UserRegistrationLookup;
use MediaWiki\User\UserEditTracker;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserGroupManagerFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityValue;
use MediaWiki\WikiMap\WikiMap;

/**
 * @coversDefaultClass \MediaWiki\Extension\EventBus\Serializers\MediaWiki\UserEntitySerializer
 * @group Database
 * @group EventBus
 */
class UserEntitySerializerTest extends MediaWikiIntegrationTestCase {
	private const MOCK_ANON_IP = '1.2.3.4';

	/**
	 * Keys into $toArrayProviders.  This allows us to provide
	 * a static dataProvider for testToArray, but with dynamically
	 * created args from UserFactory Service.
	 *
	 * You must manually make sure these each have corresponding entries in $toArrayProviders
	 */
	private const TO_ARRAY_TEST_NAMES = [
		'Anonymous User',
		'Anonymous UserIdentity',
		'Registered User',
	];

	/**
	 * Dynamically allocated data provider args for testToArray.
	 * These are of the form 'test name' => [input, expected].
	 * @var array
	 */
	private array $toArrayProviders;

	/**
	 * @var UserEntitySerializer
	 */
	private UserEntitySerializer $userEntitySerializer;

	/**
	 * We need to use setUp to have access to MediaWikiIntegrationTestCase methods,
	 * but we only need to initialize things once.
	 * @var bool
	 */
	private bool $setUpHasRun = false;

	public function setUp(): void {
		if ( $this->setUpHasRun ) {
			return;
		}

		$this->overrideConfigValues( [
			// We don't want to test specifically the CentralAuth implementation
			// of the CentralIdLookup. As such, force it to be the local provider.
			MainConfigNames::CentralIdLookupProvider => 'local',
		] );

		$userFactory = $this->getServiceContainer()->getUserFactory();
		$centralIdLookup = $this->getServiceContainer()->getCentralIdLookup();
		$userRegistrationLookup = $this->getServiceContainer()->getUserRegistrationLookup();
		$this->userEntitySerializer = new UserEntitySerializer(
			$userFactory,
			$this->getServiceContainer()->getUserGroupManagerFactory(),
			$centralIdLookup,
			$userRegistrationLookup,
			$this->getServiceContainer()->getUserIdentityUtils(),
			$this->getServiceContainer()->getUserEditTracker()
		);
		// This is stupid workaround that allows us to declare a dataProider with
		// the arguments initialized dynamically using getServiceContainer.
		// Calling $this->>getServiceContainer in a @dataProvider will throw
		// an exception, so instead we look up the data we need by a static
		// key name.
		$anonUser = $userFactory->newAnonymous( self::MOCK_ANON_IP );
		$anonUserIdentity = UserIdentityValue::newAnonymous( self::MOCK_ANON_IP );
		$regUser = $this->getTestUser()->getUser();
		$firstRegistration = $userRegistrationLookup->getFirstRegistration( $regUser );
		$firstRegistrationDt = $firstRegistration !== null
			? EventSerializer::timestampToDt( $firstRegistration )
			: null;
		$this->toArrayProviders = [
			'Anonymous User' => [
				$anonUser,
				[
					'user_text' => $anonUser->getName(),
					'groups' => [ '*' ],
					'is_bot' => false,
					'is_system' => false,
					'is_temp' => false,
				]
			],
			'Anonymous UserIdentity' => [
				$anonUserIdentity,
				[
					'user_text' => $anonUserIdentity->getName(),
					'groups' => [ '*' ],
					'is_bot' => false,
					'is_system' => false,
					'is_temp' => false,
				]
			],
			'Registered User' => [
				$regUser,
				array_filter( [
					'user_text' => $regUser->getName(),
					'groups' => [ '*', 'user', 'autoconfirmed' ],
					'is_bot' => false,
					'is_system' => false,
					'is_temp' => false,
					'user_id' => $regUser->getId(),
					'registration_dt' => EventSerializer::timestampToDt( $regUser->getRegistration() ),
					'edit_count' => $regUser->getEditCount(),
					'user_central_id' => $centralIdLookup->centralIdFromLocalUser( $regUser ),
				], static fn ( $v ) => $v !== null )
			],
		];

		$this->setUpHasRun = true;
	}

	/**
	 * @covers ::__construct
	 */
	public function testConstruct() {
		$this->assertInstanceOf( UserEntitySerializer::class, $this->userEntitySerializer );
	}

	public static function provideToArray() {
		foreach ( self::TO_ARRAY_TEST_NAMES as $testName ) {
			yield $testName => [ $testName ];
		}
	}

	/**
	 * @covers ::toArray
	 * @dataProvider provideToArray
	 * @param string $testName key in $this->>toArrayProviders from which to extract provided params.
	 */
	public function testToArray( string $testName ) {
		$user = $this->toArrayProviders[$testName][0];
		$expected = $this->toArrayProviders[$testName][1];
		$this->assertEquals(
			$expected,
			$this->userEntitySerializer->toArray( $user, '1.1.0' )
		);
	}

	/**
	 * The wiki_id field was added at schema version 1.2.0.
	 * It must not be emitted when serializing to schema version 1.1.0.
	 * @covers ::toArray
	 */
	public function testToArrayVersion_1_1_0_DoesNotIncludeWikiId() {
		$user = $this->getTestUser()->getUser();
		$result = $this->userEntitySerializer->toArray( $user, '1.1.0' );
		$this->assertArrayNotHasKey( 'wiki_id', $result );
	}

	/**
	 * The default schema version is 1.1.0, so wiki_id should not be emitted
	 * when no schema version is given.
	 * @covers ::toArray
	 */
	public function testToArrayDefaultVersionDoesNotIncludeWikiId() {
		$user = $this->getTestUser()->getUser();
		$result = $this->userEntitySerializer->toArray( $user );
		$this->assertArrayNotHasKey( 'wiki_id', $result );
	}

	/**
	 * The wiki_id field was added at schema version 1.2.0.
	 * It must be emitted as a non-empty string when serializing to schema version 1.2.0.
	 * For users that belong to the local wiki, this falls back to
	 * WikiMap::getCurrentWikiId().
	 * @covers ::toArray
	 */
	public function testToArrayVersion_1_2_0_IncludesWikiId() {
		$user = $this->getTestUser()->getUser();
		$result = $this->userEntitySerializer->toArray( $user, '1.2.0' );
		$this->assertArrayHasKey( 'wiki_id', $result );
		$this->assertSame(
			$user->getWikiId() ?: WikiMap::getCurrentWikiId(),
			$result['wiki_id']
		);
		// The schema requires a non-empty string.
		$this->assertIsString( $result['wiki_id'] );
		$this->assertNotEmpty( $result['wiki_id'] );
	}

	/**
	 * The wiki_id field should also be emitted for anonymous users at version 1.2.0.
	 * @covers ::toArray
	 */
	public function testToArrayVersion_1_2_0_IncludesWikiIdForAnonymousUser() {
		$anonUser = $this->getServiceContainer()->getUserFactory()->newAnonymous( self::MOCK_ANON_IP );
		$result = $this->userEntitySerializer->toArray( $anonUser, '1.2.0' );
		$this->assertArrayHasKey( 'wiki_id', $result );
		$this->assertSame(
			$anonUser->getWikiId() ?: WikiMap::getCurrentWikiId(),
			$result['wiki_id']
		);
	}

	/**
	 * Builds a UserEntitySerializer with mocked UserGroupManagerFactory,
	 * CentralIdLookup and UserEditTracker so we can exercise the foreign-wiki
	 * branch without requiring a real foreign DB.
	 *
	 * @param string[] $effectiveGroups Effective groups the mock UserGroupManager will return
	 * @param int $centralId Central id the mock CentralIdLookup will return for foreign users
	 * @param int|null $editCount Edit count the mock UserEditTracker will return (null = no count)
	 * @return UserEntitySerializer
	 */
	private function buildSerializerForForeignWiki(
		array $effectiveGroups,
		int $centralId = 0,
		?int $editCount = null
	): UserEntitySerializer {
		$userGroupManager = $this->createMock( UserGroupManager::class );
		$userGroupManager->method( 'getUserEffectiveGroups' )->willReturn( $effectiveGroups );

		$userGroupManagerFactory = $this->createMock( UserGroupManagerFactory::class );
		$userGroupManagerFactory->method( 'getUserGroupManager' )
			->willReturn( $userGroupManager );

		$centralIdLookup = $this->createMock( CentralIdLookup::class );
		$centralIdLookup->method( 'lookupAttachedUserNames' )
			->willReturnCallback(
				static function ( array $nameToId ) use ( $centralId ): array {
					return array_map( static fn () => $centralId, $nameToId );
				}
			);

		$userRegistrationLookup = $this->createMock( UserRegistrationLookup::class );
		$userRegistrationLookup->method( 'getRegistration' )->willReturn( null );
		$userRegistrationLookup->method( 'getFirstRegistration' )->willReturn( null );

		$userEditTracker = $this->createMock( UserEditTracker::class );
		$userEditTracker->method( 'getUserEditCount' )->willReturn( $editCount );

		return new UserEntitySerializer(
			$this->getServiceContainer()->getUserFactory(),
			$userGroupManagerFactory,
			$centralIdLookup,
			$userRegistrationLookup,
			$this->getServiceContainer()->getUserIdentityUtils(),
			$userEditTracker
		);
	}

	/**
	 * A UserIdentity from a foreign wiki should be serialized with
	 * wiki_id = the foreign wikiId, and only the truly local-only fields
	 * (is_bot, is_system) should be omitted. edit_count is wiki-aware via
	 * UserEditTracker and is included when available.
	 * @covers ::toArray
	 */
	public function testToArrayForeignWikiOmitsLocalOnlyFields() {
		$foreignWikiId = 'foreignwiki';
		$serializer = $this->buildSerializerForForeignWiki(
			[ '*', 'user', 'sysop' ],
			42,
			17
		);

		$foreignUser = new UserIdentityValue( 7, 'ForeignUser', $foreignWikiId );
		$result = $serializer->toArray( $foreignUser, '1.2.0' );

		$this->assertSame( $foreignWikiId, $result['wiki_id'] );
		$this->assertSame( 'ForeignUser', $result['user_text'] );
		$this->assertSame( 7, $result['user_id'] );
		$this->assertSame( [ '*', 'user', 'sysop' ], $result['groups'] );
		$this->assertSame( 42, $result['user_central_id'] );
		$this->assertFalse( $result['is_temp'] );
		// edit_count is wiki-aware and present for foreign users.
		$this->assertSame( 17, $result['edit_count'] );

		// is_bot and is_system are local-only (no first-class cross-wiki path).
		$this->assertArrayNotHasKey( 'is_bot', $result );
		$this->assertArrayNotHasKey( 'is_system', $result );
	}

	/**
	 * If UserEditTracker throws LogicException for an uninitialized edit count
	 * on a foreign wiki, edit_count should be omitted rather than crashing.
	 * @covers ::toArray
	 */
	public function testToArrayForeignWikiOmitsEditCountOnLogicException() {
		$userGroupManager = $this->createMock( UserGroupManager::class );
		$userGroupManager->method( 'getUserEffectiveGroups' )->willReturn( [ '*', 'user' ] );

		$userGroupManagerFactory = $this->createMock( UserGroupManagerFactory::class );
		$userGroupManagerFactory->method( 'getUserGroupManager' )
			->willReturn( $userGroupManager );

		$centralIdLookup = $this->createMock( CentralIdLookup::class );
		$centralIdLookup->method( 'lookupAttachedUserNames' )
			->willReturnCallback(
				static fn ( array $nameToId ): array =>
					array_map( static fn () => 0, $nameToId )
			);

		$userRegistrationLookup = $this->createMock( UserRegistrationLookup::class );
		$userRegistrationLookup->method( 'getRegistration' )->willReturn( null );

		$userEditTracker = $this->createMock( UserEditTracker::class );
		$userEditTracker->method( 'getUserEditCount' )
			->willThrowException( new \LogicException( 'uninitialized on foreign wiki' ) );

		$serializer = new UserEntitySerializer(
			$this->getServiceContainer()->getUserFactory(),
			$userGroupManagerFactory,
			$centralIdLookup,
			$userRegistrationLookup,
			$this->getServiceContainer()->getUserIdentityUtils(),
			$userEditTracker
		);

		$foreignUser = new UserIdentityValue( 7, 'ForeignUser', 'foreignwiki' );
		$result = $serializer->toArray( $foreignUser, '1.2.0' );

		$this->assertArrayNotHasKey( 'edit_count', $result );
		$this->assertSame( 7, $result['user_id'] );
	}

	/**
	 * If the foreign user is not attached / has no central id, user_central_id
	 * should be omitted rather than emitted as 0.
	 * @covers ::toArray
	 */
	public function testToArrayForeignWikiOmitsUserCentralIdWhenZero() {
		$serializer = $this->buildSerializerForForeignWiki( [ '*' ], 0 );

		$foreignUser = new UserIdentityValue( 3, 'OtherForeignUser', 'foreignwiki' );
		$result = $serializer->toArray( $foreignUser, '1.2.0' );

		$this->assertArrayNotHasKey( 'user_central_id', $result );
	}

	/**
	 * A foreign UserIdentity with user_id == 0 (unregistered on its home wiki)
	 * should have user_id omitted.
	 * @covers ::toArray
	 */
	public function testToArrayForeignWikiOmitsUserIdWhenUnregistered() {
		$serializer = $this->buildSerializerForForeignWiki( [ '*' ] );

		// UserIdentityValue with id=0 means unregistered.
		$foreignUser = new UserIdentityValue( 0, 'AnonOnForeign', 'foreignwiki' );
		$result = $serializer->toArray( $foreignUser, '1.2.0' );

		$this->assertArrayNotHasKey( 'user_id', $result );
		$this->assertSame( 'foreignwiki', $result['wiki_id'] );
	}

	/**
	 * At schema version 1.1.0, the foreign branch should still omit the
	 * local-only fields and wiki_id (the latter is gated on 1.2.0).
	 * @covers ::toArray
	 */
	public function testToArrayForeignWikiAtVersion_1_1_0_OmitsWikiId() {
		$serializer = $this->buildSerializerForForeignWiki( [ '*', 'user' ] );

		$foreignUser = new UserIdentityValue( 7, 'ForeignUser', 'foreignwiki' );
		$result = $serializer->toArray( $foreignUser, '1.1.0' );

		$this->assertArrayNotHasKey( 'wiki_id', $result );
		$this->assertArrayNotHasKey( 'is_bot', $result );
		$this->assertArrayNotHasKey( 'is_system', $result );
		$this->assertSame( 'ForeignUser', $result['user_text'] );
	}

	/**
	 * The wiki_id helper falls back to WikiMap::getCurrentWikiId() when the
	 * UserIdentity's wikiId is UserIdentity::LOCAL (i.e. false).
	 * @covers ::toArray
	 */
	public function testToArrayLocalWikiIdResolvesViaWikiMap() {
		$localUserIdentity = new UserIdentityValue( 99, 'LocalUser', UserIdentity::LOCAL );
		$result = $this->userEntitySerializer->toArray( $localUserIdentity, '1.2.0' );

		$this->assertSame( WikiMap::getCurrentWikiId(), $result['wiki_id'] );
	}
}
