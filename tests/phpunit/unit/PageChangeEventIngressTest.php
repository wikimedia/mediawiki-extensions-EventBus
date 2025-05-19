<?php

namespace phpunit\unit;

use MediaWiki\Config\Config;
use MediaWiki\Config\SiteConfiguration;
use MediaWiki\Content\ContentHandlerFactory;
use MediaWiki\Content\IContentHandlerFactory;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\EventBus\EventBus;
use MediaWiki\Extension\EventBus\EventBusFactory;
use MediaWiki\Extension\EventBus\MediaWikiEventSubscribers\PageChangeEventIngress;
use MediaWiki\Extension\EventBus\StreamNameMapper;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\Page\Event\PageDeletedEvent;
use MediaWiki\Page\Event\PageMovedEvent;
use MediaWiki\Page\Event\PageRevisionUpdatedEvent;
use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Page\PageLookup;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Page\RedirectLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Storage\EditResult;
use MediaWiki\Storage\PageUpdateCauses;
use MediaWiki\Storage\RevisionSlotsUpdate;
use MediaWiki\Title\Title;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentity;
use MediaWikiUnitTestCase;
use PHPUnit\Framework\Assert;
use Psr\Log\LoggerInterface;
use TitleFormatter;
use Wikimedia\ObjectFactory\ObjectFactory;
use Wikimedia\Timestamp\TimestampException;
use Wikimedia\UUID\GlobalIdGenerator;

/**
 * TODO: we should consider deprecating this suite in favor of
 * phpunit\integration\ChangeEmissionTest. There is some value in testing
 * specific cases like performer suppression and move errors, but it's unclear
 * whether that justifies the considerable mocking boilerplate.
 *
 * @covers \MediaWiki\Extension\EventBus\MediaWikiEventSubscribers\PageChangeEventIngress
 */
class PageChangeEventIngressTest extends MediaWikiUnitTestCase {
	/** @var EventBusFactory */
	private $eventBusFactory;

	/** @var StreamNameMapper */
	private $streamNameMapper;

	/** @var Config */
	private $mainConfig;

	/** @var GlobalIdGenerator */
	private $globalIdGenerator;

	/** @var UserGroupManager */
	private $userGroupManager;

	/** @var TitleFormatter */
	private $titleFormatter;

	/** @var UserFactory */
	private $userFactory;

	/** @var RevisionStore */
	private $revisionStore;

	/** @var IContentHandlerFactory */
	private $contentHandlerFactory;

	/** @var RedirectLookup */
	private $redirectLookup;

	/** @var PageLookup */
	private $pageLookup;

	/**
	 * @var EventBus
	 */
	private $eventBus;

	private int $pageId = 456;

	private string $pageDBkey = 'TestPage';

	protected function setUp(): void {
		parent::setUp();

		// Mock $wgConf for the first path in WikiMap::getWiki
		$mockSiteConfiguration = $this->createMock( SiteConfiguration::class );
		$mockSiteConfiguration->method( 'loadFullData' )
			->willReturn( null );
		$mockSiteConfiguration->method( 'siteFromDB' )
			->willReturn( [ 'wiki', 'unittest' ] );
		$mockSiteConfiguration->method( 'get' )
			->willReturnCallback( static function ( $setting ) {
				switch ( $setting ) {
					case 'wgCanonicalServer':
					case 'wgServer':
						return 'http://localhost';
					case 'wgArticlePath':
						return '/wiki/$1';
					default:
						return null;
				}
			} );

		// Set up the global configuration to be used in static
		// calls to WikiMap.
		global $wgConf;
		$wgConf = $mockSiteConfiguration;

		$handlerSpecs = [
			'handlerOne' => [
				'class' => 'HandlerOneClass',
				'services' => [ 'service1', 'service2' ]
			],
			'handlerTwo' => [
				'class' => 'HandlerTwoClass',
				'services' => [ 'service3' ]
			]
		];

		$this->eventBusFactory = $this->createMock( EventBusFactory::class );
		$this->streamNameMapper = $this->createMock( StreamNameMapper::class );
		$this->mainConfig = $this->createMock( Config::class );
		$this->mainConfig->method( 'get' )
			->willReturnMap( [
				[ 'Server', 'http://localhost' ],
				[ 'CanonicalServer', 'http://localhost' ],
				[ 'DBname', 'my_wiki-unittest_' ],
				[ 'ArticlePath', '/wiki/$1' ],
			] );

		$this->globalIdGenerator = $this->createMock( GlobalIdGenerator::class );
		$this->userGroupManager = $this->createMock( UserGroupManager::class );
		$this->titleFormatter = $this->createMock( TitleFormatter::class );
		$this->userFactory = $this->createMock( UserFactory::class );
		$this->revisionStore = $this->createMock( RevisionStore::class );

		$objectFactory = $this->createMock( ObjectFactory::class );
		$hookContainer = $this->createMock( HookContainer::class );
		$logger = $this->createMock( LoggerInterface::class );

		$objectFactory->method( 'createObject' )
			->willReturnCallback( function ( $spec ) {
				return $this->createMock( $spec['class'] );
			} );

		$this->contentHandlerFactory = new ContentHandlerFactory( $handlerSpecs,
			$objectFactory,
			$hookContainer,
			$logger );

		$this->redirectLookup = $this->createMock( RedirectLookup::class );
		$this->pageLookup = $this->createMock( PageLookup::class );

		$this->titleFormatter->method( 'getPrefixedDBkey' )->willReturn( $this->pageId );

		$mockRedirectTitle = $this->createMockTitle( 'RedirectPage' );

		$mockPageRecord = $this->createMockPageRecord(
			$this->pageId,
			0,
			$this->pageDBkey
		);

		$this->pageLookup->method( 'getPageById' )
			->with( $this->pageId )
			->willReturn( $mockPageRecord );

		$this->redirectLookup->method( 'getRedirectTarget' )
			->willReturn( $mockRedirectTitle );

		$this->eventBus = $this->createMock( EventBus::class );

		$this->eventBusFactory->method( 'getInstanceForStream' )
			->with( '' )
			->willReturn( $this->eventBus );
	}

	/**
	 * Helper to instantiate PageChangeEventIngress with dependency overrides.
	 */
	protected function newListenerWithOverrides( array $overrides = [] ): PageChangeEventIngress {
		$defaults = [
			'eventBusFactory' => $this->eventBusFactory ?? $this->createMock( EventBusFactory::class ),
			'streamNameMapper' => $this->streamNameMapper ?? $this->createMock( StreamNameMapper::class ),
			'mainConfig' => $this->mainConfig ?? $this->createMock( Config::class ),
			'globalIdGenerator' => $this->globalIdGenerator ?? $this->createMock( GlobalIdGenerator::class ),
			'userGroupManager' => $this->userGroupManager ?? $this->createMock( UserGroupManager::class ),
			'titleFormatter' => $this->titleFormatter ?? $this->createMock( TitleFormatter::class ),
			'userFactory' => $this->userFactory ?? $this->createMock( UserFactory::class ),
			'revisionStore' => $this->revisionStore ?? $this->createMock( RevisionStore::class ),
			'contentHandlerFactory' => $this->contentHandlerFactory ?? $this->createMock(
				IContentHandlerFactory::class
				),
			'redirectLookup' => $this->redirectLookup ?? $this->createMock( RedirectLookup::class ),
			'pageLookup' => $this->pageLookup ?? $this->createMock( PageLookup::class )
		];
		$deps = array_merge( $defaults, $overrides );
		return new PageChangeEventIngress(
			$deps['eventBusFactory'],
			$deps['streamNameMapper'],
			$deps['mainConfig'],
			$deps['globalIdGenerator'],
			$deps['userGroupManager'],
			$deps['titleFormatter'],
			$deps['userFactory'],
			$deps['revisionStore'],
			$deps['contentHandlerFactory'],
			$deps['redirectLookup'],
			$deps['pageLookup']
		);
	}

	/**
	 * Create a mock Title object with specified properties
	 *
	 * @param string $dbKey The database key to return
	 * @return Title The mocked Title object
	 */
	private function createMockTitle( $dbKey ): Title {
		$mockTitle = $this->createMock( Title::class );
		$mockTitle->method( 'getDBkey' )->willReturn( $dbKey );
		$mockTitle->method( 'getNamespace' )->willReturn( 0 );
		$mockTitle->method( 'getInterwiki' )->willReturn( '' );

		return $mockTitle;
	}

	/**
	 * Creates a mock PageIdentity with consistent properties
	 *
	 * @param int $pageId The page ID
	 * @param int $namespace The namespace ID
	 * @param string $dbKey The DB key
	 * @return ProperPageIdentity The mocked object
	 */
	private function createMockPageIdentity(
		int $pageId = 456,
		int $namespace = 0,
		string $dbKey = 'TestPage'
	): ProperPageIdentity {
		$pageIdentity = $this->createMock( ProperPageIdentity::class );
		$pageIdentity->method( 'getId' )->willReturn( $pageId );
		$pageIdentity->method( 'getNamespace' )->willReturn( $namespace );
		$pageIdentity->method( 'getDBkey' )->willReturn( $dbKey );
		return $pageIdentity;
	}

	/**
	 * Creates a mock PageRecord with consistent properties
	 *
	 * @param int|null $pageId The page ID
	 * @param int $namespace The namespace ID
	 * @param string|null $dbKey The DB key
	 * @param bool $isSamePageAs The return value for isSamePageAs method
	 * @return ExistingPageRecord The mocked object
	 */
	private function createMockPageRecord(
		?int $pageId = null,
		int $namespace = 0,
		?string $dbKey = null,
		bool $isSamePageAs = true,
		array $additionalMethods = []
	): ExistingPageRecord {
		$pageId = $pageId ?? $this->pageId;
		$dbKey = $dbKey ?? $this->pageDBkey;

		$pageRecord = $this->createMock( ExistingPageRecord::class );
		$pageRecord->method( 'getId' )->willReturn( $pageId );
		$pageRecord->method( 'getNamespace' )->willReturn( $namespace );
		$pageRecord->method( 'getDBkey' )->willReturn( $dbKey );
		$pageRecord->method( 'isSamePageAs' )->willReturn( $isSamePageAs );
		$pageRecord->method( '__toString' )->willReturn( $dbKey );

		foreach ( $additionalMethods as $method => $returnValue ) {
			$pageRecord->method( $method )->willReturn( $returnValue );
		}

		return $pageRecord;
	}

	/**
	 * Creates a mock RevisionRecord with specified properties
	 *
	 * @param int $revId The revision ID
	 * @param string $timestamp The revision timestamp
	 * @param string $sha1 The SHA1 hash
	 * @param ProperPageIdentity|null $pageIdentity The page identity
	 * @param array $additionalMethods Additional methods to mock
	 * @return RevisionRecord The mocked object
	 */
	private function createMockRevision(
		int $revId,
		string $timestamp,
		string $sha1,
		?ProperPageIdentity $pageIdentity = null,
		array $additionalMethods = []
	): RevisionRecord {
		$revision = $this->createMock( RevisionRecord::class );
		$revision->method( 'getId' )->willReturn( $revId );
		$revision->method( 'getTimestamp' )->willReturn( $timestamp );
		$revision->method( 'getSha1' )->willReturn( $sha1 );
		if ( $pageIdentity ) {
			$revision->method( 'getPage' )->willReturn( $pageIdentity );
		}

		foreach ( $additionalMethods as $method => $returnValue ) {
			$revision->method( $method )->willReturn( $returnValue );
		}

		return $revision;
	}

	/**
	 * Test that page PageRevisionUpdated events are properly handled
	 */
	public function testPageRevisionUpdatedWithEdit() {
		$performer = $this->createMock( UserIdentity::class );
		$performer->method( 'getName' )->willReturn( 'TestUser' );
		$performer->method( 'getId' )->willReturn( 123 );

		$pageIdentityBefore = $this->createMockPageIdentity();
		$pageIdentityAfter = $this->createMockPageIdentity();

		$pageRecordBefore = $this->createMockPageRecord();
		$pageRecordAfter = $this->createMockPageRecord();

		$latestRevisionBefore = $this->createMockRevision(
			7890,
			'20230101000000',
			'abc123',
			$pageIdentityBefore
		);

		$latestRevisionAfter = $this->createMockRevision(
			7891,
			'20230101010000',
			'def456',
			$pageIdentityAfter,
			[
				'getParentId' => 7890,
				'getVisibility' => 1
			]
		);

		$authorUser = $this->createMock( UserIdentity::class );
		$authorUser->method( 'getName' )->willReturn( 'RevisionAuthor' );
		$authorUser->method( 'getId' )->willReturn( 124 );

		$slotsUpdate = $this->createMock( RevisionSlotsUpdate::class );
		$slotsUpdate->method( 'isModifiedSlot' )
			->willReturnCallback( static function ( $slotRole ) {
				return $slotRole === 'main';
			} );

		$editResult = $this->createMock( EditResult::class );
		$editResult->method( 'isRevert' )->willReturn( false );

		$tags = [ 'tag1', 'tag2' ];
		$flags = [];
		$patrolStatus = 1;

		$event = new PageRevisionUpdatedEvent(
			PageUpdateCauses::CAUSE_EDIT,
			$pageRecordBefore,
			$pageRecordAfter,
			$latestRevisionBefore,
			$latestRevisionAfter,
			$slotsUpdate,
			$editResult,
			$performer,
			$tags,
			$flags,
			$patrolStatus
		);

		// `send` triggers only if the PageRevisionUpdated event
		// has successfully been serialized and is ready to be sent
		// to EventGate.
		$this->eventBus->expects( $this->once() )
			->method( 'send' );

		$listener = $this->newListenerWithOverrides();
		$listener->handlePageRevisionUpdatedEvent( $event );

		DeferredUpdates::doUpdates();
	}

	/**
	 * Test that page deletion events are properly handled
	 * @throws TimestampException
	 *
	 * @throws TimestampException
	 */
	public function testHandlePageDeletedEvent() {
		$performer = $this->createMock( UserIdentity::class );
		$performer->method( 'getName' )->willReturn( 'TestUser' );
		$performer->method( 'getId' )->willReturn( 123 );

		$pageIdentity = $this->createMockPageIdentity();

		$deletedRevision = $this->createMockRevision(
			7890,
			'20250430115550',
			'abc123',
			$pageIdentity,
			[
				'getVisibility' => 0
			]
		);

		$event = $this->createMock( PageDeletedEvent::class );
		$event->method( 'getPageId' )->willReturn( $this->pageId );
		$event->method( 'isSuppressed' )->willReturn( false );
		$event->method( 'getPerformer' )->willReturn( $performer );
		$event->method( 'getReason' )->willReturn( 'Test deletion reason' );
		$event->method( 'getArchivedRevisionCount' )->willReturn( 1 );
		$event->method( 'getLatestRevisionBefore' )->willReturn( $deletedRevision );

		$this->eventBus->expects( $this->once() )
			->method( 'send' )
			->willReturnCallback( static function ( $events ) {
				Assert::assertNotNull( $events );
				Assert::assertCount( 1, $events, 'Should have exactly one event' );

				$event = $events[0];

				Assert::assertArrayHasKey( 'performer',
					$event,
					'Suppressed deletion should include performer information' );
			} );

		$listener = $this->newListenerWithOverrides();
		$listener->handlePageDeletedEvent( $event );

		DeferredUpdates::doUpdates();
	}

	/**
	 * Test that suppressed page deletion events are properly handled
	 * @throws TimestampException
	 */
	public function testHandlePageDeletedEventWithSuppression() {
		$performer = $this->createMock( UserIdentity::class );
		$pageIdentity = $this->createMockPageIdentity();

		$deletedRevision = $this->createMockRevision(
			7890,
			'20250430115550',
			'abc123',
			$pageIdentity,
			[
				'getVisibility' => RevisionRecord::DELETED_USER
			]
		);

		$event = $this->createMock( PageDeletedEvent::class );
		$event->method( 'getPageId' )->willReturn( $this->pageId );
		$event->method( 'isSuppressed' )->willReturn( true );
		$event->method( 'getPerformer' )->willReturn( $performer );
		$event->method( 'getReason' )->willReturn( 'Test deletion reason' );
		$event->method( 'getArchivedRevisionCount' )->willReturn( 1 );
		$event->method( 'getLatestRevisionBefore' )->willReturn( $deletedRevision );

		$this->eventBus->expects( $this->once() )
			->method( 'send' )
			->willReturnCallback( static function ( $events ) {
				Assert::assertNotNull( $events );
				Assert::assertCount( 1, $events, 'Should have exactly one event' );

				$event = $events[0];

				Assert::assertArrayNotHasKey( 'performer',
					$event,
					'Suppressed deletion should not include performer information' );
			} );

		$listener = $this->newListenerWithOverrides();
		$listener->handlePageDeletedEvent( $event );

		DeferredUpdates::doUpdates();
	}

	/**
	 * Test that page move events are properly handled
	 */
	public function testHandlePageMovedEvent() {
		$performer = $this->createMock( UserIdentity::class );
		$performer->method( 'getName' )->willReturn( 'MoveUser' );
		$performer->method( 'getId' )->willReturn( 555 );

		$pageRecordBefore = $this->createMockPageRecord( 456, 0, 'OldTitle', true, [ 'getLatest' => 7889 ] );
		$pageRecordAfter = $this->createMockPageRecord( 456,
			0,
			'NewTitle',
			true, [
				'exists' => true,
				'getLatest' => 7890
			] );
		$redirectPage = null;

		$event = new PageMovedEvent(
			$pageRecordBefore,
			$pageRecordAfter,
			$performer,
			'Move reason here',
			$redirectPage
		);

		$this->eventBus->expects( $this->once() )
			->method( 'send' )
			->willReturnCallback( static function ( $events ) {
				$event = $events[0];
				Assert::assertCount( 1, $events, 'Should have one event' );
				Assert::assertSame( "update", $event['changelog_kind'] );
				Assert::assertSame( "move", $event['page_change_kind'] );
			} );

		// Provide a custom revisionStore mock that returns a parent revision for the move event
		$revisionStore = $this->createMock( RevisionStore::class );
		$revisionStore->method( 'getRevisionById' )->willReturnCallback( function ( $revId ) {
			if ( $revId === 7890 ) {
				return $this->createMockRevision( 7890, '20230101000000', 'abc123', null, [
					'getParentId' => 7889,
					'getVisibility' => 0
				] );
			} elseif ( $revId === 7889 ) {
				return $this->createMockRevision( 7889, '20220101000000', 'parent', null, [
					'getVisibility' => 0
				] );
			}
			return null;
		} );

		$listener = $this->newListenerWithOverrides( [ 'revisionStore' => $revisionStore ] );
		$listener->handlePageMovedEvent( $event );
		DeferredUpdates::doUpdates();
	}

	/**
	 * Test that handlePageMovedEvent throws if the WikiPage is not found
	 */
	public function testHandlePageMovedEventThrowsIfWikiPageNotFound() {
		$performer = $this->createMock( UserIdentity::class );

		$pageRecordBefore = $this->createMockPageRecord( 456, 0, 'OldTitle' );
		$pageRecordAfter = $this->createMockPageRecord( 457,
			0,
			'NewTitle',
			true, [ 'exists' => false ] );

		$event = $this->createMock( PageMovedEvent::class );
		$event->method( 'getPageRecordBefore' )->willReturn( $pageRecordBefore );
		$event->method( 'getPageRecordAfter' )->willReturn( $pageRecordAfter );
		$event->method( 'getPageId' )->willReturn( 123 );
		$event->method( 'getPerformer' )->willReturn( $performer );
		$event->method( 'getReason' )->willReturn( 'No page found' );
		$event->method( 'wasRedirectCreated' )->willReturn( false );

		$listener = $this->newListenerWithOverrides();

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( "No page moved from 'OldTitle' to 'NewTitle' with ID 123 could be found" );
		$listener->handlePageMovedEvent( $event );
	}

}
