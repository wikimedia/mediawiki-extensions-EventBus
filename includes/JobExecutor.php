<?php

/**
 * A massively simplified JobRunner with a solo purpose of
 * executing a Job
 */

namespace MediaWiki\Extension\EventBus;

use Exception;
use MediaWiki\Config\Config;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Exception\MWExceptionHandler;
use MediaWiki\Http\Telemetry;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use Psr\Log\LoggerInterface;
use Wikimedia\Stats\StatsFactory;
use Wikimedia\Telemetry\SpanInterface;

class JobExecutor {

	/** @var LoggerInterface instance for all JobExecutor instances */
	private static $logger;

	/** @var StatsFactory instance
	 * for all JobExecutor instances
	 */
	private static $statsFactory;

	/**
	 * @var Config a references to the wiki config
	 */
	private static $config;

	/**
	 * @param array $jobEvent the job event
	 * @return array containing the response status and the message
	 */
	public function execute( $jobEvent ) {
		$startTime = microtime( true );
		$isReadonly = false;
		$jobCreateResult = $this->getJobFromParams( $jobEvent );

		if ( !$jobCreateResult['status'] ) {
			$this->logger()->error( 'Failed creating job from description', [
				'job_type' => $jobEvent['type'],
				'message' => $jobCreateResult['message']
			] );
			$jobCreateResult['readonly'] = false;
			return $jobCreateResult;
		}

		// Wrap job execution in a span to easily identify job types in traces.
		$tracer = MediaWikiServices::getInstance()->getTracer();
		$span = $tracer->createSpan( 'execute job' )
			->setAttributes( [ 'org.wikimedia.eventbus.job.type' => $jobEvent['type'] ] )
			->start();
		$scope = $span->activate();

		$job = $jobCreateResult['job'];
		$this->logger()->debug( 'Beginning job execution', [
			'job' => $job->toString(),
			'job_type' => $job->getType()
		] );

		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
		$telemetry = Telemetry::getInstance();

		if ( $job->getRequestId() !== null ) {
			$telemetry->overrideRequestId( $job->getRequestId() );
		} else {
			$telemetry->regenerateRequestId();
		}
		// Clear out title cache data from prior snapshots
		MediaWikiServices::getInstance()->getLinkCache()->clear();

		// Actually execute the job
		try {
			$fnameTrxOwner = get_class( $job ) . '::run';
			// Flush any pending changes left over from an implicit transaction round
			if ( $job->hasExecutionFlag( $job::JOB_NO_EXPLICIT_TRX_ROUND ) ) {
				$lbFactory->commitPrimaryChanges( $fnameTrxOwner );
			} else {
				$lbFactory->beginPrimaryChanges( $fnameTrxOwner );
			}
			// Clear any stale REPEATABLE-READ snapshots from replica DB connections
			$status = $job->run();
			// Commit all pending changes from this job
			$lbFactory->commitPrimaryChanges(
				$fnameTrxOwner,
				// Abort if any transaction was too big
				$this->config()->get( 'MaxJobDBWriteDuration' )
			);

			if ( $status === false ) {
				$message = $job->getLastError();
				$this->logger()->error( 'Failed executing job: ' . $job->toString(), [
					'job_type' => $job->getType(),
					'error' => $message
				] );
			} elseif ( !is_bool( $status ) ) {
				$message = 'Success, but no status returned';
				$this->logger()->warning( 'Non-boolean result returned by job: ' . $job->toString(),
					[
						'job_type' => $job->getType(),
						'job_result' => $status ?? 'unset'
					] );
				// For backwards compatibility with old job executor we should set the status
				// to true here, as before anything other then boolean false was considered a success.
				// TODO: After all the jobs are fixed to return proper result this should be removed.
				$status = true;
			} else {
				$message = 'success';
			}

			// Run any deferred update tasks; doUpdates() manages transactions itself
			DeferredUpdates::doUpdates();
		} catch ( \Wikimedia\Rdbms\DBReadOnlyError $e ) {
			$status = false;
			$isReadonly = true;
			$message = 'Database is in read-only mode';
			MWExceptionHandler::rollbackPrimaryChangesAndLog( $e, MWExceptionHandler::CAUGHT_BY_ENTRYPOINT );
		} catch ( Exception $e ) {
			MWExceptionHandler::rollbackPrimaryChangesAndLog( $e, MWExceptionHandler::CAUGHT_BY_ENTRYPOINT );
			$status = false;
			$message = 'Exception executing job: '
				. $job->toString() . ' : '
				. get_class( $e ) . ': ' . $e->getMessage();
			$this->logger()->error( $message,
				[
					'job_type'  => $job->getType(),
					'exception' => $e
				]
			);
		}

		// Always attempt to call teardown() even if Job throws exception.
		try {
			$job->teardown( $status );
		} catch ( Exception $e ) {
			$message = 'Exception tearing down job: '
				. $job->toString() . ' : '
				. get_class( $e ) . ': ' . $e->getMessage();
			$this->logger()->error( $message,
				[
					'job_type'  => $job->getType(),
					'exception' => $e
				]
			);
		}

		// The JobRunner at this point makes some cleanups to prepare for
		// the next Job execution. However since we run one job at a time
		// we don't need that.

		// Report pure job execution timing
		$jobDuration = microtime( true ) - $startTime;
		self::stats()->getTiming( 'jobexecutor_exec_runtime_seconds' )
				->setLabel( 'type', $job->getType() )
				->copyToStatsdAt( "jobexecutor.{$job->getType()}.exec" )
				->observeSeconds( $jobDuration );
		$this->logger()->info( 'Finished job execution',
			[
				'job' => $job->toString(),
				'job_type' => $job->getType(),
				'job_status' => $status,
				'job_duration' => $jobDuration
			]
		);

		$span->setSpanStatus( $status ? SpanInterface::SPAN_STATUS_OK : SpanInterface::SPAN_STATUS_ERROR );

		if ( !$job->allowRetries() ) {
			// Report success if the job doesn't allow retries
			// even if actually the job has failed.
			$status = true;
		}

		return [
			'status'   => $status,
			'readonly' => $isReadonly,
			'message'  => $message
		];
	}

	/**
	 * @param array $jobEvent containing the job EventBus event.
	 * @return array containing the Job, status and potentially error message
	 */
	private function getJobFromParams( $jobEvent ) {
		if ( !isset( $jobEvent['type'] ) ) {
			return [
				'status'  => false,
				'message' => 'Job event type is not defined'
			];
		}

		$jobType = $jobEvent['type'];
		$params = $jobEvent['params'];

		try {
			$jobFactory = MediaWikiServices::getInstance()->getJobFactory();
			$job = $jobFactory->newJob( $jobType, $params );
		} catch ( Exception $e ) {
			return [
				'status'  => false,
				'message' => $e->getMessage()
			];
		}

		// @phan-suppress-next-line PhanImpossibleTypeComparison
		if ( $job === null ) {
			return [
				'status'  => false,
				'message' => 'Could not create a job from event'
			];
		}

		return [
			'status' => true,
			'job'    => $job
		];
	}

	/**
	 * Returns a singleton logger instance for all JobExecutor instances.
	 * Use like: self::logger()->info( $message )
	 * We use this so we don't have to check if the logger has been created
	 * before attempting to log a message.
	 * @return LoggerInterface
	 */
	private static function logger() {
		if ( !self::$logger ) {
			self::$logger = LoggerFactory::getInstance( 'JobExecutor' );
		}
		return self::$logger;
	}

	/**
	 * Returns a singleton config instance for all JobExecutor instances.
	 * Use like: self::config()->get( 'SomeConfigParameter' )
	 * @return Config
	 */
	private static function config() {
		if ( !self::$config ) {
			self::$config = MediaWikiServices::getInstance()->getMainConfig();
		}
		return self::$config;
	}

	/**
	 * Returns a singleton stats reporter instance for all JobExecutor instances.
	 * Use like self::stats()->getGauge()
	 * @return StatsFactory
	 */
	private static function stats() {
		if ( !self::$statsFactory ) {
			self::$statsFactory = MediaWikiServices::getInstance()->getStatsFactory();
		}
		return self::$statsFactory;
	}
}
