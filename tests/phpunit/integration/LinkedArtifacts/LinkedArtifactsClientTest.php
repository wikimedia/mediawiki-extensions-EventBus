<?php

namespace MediaWiki\Extension\EventBus\Tests\Integration\LinkedArtifacts;

use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactsClient
 * @group EventBus
 */
class LinkedArtifactsClientTest extends MediaWikiIntegrationTestCase {

	public function testGetPathResolvesFalseToTheLocalWikiId(): void {
		// A local page's getWikiId() is false, so callers pass it straight through. Tested
		// here rather than in the unit test: WikiMap reads $wgDBname, which
		// MediaWikiUnitTestCase unsets, making the resolved value '' there.
		$client = $this->getServiceContainer()->getService( 'EventBus.LinkedArtifactsClient' );

		$wikiId = WikiMap::getCurrentWikiId();
		$this->assertNotSame( '', $wikiId );
		$this->assertSame(
			sprintf( '/revisions/v1/my-artifact/%s/42/99', rawurlencode( $wikiId ) ),
			$client->getRevisionArtifactUri( 'my-artifact', false, 42, 99 )
		);
	}
}
