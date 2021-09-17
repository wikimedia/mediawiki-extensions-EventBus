<?php
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\EventBus\EventBusFactory;
use MediaWiki\Extension\EventBus\EventFactory;
use MediaWiki\Extension\EventStreamConfig\StreamConfigs;
use Psr\Log\NullLogger;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \MediaWiki\Extension\EventBus\EventBusFactory
 * @group EventBus
 */
class EventBusFactoryTest extends MediaWikiUnitTestCase {

	private const DEFAULT_MW_CONFIG = [
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
		'EventBusMaxBatchByteSize' => 1000000
	];

	private function getEventBusFactory( array $mwConfig ): EventBusFactory {
		$logger = new NullLogger();

		// EventBus behavior is different if EventStreamConfig is loaded.
		if (
			array_key_exists( 'EventStreams', $mwConfig ) &&
			ExtensionRegistry::getInstance()->isLoaded( 'EventStreamConfig' )
		) {
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
			new NullLogger()
		);
	}

	public function provideGetInstance() {
		yield 'non existent event service name' => [
			'nonexistent',
			self::DEFAULT_MW_CONFIG,
			// Expected: throw InvalidArgumentException
			null
		];

		yield 'existent service name, no url set' => [
			'testbus',
			array_merge_recursive(
				self::DEFAULT_MW_CONFIG, [ 'EventServices' => [ 'intake-no-url' => [] ] ]
			),
			// Expected: throw InvalidArgumentException
			null
		];

		yield 'existing specific event service name' => [
			'intake-other',
			self::DEFAULT_MW_CONFIG,
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

	public function provideGetInstanceForStream() {
		$mwConfigWithEventStreams = array_merge_recursive(
			self::DEFAULT_MW_CONFIG,
			[
				'EventStreams' => [
					'other_stream' => [
						'stream' => 'other_stream',
						'destination_event_service' => 'intake-other'
					],
					'stream_without_destination_event_service' => [
						'stream' => 'stream_without_destination_event_service'
					],
					'stream_with_undefined_event_service' => [
						'stream' => 'stream_with_undefined_event_service',
						'destination_event_service' => 'undefined_event_service'
					],
				]
			]
		);

		// Expected results are different if EventStreamConfig is loaded vs when not loaded.
		$defaultEventServiceUrl = self::DEFAULT_MW_CONFIG['EventServices'][
			self::DEFAULT_MW_CONFIG['EventServiceDefault']
		]['url'];
		if ( ExtensionRegistry::getInstance()->isLoaded( 'EventStreamConfig' ) ) {
			$expectedEventServiceUrls = [
				'default' => $defaultEventServiceUrl,
				'intake-main' => self::DEFAULT_MW_CONFIG['EventServices']['intake-main']['url'],
				'intake-other' => self::DEFAULT_MW_CONFIG['EventServices']['intake-other']['url'],
				'InvalidArgumentException' => null,
			];
		} else {
			// If no EventStreamConfig extension, EventBus will always use EventServiceDefault
			$expectedEventServiceUrls = [
				'default' => $defaultEventServiceUrl,
				'intake-main' => $defaultEventServiceUrl,
				'intake-other' => $defaultEventServiceUrl,
				'InvalidArgumentException' => $defaultEventServiceUrl,
			];
		}

		yield 'default event service if no EventStreams configuration' => [
			'my_stream',
			self::DEFAULT_MW_CONFIG,
			// expected:
			$expectedEventServiceUrls['default'],
			false
		];

		yield 'default event service if no destination_event_service for this configured stream' => [
			'stream_without_destination_event_service',
			$mwConfigWithEventStreams,
			// expected:
			$expectedEventServiceUrls['default'],
			false
		];

		yield 'default event service if no stream config for this stream' => [
			'my_stream',
			$mwConfigWithEventStreams,
			// expected:
			$expectedEventServiceUrls['default'],
			false
		];

		yield 'specific destination_event_service' => [
			'other_stream',
			$mwConfigWithEventStreams,
			// expected:
			$expectedEventServiceUrls['intake-other'],
			ExtensionRegistry::getInstance()->isLoaded( 'EventStreamConfig' )
		];

		yield 'undefined destination_event_service in EventServices' => [
			'stream_with_undefined_event_service',
			$mwConfigWithEventStreams,
			// expected: null -> throws InvalidArgumentException
			$expectedEventServiceUrls['InvalidArgumentException'],
			false
		];
	}

	/**
	 * @covers \MediaWiki\Extension\EventBus\EventBusFactory::getInstanceForStream
	 * @dataProvider provideGetInstanceForStream
	 * @param string $streamName
	 * @param array $mwConfig
	 * @param string|null $expectedUrl - expected EventBus instance url.
	 *   If null - expect InvalidArgumentException
	 * @param bool $forwardXClientIP
	 */
	public function testGetInstanceForStream(
		string $streamName,
		array $mwConfig,
		?string $expectedUrl,
		bool $forwardXClientIP
	) {
		if ( !$expectedUrl ) {
			$this->expectException( InvalidArgumentException::class );
		}
		$factory = $this->getEventBusFactory( $mwConfig );
		$instance = $factory->getInstanceForStream( $streamName );
		$instance = TestingAccessWrapper::newFromObject( $instance );
		if ( $expectedUrl ) {
			$this->assertSame( $expectedUrl, $instance->url );
		}
		$this->assertSame( $forwardXClientIP, $instance->forwardXClientIP );
	}
}
