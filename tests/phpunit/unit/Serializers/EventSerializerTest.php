<?php

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\EventBus\Serializers\EventSerializer;
use MediaWiki\MainConfigNames;
use Wikimedia\UUID\GlobalIdGenerator;

/**
 * @coversDefaultClass \MediaWiki\Extension\EventBus\Serializers\EventSerializer
 */
class EventSerializerTest extends MediaWikiUnitTestCase {
	private const MOCK_SERVER_NAME = 'my_wiki';
	private const MOCK_UUID = 'b14a2ee4-f5df-40f3-b995-ce6c954e29e3';
	private const MOCK_SCHEMA_URI = 'my/schema/uri/1.0.0';
	private const MOCK_STREAM_NAME = 'my_stream';
	private const MOCK_URI = 'http://woohoo';
	private const MOCK_INGESTION_TIMESTAMP = '20221021000000';
	private const MOCK_EVENT_ATTRS = [ 'fieldA' => 'fieldB' ];

	/**
	 * @var EventSerializer
	 */
	private EventSerializer $eventSerializer;

	/**
	 * We need to use setUp to have access to MediaWikiUnitTestCase methods,
	 * but we only need to initialize things once.
	 * @var bool
	 */
	private bool $setUpHasRun = false;

	public function setUp(): void {
		if ( $this->setUpHasRun ) {
			return;
		}

		$globalIdGenerator = $this->createMock( GlobalIdGenerator::class );
		$globalIdGenerator->method( 'newUUIDv4' )->willReturn( self::MOCK_UUID );

		$telemetry = $this->createMock( \MediaWiki\Http\Telemetry::class );
		$telemetry->method( 'getRequestId' )->willReturn( 'requestid' );

		$this->eventSerializer = new EventSerializer(
			new HashConfig( [ MainConfigNames::ServerName => self::MOCK_SERVER_NAME ] ),
			$globalIdGenerator,
			$telemetry
		);

		$this->setUpHasRun = true;
	}

	/**
	 * @covers ::__construct
	 */
	public function testConstruct() {
		$this->assertInstanceOf( EventSerializer::class, $this->eventSerializer );
	}

	public static function provideTimestampToDt() {
		yield 'Provided mediawiki timestamp' => [ '20221019140732', '2022-10-19T14:07:32Z' ];
		yield 'Null for current timestamp' => [ null, '/\d\d\d\d-\d\d-\d\dT\d\d:\d\d:\d\dZ/' ];
	}

	/**
	 * @covers ::timestampToDt
	 * @dataProvider provideTimestampToDt
	 */
	public function testTimestampToDt( $mwTimestamp, $expected ) {
		$actual = $this->eventSerializer->timestampToDt( $mwTimestamp );

		if ( $mwTimestamp !== null ) {
			$this->assertEquals( $expected, $actual );
		} else {
			$this->assertMatchesRegularExpression( $expected, $actual );
		}
	}

	public static function provideCreateEvent() {
		yield 'null metaDt' => [
			[
				'$schema' => self::MOCK_SCHEMA_URI,
				'meta' => [
					'stream' => self::MOCK_STREAM_NAME,
					'uri' => self::MOCK_URI,
					'id' => self::MOCK_UUID,
					'domain' => self::MOCK_SERVER_NAME
				]
			] + self::MOCK_EVENT_ATTRS,
			[
				self::MOCK_SCHEMA_URI,
				self::MOCK_STREAM_NAME,
				self::MOCK_URI,
				self::MOCK_EVENT_ATTRS
			]
		];

		yield 'provided metaDt' => [
			[
				'$schema' => self::MOCK_SCHEMA_URI,
				'meta' => [
					'stream' => self::MOCK_STREAM_NAME,
					'uri' => self::MOCK_URI,
					'id' => self::MOCK_UUID,
					'domain' => self::MOCK_SERVER_NAME,
					'dt' => EventSerializer::timestampToDt( self::MOCK_INGESTION_TIMESTAMP ),
				]
			] + self::MOCK_EVENT_ATTRS,
			[
				self::MOCK_SCHEMA_URI,
				self::MOCK_STREAM_NAME,
				self::MOCK_URI,
				self::MOCK_EVENT_ATTRS,
				null,
				self::MOCK_INGESTION_TIMESTAMP
			]
		];

		// NOTE: testing a non-null wikiId parameter is hard
		// because WikiMap:getWiki is used, which uses global params.
	}

	/**
	 * @dataProvider provideCreateEvent
	 * @covers ::createMeta
	 */
	public function testCreateEvent( $expected, $args ) {
		$actual = call_user_func_array( [ $this->eventSerializer, 'createEvent' ], $args );

		// remove meta.request_id from actual, it is not deterministic.
		unset( $actual['meta']['request_id'] );

		// meta.dt is only deterministic if $args[4] ($metaDt) is provided and not null
		if ( !isset( $args[5] ) ) {
			unset( $actual['meta']['dt'] );
		}

		$this->assertEquals( $expected, $actual );
	}

}
