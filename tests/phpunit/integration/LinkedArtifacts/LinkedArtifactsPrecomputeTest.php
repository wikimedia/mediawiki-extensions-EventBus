<?php

namespace MediaWiki\Extension\EventBus\Tests\Integration\LinkedArtifacts;

use MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactsClient;
use MediaWiki\Extension\EventBus\LinkedArtifacts\RevisionLinkedArtifactPrecomputedEvent;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use Psr\Log\NullLogger;
use Wikimedia\Http\MultiHttpClient;

/**
 * @covers \MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactsPrecompute
 * @group EventBus
 * @group Database
 */
class LinkedArtifactsPrecomputeTest extends MediaWikiIntegrationTestCase {

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
			new NullLogger()
		) );

		$received = [];
		$this->registerListener(
			RevisionLinkedArtifactPrecomputedEvent::TYPE,
			static function ( RevisionLinkedArtifactPrecomputedEvent $event ) use ( &$received ) {
				$received[] = $event;
			}
		);

		$this->getServiceContainer()->getService( 'EventBus.LinkedArtifactsPrecompute' )
			->precomputeRevisionArtifact( 'my-artifact', false, 42, 99, 'PageLatestRevisionChanged', 'edit' );
		$this->runDeferredUpdates();

		$this->assertCount( 1, $received );
		// A local page's getWikiId() is false. Tested here rather than in the unit test:
		// WikiMap reads $wgDBname, which MediaWikiUnitTestCase unsets.
		$this->assertSame( WikiMap::getCurrentWikiId(), $received[0]->getWikiId() );
		$this->assertSame( '{"a":1}', $received[0]->getBody() );
	}
}
