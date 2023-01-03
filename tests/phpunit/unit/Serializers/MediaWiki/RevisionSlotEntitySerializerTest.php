<?php

use MediaWiki\Content\IContentHandlerFactory;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\RevisionSlotEntitySerializer;
use MediaWiki\Revision\SlotRecord;

/**
 * @coversDefaultClass \MediaWiki\Extension\EventBus\Serializers\MediaWiki\RevisionSlotEntitySerializer
 * @group EventBus
 */
class RevisionSlotEntitySerializerTest extends MediaWikiUnitTestCase {

	/**
	 * Keys into $toArrayProviders.  This allows us to provide
	 * a static dataProvider for testToArray, but with dynamically
	 * created args from UserFactory Service.
	 *
	 * You must manually make sure these each have corresponding entries in $toArrayProviders
	 */
	private const TO_ARRAY_TEST_NAMES = [
		'Base Case',
	];

	/**
	 * Dynamically allocated data provider args for testToArray.
	 * These are of the form 'test name' => [input, expected].
	 * @var array
	 */
	private array $toArrayProviders;

	/**
	 * @var RevisionSlotEntitySerializer
	 */
	private RevisionSlotEntitySerializer $revisionSlotEntitySerializer;

	/**
	 * We need to use setUp to have access to MediaWikiUnitTestCase methods,
	 * but we only need to initialize things once.
	 * @var bool
	 */
	private bool $setUpHasRun = false;

	/**
	 * Creates a subclass that overrides AbstractContent::getContentHandler() and returns a
	 * ContentHandler without the need to go through MediaWikiServices.
	 *
	 * @param string $text
	 * @return TextContent
	 */
	protected function getTextContent( $text ) {
		return new class( $text ) extends TextContent {
			public function getContentHandler() {
				return new TextContentHandler();
			}
		};
	}

	public function setUp(): void {
		if ( $this->setUpHasRun ) {
			return;
		}
		$content = $this->getTextContent( 'Foo' );

		// Mock a IContentHandlerFactory that will just return $content's ContentHandler
		$contentHandlerFactory = $this->createMock( IContentHandlerFactory::class );
		$contentHandlerFactory->method( 'getContentHandler' )->willReturn( $content->getContentHandler() );

		$this->revisionSlotEntitySerializer = new RevisionSlotEntitySerializer(
			$contentHandlerFactory
		);

		$baseCaseSlotRecord = SlotRecord::newUnsaved( SlotRecord::MAIN, $content );
		$baseCaseExpected = [
			'slot_role' => $baseCaseSlotRecord->getRole(),
			'content_model' => 'text',
			'content_format' => 'text/plain',
			'content_size' => 3,
			'content_sha1' => '3r00jk1xoatd95ko7lnjrdtvi79jort',
		];

		$this->toArrayProviders = [];
		$this->toArrayProviders['Base Case'] = [
			// SlotRecord
			$baseCaseSlotRecord,
			// expected
			$baseCaseExpected
		];

		$this->setUpHasRun = true;
	}

	/**
	 * @covers ::__construct
	 */
	public function testConstruct() {
		$this->assertInstanceOf( RevisionSlotEntitySerializer::class, $this->revisionSlotEntitySerializer );
	}

	public function provideToArray() {
		foreach ( self::TO_ARRAY_TEST_NAMES as $testName ) {
			yield $testName => [ $testName ];
		}
	}

	/**
	 * @dataProvider provideToArray
	 * @covers ::toArray
	 */
	public function testToArray( $testName ) {
		$revisionRecord = $this->toArrayProviders[$testName][0];
		$expected = $this->toArrayProviders[$testName][1];

		$actual = $this->revisionSlotEntitySerializer->toArray( $revisionRecord );
		$this->assertEquals( $expected, $actual );
	}
}
