<?php

namespace MediaWiki\Extension\EventBus\Tests\Integration;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\EventBus\EventBus;
use MediaWiki\Extension\EventBus\EventBusFactory;
use MediaWiki\Extension\EventBus\HookHandlers\MediaWiki\UserChangeHooks;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\UserChangeEventSerializer;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Title\Title;
use MediaWiki\User\UserGroupMembership;
use MediaWiki\User\UserIdentityValue;
use MediaWiki\WikiMap\WikiMap;
use PHPUnit\Framework\Assert;

/**
 * @covers \MediaWiki\Extension\EventBus\HookHandlers\MediaWiki\UserChangeHooks
 * @group Database
 * @group EventBus
 */
class UserChangeEmissionTest extends \MediaWikiIntegrationTestCase {

	private function mockEventBusFactory(
		callable $sendCallback,
		int $expectedNumberOfEvents,
		?string $streamName = null
	): EventBusFactory {
		$capturedEvents = [];

		$spyEventBus = $this->createNoOpMock( EventBus::class, [ 'send' ] );
		$spyEventBus->expects( $this->exactly( $expectedNumberOfEvents ) )
			->method( 'send' )
			->willReturnCallback( static function ( array $events ) use (
				&$capturedEvents,
				$expectedNumberOfEvents,
				$sendCallback
			) {
				self::assertHasProducedOneUserChangeEvent( $events );
				$capturedEvents[] = $events[0];

				if ( count( $capturedEvents ) === $expectedNumberOfEvents ) {
					$sendCallback( $capturedEvents );
				}
			} );

		$eventBusFactory = $this->createNoOpMock( EventBusFactory::class, [ 'getInstanceForStream' ] );
		if ( $streamName !== null ) {
			$eventBusFactory->method( 'getInstanceForStream' )
				->willReturnCallback( static function ( string $stream ) use ( $spyEventBus, $streamName ) {
					// Only spy on the target stream; ignore others.
					return $stream === $streamName ? $spyEventBus : $spyEventBus;
				} );
		} else {
			$eventBusFactory->method( 'getInstanceForStream' )->willReturn( $spyEventBus );
		}

		return $eventBusFactory;
	}

	private static function assertHasProducedOneUserChangeEvent( array $events ): void {
		Assert::assertCount( 1, $events, 'Should have exactly one event per send() call' );
	}

	private static function assertIsValidUserChangeSchemaAndWiki( array $event ): void {
		Assert::assertSame( WikiMap::getCurrentWikiId(), $event['wiki_id'] );
		Assert::assertSame( UserChangeEventSerializer::USER_CHANGE_SCHEMA_URI, $event['$schema'] );
		Assert::assertArrayHasKey( 'changelog_kind', $event );
		Assert::assertContains( $event['changelog_kind'], [ 'insert', 'update', 'delete' ] );
		Assert::assertArrayHasKey( 'meta', $event );
		Assert::assertArrayHasKey( 'id', $event['meta'] );
		Assert::assertArrayHasKey( 'dt', $event );
		Assert::assertIsString( $event['dt'] );
	}

	public function testLocalUserCreatedEmitsCreateEvent(): void {
		// Flush any pending events in the queue
		$this->runDeferredUpdates();

		$createdUser = $this->getTestUser()->getUser();
		$performer = $this->getTestSysop()->getUser();

		$oldUser = RequestContext::getMain()->getUser();
		RequestContext::getMain()->setUser( $performer );

		try {
			$sendCallback = function ( array $events ) use ( $createdUser, $performer ) {
				Assert::assertCount( 1, $events );
				$event = $events[0];

				self::assertIsValidUserChangeSchemaAndWiki( $event );
				Assert::assertSame( 'create', $event['user_change_kind'] );
				Assert::assertSame( 'insert', $event['changelog_kind'] );
				Assert::assertSame( $createdUser->getName(), $event['user']['user_text'] );

				// Hook path uses RequestContext main user as performer.
				Assert::assertArrayHasKey( 'performer', $event );
				Assert::assertSame( $performer->getName(), $event['performer']['user_text'] );

				// Meta URI should be the user's canonical page URL.
				$userTitle = Title::makeTitle( NS_USER, $createdUser->getName() );
				Assert::assertSame( $userTitle->getCanonicalURL(), $event['meta']['uri'] );
			};

			$eventBusFactory = $this->mockEventBusFactory(
				$sendCallback,
				1,
				UserChangeHooks::USER_CHANGE_STREAM_NAME_DEFAULT
			);
			$this->setService( 'EventBus.EventBusFactory', $eventBusFactory );

			/** @var HookContainer $hookContainer */
			$hookContainer = $this->getServiceContainer()->getHookContainer();
			$hookContainer->run( 'LocalUserCreated', [ $createdUser, false ] );

			$this->runDeferredUpdates();
		} finally {
			RequestContext::getMain()->setUser( $oldUser );
		}
	}

	public function testLocalUserCreatedOmitsPerformerWhenAnonymous(): void {
		// Flush any pending events in the queue
		$this->runDeferredUpdates();

		$createdUser = $this->getTestUser()->getUser();
		$anonymousPerformer = $this->getServiceContainer()->getUserFactory()->newAnonymous( '1.2.3.4' );

		$oldUser = RequestContext::getMain()->getUser();
		RequestContext::getMain()->setUser( $anonymousPerformer );

		try {
			$sendCallback = function ( array $events ) use ( $createdUser ) {
				Assert::assertCount( 1, $events );
				$event = $events[0];

				self::assertIsValidUserChangeSchemaAndWiki( $event );
				Assert::assertSame( 'create', $event['user_change_kind'] );
				Assert::assertSame( 'insert', $event['changelog_kind'] );
				Assert::assertSame( $createdUser->getName(), $event['user']['user_text'] );

				Assert::assertArrayNotHasKey( 'performer', $event );
			};

			$eventBusFactory = $this->mockEventBusFactory(
				$sendCallback,
				1,
				UserChangeHooks::USER_CHANGE_STREAM_NAME_DEFAULT
			);
			$this->setService( 'EventBus.EventBusFactory', $eventBusFactory );

			/** @var HookContainer $hookContainer */
			$hookContainer = $this->getServiceContainer()->getHookContainer();
			$hookContainer->run( 'LocalUserCreated', [ $createdUser, false ] );

			$this->runDeferredUpdates();
		} finally {
			RequestContext::getMain()->setUser( $oldUser );
		}
	}

	public function testLocalUserCreatedDoesNotEmitForTempUser(): void {
		// Flush any pending events in the queue
		$this->runDeferredUpdates();

		$tempUserCreator = $this->getServiceContainer()->getTempUserCreator();
		if ( !$tempUserCreator->isKnown() || !$tempUserCreator->isEnabled() ) {
			$this->markTestSkipped( 'Temporary users are not enabled in this environment.' );
		}

		$oldRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
		$_SERVER['REMOTE_ADDR'] = '1.2.3.4';
		try {
			$request = new FauxRequest();
			$status = $tempUserCreator->create( null, $request );
			$this->assertTrue( $status->isOK(), 'Temp user creation should succeed in test environment.' );
			$tempUser = $status->getUser();
		} finally {
			if ( $oldRemoteAddr === null ) {
				unset( $_SERVER['REMOTE_ADDR'] );
			} else {
				$_SERVER['REMOTE_ADDR'] = $oldRemoteAddr;
			}
		}

		$sendCallback = static function ( array $events ) {
			Assert::fail( 'Should not emit events for temp users' );
		};

		$eventBusFactory = $this->mockEventBusFactory(
			$sendCallback,
			0,
			UserChangeHooks::USER_CHANGE_STREAM_NAME_DEFAULT
		);
		$this->setService( 'EventBus.EventBusFactory', $eventBusFactory );

		/** @var HookContainer $hookContainer */
		$hookContainer = $this->getServiceContainer()->getHookContainer();
		$hookContainer->run( 'LocalUserCreated', [ $tempUser, false ] );

		$this->runDeferredUpdates();
	}

	public function testRenameuserLogEntryEmitsRenameEvent(): void {
		$this->runDeferredUpdates();

		// This integration test focuses on the EventBus emission path, using the
		// renameuser log entry hook.
		$user = $this->getTestUser()->getUser();
		$oldName = 'OldNameForTest';
		$newName = $user->getName();

		$performer = $this->getTestSysop()->getUser();
		$comment = 'Rename reason from log entry';
		$oldUser = RequestContext::getMain()->getUser();
		RequestContext::getMain()->setUser( $performer );

		try {
			$sendCallback = function ( array $events ) use ( $user, $oldName, $newName, $performer, $comment ) {
				Assert::assertCount( 1, $events );
				$event = $events[0];

				self::assertIsValidUserChangeSchemaAndWiki( $event );
				Assert::assertSame( 'rename', $event['user_change_kind'] );
				Assert::assertSame( 'update', $event['changelog_kind'] );
				Assert::assertSame( $newName, $event['user']['user_text'] );
				Assert::assertSame( $oldName, $event['prior_state']['user']['user_text'] );

				Assert::assertArrayHasKey( 'performer', $event );
				Assert::assertSame( $performer->getName(), $event['performer']['user_text'] );
				Assert::assertSame( $comment, $event['comment'] );

				$userTitle = Title::makeTitle( NS_USER, $user->getName() );
				Assert::assertSame( $userTitle->getCanonicalURL(), $event['meta']['uri'] );
			};

			$eventBusFactory = $this->mockEventBusFactory(
				$sendCallback,
				1,
				UserChangeHooks::USER_CHANGE_STREAM_NAME_DEFAULT
			);
			$this->setService( 'EventBus.EventBusFactory', $eventBusFactory );

			/** @var HookContainer $hookContainer */
			$hookContainer = $this->getServiceContainer()->getHookContainer();
			$logEntry = new ManualLogEntry( 'renameuser', 'renameuser' );
			$logEntry->setPerformer( $performer );
			$logEntry->setTarget( Title::makeTitle( NS_USER, $oldName ) );
			$logEntry->setComment( $comment );
			$logEntry->setParameters( [
				'4::olduser' => $oldName,
				'5::newuser' => $newName,
			] );
			$hookContainer->run( 'ManualLogEntryBeforePublish', [ $logEntry ] );

			$this->runDeferredUpdates();
		} finally {
			RequestContext::getMain()->setUser( $oldUser );
		}
	}

	/**
	 * Interwiki Special:UserRights changes deliver a foreign UserIdentity to the
	 * UserGroupsChanged hook (since MediaWiki 1.41). The emitted event must
	 * carry the foreign wiki id on user.wiki_id while the event-level wiki_id
	 * still reflects the wiki where the change was processed.
	 *
	 * To avoid touching a non-existent foreign database we replace the
	 * EventBus.UserEntitySerializer and UserGroupManagerFactory services with
	 * mocks for the duration of this test - the wiki-aware serializer's actual
	 * cross-wiki lookups are covered in UserEntitySerializerTest.
	 */
	public function testUserGroupsChangedWithForeignUserIdentityCarriesForeignWikiId(): void {
		$this->runDeferredUpdates();

		$foreignWikiId = 'foreignwiki';
		$foreignUser = new UserIdentityValue( 7, 'ForeignUser', $foreignWikiId );
		$performer = $this->getTestSysop()->getUser();
		$reason = 'interwiki rights change';

		$priorGroups = [ '*', 'user' ];
		$currentGroups = [ '*', 'sysop', 'user' ];
		$oldUGMs = [];
		foreach ( [ 'user' ] as $group ) {
			$oldUGMs[$group] = new \MediaWiki\User\UserGroupMembership( $foreignUser->getId( $foreignWikiId ), $group );
		}
		$newUGMs = [];
		foreach ( [ 'user', 'sysop' ] as $group ) {
			$newUGMs[$group] = new \MediaWiki\User\UserGroupMembership( $foreignUser->getId( $foreignWikiId ), $group );
		}

		// Mock UserGroupManager so calculateUserEffectiveGroups doesn't hit the foreign DB.
		$mockUserGroupManager = $this->createMock( \MediaWiki\User\UserGroupManager::class );
		$mockUserGroupManager->method( 'getUserEffectiveGroups' )
			->willReturnCallback( static function ( $user ) use ( $priorGroups ) {
				// Return implicit + 'user' as the "current effective groups"
				// so that calculateUserEffectiveGroups produces priorGroups when
				// unioned with the oldUGMs/newUGMs the hook receives.
				return $priorGroups;
			} );
		$mockUserGroupManager->method( 'listAllGroups' )
			->willReturn( [ 'user', 'sysop', 'bureaucrat', 'rollbacker' ] );

		$mockUserGroupManagerFactory = $this->createMock( \MediaWiki\User\UserGroupManagerFactory::class );
		$mockUserGroupManagerFactory->method( 'getUserGroupManager' )
			->willReturn( $mockUserGroupManager );
		$this->setService( 'UserGroupManagerFactory', $mockUserGroupManagerFactory );

		// Mock UserEntitySerializer so it doesn't try to query the foreign DB
		// for is_temp / registration_dt / central id.
		$mockUserEntitySerializer = $this->createMock(
			\MediaWiki\Extension\EventBus\Serializers\MediaWiki\UserEntitySerializer::class
		);
		$mockUserEntitySerializer->method( 'toArray' )
			->willReturnCallback( static function ( $userIdentity ): array {
				return [
					'user_text' => $userIdentity->getName(),
					'user_id' => $userIdentity->getId( $userIdentity->getWikiId() ),
					'wiki_id' => $userIdentity->getWikiId() ?: WikiMap::getCurrentWikiId(),
					'groups' => [],
					'is_temp' => false,
				];
			} );
		$mockUserEntitySerializer->method( 'getFirstRegistrationTimestamp' )->willReturn( null );
		$this->setService( 'EventBus.UserEntitySerializer', $mockUserEntitySerializer );

		$sendCallback = function ( array $events ) use ( $foreignWikiId, $performer, $reason ) {
			Assert::assertCount( 1, $events );
			$event = $events[0];

			self::assertIsValidUserChangeSchemaAndWiki( $event );
			Assert::assertSame( 'groups_change', $event['user_change_kind'] );
			// Event-level wiki_id: the wiki on which the change was processed.
			Assert::assertSame( WikiMap::getCurrentWikiId(), $event['wiki_id'] );
			// user.wiki_id: the foreign wiki the target user belongs to.
			Assert::assertSame( $foreignWikiId, $event['user']['wiki_id'] );
			Assert::assertSame( 'ForeignUser', $event['user']['user_text'] );
			Assert::assertSame( 7, $event['user']['user_id'] );

			Assert::assertArrayHasKey( 'performer', $event );
			Assert::assertSame( $performer->getName(), $event['performer']['user_text'] );
			Assert::assertSame( $reason, $event['comment'] );
		};

		$eventBusFactory = $this->mockEventBusFactory(
			$sendCallback,
			1,
			UserChangeHooks::USER_CHANGE_STREAM_NAME_DEFAULT
		);
		$this->setService( 'EventBus.EventBusFactory', $eventBusFactory );

		/** @var HookContainer $hookContainer */
		$hookContainer = $this->getServiceContainer()->getHookContainer();
		$hookContainer->run( 'UserGroupsChanged', [
			$foreignUser,
			[ 'sysop' ],
			[],
			$performer,
			$reason,
			$oldUGMs,
			$newUGMs,
		] );

		$this->runDeferredUpdates();
	}

	public function testUserGroupsChangedEmitsGroupsChangeEvent(): void {
		$this->runDeferredUpdates();

		$user = $this->getTestUser()->getUser();
		$performer = $this->getTestSysop()->getUser();
		$reason = 'Integration test group change reason';

		// Test removing rollbacker and adding bureaucrat.
		// implicit groups should be unchanged.
		$priorGroups = [ '*', 'autoconfirmed', 'rollbacker', 'user' ];
		$oldUGMs = [];
		foreach ( $priorGroups as $group ) {
			$oldUGMs[$group] = new UserGroupMembership( $user->getId(), $group );
		}
		$currentGroups = [ '*', 'autoconfirmed', 'bureaucrat', 'user' ];
		$newUGMs = [];
		foreach ( $currentGroups as $group ) {
			$newUGMs[$group] = new UserGroupMembership( $user->getId(), $group );
		}

		$addedGroups = [ 'bureaucrat' ];
		$removedGroups = [ 'rollbacker' ];

		$sendCallback = function ( array $events ) use ( $user, $performer, $priorGroups, $currentGroups, $reason ) {
			Assert::assertCount( 1, $events );
			$event = $events[0];

			self::assertIsValidUserChangeSchemaAndWiki( $event );
			Assert::assertSame( 'groups_change', $event['user_change_kind'] );
			Assert::assertSame( 'update', $event['changelog_kind'] );
			Assert::assertSame( $priorGroups, $event['prior_state']['user']['groups'] );
			Assert::assertSame( $currentGroups, $event['user']['groups'] );

			Assert::assertArrayHasKey( 'performer', $event );
			Assert::assertSame( $performer->getName(), $event['performer']['user_text'] );
			Assert::assertSame( $reason, $event['comment'] );
		};

		$eventBusFactory = $this->mockEventBusFactory(
			$sendCallback,
			1,
			UserChangeHooks::USER_CHANGE_STREAM_NAME_DEFAULT
		);
		$this->setService( 'EventBus.EventBusFactory', $eventBusFactory );

		/** @var HookContainer $hookContainer */
		$hookContainer = $this->getServiceContainer()->getHookContainer();
		$hookContainer->run( 'UserGroupsChanged', [
			$user,
			$addedGroups,
			$removedGroups,
			$performer,
			$reason,
			$oldUGMs,
			$newUGMs,
		] );

		$this->runDeferredUpdates();
	}

}
