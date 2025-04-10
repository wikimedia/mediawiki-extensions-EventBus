<?php

namespace phpunit\integration;

use MediaWiki\CommentFormatter\CommentFormatter;
use MediaWiki\Extension\EventBus\EventBus;
use MediaWiki\Extension\EventBus\EventBusFactory;
use MediaWiki\Extension\EventBus\EventFactory;
use MediaWiki\Extension\EventBus\HookHandlers\MediaWiki\PageChangeHooks;
use MediaWiki\Extension\EventBus\MediaWikiEventSubscribers\PageChangeEventIngress;
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

	public function setUp(): void {
		parent::setUp();

		$commentFormatter = $this->createMock( CommentFormatter::class );
		$this->setService( 'CommentFormatter', $commentFormatter );

		$this->mockWikiMap();
	}

	private function mockEventBusFactory( callable $sendCallback,
										  int $expectedNumberOfEvents,
										  string $pageName,
										  string $streamName
	) {
		$invocationCounter = $this->exactly( $expectedNumberOfEvents );
		$capturedEvents = [];

		// The spyEventBus EventBus mock is used only to test code paths triggered by the Domain
		// Events API.
		// For example, the mocked instances will only be injected (and asserted on)
		// for mediawiki.page_change.v1 streams.
		// In this test, `send` is expected to be called twice: once after a page creation,
		// and once after the page is edited.
		$spyEventBus = $this->createNoOpMock( EventBus::class, [ 'send', 'getFactory' ] );
		$spyEventBus->expects( $this->exactly( $expectedNumberOfEvents ) )
			->method( 'send' )
			->willReturnCallback( function ( $events ) use ( $pageName,
				$invocationCounter,
				$expectedNumberOfEvents,
				&$capturedEvents,
				$streamName,
				$sendCallback ) {
					self::assertSingleEvent( $events );

					$event = $events[0];
					$capturedEvents[] = $event;

					self::assertEventBase( $event );
					self::assertEventPage( $event, $pageName, $this->getDefaultWikitextNS() );
					self::assertEventRevision( $event );
					self::assertEventMeta( $event, $pageName, $streamName );

				if ( $invocationCounter->getInvocationCount() === $expectedNumberOfEvents ) {
					$sendCallback( $events );
				}
			} );

		$eventFactory = $this->createMock( EventFactory::class );
		$eventFactory->method( 'setCommentFormatter' );

		$spyEventBus->method( 'getFactory' )
			->willReturn( $eventFactory );

		// Create a no-op EventBus instance for streams populated via Hooks API.
		$dummyEventBus = $this->createNoOpMock( EventBus::class, [ 'send', 'getFactory' ] );
		$dummyEventBus->method( 'getFactory' )
			->willReturn( $eventFactory );

		$eventBusFactory = $this->createNoOpMock( EventBusFactory::class, [ 'getInstanceForStream' ] );
		$eventBusFactory->method( 'getInstanceForStream' )
			->willReturnCallback( static function ( $stream ) use ( $spyEventBus,
				$dummyEventBus,
				$streamName ) {
				if ( $stream === $streamName ) {
					return $spyEventBus;
				}
				return $dummyEventBus;
			} );

		$this->setService( 'EventBus.EventBusFactory', $eventBusFactory );
	}

	/**
	 * Test that the event ingress object tracks page revision updates (creation / edit)
	 * and deletes.
	 */
	public function testPageCreateEditThenDelete() {
		$pageName = Title::newFromText(
			"TestPageCreateEditThenDelete",
			$this->getDefaultWikitextNS()
		);

		// Flush any pending events in the queue
		$this->runDeferredUpdates();

		$sendCallback = function ( $events ) {
			self::assertEditEventActions( $events );
		};

		$this->mockEventBusFactory( $sendCallback,
			2,
			$pageName,
			PageChangeEventIngress::PAGE_CHANGE_STREAM_NAME_DEFAULT );

		// Create a page
		$this->editPage(
			$pageName,
			'Some content'
		);

		// Edit the page
		$this->editPage(
			$pageName,
			'Some edits'
		);

		// Delete the page
		$this->deletePageAndAssertEvent( $pageName, PageChangeHooks::PAGE_CHANGE_STREAM_NAME_DEFAULT );

		$this->runDeferredUpdates();
	}

	private function deletePageAndAssertEvent( string $pageName, string $streamName ) {
		$sendCallback = function ( $events ) {
			self::assertDeleteActions( $events );
		};

		$this->mockEventBusFactory( $sendCallback, 1, $pageName, $streamName );

		$page = $this->getExistingTestPage( $pageName );
		$this->deletePage( $page );
	}

	private static function assertSingleEvent( $events ): void {
		Assert::assertNotNull( $events );
		Assert::assertCount( 1, $events, 'Should have exactly one event per call' );
	}

	private static function assertEventBase( array $event ): void {
		Assert::assertEquals( WikiMap::getCurrentWikiId(), $event['wiki_id'] );
		Assert::assertEquals( '/mediawiki/page/change/1.2.0', $event['$schema'] );
	}

	private static function assertEventPage( array $event, string $pageName, int $ns ): void {
		Assert::assertArrayHasKey( 'page', $event );
		Assert::assertEquals( $pageName, $event['page']['page_title'] );
		Assert::assertSame( $ns, $event['page']['namespace_id'] );
		Assert::assertFalse( $event['page']['is_redirect'] );
	}

	private static function assertEventRevision( array $event ): void {
		Assert::assertArrayHasKey( 'revision', $event );
		Assert::assertFalse( $event['revision']['is_minor_edit'] );
		Assert::assertArrayHasKey( 'content_slots', $event['revision'] );
		Assert::assertArrayHasKey( 'main', $event['revision']['content_slots'] );

		$mainSlot = $event['revision']['content_slots']['main'];
		Assert::assertEquals( 'main', $mainSlot['slot_role'] );
		Assert::assertEquals( 'wikitext', $mainSlot['content_model'] );
		Assert::assertEquals( 'text/x-wiki', $mainSlot['content_format'] );
	}

	private static function assertEventMeta( array $event,
											 string $pageName,
											 string $streamName ): void {
		Assert::assertArrayHasKey( 'meta', $event );
		Assert::assertEquals( $streamName, $event['meta']['stream'] );
		Assert::assertEquals(
			Title::newFromText( $pageName )->getFullURL(),
			$event['meta']['uri']
		);

		// `domain` is extracted from MockWkiMapTrait.
		$wikiReference = WikiMap::getWiki( WikiMap::getCurrentWikiId() );
		Assert::assertEquals( $wikiReference->getDisplayName(), $event['meta']['domain'] );
	}

	private static function assertEditEventActions( array $events ): void {
		usort( $events, static fn ( $a, $b ) => strcmp( $a['changelog_kind'], $b['changelog_kind'] ) );

		Assert::assertEquals( 'insert', $events[0]['changelog_kind'] );
		Assert::assertEquals( 'create', $events[0]['page_change_kind'] );

		Assert::assertEquals( 'update', $events[1]['changelog_kind'] );
		Assert::assertEquals( 'edit', $events[1]['page_change_kind'] );
	}

	private static function assertDeleteActions( array $events ): void {
		usort( $events, static fn ( $a, $b ) => strcmp( $a['changelog_kind'], $b['changelog_kind'] ) );

		Assert::assertEquals( 'delete', $events[0]['changelog_kind'] );
		Assert::assertEquals( 'delete', $events[0]['page_change_kind'] );
	}

}
