<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\EventBus\EventBusFactory;
use MediaWiki\Extension\EventBus\EventFactory;
use Psr\Log\NullLogger;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \MediaWiki\Extension\EventBus\EventBusFactory
 * @group EventBus
 */
class EventBusFactoryTest extends MediaWikiUnitTestCase {
	use MediaWikiCoversValidator;

	private function getEventBusFactory(
		array $serviceConfig,
		array $streamConfig
	) : EventBusFactory {
		return new EventBusFactory(
			new ServiceOptions(
				EventBusFactory::CONSTRUCTOR_OPTIONS,
				new HashConfig( [
						'EventServices' => $serviceConfig,
						'EventServiceStreamConfig' => $streamConfig,
						'EnableEventBus' => 'TYPE_ALL'
					]
				)
			),
			$this->createNoOpMock( EventFactory::class ),
			$this->createNoOpMock( MultiHttpClient::class ),
			new NullLogger()
		);
	}

	public function provideGetInstance() {
		yield 'non existent service name' => [
			'nonexistent',
			[ 'testbus' => [ 'url' => 'http://testbus.test' ] ],
			null
		];
		yield 'existent service name, no url' => [
			'testbus',
			[ 'testbus' => [] ],
			null
		];
		yield 'existing service name' => [
			'testbus',
			[ 'testbus' => [ 'url' => 'http://testbus.test' ] ],
			'http://testbus.test'
		];
		yield 'multiple service names configured' => [
			'other_testbus',
			[
				'testbus' => [ 'url' => 'http://testbus.test' ],
				'other_testbus' => [ 'url' => 'http://othertestbus.test' ]
			],
			'http://othertestbus.test'
		];
	}

	/**
	 * @covers \MediaWiki\Extension\EventBus\EventBusFactory::getInstance
	 * @dataProvider provideGetInstance
	 * @param string $serviceName
	 * @param array $serviceConfig
	 * @param string|null $expectedUrl - expected EventBus instance url.
	 *   If null - expect ConfigException
	 */
	public function testGetInstance(
		string $serviceName,
		array $serviceConfig,
		?string $expectedUrl
	) {
		if ( !$expectedUrl ) {
			$this->expectException( ConfigException::class );
		}
		$factory = $this->getEventBusFactory(
			$serviceConfig,
			[ 'default' => [ 'EventServiceName' => $serviceName ] ]
		);
		$instance = $factory->getInstance( $serviceName );
		$instance = TestingAccessWrapper::newFromObject( $instance );
		if ( $expectedUrl ) {
			$this->assertSame( $expectedUrl, $instance->url );
		}
	}

	public function provideGetInstanceForStream() {
		$serviceConfig = [
			'testbus' => [ 'url' => 'http://testbus.test' ],
			'other_testbus' => [ 'url' => 'http://othertestbus.test' ]
		];
		yield 'non existent stream' => [
			'nonexistent',
			$serviceConfig,
			[],
			null
		];
		yield 'default bus' => [
			'nonexistent',
			$serviceConfig,
			[ 'default' => [ 'EventServiceName' => 'testbus' ] ],
			'http://testbus.test'
		];
		yield 'non-default bus' => [
			'existing_stream',
			$serviceConfig,
			[
				'existing_stream' => [ 'EventServiceName' => 'other_testbus' ],
				'default' => [ 'EventServiceName' => 'testbus' ]
			],
			'http://othertestbus.test'
		];
	}

	/**
	 * @covers \MediaWiki\Extension\EventBus\EventBusFactory::getInstanceForStream
	 * @dataProvider provideGetInstanceForStream
	 * @param string $streamName
	 * @param array $serviceConfig
	 * @param array $streamConfig
	 * @param string|null $expectedUrl - expected EventBus instance url.
	 *   If null - expect ConfigException
	 */
	public function testGetInstanceForStream(
		string $streamName,
		array $serviceConfig,
		array $streamConfig,
		?string $expectedUrl
	) {
		if ( !$expectedUrl ) {
			$this->expectException( ConfigException::class );
		}
		$factory = $this->getEventBusFactory( $serviceConfig, $streamConfig );
		$instance = $factory->getInstanceForStream( $streamName );
		$instance = TestingAccessWrapper::newFromObject( $instance );
		if ( $expectedUrl ) {
			$this->assertSame( $expectedUrl, $instance->url );
		}
	}
}
