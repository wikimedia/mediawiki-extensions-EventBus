<?php

namespace MediaWiki\Extension\EventBus\Tests\Integration;

use InvalidArgumentException;
use MediaWiki\CommentFormatter\CommentFormatter;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\EventBus\EventBus;
use MediaWiki\Extension\EventBus\EventBusFactory;
use MediaWiki\Extension\EventBus\EventFactory;
use MediaWiki\Extension\EventBus\MediaWikiEventSubscribers\PageChangeEventIngress;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\PageChangeEventSerializer;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Tests\MockWikiMapTrait;
use MediaWiki\Title\Title;
use MediaWiki\WikiMap\WikiMap;
use PHPUnit\Framework\Assert;
use RevisionDeleter;

/**
 * @covers \MediaWiki\Extension\EventBus\MediaWikiEventSubscribers\PageChangeEventIngress
 * @group Database
 * @group EventBus
 */
class PageChangeEmissionTest extends \MediaWikiIntegrationTestCase {
	use MockWikiMapTrait;

	/**
	 * Sort page move events by page_change_kind enforces deterministic
	 * ordering of the action as performed by MediaWiki.
	 *
	 * While events carry an event time (`dt`) timestamp, we can't rely
	 * on that to sort move actions chronologically in both Hook
	 * and Domain Event code paths. Events originating from Hook callbacks
	 * might have been emitted out-of-order relative to the page action.
	 *
	 * This method tries to reconstruct temporal ordering from semantic event
	 * types rather than using actual timestamps.
	 *
	 * @param array $events
	 * @return array
	 * @throws \Exception
	 */
	private static function sortMoveActionsByKind( array $events ): array {
		usort( $events, static function ( $a, $b ) {
			$kindOrder = [
				'create' => 0,
				'move' => 1
			];

			if ( !isset( $kindOrder[$a['page_change_kind']] ) || !isset( $kindOrder[$b['page_change_kind']] ) ) {
				throw new InvalidArgumentException( 'Unknown page_change_kind in event' );
			}

			$kindA = $kindOrder[$a['page_change_kind']];
			$kindB = $kindOrder[$b['page_change_kind']];

			$kindCmp = $kindA <=> $kindB;

			// If both are the same kind and both are 'create', break tie on redirect status.
			// This is an arbitrary choice to enforce predictable ordering in chain of
			// assertions.
			if ( $kindCmp === 0 && $a['page_change_kind'] === 'create' ) {
				$isRedirectA = $a['page']['is_redirect'] ?? false;
				$isRedirectB = $b['page']['is_redirect'] ?? false;

				return (int)$isRedirectA <=> (int)$isRedirectB;
			}

			return $kindCmp;
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
	 * Data provider for Domain Event Ingress code paths.
	 *
	 * Provides stream name constants for testing different event
	 * processing pathways for `page_change` record serialization.
	 *
	 * @return array<string, array{string}> Test cases with stream names
	 */
	private static function provideStreamName(): array {
		return [
			"Test Domain Event code paths" =>
				[ PageChangeEventIngress::PAGE_CHANGE_STREAM_NAME_DEFAULT ]
		];
	}

	/**
	 * @dataProvider provideStreamName
	 *
	 * Test that the event ingress object tracks page revision updates (creation / edit).
	 *
	 * @param string $streamName
	 */
	public function testPageCreateEdit( string $streamName ) {
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
				$streamName
			);

		$this->setService( 'EventBus.EventBusFactory', $eventBusFactory );

		// Create a page
		$this->editPage( $pageTitle, 'Some content' );

		// Edit the page
		$this->editPage( $pageTitle, 'Some edits' );

		$this->runDeferredUpdates();
	}

	/**
	 * @dataProvider provideStreamName
	 *
	 * Test that the event ingress object tracks page delete.
	 * Undeletes are still handled by the Hooks API code path.
	 *
	 * @param string $streamName
	 */
	public function testPageDeleteThenUndelete( string $streamName ) {
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
				$streamName
			);

		$this->setService( 'EventBus.EventBusFactory', $eventBusFactory );

		$page = $this->getExistingTestPage( $pageTitle );

		// Delete and Undelete actions will be asserted on their own EventBusFactory mock.
		// Undelete triggers the Hook API.
		$this->deletePageAndAssertEvent(
			$page,
			$pageTitle,
			$streamName
		);

		$this->undeletePageAndAssertEvent(
			$page,
			$pageTitle,
			$streamName
		);

		$this->runDeferredUpdates();
	}

	private function deletePageAndAssertEvent(
		ProperPageIdentity $page,
		Title $pageTitle,
		string $streamName
	): void {
		$sendCallback = function ( $events ) use ( $page ) {
			foreach ( $events as $event ) {
				self::assertPageChangeKindIsDelete( $event );
				self::assertIsValidPageChangePageIdentity( $page, $event );
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
	): void {
		$sendCallback = static function ( $events ) use ( $page ) {
			foreach ( $events as $event ) {
				self::assertPageChangeKindIsUndelete( $event );
				self::assertIsValidPageChangePageIdentity( $page, $event );
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
	 * @param string $streamName
	 * @param string $sourceTitle
	 * @param string $destinationTitle
	 * @param bool $createRedirect
	 * @param int $expectedNumberOfEvents
	 * @return void
	 */
	public function testPageMove(
		string $streamName,
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
			$streamName,
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
			self::deleteAndAssertRedirectPage( $moveFrom, $moveTo, $streamName );
		}

		$this->runDeferredUpdates();
	}

	/**
	 * Test revision visibility changes as performed by a batch process
	 * that alters revision history.
	 *
	 * Ensure that suppressed editor information is not leaked by EventBus
	 *
	 * @dataProvider provideStreamName
	 *
	 * @return void
	 */
	public function testRevisionVisibilityChange( string $streamName ) {
		// flush
		$this->runDeferredUpdates();

		$pageTile = Title::newFromText(
			"TestRevisionVisibilityChange",
			$this->getDefaultWikitextNS()
		);

		// Don't assert on page creation and edit.
		// We need these operation to create a new test page,
		// but we don't need to assert that code path.
		$eventBusFactory = $this->mockEventBusFactory(
			static function () {
			},
			2,
			$pageTile,
			$streamName,
			true
		);
		$this->setService( 'EventBus.EventBusFactory', $eventBusFactory );

		// Create page and edit test revisions
		$page = $this->getExistingTestPage( $pageTile );

		$rev1 = $page->getLatest();
		$rev2 = $this->editPage( $page, 'newer content' )->getNewRevision()->getId();
		$this->runDeferredUpdates();

		Assert::assertTrue( $rev2 > $rev1 );

		$this->setVisibilityAndAssertRevisionChange( $page, $pageTile, $rev1, $rev2, $streamName );
	}

	private function setVisibilityAndAssertRevisionChange(
		ProperPageIdentity $page,
		Title $pageTile,
		int $rev1,
		int $rev2,
		string $streamName
	): void {
		$context = RequestContext::getMain();
		$context->setUser( $this->getTestSysop()->getUser() );

		$sendCallback = function ( $events ) use ( $page, $rev2 ) {
			$wasVisibilityChanged = false;
			foreach ( $events as $event ) {
				if ( $event['page_change_kind'] == 'visibility_change' ) {
					$wasVisibilityChanged = true;
					Assert::assertSame( $page->getId(), $event['page']['page_id'] );
					Assert::assertSame( $rev2, $event['revision']['rev_id'] );
					// Performer must be omitted for suppressed revisions
					Assert::assertFalse( $event['revision']['is_editor_visible'] );
					Assert::assertArrayNotHasKey( 'performer', $event );
					// But content can still be visible
					Assert::assertTrue( $event['revision']['is_content_visible'] );

				}
			}
			self::assertTrue( $wasVisibilityChanged );
		};

		$eventBusFactory = $this->mockEventBusFactory(
			$sendCallback,
			1,
			$pageTile,
			$streamName
		);

		$this->setService( 'EventBus.EventBusFactory', $eventBusFactory );

		$visibility = [
			RevisionRecord::DELETED_TEXT => 0,
			RevisionRecord::DELETED_USER => 1,
			RevisionRecord::DELETED_RESTRICTED => 1, ];
		$ids = [ $rev1 ];

		$deleter = RevisionDeleter::createList( 'revision', $context, $page, $ids );
		$params = [
			'value' => $visibility,
			'comment' => 'test 1',
			'tags' => [ 'test' ]
		];
		$status = $deleter->setVisibility( $params );
		$this->assertStatusOK( $status );

		$visibility = [
			// 1 = se, 0 = unset, -1 = keep
			RevisionRecord::DELETED_USER => 1,
			RevisionRecord::DELETED_RESTRICTED => 1,
		];

		$ids = [ $rev1, $rev2 ];

		$deleter = RevisionDeleter::createList( 'revision', $context, $page, $ids );

		$params = [
			'value' => $visibility,
			'comment' => 'test 2',
			'tags' => [ 'test' ]
		];

		$status = $deleter->setVisibility( $params );
		$this->assertStatusOK( $status );

		$this->runDeferredUpdates();
	}

	/**
	 * This method is executed within testPageMove() as part of a page move.
	 * The assertions test the redirect created by the move actions is properly handled.
	 *
	 * @param Title $deletedPageTitle
	 * @param Title $redirectTargetTitle
	 * @param string $streamName
	 * @param int $expectedNumberOfEvents
	 * @return void
	 */
	private function deleteAndAssertRedirectPage(
		Title $deletedPageTitle,
		Title $redirectTargetTitle,
		string $streamName,
		int $expectedNumberOfEvents = 1
	): void {
		// Delete the page
		$page = $this->getExistingTestPage( $deletedPageTitle );

		$sendCallback = function ( $events ) use (
			$page,
			$deletedPageTitle,
			$redirectTargetTitle
		) {
			foreach ( $events as $event ) {
				Assert::assertTrue( $event['page']['is_redirect'] );
				self::assertIsValidPageChangeRevision( $event );
				self::assertPageChangeKindIsDelete( $event );
				self::assertIsValidPageChangePageIdentity( $page, $event );

				Assert::assertArrayHasKey( 'redirect_page_link', $event['page'] );

				Assert::assertSame(
					$event['page']['redirect_page_link']['page_title'],
					$redirectTargetTitle->getPrefixedDBkey()
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
			$streamName
		);

		$this->setService( 'EventBus.EventBusFactory', $eventBusFactory );

		$this->deletePage( $page );
	}

	/**
	 * Page move scenarios combined with multiple stream names,
	 * to test Hooks and Domain Event serialization code paths.
	 *
	 * @return array
	 */
	public static function providePageMove(): array {
		$streamNames = static::provideStreamName();
		$scenarios = [];

		foreach ( $streamNames as $streamName ) {
			$scenarios[$streamName[0] . '_valid_move_with_redirect'] = [
				$streamName[0],
				'SourcePageA',
				'DestinationPageA',
				true,
				3
			];

			$scenarios[$streamName[0] . '_valid_move_without_redirect'] = [
				$streamName[0],
				'SourcePageB',
				'DestinationPageB',
				false,
				2
			];
		}

		return $scenarios;
	}

	private static function assertHasProducedOnePageChangeEvent( $events ): void {
		Assert::assertNotNull( $events );
		Assert::assertCount( 1, $events, 'Should have exactly one event per call' );
	}

	private static function assertIsValidPageChangeSchemaAndWiki( array $event ): void {
		Assert::assertEquals( WikiMap::getCurrentWikiId(), $event['wiki_id'] );
		Assert::assertEquals( PageChangeEventSerializer::PAGE_CHANGE_SCHEMA_URI, $event['$schema'] );
	}

	private static function assertIsValidPageChangePage( array $event, Title $pageTitle, int $ns ): void {
		Assert::assertArrayHasKey( 'page', $event );
		Assert::assertEquals( $pageTitle, $event['page']['page_title'] );
		Assert::assertSame( $ns, $event['page']['namespace_id'] );
	}

	private static function assertIsValidPageChangePageIdentity(
		ProperPageIdentity $page,
		array $event
	): void {
		Assert::assertEquals( $page->getId(), $event['page']['page_id'] );
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
		$events = self::sortMoveActionsByKind( $events );

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
		Assert::assertSame(
			$sourceEvent['page']['page_title'],
			$destinationEvent['prior_state']['page']['page_title']
		);
		Assert::assertSame(
			$sourceEvent['page']['namespace_id'],
			$destinationEvent['prior_state']['page']['namespace_id']
		);

		// The moved page carries information about the parent revision's prior state.
		// Some fields. like edit_count of an editor subkey, might diverge. Here we explicitly
		// assert on
		// the fields explicitly set by the `PageChangeEventSerializer::toMoveEvent` method.

		Assert::assertSame(
			$sourceEvent['revision']['rev_id'],
			$destinationEvent['prior_state']['revision']['rev_id']
		);

		Assert::assertSame(
			$sourceEvent['revision']['rev_dt'],
			$destinationEvent['prior_state']['revision']['rev_dt']
		);

		Assert::assertSame(
			$sourceEvent['revision']['is_minor_edit'],
			$destinationEvent['prior_state']['revision']['is_minor_edit']
		);

		Assert::assertSame(
			$sourceEvent['revision']['rev_sha1'],
			$destinationEvent['prior_state']['revision']['rev_sha1']
		);

		Assert::assertSame(
			$sourceEvent['revision']['rev_size'],
			$destinationEvent['prior_state']['revision']['rev_size']
		);

		if ( isset( $sourceEvent['revision']['comment'] ) ) {
			Assert::assertSame(
				$sourceEvent['revision']['comment'],
				$destinationEvent['prior_state']['revision']['comment']
			);
		}
		Assert::assertSame(
			$sourceEvent['revision']['editor']['is_temp'],
			$destinationEvent['prior_state']['revision']['editor']['is_temp']
		);

		Assert::assertSame(
			$sourceEvent['revision']['editor']['user_id'],
			$destinationEvent['prior_state']['revision']['editor']['user_id']
		);

		Assert::assertSame(
			$sourceEvent['revision']['editor']['edit_count'],
			$destinationEvent['prior_state']['revision']['editor']['edit_count'] - 1
		);

		Assert::assertSame(
			$sourceEvent['revision']['editor']['registration_dt'],
			$destinationEvent['prior_state']['revision']['editor']['registration_dt']
		);

		if ( isset( $sourceEvent['revision']['content_slots'] ) ) {
			Assert::assertSame(
				$sourceEvent['revision']['content_slots'],
				$destinationEvent['prior_state']['revision']['content_slots']
			);
		}
	}
}
