<?php
namespace MediaWiki\Extension\EventBus\Tests\Unit;

use MediaWiki\Block\DatabaseBlock;
use MediaWiki\CommentFormatter\CommentFormatter;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Deferred\LinksUpdate\LinksTable;
use MediaWiki\Deferred\LinksUpdate\LinksUpdate;
use MediaWiki\Extension\EventBus\EventBus;
use MediaWiki\Extension\EventBus\EventBusFactory;
use MediaWiki\Extension\EventBus\EventBusHooks;
use MediaWiki\Extension\EventBus\EventFactory;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Page\PageReference;
use MediaWiki\Page\PageReferenceValue;
use MediaWiki\Page\WikiPage;
use MediaWiki\Permissions\SimpleAuthority;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\EditResult;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\Title\TitleValue;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentityValue;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\EventBus\EventBusHooks
 */
class EventBusHooksTest extends MediaWikiUnitTestCase {
	private EventBusFactory $eventBusFactory;
	private RevisionLookup $revisionLookup;
	private CommentFormatter $commentFormatter;
	private TitleFactory $titleFactory;

	private EventBus $eventBus;
	private EventFactory $eventFactory;

	private EventBusHooks $hooks;

	protected function setUp(): void {
		parent::setUp();

		$this->eventBusFactory = $this->createMock( EventBusFactory::class );
		$this->revisionLookup = $this->createMock( RevisionLookup::class );
		$this->commentFormatter = $this->createMock( CommentFormatter::class );
		$this->titleFactory = $this->createMock( TitleFactory::class );

		$this->eventBus = $this->createMock( EventBus::class );
		$this->eventFactory = $this->createMock( EventFactory::class );

		$this->eventBus->method( 'getFactory' )
			->willReturn( $this->eventFactory );

		$this->hooks = new EventBusHooks(
			$this->eventBusFactory,
			$this->revisionLookup,
			$this->commentFormatter,
			$this->titleFactory
		);
	}

	/**
	 * @dataProvider provideLogEntryTypes
	 */
	public function testShouldSendPageDeleteEventOnDeleteComplete( string $logEntryType ): void {
		$pageID = 1;
		$pageIdentity = new PageIdentityValue(
			$pageID,
			NS_MAIN,
			'TestPage',
			WikiAwareEntity::LOCAL
		);
		$performer = new SimpleAuthority(
			new UserIdentityValue( 1, 'TestUser' ),
			[]
		);
		$reason = 'Test reason';
		$deletedRev = $this->createMock( RevisionRecord::class );
		$archivedRevisionCount = 5;

		$logEntry = $this->createMock( ManualLogEntry::class );
		$logEntry->method( 'getType' )
			->willReturn( $logEntryType );

		$expectedStreamName = $logEntryType === 'suppress' ? 'mediawiki.page-suppress' : 'mediawiki.page-delete';
		$this->eventBusFactory->method( 'getInstanceForStream' )
			->with( $expectedStreamName )
			->willReturn( $this->eventBus );

		$this->eventFactory->expects( $this->once() )
			->method( 'setCommentFormatter' )
			->with( $this->commentFormatter );

		$title = $this->createMock( Title::class );
		$title->method( 'isRedirect' )
			->willReturn( false );

		$this->titleFactory->method( 'newFromPageIdentity' )
			->with( $pageIdentity )
			->willReturn( $title );

		$expectedPerformer = $logEntryType === 'suppress' ? null : $performer->getUser();

		$mockEvent = [ 'test event' ];
		$this->eventFactory->expects( $this->once() )
			->method( 'createPageDeleteEvent' )
			->with(
				$expectedStreamName,
				$expectedPerformer,
				$pageID,
				$title,
				$title->isRedirect(),
				$archivedRevisionCount,
				$deletedRev,
				$reason
			)
			->willReturn( $mockEvent );

		$this->eventBus->expects( $this->once() )
			->method( 'send' )
			->with( [ $mockEvent ] );

		$this->hooks->onPageDeleteComplete(
			$pageIdentity,
			$performer,
			$reason,
			$pageID,
			$deletedRev,
			$logEntry,
			$archivedRevisionCount
		);

		DeferredUpdates::doUpdates();
	}

	public static function provideLogEntryTypes(): iterable {
		yield 'regular delete' => [ 'delete' ];
		yield 'oversight' => [ 'suppress' ];
	}

	public function testPageUndelete(): void {
		$pageIdentity = new PageIdentityValue(
			2,
			NS_MAIN,
			'TestPage',
			WikiAwareEntity::LOCAL
		);
		$performer = new SimpleAuthority(
			new UserIdentityValue( 1, 'TestUser' ),
			[]
		);
		$reason = 'Test reason';
		$restoredRev = $this->createMock( RevisionRecord::class );
		$restoredRevisionCount = 5;

		$this->eventBusFactory->method( 'getInstanceForStream' )
			->with( 'mediawiki.page-undelete' )
			->willReturn( $this->eventBus );

		$this->eventFactory->expects( $this->once() )
			->method( 'setCommentFormatter' )
			->with( $this->commentFormatter );

		$title = $this->createMock( Title::class );

		$this->titleFactory->method( 'newFromPageIdentity' )
			->with( $pageIdentity )
			->willReturn( $title );

		$mockEvent = [ 'test event' ];
		$this->eventFactory->expects( $this->once() )
			->method( 'createPageUndeleteEvent' )
			->with(
				'mediawiki.page-undelete',
				$performer->getUser(),
				$title,
				$reason,
				$pageIdentity->getId(),
				$restoredRev,
			)
			->willReturn( $mockEvent );

		$this->eventBus->expects( $this->once() )
			->method( 'send' )
			->with( [ $mockEvent ] );

		$this->hooks->onPageUndeleteComplete(
			$pageIdentity,
			$performer,
			$reason,
			$restoredRev,
			$this->createMock( ManualLogEntry::class ),
			$restoredRevisionCount,
			true,
			[]
		);

		DeferredUpdates::doUpdates();
	}

	public function testPageMoveComplete(): void {
		$oldTitle = new TitleValue( NS_MAIN, 'OldPage' );
		$newTitle = new TitleValue( NS_MAIN, 'NewPage' );
		$performer = new UserIdentityValue( 1, 'TestUser' );
		$reason = 'Test reason';
		$newRev = $this->createMock( RevisionRecord::class );

		$this->eventBusFactory->method( 'getInstanceForStream' )
			->with( 'mediawiki.page-move' )
			->willReturn( $this->eventBus );

		$this->eventFactory->expects( $this->once() )
			->method( 'setCommentFormatter' )
			->with( $this->commentFormatter );

		$mockEvent = [ 'test event' ];
		$this->eventFactory->expects( $this->once() )
			->method( 'createPageMoveEvent' )
			->with(
				'mediawiki.page-move',
				$oldTitle,
				$newTitle,
				$newRev,
				$performer,
				$reason
			)
			->willReturn( $mockEvent );

		$this->eventBus->expects( $this->once() )
			->method( 'send' )
			->with( [ $mockEvent ] );

		$this->hooks->onPageMoveComplete(
			$oldTitle,
			$newTitle,
			$performer,
			0,
			0,
			$reason,
			$newRev
		);

		DeferredUpdates::doUpdates();
	}

	public function testArticlePurge(): void {
		$title = $this->createMock( Title::class );
		$wikiPage = $this->createMock( WikiPage::class );
		$wikiPage->method( 'getTitle' )
			->willReturn( $title );

		$this->eventBusFactory->method( 'getInstanceForStream' )
			->with( 'resource_change' )
			->willReturn( $this->eventBus );

		$mockEvent = [ 'test event' ];
		$this->eventFactory->expects( $this->once() )
			->method( 'createResourceChangeEvent' )
			->with(
				'resource_change',
				$title,
				[ 'purge' ]
			)
			->willReturn( $mockEvent );

		$this->eventBus->expects( $this->once() )
			->method( 'send' )
			->with( [ $mockEvent ] );

		$this->hooks->onArticlePurge( $wikiPage );

		DeferredUpdates::doUpdates();
	}

	public function testShouldDoNothingOnNonNullEdit(): void {
		$editResult = $this->createMock( EditResult::class );
		$editResult->method( 'isNullEdit' )
			->willReturn( false );

		$this->eventBusFactory->expects( $this->never() )
			->method( 'getInstanceForStream' );

		$this->eventFactory->expects( $this->never() )
			->method( 'createResourceChangeEvent' );

		$this->eventBus->expects( $this->never() )
			->method( 'send' );

		$this->hooks->onPageSaveComplete(
			$this->createMock( WikiPage::class ),
			new UserIdentityValue( 1, 'TestUser' ),
			'',
			EDIT_UPDATE,
			$this->createMock( RevisionRecord::class ),
			$editResult
		);

		DeferredUpdates::doUpdates();
	}

	public function testShouldSendResourceChangeEventOnNullEdit(): void {
		$title = $this->createMock( Title::class );
		$wikiPage = $this->createMock( WikiPage::class );
		$wikiPage->method( 'getTitle' )
			->willReturn( $title );

		$editResult = $this->createMock( EditResult::class );
		$editResult->method( 'isNullEdit' )
			->willReturn( true );

		$this->eventBusFactory->method( 'getInstanceForStream' )
			->with( 'resource_change' )
			->willReturn( $this->eventBus );

		$mockEvent = [ 'test event' ];
		$this->eventFactory->expects( $this->once() )
			->method( 'createResourceChangeEvent' )
			->with(
				'resource_change',
				$title,
				[ 'null_edit' ]
			)
			->willReturn( $mockEvent );

		$this->eventBus->expects( $this->once() )
			->method( 'send' )
			->with( [ $mockEvent ] );

		$this->hooks->onPageSaveComplete(
			$wikiPage,
			new UserIdentityValue( 1, 'TestUser' ),
			'',
			EDIT_UPDATE,
			$this->createMock( RevisionRecord::class ),
			$editResult
		);

		DeferredUpdates::doUpdates();
	}

	public function testShouldSendRevisionCreateEventOnPageCreate(): void {
		$editResult = $this->createMock( EditResult::class );
		$editResult->method( 'isNullEdit' )
			->willReturn( false );

		$revisionRecord = $this->createMock( RevisionRecord::class );

		$this->eventBusFactory->method( 'getInstanceForStream' )
			->with( 'mediawiki.page-create' )
			->willReturn( $this->eventBus );

		$mockEvent = [ 'test event' ];
		$this->eventFactory->expects( $this->once() )
			->method( 'createRevisionCreateEvent' )
			->with(
				'mediawiki.page-create',
				$revisionRecord
			)
			->willReturn( $mockEvent );

		$this->eventBus->expects( $this->once() )
			->method( 'send' )
			->with( [ $mockEvent ] );

		$this->hooks->onPageSaveComplete(
			$this->createMock( WikiPage::class ),
			new UserIdentityValue( 1, 'TestUser' ),
			'',
			EDIT_NEW,
			$revisionRecord,
			$editResult
		);

		DeferredUpdates::doUpdates();
	}

	public function testShouldSendRevisionCreateEventOnRevisionRecordInserted(): void {
		$revisionRecord = $this->createMock( RevisionRecord::class );

		$this->eventBusFactory->method( 'getInstanceForStream' )
			->with( 'mediawiki.revision-create' )
			->willReturn( $this->eventBus );

		$mockEvent = [ 'test event' ];
		$this->eventFactory->expects( $this->once() )
			->method( 'createRevisionCreateEvent' )
			->with(
				'mediawiki.revision-create',
				$revisionRecord
			)
			->willReturn( $mockEvent );

		$this->eventBus->expects( $this->once() )
			->method( 'send' )
			->with( [ $mockEvent ] );

		$this->hooks->onRevisionRecordInserted( $revisionRecord );

		DeferredUpdates::doUpdates();
	}

	public function testShouldSendUserBlockChangeEventOnBlockInsertion(): void {
		$block = $this->createMock( DatabaseBlock::class );
		$performer = $this->createMock( User::class );

		$this->eventBusFactory->method( 'getInstanceForStream' )
			->with( 'mediawiki.user-blocks-change' )
			->willReturn( $this->eventBus );

		$mockEvent = [ 'test event' ];
		$this->eventFactory->expects( $this->once() )
			->method( 'createUserBlockChangeEvent' )
			->with(
				'mediawiki.user-blocks-change',
				$performer,
				$block,
				null
			)
			->willReturn( $mockEvent );

		$this->eventBus->expects( $this->once() )
			->method( 'send' )
			->with( [ $mockEvent ] );

		$this->hooks->onBlockIpComplete( $block, $performer, null );

		DeferredUpdates::doUpdates();
	}

	/**
	 * @dataProvider provideLinksUpdateData
	 *
	 * @param array $addedPropsMap
	 * @param array $removedPropsMap
	 * @param PageReference[] $addedLinks
	 * @param PageReference[] $removedLinks
	 * @param string[] $addedExternalLinks
	 * @param string[] $removedExternalLinks
	 * @return void
	 */
	public function testShouldSendPropertiesChangeEventsOnLinksUpdate(
		array $addedPropsMap,
		array $removedPropsMap,
		array $addedLinks,
		array $removedLinks,
		array $addedExternalLinks,
		array $removedExternalLinks
	): void {
		$title = $this->createMock( Title::class );
		$user = new UserIdentityValue( 1, 'TestUser' );

		$revRecord = $this->createMock( RevisionRecord::class );
		$revRecord->method( 'getId' )
			->willReturn( 1 );

		$linksUpdate = $this->createMock( LinksUpdate::class );

		$linksUpdate->method( 'getTitle' )
			->willReturn( $title );
		$linksUpdate->method( 'getTriggeringUser' )
			->willReturn( $user );
		$linksUpdate->method( 'getRevisionRecord' )
			->willReturn( $revRecord );
		$linksUpdate->method( 'getPageId' )
			->willReturn( 2 );

		$linksUpdate->method( 'getAddedProperties' )
			->willReturn( $addedPropsMap );
		$linksUpdate->method( 'getRemovedProperties' )
			->willReturn( $removedPropsMap );

		$linksUpdate->method( 'getPageReferenceArray' )
			->willReturnMap( [
				[ 'pagelinks', LinksTable::INSERTED, $addedLinks ],
				[ 'pagelinks', LinksTable::DELETED, $removedLinks ],
			] );

		$linksUpdate->method( 'getAddedExternalLinks' )
			->willReturn( $addedExternalLinks );
		$linksUpdate->method( 'getRemovedExternalLinks' )
			->willReturn( $removedExternalLinks );

		$this->eventBusFactory->method( 'getInstanceForStream' )
			->willReturnMap( [
				[ 'mediawiki.page-properties-change', $this->eventBus ],
				[ 'mediawiki.page-links-change', $this->eventBus ]
			] );

		$expectedEvents = [];
		$actualEvents = [];

		if ( $addedPropsMap || $removedPropsMap ) {
			$mockEvent = [ 'props change event' ];

			$this->eventFactory->expects( $this->once() )
				->method( 'createPagePropertiesChangeEvent' )
				->with(
					'mediawiki.page-properties-change',
					$title,
					$addedPropsMap,
					$removedPropsMap,
					$user,
					$revRecord->getId(),
					$linksUpdate->getPageId()
				)
				->willReturn( $mockEvent );

			$expectedEvents[] = [ $mockEvent ];
		}

		if ( $addedLinks || $removedLinks || $addedExternalLinks || $removedExternalLinks ) {
			$mockEvent = [ 'links change event' ];

			$this->eventFactory->expects( $this->once() )
				->method( 'createPageLinksChangeEvent' )
				->with(
					'mediawiki.page-links-change',
					$title,
					$addedLinks,
					$addedExternalLinks,
					$removedLinks,
					$removedExternalLinks,
					$user,
					$revRecord->getId(),
					$linksUpdate->getPageId()
				)
				->willReturn( $mockEvent );

			$expectedEvents[] = [ $mockEvent ];
		}

		$this->eventBus->expects( $this->exactly( count( $expectedEvents ) ) )
			->method( 'send' )
			->willReturnCallback( static function ( array $event ) use ( &$actualEvents ): void {
				$actualEvents[] = $event;
			} );

		$this->hooks->onLinksUpdateComplete( $linksUpdate, '' );

		DeferredUpdates::doUpdates();

		$this->assertSame( $expectedEvents, $actualEvents );
	}

	public static function provideLinksUpdateData(): iterable {
		yield 'no changes to page properties or links' => [
			[],
			[],
			[],
			[],
			[],
			[]
		];

		yield 'added and removed page properties' => [
			[ 'prop1' => 'value1' ],
			[ 'prop2' => 'value2' ],
			[],
			[],
			[],
			[]
		];

		yield 'changes to page properties, links and external links' => [
			[ 'prop1' => 'value1' ],
			[ 'prop2' => 'value2' ],
			[
				new PageReferenceValue( NS_MAIN, 'TestPage1', WikiAwareEntity::LOCAL ),
				new PageReferenceValue( NS_MAIN, 'TestPage2', WikiAwareEntity::LOCAL )
			],
			[
				new PageReferenceValue( NS_MAIN, 'TestPage3', WikiAwareEntity::LOCAL ),
				new PageReferenceValue( NS_MAIN, 'TestPage4', WikiAwareEntity::LOCAL )
			],
			[ 'http://example.com' ],
			[ 'http://example.org' ]
		];
	}

	public function testShouldSendPageRestrictionsChangeEventOnArticleProtectComplete(): void {
		$title = $this->createMock( Title::class );
		$revRecord = $this->createMock( RevisionRecord::class );

		$wikiPage = $this->createMock( WikiPage::class );
		$wikiPage->method( 'getTitle' )
			->willReturn( $title );
		$wikiPage->method( 'getId' )
			->willReturn( 1 );
		$wikiPage->method( 'getRevisionRecord' )
			->willReturn( $revRecord );
		$wikiPage->method( 'isRedirect' )
			->willReturn( false );

		$performer = $this->createMock( User::class );
		$reason = 'Test reason';
		$protections = [
			'edit' => 'sysop',
			'move' => 'sysop'
		];

		$this->eventBusFactory->method( 'getInstanceForStream' )
			->with( 'mediawiki.page-restrictions-change' )
			->willReturn( $this->eventBus );

		$mockEvent = [ 'test event' ];
		$this->eventFactory->expects( $this->once() )
			->method( 'createPageRestrictionsChangeEvent' )
			->with(
				'mediawiki.page-restrictions-change',
				$performer,
				$title,
				$wikiPage->getId(),
				$wikiPage->getRevisionRecord(),
				$wikiPage->isRedirect(),
				$reason,
				$protections
			)
			->willReturn( $mockEvent );

		$this->eventBus->expects( $this->once() )
			->method( 'send' )
			->with( [ $mockEvent ] );

		$this->hooks->onArticleProtectComplete(
			$wikiPage,
			$performer,
			$protections,
			$reason
		);

		DeferredUpdates::doUpdates();
	}

	public function testChangeTagsAfterUpdateTagsShouldNotSendEventForNonRevisionTags(): void {
		$prevTags = [ 'tag1', 'tag2' ];
		$addedTags = [ 'tag3' ];
		$removedTags = [ 'tag2' ];
		$performer = $this->createMock( User::class );

		$this->revisionLookup->expects( $this->never() )
			->method( 'getRevisionById' );

		$this->eventBusFactory->expects( $this->never() )
			->method( 'getInstanceForStream' );

		$this->eventFactory->expects( $this->never() )
			->method( 'createRevisionTagsChangeEvent' );

		$this->eventBus->expects( $this->never() )
			->method( 'send' );

		$this->hooks->onChangeTagsAfterUpdateTags(
			$addedTags,
			$removedTags,
			$prevTags,
			1,
			null,
			null,
			null,
			null,
			$performer
		);

		DeferredUpdates::doUpdates();
	}

	public function testChangeTagsAfterUpdateTagsShouldNotSendEventIfRevisionNotFound(): void {
		$prevTags = [ 'tag1', 'tag2' ];
		$addedTags = [ 'tag3' ];
		$removedTags = [ 'tag2' ];
		$revisionId = 2;
		$performer = $this->createMock( User::class );

		$this->revisionLookup->method( 'getRevisionById' )
			->with( $revisionId )
			->willReturn( null );

		$this->eventBusFactory->expects( $this->never() )
			->method( 'getInstanceForStream' );

		$this->eventFactory->expects( $this->never() )
			->method( 'createRevisionTagsChangeEvent' );

		$this->eventBus->expects( $this->never() )
			->method( 'send' );

		$this->hooks->onChangeTagsAfterUpdateTags(
			$addedTags,
			$removedTags,
			$prevTags,
			null,
			$revisionId,
			null,
			null,
			null,
			$performer
		);

		DeferredUpdates::doUpdates();
	}

	public function testChangeTagsAfterUpdateTagsShouldSendEventForRevisionTagChanges(): void {
		$prevTags = [ 'tag1', 'tag2' ];
		$addedTags = [ 'tag3' ];
		$removedTags = [ 'tag2' ];
		$revisionId = 2;

		$performer = $this->createMock( User::class );
		$revisionRecord = $this->createMock( RevisionRecord::class );

		$this->revisionLookup->method( 'getRevisionById' )
			->with( $revisionId )
			->willReturn( $revisionRecord );

		$this->eventBusFactory->method( 'getInstanceForStream' )
			->with( 'mediawiki.revision-tags-change' )
			->willReturn( $this->eventBus );

		$mockEvent = [ 'test event' ];
		$this->eventFactory->expects( $this->once() )
			->method( 'createRevisionTagsChangeEvent' )
			->with(
				'mediawiki.revision-tags-change',
				$revisionRecord,
				$prevTags,
				$addedTags,
				$removedTags,
				$performer
			)
			->willReturn( $mockEvent );

		$this->eventBus->expects( $this->once() )
			->method( 'send' )
			->with( [ $mockEvent ] );

		$this->hooks->onChangeTagsAfterUpdateTags(
			$addedTags,
			$removedTags,
			$prevTags,
			null,
			$revisionId,
			null,
			null,
			null,
			$performer
		);

		DeferredUpdates::doUpdates();
	}
}
