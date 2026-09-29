<?php

namespace MediaWiki\Extension\EventBus\Tests\Unit\LinkedArtifacts;

use MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactResponse;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactResponse
 */
class LinkedArtifactResponseTest extends MediaWikiUnitTestCase {

	public function testGetters(): void {
		$response = new LinkedArtifactResponse( 200, [ 'content-type' => 'application/json' ], '{"ok":true}' );

		$this->assertSame( 200, $response->getStatusCode() );
		$this->assertSame( 'application/json', $response->getContentType() );
		$this->assertSame( '{"ok":true}', $response->getBody() );
		$this->assertSame( [ 'ok' => true ], $response->getJsonBody() );
	}

	public function testGetHeaderIsCaseInsensitive(): void {
		$response = new LinkedArtifactResponse( 200, [ 'X-Hoarde-Revision-ID' => '99' ] );

		$this->assertSame( '99', $response->getHeader( 'x-hoarde-revision-id' ) );
		$this->assertSame( '99', $response->getHeader( 'X-Hoarde-Revision-ID' ) );
		$this->assertNull( $response->getHeader( 'etag' ) );
	}

	/**
	 * @dataProvider provideLastModifiedHeaders
	 */
	public function testGetLastModified( array $headers, ?string $expected ): void {
		$lastModified = ( new LinkedArtifactResponse( 200, $headers ) )->getLastModified();

		$this->assertSame( $expected, $lastModified?->getTimestamp( TS_ISO_8601 ) );
	}

	public static function provideLastModifiedHeaders(): array {
		return [
			'HTTP date' => [ [ 'last-modified' => 'Tue, 06 Oct 2026 12:00:05 GMT' ], '2026-10-06T12:00:05Z' ],
			// HTTP dates must use "GMT" (RFC 9110 §5.6.7). LAC used "UTC" before T440395.
			'UTC zone' => [ [ 'last-modified' => 'Tue, 06 Oct 2026 12:00:05 UTC' ], null ],
			'absent' => [ [], null ],
			'not a date' => [ [ 'last-modified' => 'yesterday-ish' ], null ],
		];
	}

	/**
	 * @dataProvider provideJsonBodies
	 */
	public function testDecodeJsonBody( string $body, ?array $expected ): void {
		$this->assertSame( $expected, LinkedArtifactResponse::decodeJsonBody( $body ) );
	}

	public static function provideJsonBodies(): array {
		return [
			'object' => [ '{"a":{"b":1}}', [ 'a' => [ 'b' => 1 ] ] ],
			'empty object' => [ '{}', [] ],
			'list' => [ '[1,2]', [ 1, 2 ] ],
			'empty body' => [ '', null ],
			'invalid JSON' => [ '{"a":', null ],
			'not JSON' => [ 'upstream error', null ],
			'JSON scalar' => [ '"a"', null ],
			'JSON null' => [ 'null', null ],
		];
	}

	/**
	 * @dataProvider provideStatusCodes
	 */
	public function testIsSuccess( int $statusCode, bool $expected ): void {
		$this->assertSame(
			$expected,
			( new LinkedArtifactResponse( $statusCode ) )->isSuccess()
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
			( new LinkedArtifactResponse( 504, [ 'content-type' => 'application/problem+json' ] ) )->isNotCached()
		);
		$this->assertFalse( ( new LinkedArtifactResponse( 504, [ 'content-type' => 'text/plain' ] ) )->isNotCached() );
		$this->assertFalse( ( new LinkedArtifactResponse( 504 ) )->isNotCached() );
		$this->assertFalse(
			( new LinkedArtifactResponse( 404, [ 'content-type' => 'application/problem+json' ] ) )->isNotCached()
		);
	}

	public function testIsNotFoundRequiresBothA404AndAProblemBody(): void {
		// LAC reports a missing artifact as a 404 with an RFC7807 problem object. A bare
		// 404 is something else (a bad route, a proxy) and may be worth retrying.
		$this->assertTrue(
			( new LinkedArtifactResponse( 404, [ 'content-type' => 'application/problem+json' ] ) )->isNotFound()
		);
		$this->assertTrue(
			( new LinkedArtifactResponse(
				404, [ 'content-type' => 'application/problem+json; charset=utf-8' ]
			) )->isNotFound()
		);
		$this->assertFalse( ( new LinkedArtifactResponse( 404, [ 'content-type' => 'text/html' ] ) )->isNotFound() );
		$this->assertFalse( ( new LinkedArtifactResponse( 404 ) )->isNotFound() );
		$this->assertFalse(
			( new LinkedArtifactResponse( 500, [ 'content-type' => 'application/problem+json' ] ) )->isNotFound()
		);
	}
}
