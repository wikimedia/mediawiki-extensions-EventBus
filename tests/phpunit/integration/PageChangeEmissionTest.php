<?php

namespace phpunit\integration;

use DateTime;
use MediaWiki\CommentFormatter\CommentFormatter;
use MediaWiki\Extension\EventBus\EventBus;
use MediaWiki\Extension\EventBus\EventBusFactory;
use MediaWiki\Extension\EventBus\EventFactory;
use MediaWiki\Extension\EventBus\HookHandlers\MediaWiki\PageChangeHooks;
use MediaWiki\Extension\EventBus\MediaWikiEventSubscribers\PageChangeEventIngress;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Tests\MockWikiMapTrait;
use MediaWiki\Title\Title;
use MediaWiki\WikiMap\WikiMap;
use PHPUnit\Framework\Assert;

/**
 * @covers \MediaWiki\Extension\EventBus\HookHandlers\MediaWiki\PageChangeHooks
 * @group Database
 * @group EventBus
 */
class PageChangeEmissionTest extends \MediaWikiIntegrationTestCase {
	use MockWikiMapTrait;

	/**
	 * Sort an array of event by event time.
	 * Ordering should be guaranteed for in-process emission.
	 *
	 * @param array $events
	 * @return array
	 * @throws \Exception
	 */
	public static function sortEvents( array $events ): array {
		usort( $events, static function ( $a, $b ) {
			$dtA = new DateTime( $a['dt'] );
			$dtB = new DateTime( $b['dt'] );
			return $dtA <=> $dtB;
		} );

		return $events;
	}

	public function setUp(): void {
		parent::setUp();

		$commentFormatter = $this->createMock( CommentFormatter::class );
		$this->setService( 'CommentFormatter', $commentFormatter );

		$this->mockWikiMap();
	}

	/**
	 * Creates a mock EventBusFactory for testing EventBus interactions that
	 * generate mediawiki.page_change.v1 events.
	 *
	 * Creates mocked EventBus objects that can capture and validate events,
	 * along with configurable assertion callbacks.
	 *
	 * The method distinguishes between two types of event streams:
	 * - Streams populated via Domain Events API (uses spyEventBus with assertions)
	 * - Streams populated via Hooks API (uses dummyEventBus with no-op behavior)
	 *
	 * @param callable $sendCallback Callback function invoked after all expected events
	 *                               have been captured. Receives array of captured events.
	 * @param int $expectedNumberOfEvents The exact number of events expected to be sent
	 *                                   through EventBus during the test.
	 * @param Title|null $pageTitle Optional page title for event validation. When provided,
	 *                              enables page-specific assertions on captured events.
	 * @param string|null $streamName Optional stream name to target. When provided, only
	 *                               events for this specific stream will use the spy bus
	 *                               with assertions; other streams use dummy bus.
	 * @param bool $skipCommonAssertions Whether to skip standard event validation assertions.
	 *                                  When true, events are only captured without validation,
	 *                                  useful for tests requiring custom assertion logic.
	 *
	 * @return EventBusFactory Mock EventBusFactory instance configured with the specified
	 *                        behavior and assertion callbacks.
	 */
	private function mockEventBusFactory(
		callable $sendCallback,
		int $expectedNumberOfEvents,
		?Title $pageTitle = null,
		?string $streamName = null,
		bool $skipCommonAssertions = false
	): EventBusFactory {
		$invocationCounter = $this->exactly( $expectedNumberOfEvents );
		$capturedEvents = [];

		$commonAssertionCallback = function ( $events ) use (
			$invocationCounter,
			$expectedNumberOfEvents,
			&$capturedEvents,
			$sendCallback,
			$pageTitle,
			$streamName,
			$skipCommonAssertions
		) {
			if ( !$skipCommonAssertions ) {
				self::assertHasProducedOnePageChangeEvent( $events );

				$event = $events[0];

				self::assertIsValidPageChangeSchemaAndWiki( $event );
				if ( $pageTitle ) {
					self::assertIsValidPageChangePage( $event, $pageTitle, $this->getDefaultWikitextNS() );
					self::assertIsValidPageChangeMeta( $event, $pageTitle, $streamName );
				}

				$capturedEvents[] = $event;
			} else {
				// For tests that need custom assertions, just accumulate events
				$capturedEvents = array_merge( $capturedEvents, $events );
			}

			if ( $invocationCounter->getInvocationCount() === $expectedNumberOfEvents ) {
				$sendCallback( $capturedEvents );
			}
		};

		// The spyEventBus EventBus mock is used only to test code paths triggered by the Domain
		// Events API.
		// For example, the mocked instances will only be injected (and asserted on)
		// for mediawiki.page_change.v1 streams.
		$spyEventBus = $this->createNoOpMock( EventBus::class, [ 'send', 'getFactory' ] );
		$spyEventBus->expects( $invocationCounter )
			->method( 'send' )
			->willReturnCallback( $commonAssertionCallback );

		$eventFactory = $this->createMock( EventFactory::class );
		$eventFactory->method( 'setCommentFormatter' );

		$spyEventBus->method( 'getFactory' )
			->willReturn( $eventFactory );

		// Create a no-op EventBus instance for streams populated via Hooks API.
		$dummyEventBus = $this->createNoOpMock( EventBus::class, [ 'send', 'getFactory' ] );
		$dummyEventBus->method( 'getFactory' )
			->willReturn( $eventFactory );

		$eventBusFactory = $this->createNoOpMock( EventBusFactory::class, [ 'getInstanceForStream' ] );

		if ( $streamName ) {
			$eventBusFactory->method( 'getInstanceForStream' )
				->willReturnCallback( static function ( $stream ) use (
					$spyEventBus,
					$dummyEventBus,
					$streamName
				) {
					if ( $stream === $streamName ) {
						return $spyEventBus;
					}
					return $dummyEventBus;
				} );
		} else {
			$eventBusFactory->method( 'getInstanceForStream' )
				->willReturn( $spyEventBus );
		}

		return $eventBusFactory;
	}

	/**
	 * Test that the event ingress object tracks page revision updates (creation / edit).
	 */
	public function testPageCreateEdit() {
		$pageTitle =
			Title::newFromText( "TestPageCreateEdit", $this->getDefaultWikitextNS() );

		// Flush any pending events in the queue
		$this->runDeferredUpdates();

		$sendCallback = function ( $events ) {
			self::assertIsValidCreateThenEditAction( $events );
		};

		$eventBusFactory =
			$this->mockEventBusFactory(
				$sendCallback,
				2,
				$pageTitle,
				PageChangeEventIngress::PAGE_CHANGE_STREAM_NAME_DEFAULT
			);

		$this->setService( 'EventBus.EventBusFactory', $eventBusFactory );

		// Create a page
		$this->editPage( $pageTitle, 'Some content' );

		// Edit the page
		$this->editPage( $pageTitle, 'Some edits' );

		$this->runDeferredUpdates();
	}

	/**
	 * Test that the event ingress object tracks page delete.
	 * Undeletes are still handled by the Hooks API code path.
	 */
	public function testPageDeleteThenUndelete() {
		$pageTitle =
			Title::newFromText( "TestPageDeleteUndelete", $this->getDefaultWikitextNS() );

		// Flush any pending events in the queue
		$this->runDeferredUpdates();

		// Mock an eventbus instance to allow page creation.
		$eventBusFactory =
			$this->mockEventBusFactory(
				static function () {
				},
				1,
				$pageTitle,
				PageChangeEventIngress::PAGE_CHANGE_STREAM_NAME_DEFAULT
			);

		$this->setService( 'EventBus.EventBusFactory', $eventBusFactory );

		$page = $this->getExistingTestPage( $pageTitle );

		// Delete and Undelete actions will be asserted on their own EventBusFactory mock.
		// Undelete triggers the Hook API.
		$this->deletePageAndAssertEvent(
			$page,
			$pageTitle,
			PageChangeEventIngress::PAGE_CHANGE_STREAM_NAME_DEFAULT
		);

		$this->undeletePageAndAssertEvent(
			$page,
			$pageTitle,
			PageChangeHooks::PAGE_CHANGE_STREAM_NAME_DEFAULT
		);

		$this->runDeferredUpdates();
	}

	private function deletePageAndAssertEvent(
		ProperPageIdentity $page,
		Title $pageTitle,
		string $streamName
	) {
		$sendCallback = function ( $events ) {
			foreach ( $events as $event ) {
				self::assertPageChangeKindIsDelete( $event );
			}
		};

		$eventBusFactory = $this->mockEventBusFactory(
			$sendCallback,
			1,
			$pageTitle,
			$streamName
		);
		$this->setService( 'EventBus.EventBusFactory', $eventBusFactory );

		$this->deletePage( $page );
	}

	private function undeletePageAndAssertEvent(
		ProperPageIdentity $page,
		Title $pageTitle,
		string $streamName
	) {
		$sendCallback = static function ( $events ) {
			foreach ( $events as $event ) {
				self::assertPageChangeKindIsUndelete( $event );
			}
		};

		$eventBusFactory = $this->mockEventBusFactory(
			$sendCallback,
			1,
			$pageTitle,
			$streamName
		);
		$this->setService( 'EventBus.EventBusFactory', $eventBusFactory );

		MediaWikiServices::getInstance()
			->getUndeletePageFactory()
			->newUndeletePage( $page, $this->getTestUser()->getAuthority() )
			->undeleteUnsafe( "Undelete page" );
	}

	/**
	 * @dataProvider providePageMove
	 *
	 * @param string $sourceTitle
	 * @param string $destinationTitle
	 * @param bool $createRedirect
	 * @param int $expectedNumberOfEvents
	 * @return void
	 */
	public function testPageMove(
		string $sourceTitle,
		string $destinationTitle,
		bool $createRedirect = true,
		int $expectedNumberOfEvents = 3
	) {
		$moveFrom = Title::newFromText(
			$sourceTitle,
			$this->getDefaultWikitextNS()
		);

		$moveTo = Title::newFromText(
			$destinationTitle,
			$this->getDefaultWikitextNS()
		);

		// Flush any pending events in the queue
		$this->runDeferredUpdates();

		$sendCallback = function ( $events ) use (
			$moveFrom,
			$moveTo,
			$expectedNumberOfEvents,
			$createRedirect
		) {
			foreach ( $events as $event ) {
				self::assertHasProducedOnePageChangeEvent( [ $event ] );

				$isMinorEdit = $event['page_change_kind'] == 'move';
				$testPageTitle = $event['page_change_kind'] == 'create' ? $moveFrom : $moveTo;

				self::assertIsValidPageChangeSchemaAndWiki( $event );
				self::assertIsValidPageChangePage( $event, $testPageTitle, $this->getDefaultWikitextNS() );
				self::assertIsValidPageChangeRevision( $event, $isMinorEdit );
				self::assertIsValidPageChangeMeta( $event, $testPageTitle );
			}

			// Final assertion on all accumulated events
			self::assertIsValidMoveAction( $events, $createRedirect );
		};

		// No single page name since we have multiple pages
		// Skip common assertions since we do custom ones
		$eventBusFactory = $this->mockEventBusFactory(
			$sendCallback,
			$expectedNumberOfEvents,
			null,
			PageChangeHooks::PAGE_CHANGE_STREAM_NAME_DEFAULT,
			true
		);
		$this->setService( 'EventBus.EventBusFactory', $eventBusFactory );

		$this->getExistingTestPage( $moveFrom );

		Assert::assertTrue( $moveFrom->isMovable() );

		MediaWikiServices::getInstance()
			->getMovePageFactory()
			->newMovePage( $moveFrom->toPageIdentity(), $moveTo->toPageIdentity() )
			->move( $this->getTestUser()->getUserIdentity(), null, $createRedirect );

		if ( $createRedirect ) {
			self::deleteAndAssertRedirectPage( $moveFrom, $moveTo );
		}

		$this->runDeferredUpdates();
	}

	/**
	 * This method is executed within testPageMove() as part of a page move.
	 * The assertions test the redirect created by the move actions is properly handled.
	 *
	 * @param Title $deletedPageTitle
	 * @param Title $redirectTargetTitle
	 * @param int $expectedNumberOfEvents
	 * @return void
	 */
	private function deleteAndAssertRedirectPage(
		Title $deletedPageTitle,
		Title $redirectTargetTitle,
		int $expectedNumberOfEvents = 1
	) {
		$sendCallback = function ( $events ) use (
			$deletedPageTitle,
			$redirectTargetTitle
		) {
			foreach ( $events as $event ) {
				Assert::assertTrue( $event['page']['is_redirect'] );
				self::assertIsValidPageChangeRevision( $event );
				self::assertPageChangeKindIsDelete( $event );

				Assert::assertArrayHasKey( 'redirect_page_link', $event['page'] );

				Assert::assertSame(
					$event['page']['redirect_page_link']['page_title'],
					$redirectTargetTitle->getText()
				);

				Assert::assertSame(
					$event['page']['redirect_page_link']['namespace_id'],
					$this->getDefaultWikitextNS()
				);
			}
		};

		$eventBusFactory = $this->mockEventBusFactory(
			$sendCallback,
			$expectedNumberOfEvents,
			$deletedPageTitle,
			PageChangeHooks::PAGE_CHANGE_STREAM_NAME_DEFAULT
		);

		$this->setService( 'EventBus.EventBusFactory', $eventBusFactory );

		// Delete the page
		$page = $this->getExistingTestPage( $deletedPageTitle );
		$this->deletePage( $page );
	}

	/**
	 * Test that the event ingress object tracks page moves.
	 */
	public static function providePageMove(): array {
		return [
			'Valid move with redirect' => [
				'SourcePageA',
				'DestinationPageA',
				true,
				3
			],
			'Valid move without redirect' => [
				'SourcePageB',
				'DestinationPageB',
				false,
				2
			]
		];
	}

	private static function assertHasProducedOnePageChangeEvent( $events ): void {
		Assert::assertNotNull( $events );
		Assert::assertCount( 1, $events, 'Should have exactly one event per call' );
	}

	private static function assertIsValidPageChangeSchemaAndWiki( array $event ): void {
		Assert::assertEquals( WikiMap::getCurrentWikiId(), $event['wiki_id'] );
		Assert::assertEquals( '/mediawiki/page/change/1.2.0', $event['$schema'] );
	}

	private static function assertIsValidPageChangePage( array $event, Title $pageTitle, int $ns ): void {
		Assert::assertArrayHasKey( 'page', $event );
		Assert::assertEquals( $pageTitle, $event['page']['page_title'] );
		Assert::assertSame( $ns, $event['page']['namespace_id'] );
	}

	private static function assertIsValidPageChangeRevision(
		array $event,
		bool $isMinorEdit = false
	): void {
		Assert::assertArrayHasKey( 'revision', $event );
		Assert::assertSame( $isMinorEdit, $event['revision']['is_minor_edit'] );
		Assert::assertArrayHasKey( 'content_slots', $event['revision'] );
		Assert::assertArrayHasKey( 'main', $event['revision']['content_slots'] );

		$mainSlot = $event['revision']['content_slots']['main'];
		Assert::assertEquals( 'main', $mainSlot['slot_role'] );
		Assert::assertEquals( 'wikitext', $mainSlot['content_model'] );
		Assert::assertEquals( 'text/x-wiki', $mainSlot['content_format'] );
	}

	private static function assertIsValidPageChangeMeta(
		array $event,
		Title $pageTitle,
		?string $streamName = null
	): void {
		Assert::assertArrayHasKey( 'meta', $event );

		if ( $streamName ) {
			Assert::assertEquals( $streamName, $event['meta']['stream'] );
		}

		Assert::assertEquals(
			$pageTitle->getFullURL(),
			$event['meta']['uri']
		);

		// `domain` is extracted from MockWkiMapTrait.
		$wikiReference = WikiMap::getWiki( WikiMap::getCurrentWikiId() );
		Assert::assertEquals( $wikiReference->getDisplayName(), $event['meta']['domain'] );
	}

	private static function assertIsValidCreateThenEditAction( array $events ): void {
		usort( $events, static fn ( $a, $b ) => strcmp( $a['changelog_kind'], $b['changelog_kind'] ) );

		self::assertPageChangeKindIsCreate( $events[0] );
		self::assertPageChangeKindIsEdit( $events[1] );
	}

	/**
	 * @throws \Exception
	 */
	private static function assertIsValidMoveAction( array $events, bool $wasRedirect = true ): void {
		$events = self::sortEvents( $events );

		self::assertPageChangeKindIsCreate( $events[0] );

		if ( $wasRedirect ) {
			Assert::assertCount( 3, $events, 'Expected 3 events when redirect is created' );
			self::assertMoveActionCreatedRedirectPageEvent( $events[1], $events[0], $events[2] );
			self::assertPageChangeKindIsMove( $events[2] );
		} else {
			Assert::assertCount( 2, $events, 'Expected 2 events when no redirect is created' );
			self::assertPageChangeKindIsMove( $events[1] );
		}
	}

	private static function assertPageChangeKindIsCreate( array $event ): void {
		self::assertPageChangeKindIs( $event, 'insert', 'create' );
		Assert::assertFalse( $event['page']['is_redirect'] );
	}

	private static function assertPageChangeKindIsEdit( array $event ): void {
		self::assertPageChangeKindIs( $event, 'update', 'edit' );
		Assert::assertFalse( $event['page']['is_redirect'] );
	}

	private static function assertPageChangeKindIsMove( array $event ): void {
		self::assertPageChangeKindIs( $event, 'update', 'move' );
		Assert::assertFalse( $event['page']['is_redirect'] );
	}

	private static function assertPageChangeKindIsDelete( array $event ): void {
		self::assertPageChangeKindIs( $event, 'delete', 'delete' );
	}

	private static function assertPageChangeKindIsUndelete( array $event ): void {
		self::assertPageChangeKindIs( $event, 'insert', 'undelete' );
	}

	private static function assertPageChangeKindIs(
		array $event,
		string $changelogKind,
		string $pageChangeKind
	): void {
		Assert::assertEquals( $changelogKind, $event['changelog_kind'] );
		Assert::assertEquals( $pageChangeKind, $event['page_change_kind'] );
	}

	private static function assertMoveActionCreatedRedirectPageEvent(
		array $redirectEvent,
		array $sourceEvent,
		array $destinationEvent
	): void {
		self::assertPageChangeKindIs( $redirectEvent, 'insert', 'create' );
		Assert::assertTrue( $redirectEvent['page']['is_redirect'] );
		Assert::assertSame( $sourceEvent['page']['page_title'], $redirectEvent['page']['page_title'] );
		Assert::assertArrayHasKey( 'redirect_page_link', $redirectEvent['page'] );
		Assert::assertSame(
			$redirectEvent['page']['redirect_page_link']['page_title'],
			$destinationEvent['page']['page_title']
		);

		Assert::assertArrayHasKey( "prior_state", $destinationEvent );
		Assert::assertSame( $sourceEvent['page']['page_title'],
			$destinationEvent['prior_state']['page']['page_title'] );
		Assert::assertSame( $sourceEvent['page']['namespace_id'],
			$destinationEvent['prior_state']['page']['namespace_id'] );

		// The moved page carries information about the parent revision's prior state.
		// Some fields. like edit_count of an editor subkey, might diverge. Here we explicitly
		// assert on
		// the fields explicitly set by the `PageChangeEventSerializer::toMoveEvent` method.

		Assert::assertSame( $sourceEvent['revision']['rev_id'],
			$destinationEvent['prior_state']['revision']['rev_id'] );

		Assert::assertSame( $sourceEvent['revision']['rev_dt'],
			$destinationEvent['prior_state']['revision']['rev_dt'] );

		Assert::assertSame( $sourceEvent['revision']['is_minor_edit'],
			$destinationEvent['prior_state']['revision']['is_minor_edit'] );

		Assert::assertSame( $sourceEvent['revision']['rev_sha1'],
			$destinationEvent['prior_state']['revision']['rev_sha1'] );

		Assert::assertSame( $sourceEvent['revision']['rev_size'],
			$destinationEvent['prior_state']['revision']['rev_size'] );

		if ( isset( $sourceEvent['revision']['comment'] ) ) {
			Assert::assertSame( $sourceEvent['revision']['comment'],
				$destinationEvent['prior_state']['revision']['comment'] );
		}
		Assert::assertSame( $sourceEvent['revision']['editor']['is_temp'],
			$destinationEvent['prior_state']['revision']['editor']['is_temp'] );

		Assert::assertSame( $sourceEvent['revision']['editor']['user_id'],
			$destinationEvent['prior_state']['revision']['editor']['user_id'] );

		Assert::assertSame( $sourceEvent['revision']['editor']['edit_count'],
			$destinationEvent['prior_state']['revision']['editor']['edit_count'] - 1 );

		Assert::assertSame( $sourceEvent['revision']['editor']['registration_dt'],
			$destinationEvent['prior_state']['revision']['editor']['registration_dt'] );

		if ( isset( $sourceEvent['revision']['content_slots'] ) ) {
			Assert::assertSame( $sourceEvent['revision']['content_slots'],
				$destinationEvent['prior_state']['revision']['content_slots'] );
		}
	}
}
