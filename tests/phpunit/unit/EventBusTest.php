<?php

use MediaWiki\Extension\EventBus\EventBus;
use MediaWiki\Extension\EventBus\EventFactory;

/**
 * @covers \MediaWiki\Extension\EventBus\EventBus
 * @group EventBus
 */
class EventBusTest extends MediaWikiUnitTestCase {

	public function testReplaceBinaryValues() {
		$stringVal = "hi there";
		$this->assertEquals( 'hi there', EventBus::replaceBinaryValues( $stringVal ) );

		$binaryVal = "\xFF\xFF\xFF\xFF";
		$expected = "data:application/octet-stream;base64," . base64_encode( $binaryVal );
		$this->assertEquals( $expected, EventBus::replaceBinaryValues( $binaryVal ) );
	}

	public function testReplaceBinaryValuesRecursive() {
		$binaryVal = "\xFF\xFF\xFF\xFF";

		$events = [
			[ "k1" => "v1", "o1" => [ "ok1" => $binaryVal ] ],
			[ "k1" => "v2", "o1" => [ "ok1" => "ov1" ] ]
		];

		$expected = [
			[ "k1" => "v1", "o1" => [
				"ok1" => "data:application/octet-stream;base64," . base64_encode( $binaryVal ) ]
			],
			[ "k1" => "v2", "o1" => [ "ok1" => "ov1" ] ]
		];

		EventBus::replaceBinaryValuesRecursive( $events );
		$this->assertEquals( $expected, $events );
	}

	public function provideAllowedTypes() {
		yield 'Nothing provided, defaults to TYPE_ALL' => [ '', EventBus::TYPE_ALL ];
		yield 'TYPE_ALL allows everything' => [ 'TYPE_ALL', EventBus::TYPE_ALL ];
		yield 'TYPE_NONE allows nothing' => [ 'TYPE_NONE', EventBus::TYPE_NONE ];
		yield 'TYPE_JOB allows only jobs' => [ 'TYPE_JOB', EventBus::TYPE_JOB ];
		yield 'Union types' => [ 'TYPE_EVENT|TYPE_PURGE', EventBus::TYPE_EVENT | EventBus::TYPE_PURGE ];
		yield 'Integer support' =>
			[ EventBus::TYPE_EVENT | EventBus::TYPE_PURGE, EventBus::TYPE_EVENT | EventBus::TYPE_PURGE ];
	}

	/**
	 * @dataProvider provideAllowedTypes
	 * @param string|int $enableEventBus
	 * @param int $expectedProducedTypes
	 */
	public function testAllowedTypes(
		$enableEventBus,
		int $expectedProducedTypes
	) {
		foreach ( [ EventBus::TYPE_EVENT, EventBus::TYPE_JOB, EventBus::TYPE_PURGE ] as $type ) {
			if ( $expectedProducedTypes & $type ) {
				$httpClient = $this->createNoOpMock( MultiHttpClient::class, [ 'run' ] );
				$httpClient
					->expects( $this->once() )
					->method( 'run' )
					->willReturn( [
						'code' => 201
					] );
			} else {
				$httpClient = $this->createNoOpMock( MultiHttpClient::class, [ 'run' ] );
				$httpClient->expects( $this->never() )
					->method( 'run' );
			}
			$eventBus = new EventBus(
				$httpClient,
				$enableEventBus,
				$this->createNoOpMock( EventFactory::class ),
				'test.org'
			);
			$eventBus->send( 'BODY', $type );
		}
	}
}
