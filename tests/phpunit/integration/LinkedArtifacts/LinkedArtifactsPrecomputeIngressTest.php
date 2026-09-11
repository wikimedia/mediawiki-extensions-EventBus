<?php

namespace MediaWiki\Extension\EventBus\Tests\Integration\LinkedArtifacts;

use MediaWiki\Extension\EventBus\LinkedArtifacts\Job\LinkedArtifactPrecomputeJob;
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
		$this->overrideConfigValue( 'EventBusLinkedArtifacts', [
			'enabled' => true,
			'baseUrl' => 'https://lac.example',
			'artifacts' => [
				'my-artifact' => [
					'entity' => 'revision',
					'precompute' => [ 'events' => [ PageLatestRevisionChangedEvent::TYPE ] ],
				],
			],
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
			->get( LinkedArtifactPrecomputeJob::JOB_NAME )
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
		$this->assertSame(
			sprintf(
				'/revisions/v1/my-artifact/%s/%d/%d',
				rawurlencode( WikiMap::getCurrentWikiId() ),
				$revision->getPageId(),
				$revision->getId()
			),
			$params[LinkedArtifactPrecomputeJob::ARTIFACT_URI_PARAM]
		);
	}
}
