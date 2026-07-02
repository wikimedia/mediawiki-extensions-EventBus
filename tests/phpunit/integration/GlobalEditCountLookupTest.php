<?php

use MediaWiki\Extension\CentralAuth\CentralAuthEditCounter;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUserHelper;
use MediaWiki\Extension\EventBus\GlobalEditCountLookup;
use MediaWiki\Registration\ExtensionRegistry;

/**
 * @coversDefaultClass \MediaWiki\Extension\EventBus\GlobalEditCountLookup
 * @group EventBus
 */
class GlobalEditCountLookupTest extends MediaWikiIntegrationTestCase {

	/**
	 * Skips the test when CentralAuth is not installed. Tests that mock
	 * CentralAuth classes require the extension's autoloader to be registered.
	 */
	private function requireCentralAuth(): void {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'CentralAuth' ) ) {
			$this->markTestSkipped( 'CentralAuth is not loaded.' );
		}
	}

	/**
	 * @covers ::getGlobalEditCount
	 */
	public function testReturnsCountWhenInitialized(): void {
		$this->requireCentralAuth();

		$helper = $this->createMock( CentralAuthUserHelper::class );
		$helper->method( 'getCentralAuthUserById' )
			->with( 555 )
			->willReturn( StatusValue::newGood( $this->createMock( CentralAuthUser::class ) ) );
		$counter = $this->createMock( CentralAuthEditCounter::class );
		$counter->method( 'getCountIfInitialized' )->willReturn( 4242 );

		$lookup = new GlobalEditCountLookup( $counter, $helper );

		$this->assertSame( 4242, $lookup->getGlobalEditCount( 555 ) );
	}

	/**
	 * @covers ::getGlobalEditCount
	 */
	public function testReturnsNullWhenGlobalAccountLookupFails(): void {
		$this->requireCentralAuth();

		$helper = $this->createMock( CentralAuthUserHelper::class );
		$helper->method( 'getCentralAuthUserById' )
			->willReturn( StatusValue::newFatal( 'noname' ) );
		$counter = $this->createMock( CentralAuthEditCounter::class );
		$counter->expects( $this->never() )->method( 'getCountIfInitialized' );

		$lookup = new GlobalEditCountLookup( $counter, $helper );

		$this->assertNull( $lookup->getGlobalEditCount( 555 ) );
	}

	/**
	 * @covers ::getGlobalEditCount
	 */
	public function testReturnsNullWhenCountUninitialized(): void {
		$this->requireCentralAuth();

		$helper = $this->createMock( CentralAuthUserHelper::class );
		$helper->method( 'getCentralAuthUserById' )
			->willReturn( StatusValue::newGood( $this->createMock( CentralAuthUser::class ) ) );
		$counter = $this->createMock( CentralAuthEditCounter::class );
		$counter->method( 'getCountIfInitialized' )->willReturn( null );

		$lookup = new GlobalEditCountLookup( $counter, $helper );

		$this->assertNull( $lookup->getGlobalEditCount( 555 ) );
	}

	/**
	 * With no CentralAuth services (extension absent), the lookup always returns
	 * null. This does not require CentralAuth to be loaded, since it passes null
	 * dependencies.
	 * @covers ::getGlobalEditCount
	 */
	public function testReturnsNullWithoutCentralAuth(): void {
		$lookup = new GlobalEditCountLookup( null, null );

		$this->assertNull( $lookup->getGlobalEditCount( 555 ) );
	}
}
