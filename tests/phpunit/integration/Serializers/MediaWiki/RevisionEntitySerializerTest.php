<?php

use MediaWiki\Extension\EventBus\Serializers\EventSerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\RevisionEntitySerializer;
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

		$this->revisionEntitySerializer = new RevisionEntitySerializer();

		$this->setUpHasRun = true;
	}

	/**
	 * @covers ::toArray
	 */
	public function testToArray() {
		$wikiPage = $this->getExistingTestPage(
			Title::makeTitle( $this->getDefaultWikitextNS(), self::MOCK_PAGE_TITLE )
		);

		$revisionRecord = $wikiPage->getRevisionRecord();

		$expected = [
			'rev_id' => $revisionRecord->getId(),
			'rev_parent_id' => $revisionRecord->getParentId(),
			'rev_dt' => EventSerializer::timestampToDt( $revisionRecord->getTimestamp() ),
			'is_minor_edit' => $revisionRecord->isMinor(),
			'rev_sha1' => $revisionRecord->getSha1(),
			'rev_size' => $revisionRecord->getSize(),
			'comment' => $revisionRecord->getComment()->text,
			'is_content_visible' => true,
			'is_editor_visible' => true,
			'is_comment_visible' => true,
		];

		$actual = $this->revisionEntitySerializer->toArray( $revisionRecord );
		$this->assertEquals( $expected, $actual );
	}

}
