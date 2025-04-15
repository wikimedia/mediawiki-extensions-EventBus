<?php

use MediaWiki\Extension\EventBus\Serializers\EventSerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\RevisionEntitySerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\RevisionSlotEntitySerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\UserEntitySerializer;
use MediaWiki\Tests\MockWikiMapTrait;
use MediaWiki\Title\Title;

/**
 * @coversDefaultClass \MediaWiki\Extension\EventBus\Serializers\MediaWiki\RevisionEntitySerializer
 * @group Database
 * @group EventBus
 */
class RevisionEntitySerializerTest extends MediaWikiIntegrationTestCase {
	use MockWikiMapTrait;

	private const MOCK_PAGE_TITLE = 'MyPage';

	/**
	 * @var UserEntitySerializer
	 */
	private UserEntitySerializer $userEntitySerializer;
	/**
	 * @var RevisionSlotEntitySerializer
	 */
	private RevisionSlotEntitySerializer $contentEntitySerializer;
	/**
	 * @var RevisionEntitySerializer
	 */
	private RevisionEntitySerializer $revisionEntitySerializer;

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
		$this->mockWikiMap();

		$this->userEntitySerializer = new UserEntitySerializer(
			$this->getServiceContainer()->getUserFactory(),
			$this->getServiceContainer()->getUserGroupManager()
		);

		$this->contentEntitySerializer = new RevisionSlotEntitySerializer(
			$this->getServiceContainer()->getContentHandlerFactory()
		);

		$this->revisionEntitySerializer = new RevisionEntitySerializer(
			$this->contentEntitySerializer,
			$this->userEntitySerializer
		);

		$this->setUpHasRun = true;
	}

	/**
	 * @covers ::__construct
	 */
	public function testConstruct() {
		$this->assertInstanceOf( RevisionEntitySerializer::class, $this->revisionEntitySerializer );
	}

	/**
	 * @covers ::toArray
	 */
	public function testToArray() {
		$wikiPage = $this->getExistingTestPage(
			Title::newFromText( self::MOCK_PAGE_TITLE, $this->getDefaultWikitextNS() )
		);

		$revisionRecord = $wikiPage->getRevisionRecord();

		$contentSlots = [];
		foreach ( $revisionRecord->getSlots()->getSlots() as $slotRole => $slotRecord ) {
			$contentSlots[$slotRole] = $this->contentEntitySerializer->toArray( $slotRecord );
		}

		$expected = [
			'rev_id' => $revisionRecord->getId(),
			'rev_dt' => EventSerializer::timestampToDt( $revisionRecord->getTimestamp() ),
			'is_minor_edit' => $revisionRecord->isMinor(),
			'rev_sha1' => $revisionRecord->getSha1(),
			'rev_size' => $revisionRecord->getSize(),
			'comment' => $revisionRecord->getComment()->text,
			'editor' => $this->userEntitySerializer->toArray( $revisionRecord->getUser() ),
			'is_content_visible' => true,
			'is_editor_visible' => true,
			'is_comment_visible' => true,
			'content_slots' => $contentSlots,
		];

		$actual = $this->revisionEntitySerializer->toArray( $revisionRecord );
		$this->assertEquals( $expected, $actual );
	}

}
