<?php

namespace MediaWiki\Extension\EventBus\Tests\Unit\LinkedArtifacts;

use MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactResponse;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactResponse
 */
class LinkedArtifactResponseTest extends MediaWikiUnitTestCase {

	public function testGetters(): void {
		$response = new LinkedArtifactResponse( 200, 'application/json', '{"ok":true}' );

		$this->assertSame( 200, $response->getStatusCode() );
		$this->assertSame( 'application/json', $response->getContentType() );
		$this->assertSame( '{"ok":true}', $response->getBody() );
	}

	/**
	 * @dataProvider provideStatusCodes
	 */
	public function testIsSuccess( int $statusCode, bool $expected ): void {
		$this->assertSame(
			$expected,
			( new LinkedArtifactResponse( $statusCode, null ) )->isSuccess()
		);
	}

	public static function provideStatusCodes(): array {
		return [
			'200' => [ 200, true ],
			'204' => [ 204, true ],
			'299' => [ 299, true ],
			'304' => [ 304, false ],
			'404' => [ 404, false ],
			'500' => [ 500, false ],
		];
	}

	public function testIsNotCachedRequiresBothA504AndAProblemBody(): void {
		// LAC reports an only-if-cached miss as a 504 with an RFC7807 problem object.
		// A bare 504 is something else, e.g. a proxy timeout.
		$this->assertTrue(
			( new LinkedArtifactResponse( 504, 'application/problem+json' ) )->isNotCached()
		);
		$this->assertFalse( ( new LinkedArtifactResponse( 504, 'text/plain' ) )->isNotCached() );
		$this->assertFalse( ( new LinkedArtifactResponse( 504, null ) )->isNotCached() );
		$this->assertFalse(
			( new LinkedArtifactResponse( 404, 'application/problem+json' ) )->isNotCached()
		);
	}

	public function testIsNotFoundRequiresBothA404AndAProblemBody(): void {
		// LAC reports a missing artifact as a 404 with an RFC7807 problem object. A bare
		// 404 is something else (a bad route, a proxy) and may be worth retrying.
		$this->assertTrue(
			( new LinkedArtifactResponse( 404, 'application/problem+json' ) )->isNotFound()
		);
		$this->assertTrue(
			( new LinkedArtifactResponse( 404, 'application/problem+json; charset=utf-8' ) )->isNotFound()
		);
		$this->assertFalse( ( new LinkedArtifactResponse( 404, 'text/html' ) )->isNotFound() );
		$this->assertFalse( ( new LinkedArtifactResponse( 404, null ) )->isNotFound() );
		$this->assertFalse(
			( new LinkedArtifactResponse( 500, 'application/problem+json' ) )->isNotFound()
		);
	}
}
