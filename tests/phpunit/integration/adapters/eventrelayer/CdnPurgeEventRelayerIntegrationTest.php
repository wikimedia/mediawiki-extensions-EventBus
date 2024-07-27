<?php

use MediaWiki\Deferred\CdnCacheUpdate;
use MediaWiki\Extension\EventBus\Adapters\EventRelayer\CdnPurgeEventRelayer;
use MediaWiki\Extension\EventBus\EventBus;
use MediaWiki\Extension\EventBus\EventBusFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;

/**
 * @covers \MediaWiki\Extension\EventBus\Adapters\EventRelayer\CdnPurgeEventRelayer
 */
class CdnPurgeEventRelayerIntegrationTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValues( [
			MainConfigNames::HTCPRouting => false,
			MainConfigNames::CdnServers => false,
			'EventRelayerConfig' => [
				'cdn-url-purges' => [
					'class' => CdnPurgeEventRelayer::class,
					'stream' => 'test-resource-purge'
				]
			]
		] );
	}

	/**
	 * @covers \MediaWiki\Extension\EventBus\Adapters\EventRelayer\CdnPurgeEventRelayer::doNotify
	 */
	public function testSendsProperPurge() {
		$fakeUrl = 'https://fake.wiki/url/for/testing';
		$eventFactory = MediaWikiServices::getInstance()
			->getService( 'EventBus.EventBusFactory' )
			->getInstanceForStream( 'test-resource-purge' )
			->getFactory();
		$mockEventBus = $this->createNoOpMock(
			EventBus::class,
			[ 'getFactory', 'send' ]
		);
		$mockEventBus
			->expects( $this->any() )
			->method( 'getFactory' )
			->willReturn( $eventFactory );
		$mockEventBus
			->expects( $this->once() )
			->method( 'send' )
			->with( $this->callback( function ( array $events ) use ( $fakeUrl ) {
				$this->assertCount( 1, $events );
				$this->assertSame( 'test-resource-purge', $events[0]['meta']['stream'] );
				$this->assertSame( $fakeUrl, $events[0]['meta']['uri'] );
				$this->assertArrayEquals( [ 'mediawiki' ], $events[0]['tags'] );
				return true;
			} ) );
		$mockEventBusFactory = $this->createNoOpMock(
			EventBusFactory::class,
			[ 'getInstanceForStream' ]
		);
		$mockEventBusFactory->method( 'getInstanceForStream' )->willReturn( $mockEventBus );
		$this->setService( 'EventBus.EventBusFactory', $mockEventBusFactory );
		CdnCacheUpdate::purge( [ $fakeUrl ] );
	}
}
