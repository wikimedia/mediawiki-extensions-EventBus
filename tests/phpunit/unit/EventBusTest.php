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
				$httpClient = $this->createNoOpMock( MultiHttpClient::class, [ 'runMulti' ] );
				$httpClient
					->expects( $this->once() )
					->method( 'runMulti' )
					->willReturn( [
						[
							'response' => [
								'code' => 201
							]
						]
					] );
			} else {
				$httpClient = $this->createNoOpMock( MultiHttpClient::class, [ 'runMulti' ] );
				$httpClient->expects( $this->never() )
					->method( 'runMulti' );
			}
			$eventBus = new EventBus(
				$httpClient,
				$enableEventBus,
				$this->createNoOpMock( EventFactory::class ),
				'test.org',
				1000000
			);
			$eventBus->send( 'BODY', $type );
		}
	}

	public function provideBody() {
		yield 'Single event, under maxBatchByteSize' => [
			json_encode(
				[
					"schema" => "/mediawiki/job/1.0.0",
					"meta" => [
						"uri" => "https://placeholder.invalid/wiki/Special:Badtitle",
						"request_id" => "fc3a8587259ca5fc085ca830"

					],
					"type" => "deletePage",
					"database" => "default",
					"params" => [
						"namespace" => 0,
						"title" => "test",
						"request_id" => "fc3a8587259ca5fc085ca830"
					]
				]
			)
			,
			[
				[
					'response' => [
						'code' => 201
					]
				]
			],
			true
		];

		yield "Multiple events that require partition" => [
			json_encode(
				[
					[
						"schema" => "/mediawiki/job/1.0.0",
						"meta" => [
							"uri" => "https://placeholder.invalid/wiki/Special:Badtitle",
							"request_id" => "fc3a8587259ca5fc085ca830"

						],
						"type" => "deletePage",
						"database" => "default",
						"params" => [
							"namespace" => 0,
							"title" => "test",
							"request_id" => "fc3a8587259ca5fc085ca830"
						]
					],
					[
						"schema" => "/mediawiki/job/2.0.0",
						"meta" => [
							"uri" => "https://placeholder.invalid/wiki/Special:Badtitle",
							"request_id" => "2fc3a8587259ca5fc085ca830"

						],
						"type" => "deletePage",
						"database" => "default",
						"params" => [
							"namespace" => 0,
							"title" => "test2",
							"request_id" => "2fc3a8587259ca5fc085ca830"
						]
					],
					[
						"schema" => "/mediawiki/job/3.0.0",
						"meta" => [
							"uri" => "https://placeholder.invalid/wiki/Special:Badtitle",
							"request_id" => "3fc3a8587259ca5fc085ca830"

						],
						"type" => "deletePage",
						"database" => "default",
						"params" => [
							"namespace" => 0,
							"title" => "test3",
							"request_id" => "3fc3a8587259ca5fc085ca830"
						]
					],
				]
			),
			[
				[
					'response' => [
						'code' => 201
					]
				],
				[
					'response' => [
						'code' => 201
					]
				],
				[
					'response' => [
						'code' => 201
					]
				]
			],
			true
		];

		yield 'Single event, 400 response' => [
			[
				[
					"schema" => "/mediawiki/job/111.0.0",
					"meta" => [
						"uri" => "https://placeholder.invalid/wiki/Special:Badtitle",
						"request_id" => "fc3a8587259ca5fc085ca830"

					],
					"type" => "deletePage",
					"database" => "default",
					"params" => [
						"namespace" => 0,
						"title" => "test",
						"request_id" => "fc3a8587259ca5fc085ca830"
					]
				]
			],
			[
				[
					'response' => [
						'code' => 400,
						'reason' => 'Incorrect schema'
					]
				]
			],
			[ "Unable to deliver all events: 400: Incorrect schema" ]
		];
	}

	/**
	 * @dataProvider provideBody
	 * @param string|array $body
	 * @param array $httpResponse
	 * @param boolean|string $expectedResponse
	 * @throws Exception
	 */
	public function testSend(
		$body,
		$httpResponse,
		$expectedResponse
	) {
		$httpClient = $this->createNoOpMock( MultiHttpClient::class, [ 'runMulti' ] );
		$httpClient
			->expects( $this->once() )
			->method( 'runMulti' )
			->willReturn( $httpResponse );

		$eventBus = new EventBus(
			$httpClient,
			EventBus::TYPE_ALL,
			$this->createNoOpMock( EventFactory::class ),
			'test.org',
			300
		);
		$this->assertSame( $expectedResponse, $eventBus->send( $body, EventBus::TYPE_ALL ) );
	}
}
