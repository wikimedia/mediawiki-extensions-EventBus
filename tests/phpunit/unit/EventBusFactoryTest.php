<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\EventBus\EventBusFactory;
use MediaWiki\Extension\EventBus\EventFactory;
use Psr\Log\NullLogger;
use Wikimedia\Http\MultiHttpClient;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \MediaWiki\Extension\EventBus\EventBusFactory
 * @group EventBus
 */
class EventBusFactoryTest extends MediaWikiUnitTestCase {

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

	private function getEventBusFactory( array $mwConfig ): EventBusFactory {
		$logger = new NullLogger();

		return new EventBusFactory(
			new ServiceOptions(
				EventBusFactory::CONSTRUCTOR_OPTIONS,
				$mwConfig
			),
			null,
			$this->createNoOpMock( EventFactory::class ),
			$this->createNoOpMock( MultiHttpClient::class ),
			$logger
		);
	}

	public static function provideGetInstance() {
		yield 'non existent event service name' => [
			'nonexistent',
			self::MW_CONFIG,
			// Expected: throw InvalidArgumentException
			null
		];

		yield 'existent service name, no url set' => [
			'testbus',
			array_merge_recursive(
				self::MW_CONFIG, [ 'EventServices' => [ 'intake-no-url' => [] ] ]
			),
			// Expected: throw InvalidArgumentException
			null
		];

		yield 'existing specific event service name' => [
			'intake-other',
			self::MW_CONFIG,
			'http://intake.other'
		];
	}

	/**
	 * @covers \MediaWiki\Extension\EventBus\EventBusFactory::getInstance
	 * @dataProvider provideGetInstance
	 * @param string $serviceName
	 * @param array $mwConfig
	 * @param string|null $expectedUrl - expected EventBus instance url.
	 *   If null - expect InvalidArgumentException
	 */
	public function testGetInstance(
		string $serviceName,
		array $mwConfig,
		?string $expectedUrl
	) {
		if ( !$expectedUrl ) {
			$this->expectException( InvalidArgumentException::class );
		}
		$factory = $this->getEventBusFactory( $mwConfig );
		$instance = $factory->getInstance( $serviceName );
		$instance = TestingAccessWrapper::newFromObject( $instance );
		if ( $expectedUrl ) {
			$this->assertSame( $expectedUrl, $instance->url );
		}
	}

	public function testGetInstanceForStreamNoEventStreams() {
		$mwConfigWithoutEventStreams = self::MW_CONFIG;
		unset( $mwConfigWithoutEventStreams['EventStreams'] );

		$factory = $this->getEventBusFactory( $mwConfigWithoutEventStreams );
		$instance = $factory->getInstanceForStream( 'my_stream' );
		$instance = TestingAccessWrapper::newFromObject( $instance );

		$this->assertSame( 'http://intake.main', $instance->url );
		$this->assertSame( false, $instance->forwardXClientIP );
	}
}
