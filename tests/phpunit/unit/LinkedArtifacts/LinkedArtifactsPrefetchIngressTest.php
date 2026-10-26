<?php

namespace MediaWiki\Extension\EventBus\Tests\Unit\LinkedArtifacts;

use MediaWiki\Extension\EventBus\LinkedArtifacts\Job\LinkedArtifactPrefetchJob;
use MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactsConfig;
use MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactsPrefetchIngress;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\JobQueue\JobSpecification;
use MediaWiki\Page\Event\PageLatestRevisionChangedEvent;
use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Revision\RevisionRecord;
use MediaWikiUnitTestCase;
use PHPUnit\Framework\Assert;

/**
 * @covers \MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactsPrefetchIngress
 */
class LinkedArtifactsPrefetchIngressTest extends MediaWikiUnitTestCase {

	private const DEFAULT_ARTIFACTS = [
		'my-artifact' => [
			'entity_kind' => 'revision',
			'prefetch' => [ 'events' => [ PageLatestRevisionChangedEvent::TYPE ] ],
		],
	];

	private function newIngress(
		JobQueueGroup $jobQueueGroup,
		bool $enabled = true,
		?array $artifacts = null
	): LinkedArtifactsPrefetchIngress {
		$config = new LinkedArtifactsConfig( [
			'enabled' => $enabled,
			'base_url' => 'https://lac.example',
			'artifacts' => $artifacts ?? self::DEFAULT_ARTIFACTS,
		] );

		return new LinkedArtifactsPrefetchIngress( $jobQueueGroup, $config );
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
				Assert::assertSame( 'linkedArtifactPrefetch.my-artifact', $spec->getType() );
				$params = $spec->getParams();
				// Added by JobSpecification.
				unset( $params['requestId'] );
				// The time of the ingress with milliseconds, not the event timestamp. See T441939.
				Assert::assertMatchesRegularExpression(
					'/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/',
					$params[LinkedArtifactPrefetchJob::TRIGGER_PARAM]['dt']
				);
				unset( $params[LinkedArtifactPrefetchJob::TRIGGER_PARAM]['dt'] );
				Assert::assertEquals(
					[
						LinkedArtifactPrefetchJob::ARTIFACT_NAME_PARAM => 'my-artifact',
						LinkedArtifactPrefetchJob::ENTITY_KIND_PARAM => 'revision',
						LinkedArtifactPrefetchJob::WIKI_ID_PARAM => 'enwiki',
						LinkedArtifactPrefetchJob::PAGE_ID_PARAM => 42,
						LinkedArtifactPrefetchJob::REVISION_ID_PARAM => 99,
						LinkedArtifactPrefetchJob::TRIGGER_PARAM => [
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
					$spec->getParams()[LinkedArtifactPrefetchJob::TRIGGER_PARAM]['is_reconciliation_request']
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

	public function testSkipsAnArtifactWhosePrefetchIsDisabled(): void {
		$ingress = $this->newIngress( $this->newNeverPushingJobQueueGroup(), true, [
			'my-artifact' => [
				'entity_kind' => 'revision',
				'prefetch' => [
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
				'prefetch' => [ 'events' => [ PageLatestRevisionChangedEvent::TYPE ] ],
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

	public function testSampleZeroSkipsPrefetch(): void {
		$ingress = $this->newIngress(
			$this->newNeverPushingJobQueueGroup(),
			true,
			self::artifactsWithPageFilter( [ 'sample' => 0.0 ] )
		);

		$ingress->handlePageLatestRevisionChangedEvent( $this->newRevisionChangedEvent() );
	}
}
