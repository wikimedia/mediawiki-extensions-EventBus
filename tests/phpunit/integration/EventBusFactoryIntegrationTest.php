<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Debug\MWDebug;
use MediaWiki\Extension\EventBus\EventBusFactory;
use MediaWiki\Extension\EventBus\EventFactory;
use MediaWiki\Extension\EventStreamConfig\StreamConfigs;
use MediaWiki\Registration\ExtensionRegistry;
use Psr\Log\NullLogger;
use Wikimedia\Http\MultiHttpClient;
use Wikimedia\TestingAccessWrapper;

class EventBusFactoryIntegrationTest extends MediaWikiIntegrationTestCase {
	private const MW_CONFIG = [
		'EnableEventBus' => 'TYPE_ALL',
		'EventServiceDefault' => 'intake-main',
		'EventServices' => [
			'intake-main' => [ 'url' => 'http://intake.main' ],
			'intake-other' => [
				'url' => 'http://intake.other',
				'x_client_ip_forwarding_enabled' => true,
			],
		],
		'EventStreamsDefaultSettings' => [],
		'EventBusMaxBatchByteSize' => 1000000,
		'EventStreams' => [
			'other_stream' => [
				'stream' => 'other_stream',
				'producers' => [
					EventBusFactory::EVENT_STREAM_CONFIG_PRODUCER_NAME => [
						EventBusFactory::EVENT_STREAM_CONFIG_SERVICE_SETTING => 'intake-other',
					]
				],
			],
			'stream_without_destination_event_service' => [
				'stream' => 'stream_without_destination_event_service'
			],
			'stream_with_undefined_event_service' => [
				'stream' => 'stream_with_undefined_event_service',
				'producers' => [
					EventBusFactory::EVENT_STREAM_CONFIG_PRODUCER_NAME => [
						EventBusFactory::EVENT_STREAM_CONFIG_SERVICE_SETTING => 'undefined_event_service',
					]
				], ],
			'disabled_stream' => [
				'stream' => 'disabled_stream',
				'destination_event_service' => 'intake-other',
				'producers' => [
					EventBusFactory::EVENT_STREAM_CONFIG_PRODUCER_NAME => [
						EventBusFactory::EVENT_STREAM_CONFIG_ENABLED_SETTING => false
					]
				],
			],
			// This can be removed after https://phabricator.wikimedia.org/T321557
			'stream_with_destination_event_service_backwards_compatible_setting' => [
				'stream' => 'stream_with_destination_event_service_backwards_compatible_setting',
				'destination_event_service' => 'intake-main'
			],
		],
	];

	public static function provideGetInstanceForStream() {
		yield 'default event service if no destination_event_service for stream' => [
			'stream_without_destination_event_service',
			false,
			// expected:
			'http://intake.main',
			false
		];

		yield 'default event service if no stream config' => [
			'my_stream',
			false,
			// expected:
			'http://intake.main',
			false,
		];

		yield 'specific destination_event_service' => [
			'other_stream',
			false,
			// expected:
			'http://intake.main',
			false,
		];

		yield 'undefined destination_event_service' => [
			'stream_with_undefined_event_service',
			false,
			// expected:
			'http://intake.main',
			false
		];

		yield 'default event service if no destination_event_service for stream (w/ EventStreamConfig)' => [
			'stream_without_destination_event_service',
			true,
			// expected:
			'http://intake.main',
			false
		];

		yield 'specific destination_event_service (w/ EventStreamConfig)' => [
			'other_stream',
			true,
			// expected:
			'http://intake.other',
			true,
		];

		yield 'undefined destination_event_service (w/ EventStreamConfig)' => [
			'stream_with_undefined_event_service',
			true,
			// expected:
			// null -> expected InvalidArgumentException
			null,
			null,
		];

		yield 'explicitly disabled stream (w/ EventStreamConfig)' => [
			'disabled_stream',
			true,
			// expected:
			// dummy url is set to same as disabld instance name.
			EventBusFactory::EVENT_SERVICE_DISABLED_NAME,
			false
		];

		yield 'undeclared stream (w/ EventStreamConfig)' => [
			'undeclared_stream',
			true,
			// expected:
			// dummy url is set to same as disabld instance name.
			EventBusFactory::EVENT_SERVICE_DISABLED_NAME,
			false
		];

		// This can be removed after https://phabricator.wikimedia.org/T321557
		yield 'backwards compatible destination_event_service setting' => [
			'stream_with_destination_event_service_backwards_compatible_setting',
			true,
			// expected:
			'http://intake.main',
			false,
		];
	}

	/**
	 * @covers \MediaWiki\Extension\EventBus\EventBusFactory::getInstanceForStream
	 * @dataProvider provideGetInstanceForStream
	 * @param string $streamName
	 * @param bool $useStreamConfigs
	 * @param string|null $expectedUrl - expected EventBus instance url.
	 *   If null - expect InvalidArgumentException
	 * @param bool|null $forwardXClientIP
	 */
	public function testGetInstanceForStream(
		string $streamName,
		bool $useStreamConfigs,
		?string $expectedUrl,
		?bool $forwardXClientIP
	) {
		if (
			$useStreamConfigs &&
			!ExtensionRegistry::getInstance()->isLoaded( 'EventStreamConfig' )
		) {
			$this->markTestSkipped( 'EventStreamConfig is not loaded.' );
		}

		if ( !$expectedUrl ) {
			$this->expectException( InvalidArgumentException::class );
		}
		$factory = $this->getEventBusFactory( self::MW_CONFIG, $useStreamConfigs );
		$instance = $factory->getInstanceForStream( $streamName );
		$instance = TestingAccessWrapper::newFromObject( $instance );

		$this->assertSame( $expectedUrl, $instance->url );
		$this->assertSame( $forwardXClientIP, $instance->forwardXClientIP );
	}

	private function getEventBusFactory(
		array $mwConfig,
		$useStreamConfigs = false
	): EventBusFactory {
		$logger = new NullLogger();

		// EventBus behavior is different if EventStreamConfig is loaded.
		if ( $useStreamConfigs ) {
			MWDebug::filterDeprecationForTest( '/calling with ServiceOptions is deprecated/' );

			$streamConfigsOptions = new ServiceOptions(
				StreamConfigs::CONSTRUCTOR_OPTIONS,
				$mwConfig
			);
			$streamConfigs = new StreamConfigs( $streamConfigsOptions, $logger );
		} else {
			$streamConfigs = null;
		}

		return new EventBusFactory(
			new ServiceOptions(
				EventBusFactory::CONSTRUCTOR_OPTIONS,
				$mwConfig
			),
			$streamConfigs,
			$this->createNoOpMock( EventFactory::class ),
			$this->createNoOpMock( MultiHttpClient::class ),
			$logger
		);
	}
}
