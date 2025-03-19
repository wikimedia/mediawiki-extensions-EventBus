<?php

use MediaWiki\Extension\EventBus\JobExecutor;
use MediaWiki\JobQueue\JobFactory;
use Wikimedia\Telemetry\Clock;
use Wikimedia\Telemetry\ExporterInterface;
use Wikimedia\Telemetry\ProbabilisticSampler;
use Wikimedia\Telemetry\StaticInjectionPropagator;
use Wikimedia\Telemetry\Tracer;
use Wikimedia\Telemetry\TracerState;

/**
 * @covers \MediaWiki\Extension\EventBus\JobExecutor
 */
class JobExecutorTest extends MediaWikiIntegrationTestCase {
	private const TEST_JOB_TYPE = 'testJob';

	private JobExecutor $jobExecutor;
	private Tracer $tracer;

	protected function setUp(): void {
		parent::setUp();
		$this->jobExecutor = new JobExecutor();

		// Create a real tracer with exporting stubbed out to verify the tracing integration in JobExecutor.
		$this->tracer = new Tracer(
			new Clock(),
			new ProbabilisticSampler( 100 ),
			$this->createMock( ExporterInterface::class ),
			new TracerState(),
			new StaticInjectionPropagator( [] )
		);

		$this->setService( 'Tracer', $this->tracer );
	}

	public function testShouldRunJobSuccessfully(): void {
		$job = $this->createMock( Job::class );
		$job->method( 'getType' )
			->willReturn( self::TEST_JOB_TYPE );
		$job->expects( $this->once() )
			->method( 'run' )
			->willReturn( true );
		$job->expects( $this->once() )
			->method( 'teardown' )
			->with( true );

		$jobFactory = $this->createMock( JobFactory::class );
		$jobFactory->method( 'newJob' )
			->with( self::TEST_JOB_TYPE, [ 'foo' => 'bar' ] )
			->willReturn( $job );

		$this->setService( 'JobFactory', $jobFactory );

		$parentSpan = $this->tracer->createRootSpan( 'test root span' )
			->start();
		$parentSpan->activate();

		$result = $this->jobExecutor->execute( [
			'type' => self::TEST_JOB_TYPE,
			'params' => [ 'foo' => 'bar' ]
		] );

		$this->assertSame(
			[
				'status' => true,
				'readonly' => false,
				'message' => 'success',
			],
			$result
		);
	}
}
