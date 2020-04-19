<?php

use MediaWiki\Extension\EventBus\EventBus;

/**
 * @covers \MediaWiki\Extension\EventBus\EventBus
 * @group EventBus
 */
class EventBusTest extends MediaWikiTestCase {
	use MediaWikiCoversValidator;

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
}
