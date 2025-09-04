<?php

use MediaWiki\Extension\EventBus\Serializers\EventSerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\UserEntitySerializer;
use MediaWiki\MainConfigNames;
use MediaWiki\User\UserIdentityValue;

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
		$this->userEntitySerializer = new UserEntitySerializer(
			$userFactory,
			$this->getServiceContainer()->getUserGroupManager(),
			$centralIdLookup
		);
		// This is stupid workaround that allows us to declare a dataProider with
		// the arguments initialized dynamically using getServiceContainer.
		// Calling $this->>getServiceContainer in a @dataProvider will throw
		// an exception, so instead we look up the data we need by a static
		// key name.
		$anonUser = $userFactory->newAnonymous( self::MOCK_ANON_IP );
		$anonUserIdentity = UserIdentityValue::newAnonymous( self::MOCK_ANON_IP );
		$regUser = $this->getTestUser()->getUser();
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
				[
					'user_text' => $regUser->getName(),
					'groups' => [ '*', 'user', 'autoconfirmed' ],
					'is_bot' => false,
					'is_system' => false,
					'is_temp' => false,
					'user_id' => $regUser->getId(),
					'registration_dt' => EventSerializer::timestampToDt( $regUser->getRegistration() ),
					'edit_count' => $regUser->getEditCount(),
					'user_central_id' => $centralIdLookup->centralIdFromLocalUser( $regUser ),
				]
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
			$this->userEntitySerializer->toArray( $user )
		);
	}
}
