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
use MediaWiki\Page\Event\PageRevisionUpdatedEvent;
use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Page\PageLookup;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Page\RedirectLookup;
use MediaWiki\Page\WikiPage;
use MediaWiki\Page\WikiPageFactory;
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
use Psr\Log\LoggerInterface;
use TitleFormatter;
use Wikimedia\ObjectFactory\ObjectFactory;
use Wikimedia\Rdbms\IDBAccessObject;
use Wikimedia\UUID\GlobalIdGenerator;

/**
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

	/** @var WikiPageFactory */
	private $wikiPageFactory;

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

	/** @var PageChangeEventIngress */
	private $listener;
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
		$this->wikiPageFactory = $this->createMock( WikiPageFactory::class );
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
		$mockPageTitle = $this->createMockTitle( $this->pageDBkey );

		$mockWikiPage = $this->createMock( WikiPage::class );
		$mockWikiPage->method( 'getTitle' )->willReturn( $mockPageTitle );
		$mockWikiPage->method( 'getRedirectTarget' )->willReturn( $mockRedirectTitle );

		$this->redirectLookup->method( 'getRedirectTarget' )
			->willReturn( $mockRedirectTitle );

		$this->wikiPageFactory->method( 'newFromID' )
			->with( $this->pageId, IDBAccessObject::READ_LATEST )
			->willReturn( $mockWikiPage );

		$this->eventBus = $this->createMock( EventBus::class );

		$this->eventBusFactory->method( 'getInstanceForStream' )
			->with( '' )
			->willReturn( $this->eventBus );

		$this->listener = new PageChangeEventIngress(
			$this->eventBusFactory,
			$this->streamNameMapper,
			$this->mainConfig,
			$this->globalIdGenerator,
			$this->userGroupManager,
			$this->titleFormatter,
			$this->wikiPageFactory,
			$this->userFactory,
			$this->revisionStore,
			$this->contentHandlerFactory,
			$this->redirectLookup,
			$this->pageLookup
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
		bool $isSamePageAs = true
	): ExistingPageRecord {
		$pageId = $pageId ?? $this->pageId;
		$dbKey = $dbKey ?? $this->pageDBkey;

		$pageRecord = $this->createMock( ExistingPageRecord::class );
		$pageRecord->method( 'getId' )->willReturn( $pageId );
		$pageRecord->method( 'getNamespace' )->willReturn( $namespace );
		$pageRecord->method( 'getDBkey' )->willReturn( $dbKey );
		$pageRecord->method( 'isSamePageAs' )->willReturn( $isSamePageAs );

		return $pageRecord;
	}

	/**
	 * Creates a mock RevisionRecord with specified properties
	 *
	 * @param int $revId The revision ID
	 * @param string $timestamp The revision timestamp
	 * @param string $sha1 The SHA1 hash
	 * @param ProperPageIdentity $pageIdentity The page identity
	 * @param array $additionalMethods Additional methods to mock
	 * @return RevisionRecord The mocked object
	 */
	private function createMockRevision(
		int $revId,
		string $timestamp,
		string $sha1,
		ProperPageIdentity $pageIdentity,
		array $additionalMethods = []
	): RevisionRecord {
		$revision = $this->createMock( RevisionRecord::class );
		$revision->method( 'getId' )->willReturn( $revId );
		$revision->method( 'getTimestamp' )->willReturn( $timestamp );
		$revision->method( 'getSha1' )->willReturn( $sha1 );
		$revision->method( 'getPage' )->willReturn( $pageIdentity );

		foreach ( $additionalMethods as $method => $returnValue ) {
			$revision->method( $method )->willReturn( $returnValue );
		}

		return $revision;
	}

	/**
	 * Test that page PageRevisionUpdated events are properly handled
	 */
	public function testPageRevisionUpdatedWithEdit() {
		// Mock UserIdentity
		$performer = $this->createMock( UserIdentity::class );
		$performer->method( 'getName' )->willReturn( 'TestUser' );
		$performer->method( 'getId' )->willReturn( 123 );

		// Create page identities for revisions
		$pageIdentityBefore = $this->createMockPageIdentity();
		$pageIdentityAfter = $this->createMockPageIdentity();

		$pageRecordBefore = $this->createMockPageRecord();
		$pageRecordAfter = $this->createMockPageRecord();

		// Mock RevisionRecord objects
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

		// Mock the author user returned by the revision
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

		$this->listener->handlePageRevisionUpdatedEvent( $event );

		DeferredUpdates::doUpdates();
	}
}
