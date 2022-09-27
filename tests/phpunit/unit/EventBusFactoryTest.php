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
				'destination_event_service' => 'intake-other'
			],
			'stream_without_destination_event_service' => [
				'stream' => 'stream_without_destination_event_service'
			],
			'stream_with_undefined_event_service' => [
				'stream' => 'stream_with_undefined_event_service',
				'destination_event_service' => 'undefined_event_service'
			],
		],
	];

	private function getEventBusFactory(
		array $mwConfig,
		$useStreamConfigs = false
	): EventBusFactory {
		$logger = new NullLogger();

		// EventBus behavior is different if EventStreamConfig is loaded.
		if ( $useStreamConfigs ) {
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

	public function provideGetInstance() {
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

	public function provideGetInstanceForStream() {
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

		yield 'default event service if no stream config (w/ EventStreamConfig)' => [
			'my_stream',
			true,
			// expected:
			'http://intake.main',
			false,
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

			return;
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
