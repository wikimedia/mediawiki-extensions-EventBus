<?php

/**
 * A massively simplified JobRunner with a solo purpose of
 * executing a Job
 */

namespace MediaWiki\Extension\EventBus;

use Config;
use DeferredUpdates;
use Exception;
use Job;
use Liuggio\StatsdClient\Factory\StatsdDataFactoryInterface;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MWExceptionHandler;
use Psr\Log\LoggerInterface;
use WebRequest;
use Wikimedia\Rdbms\DBError;
use Wikimedia\Rdbms\ILBFactory;
use Wikimedia\ScopedCallback;

class JobExecutor {

	/** @var LoggerInterface instance for all JobExecutor instances */
	private static $logger;

	/** @var StatsdDataFactoryInterface instance
	 * for all JobExecutor instances
	 */
	private static $stats;

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

		$job = $jobCreateResult['job'];
		$this->logger()->debug( 'Beginning job execution', [
			'job' => $job->toString(),
			'job_type' => $job->getType()
		] );

		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();

		WebRequest::overrideRequestId( $job->getRequestId() );
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
			$this->commitPrimaryChanges( $lbFactory, $fnameTrxOwner );

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
			MWExceptionHandler::rollbackPrimaryChangesAndLog( $e );
		} catch ( Exception $e ) {
			MWExceptionHandler::rollbackPrimaryChangesAndLog( $e );
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
		self::stats()->timing(
			"jobexecutor.{$job->getType()}.exec",
			$jobDuration
		);
		$this->logger()->info( 'Finished job execution',
			[
				'job' => $job->toString(),
				'job_type' => $job->getType(),
				'job_status' => $status,
				'job_duration' => $jobDuration
			]
		);

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
			$job = Job::factory( $jobType, $params );
		} catch ( Exception $e ) {
			return [
				'status'  => false,
				'message' => $e->getMessage()
			];
		}

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
	 * Use like self::stats()->increment( $key )
	 * @return StatsdDataFactoryInterface
	 */
	private static function stats() {
		if ( !self::$stats ) {
			self::$stats = MediaWikiServices::getInstance()->getStatsdDataFactory();
		}
		return self::$stats;
	}

	/**
	 * Issue a commit on all primary DBs who are currently in a transaction and have
	 * made changes to the database. It also supports sometimes waiting for the
	 * local wiki's replica DBs to catch up. See the documentation for
	 * $wgJobSerialCommitThreshold for more.
	 *
	 * The implementation resembles the JobRunner::commitPrimaryChanges and will
	 * be merged with it once the kafka-based JobQueue will be moved to use
	 * the SpecialRunSingleJob and moved to the core.
	 *
	 * @param ILBFactory $lbFactory
	 * @param string $fnameTrxOwner
	 * @throws DBError
	 */
	private function commitPrimaryChanges( ILBFactory $lbFactory, $fnameTrxOwner ) {
		$syncThreshold = $this->config()->get( 'JobSerialCommitThreshold' );
		$maxWriteDuration = $this->config()->get( 'MaxJobDBWriteDuration' );

		$lb = $lbFactory->getMainLB();
		if ( $syncThreshold !== false && $lb->getServerCount() > 1 ) {
			// Generally, there is one primary connection to the local DB
			$dbwSerial = $lb->getAnyOpenConnection( $lb->getWriterIndex() );
			// We need natively blocking fast locks
			if ( $dbwSerial && $dbwSerial->namedLocksEnqueue() ) {
				$time = $dbwSerial->pendingWriteQueryDuration( $dbwSerial::ESTIMATE_DB_APPLY );
				if ( $time < $syncThreshold ) {
					$dbwSerial = false;
				}
			} else {
				$dbwSerial = false;
			}
		} else {
			// There are no replica DBs or writes are all to foreign DB (we don't handle that)
			$dbwSerial = false;
		}

		if ( !$dbwSerial ) {
			$lbFactory->commitPrimaryChanges(
				$fnameTrxOwner,
				// Abort if any transaction was too big
				[ 'maxWriteDuration' => $maxWriteDuration ]
			);

			return;
		}

		// Wait for an exclusive lock to commit
		if ( !$dbwSerial->lock( 'jobexecutor-serial-commit', $fnameTrxOwner, 30 ) ) {
			// This will trigger a rollback in the main loop
			throw new DBError( $dbwSerial, "Timed out waiting on commit queue." );
		}
		$unlocker = new ScopedCallback( static function () use ( $dbwSerial, $fnameTrxOwner ) {
			$dbwSerial->unlock( 'jobexecutor-serial-commit', $fnameTrxOwner );
		} );

		// Wait for the replica DBs to catch up
		$pos = $lb->getPrimaryPos();
		if ( $pos ) {
			$lb->waitForAll( $pos );
		}

		// Actually commit the DB primary changes
		$lbFactory->commitPrimaryChanges(
			$fnameTrxOwner,
			// Abort if any transaction was too big
			[ 'maxWriteDuration' => $maxWriteDuration ]
		);
		ScopedCallback::consume( $unlocker );
	}
}
