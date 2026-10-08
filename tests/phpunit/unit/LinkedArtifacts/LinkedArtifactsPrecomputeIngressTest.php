<?php

namespace MediaWiki\Extension\EventBus\Tests\Unit\LinkedArtifacts;

use MediaWiki\Extension\EventBus\LinkedArtifacts\Job\LinkedArtifactPrecomputeJob;
use MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactsConfig;
use MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactsPrecomputeIngress;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\JobQueue\JobSpecification;
use MediaWiki\Page\Event\PageLatestRevisionChangedEvent;
use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Revision\RevisionRecord;
use MediaWikiUnitTestCase;
use PHPUnit\Framework\Assert;

/**
 * @covers \MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactsPrecomputeIngress
 */
class LinkedArtifactsPrecomputeIngressTest extends MediaWikiUnitTestCase {

	private const DEFAULT_ARTIFACTS = [
		'my-artifact' => [
			'entity_kind' => 'revision',
			'precompute' => [ 'events' => [ PageLatestRevisionChangedEvent::TYPE ] ],
		],
	];

	private function newIngress(
		JobQueueGroup $jobQueueGroup,
		bool $enabled = true,
		?array $artifacts = null
	): LinkedArtifactsPrecomputeIngress {
		$config = new LinkedArtifactsConfig( [
			'enabled' => $enabled,
			'base_url' => 'https://lac.example',
			'artifacts' => $artifacts ?? self::DEFAULT_ARTIFACTS,
		] );

		return new LinkedArtifactsPrecomputeIngress( $jobQueueGroup, $config );
	}

	/**
	 * @param bool $changedLatestRevisionId Whether the edit minted a new revision ID.
	 * @param bool $isReconciliationRequest Whether core flagged this as a null edit.
	 * @param int $namespace
	 */
	private function newRevisionChangedEvent(
		bool $changedLatestRevisionId = true,
		bool $isReconciliationRequest = false,
		int $namespace = 0
	): PageLatestRevisionChangedEvent {
		$revision = $this->createMock( RevisionRecord::class );
		$revision->method( 'getId' )->willReturn( 99 );

		$pageRecord = $this->createMock( ExistingPageRecord::class );
		$pageRecord->method( 'getWikiId' )->willReturn( 'enwiki' );
		$pageRecord->method( 'getId' )->willReturn( 42 );
		$pageRecord->method( 'getNamespace' )->willReturn( $namespace );

		$event = $this->createMock( PageLatestRevisionChangedEvent::class );
		$event->method( 'changedLatestRevisionId' )->willReturn( $changedLatestRevisionId );
		$event->method( 'isReconciliationRequest' )->willReturn( $isReconciliationRequest );
		$event->method( 'getCause' )->willReturn( 'edit' );
		$event->method( 'getPageId' )->willReturn( 42 );
		$event->method( 'getLatestRevisionAfter' )->willReturn( $revision );
		$event->method( 'getPageRecordAfter' )->willReturn( $pageRecord );

		return $event;
	}

	private function newNeverPushingJobQueueGroup(): JobQueueGroup {
		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$jobQueueGroup->expects( $this->never() )->method( 'lazyPush' );
		return $jobQueueGroup;
	}

	public function testDisabledConfigNeverPushes(): void {
		$ingress = $this->newIngress( $this->newNeverPushingJobQueueGroup(), false );

		$ingress->handlePageLatestRevisionChangedEvent( $this->newRevisionChangedEvent() );
	}

	public function testPushesOneJobCarryingTheRevisionIdsPerSubscribedArtifact(): void {
		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$jobQueueGroup->expects( $this->once() )
			->method( 'lazyPush' )
			->willReturnCallback( static function ( JobSpecification $spec ) {
				Assert::assertSame( 'linkedArtifactPrecompute.my-artifact', $spec->getType() );
				$params = $spec->getParams();
				// Added by JobSpecification.
				unset( $params['requestId'] );
				Assert::assertEquals(
					[
						LinkedArtifactPrecomputeJob::ARTIFACT_NAME_PARAM => 'my-artifact',
						LinkedArtifactPrecomputeJob::ENTITY_KIND_PARAM => 'revision',
						LinkedArtifactPrecomputeJob::WIKI_ID_PARAM => 'enwiki',
						LinkedArtifactPrecomputeJob::PAGE_ID_PARAM => 42,
						LinkedArtifactPrecomputeJob::REVISION_ID_PARAM => 99,
						LinkedArtifactPrecomputeJob::TRIGGER_PARAM => [
							'event_type' => PageLatestRevisionChangedEvent::TYPE,
							'cause' => 'edit',
							'is_reconciliation_request' => false,
						],
					],
					$params
				);
			} );

		$ingress = $this->newIngress( $jobQueueGroup );
		$ingress->handlePageLatestRevisionChangedEvent( $this->newRevisionChangedEvent() );
	}

	public function testDummyRevisionPushesBecauseTheCacheKeyChanged(): void {
		// A dummy revision (e.g. from a protection change) leaves the content untouched but
		// mints a new revision ID, so the LAC key it maps to has nothing cached yet.
		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$jobQueueGroup->expects( $this->once() )->method( 'lazyPush' );

		$ingress = $this->newIngress( $jobQueueGroup );
		$ingress->handlePageLatestRevisionChangedEvent(
			$this->newRevisionChangedEvent( true, false )
		);
	}

	public function testNullEditPushesAsAReconciliationRequest(): void {
		// Null edits mint no revision, but are the supported way to force recomputation.
		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$jobQueueGroup->expects( $this->once() )
			->method( 'lazyPush' )
			->willReturnCallback( static function ( JobSpecification $spec ) {
				Assert::assertTrue(
					$spec->getParams()[LinkedArtifactPrecomputeJob::TRIGGER_PARAM]['is_reconciliation_request']
				);
			} );

		$ingress = $this->newIngress( $jobQueueGroup );
		$ingress->handlePageLatestRevisionChangedEvent(
			$this->newRevisionChangedEvent( false, true )
		);
	}

	public function testUnchangedRevisionWithoutReconciliationDoesNotPush(): void {
		$ingress = $this->newIngress( $this->newNeverPushingJobQueueGroup() );

		$ingress->handlePageLatestRevisionChangedEvent(
			$this->newRevisionChangedEvent( false, false )
		);
	}

	public function testSkipsAnArtifactWhosePrecomputeIsDisabled(): void {
		$ingress = $this->newIngress( $this->newNeverPushingJobQueueGroup(), true, [
			'my-artifact' => [
				'entity_kind' => 'revision',
				'precompute' => [
					'enabled' => false,
					'events' => [ PageLatestRevisionChangedEvent::TYPE ],
				],
			],
		] );

		$ingress->handlePageLatestRevisionChangedEvent( $this->newRevisionChangedEvent() );
	}

	private static function artifactsWithPageFilter( array $page ): array {
		return [
			'my-artifact' => [
				'entity_kind' => 'revision',
				'page' => $page,
				'precompute' => [ 'events' => [ PageLatestRevisionChangedEvent::TYPE ] ],
			],
		];
	}

	public function testNamespaceFilterSkipsPagesOutsideTheAllowlist(): void {
		$ingress = $this->newIngress(
			$this->newNeverPushingJobQueueGroup(),
			true,
			self::artifactsWithPageFilter( [ 'namespaces' => [ 0 ] ] )
		);

		// Namespace 1 (Talk) is not in the [0] allowlist.
		$ingress->handlePageLatestRevisionChangedEvent(
			$this->newRevisionChangedEvent( true, false, 1 )
		);
	}

	public function testNamespaceFilterAllowsListedNamespace(): void {
		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$jobQueueGroup->expects( $this->once() )->method( 'lazyPush' );

		$ingress = $this->newIngress(
			$jobQueueGroup,
			true,
			self::artifactsWithPageFilter( [ 'namespaces' => [ 0 ] ] )
		);
		$ingress->handlePageLatestRevisionChangedEvent(
			$this->newRevisionChangedEvent( true, false, 0 )
		);
	}

	public function testSampleZeroSkipsPrecompute(): void {
		$ingress = $this->newIngress(
			$this->newNeverPushingJobQueueGroup(),
			true,
			self::artifactsWithPageFilter( [ 'sample' => 0.0 ] )
		);

		$ingress->handlePageLatestRevisionChangedEvent( $this->newRevisionChangedEvent() );
	}
}
