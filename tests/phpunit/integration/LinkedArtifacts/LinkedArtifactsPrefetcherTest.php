<?php

namespace MediaWiki\Extension\EventBus\Tests\Integration\LinkedArtifacts;

use MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactsClient;
use MediaWiki\Extension\EventBus\LinkedArtifacts\RevisionLinkedArtifactPrefetchedEvent;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use Psr\Log\NullLogger;
use Wikimedia\Http\MultiHttpClient;
use Wikimedia\Stats\StatsFactory;

/**
 * @covers \MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactsPrefetcher
 * @group EventBus
 * @group Database
 */
class LinkedArtifactsPrefetcherTest extends MediaWikiIntegrationTestCase {

	public function testSuccessNotifiesRegisteredListeners(): void {
		$this->overrideConfigValue( 'EventBusLinkedArtifacts', [
			'enabled' => true,
			'base_url' => 'https://lac.example',
			'artifacts' => [ 'my-artifact' => [ 'entity_kind' => 'revision' ] ],
		] );

		$http = $this->createMock( MultiHttpClient::class );
		$http->method( 'runMulti' )->willReturn( [ [
			'response' => [
				'code' => 200,
				'headers' => [ 'content-type' => 'application/json' ],
				'body' => '{"a":1}',
			],
		] ] );
		$services = $this->getServiceContainer();
		$this->setService( 'EventBus.LinkedArtifactsClient', new LinkedArtifactsClient(
			$services->getService( 'EventBus.LinkedArtifactsConfig' ),
			$http,
			new NullLogger(),
			StatsFactory::newNull()
		) );

		$received = [];
		$this->registerListener(
			RevisionLinkedArtifactPrefetchedEvent::TYPE,
			static function ( RevisionLinkedArtifactPrefetchedEvent $event ) use ( &$received ) {
				$received[] = $event;
			}
		);

		$this->getServiceContainer()->getService( 'EventBus.LinkedArtifactsPrefetcher' )
			->prefetchRevisionArtifact( 'my-artifact', false, 42, 99, false, 'PageLatestRevisionChanged', 'edit' );
		$this->runDeferredUpdates();

		$this->assertCount( 1, $received );
		// A local page's getWikiId() is false. Tested here rather than in the unit test:
		// WikiMap reads $wgDBname, which MediaWikiUnitTestCase unsets.
		$this->assertSame( WikiMap::getCurrentWikiId(), $received[0]->getWikiId() );
		$this->assertSame( '{"a":1}', $received[0]->getBody() );
	}
}
