<?php

namespace MediaWiki\Extension\EventBus\Tests\Integration\LinkedArtifacts;

use MediaWiki\Extension\EventBus\LinkedArtifacts\Job\LinkedArtifactPrefetchJob;
use MediaWiki\MainConfigNames;
use MediaWiki\Page\Event\PageLatestRevisionChangedEvent;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactsPrefetchIngress
 * @group Database
 * @group EventBus
 */
class LinkedArtifactsPrefetchIngressTest extends MediaWikiIntegrationTestCase {

	public function testAnEditEnqueuesAPrefetchJobForASubscribedArtifact(): void {
		$linkedArtifacts = [
			'enabled' => true,
			'base_url' => 'https://lac.example',
			'artifacts' => [
				'my-artifact' => [
					'entity_kind' => 'revision',
					'prefetch' => [ 'events' => [ PageLatestRevisionChangedEvent::TYPE ] ],
				],
			],
		];
		$this->overrideConfigValues( [
			'EventBusLinkedArtifacts' => $linkedArtifacts,
			// The registration callback ran before this test set the config, so register the
			// job type here. Popping the job needs it.
			MainConfigNames::JobClasses => $this->getConfVar( MainConfigNames::JobClasses ) +
				[ LinkedArtifactPrefetchJob::getJobType( 'my-artifact' ) => LinkedArtifactPrefetchJob::JOB_SPEC ],
		] );

		$status = $this->editPage( 'LacPrefetchIngressTestPage', 'content' );
		$revision = $status->getNewRevision();

		// The edit is the whole trigger: core dispatches PageLatestRevisionChanged to the
		// ingress registered in extension.json, which enqueues the job. Nothing here calls
		// the handler directly, so a registration that stopped working would fail this.
		//
		// Under CLI (PHPUnit), JobQueueGroup::lazyPush() pushes immediately, so the job is
		// already on the queue and no deferred-update flush is needed.
		$job = $this->getServiceContainer()
			->getJobQueueGroup()
			->get( LinkedArtifactPrefetchJob::getJobType( 'my-artifact' ) )
			->pop();
		$this->assertInstanceOf( LinkedArtifactPrefetchJob::class, $job );

		$params = $job->getParams();
		$this->assertSame(
			'my-artifact',
			$params[LinkedArtifactPrefetchJob::ARTIFACT_NAME_PARAM]
		);

		// The page is local, so its getWikiId() is false and the ingress must have resolved
		// it. Asserted here rather than in the unit test: WikiMap reads $wgDBname, which
		// MediaWikiUnitTestCase unsets.
		$this->assertSame( WikiMap::getCurrentWikiId(), $params[LinkedArtifactPrefetchJob::WIKI_ID_PARAM] );
		$this->assertSame( $revision->getPageId(), $params[LinkedArtifactPrefetchJob::PAGE_ID_PARAM] );
		$this->assertSame( $revision->getId(), $params[LinkedArtifactPrefetchJob::REVISION_ID_PARAM] );
		$trigger = $params[LinkedArtifactPrefetchJob::TRIGGER_PARAM];
		$this->assertIsString( $trigger['dt'] );
		unset( $trigger['dt'] );
		$this->assertSame(
			[
				'event_type' => PageLatestRevisionChangedEvent::TYPE,
				'cause' => 'edit',
				'is_reconciliation_request' => false,
			],
			$trigger
		);
	}
}
