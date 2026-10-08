<?php

namespace MediaWiki\Extension\EventBus\Tests\Integration\LinkedArtifacts;

use MediaWiki\Extension\EventBus\LinkedArtifacts\Job\LinkedArtifactPrecomputeJob;
use MediaWiki\MainConfigNames;
use MediaWiki\Page\Event\PageLatestRevisionChangedEvent;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactsPrecomputeIngress
 * @group Database
 * @group EventBus
 */
class LinkedArtifactsPrecomputeIngressTest extends MediaWikiIntegrationTestCase {

	public function testAnEditEnqueuesAPrecomputeJobForASubscribedArtifact(): void {
		$linkedArtifacts = [
			'enabled' => true,
			'base_url' => 'https://lac.example',
			'artifacts' => [
				'my-artifact' => [
					'entity_kind' => 'revision',
					'precompute' => [ 'events' => [ PageLatestRevisionChangedEvent::TYPE ] ],
				],
			],
		];
		$this->overrideConfigValues( [
			'EventBusLinkedArtifacts' => $linkedArtifacts,
			// The registration callback ran before this test set the config, so register the
			// job type here. Popping the job needs it.
			MainConfigNames::JobClasses => $this->getConfVar( MainConfigNames::JobClasses ) +
				[ LinkedArtifactPrecomputeJob::getJobType( 'my-artifact' ) => LinkedArtifactPrecomputeJob::JOB_SPEC ],
		] );

		$status = $this->editPage( 'LacPrecomputeIngressTestPage', 'content' );
		$revision = $status->getNewRevision();

		// The edit is the whole trigger: core dispatches PageLatestRevisionChanged to the
		// ingress registered in extension.json, which enqueues the job. Nothing here calls
		// the handler directly, so a registration that stopped working would fail this.
		//
		// Under CLI (PHPUnit), JobQueueGroup::lazyPush() pushes immediately, so the job is
		// already on the queue and no deferred-update flush is needed.
		$job = $this->getServiceContainer()
			->getJobQueueGroup()
			->get( LinkedArtifactPrecomputeJob::getJobType( 'my-artifact' ) )
			->pop();
		$this->assertInstanceOf( LinkedArtifactPrecomputeJob::class, $job );

		$params = $job->getParams();
		$this->assertSame(
			'my-artifact',
			$params[LinkedArtifactPrecomputeJob::ARTIFACT_NAME_PARAM]
		);

		// The page is local, so its getWikiId() is false and the ingress must have resolved
		// it. Asserted here rather than in the unit test: WikiMap reads $wgDBname, which
		// MediaWikiUnitTestCase unsets.
		$this->assertSame( WikiMap::getCurrentWikiId(), $params[LinkedArtifactPrecomputeJob::WIKI_ID_PARAM] );
		$this->assertSame( $revision->getPageId(), $params[LinkedArtifactPrecomputeJob::PAGE_ID_PARAM] );
		$this->assertSame( $revision->getId(), $params[LinkedArtifactPrecomputeJob::REVISION_ID_PARAM] );
		$this->assertSame(
			[
				'event_type' => PageLatestRevisionChangedEvent::TYPE,
				'cause' => 'edit',
				'is_reconciliation_request' => false,
			],
			$params[LinkedArtifactPrecomputeJob::TRIGGER_PARAM]
		);
	}
}
