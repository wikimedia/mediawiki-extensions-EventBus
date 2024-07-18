<?php

use MediaWiki\Extension\EventBus\StreamNameMapper;

/**
 * @covers \MediaWiki\Extension\EventBus\EventBus
 * @group EventBus
 */
class StreamNameMapperTest extends MediaWikiUnitTestCase {

	public function testNameResolution() {
		$mapper = new StreamNameMapper( [ 'a' => 'b' ] );
		$this->assertEquals( 'b', $mapper->resolve( 'a' ),
			'Returns mapped value when stream is configured' );
		$this->assertEquals( 'c', $mapper->resolve( 'c' ),
			'Returns default stream name when stream is unconfigured' );
	}
}
