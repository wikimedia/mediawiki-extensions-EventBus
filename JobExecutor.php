<?php

/**
 * Class JobExecutor.
 *
 * A massively simplified JobRunner with a solo purpose of
 * executing a Job
 */

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use Psr\Log\LoggerInterface;

class JobExecutor {

	/** @var LoggerInterface instance for all JobExecutor instances */
	private static $logger;

	/**
	 * @param array $jobEvent the job event
	 * @return array containing the response status and the message
	 */
	public function execute( $jobEvent ) {
		$jobCreateResult = $this->getJobFromParams( $jobEvent );

		if ( !$jobCreateResult['status'] ) {
			$this->logger()->error( $jobCreateResult['message'] );
			return $jobCreateResult;
		}

		$job = $jobCreateResult['job'];
		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();

		WebRequest::overrideRequestId( $job->getRequestId() );

		// Actually execute the job
		try {
			$fnameTrxOwner = get_class( $job ) . '::run';

			$lbFactory->beginMasterChanges( $fnameTrxOwner );
			$status = $job->run();
			$message = $job->getLastError();
			$lbFactory->commitMasterChanges( $fnameTrxOwner );

			if ( !$status ) {
				$this->logger()->error( $job->toString() . " error={$message}" );
			}

			// Important: this must be the last deferred update added (T100085, T154425)
			DeferredUpdates::addCallableUpdate( [ JobQueueGroup::class, 'pushLazyJobs' ] );
			// Run any deferred update tasks; doUpdates() manages transactions itself
			DeferredUpdates::doUpdates();
		} catch ( Exception $e ) {
			MWExceptionHandler::rollbackMasterChangesAndLog( $e );
			$status = false;
			$message = 'Error executing job: '
					   . $job->toString() . ' : '
					   . get_class( $e ) . ': ' . $e->getMessage();
			$this->logger()->error( $message );
		}

		// Always attempt to call teardown() even if Job throws exception.
		try {
			$job->teardown( $status );
		} catch ( Exception $e ) {
			$message = 'Error tearing down job: '
					   . $job->toString() . ' : '
					   . get_class( $e ) . ': ' . $e->getMessage();
			$this->logger()->error( $message );
		}

		// The JobRunner at this point makes some cleanups to prepare for
		// the next Job execution. However since we run one job at a time
		// we don't need that.

		return [
			'status'  => $status,
			'message' => $message
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

		if ( !isset( $jobEvent['page_title'] ) ) {
			return [
				'status'  => false,
				'message' => 'Job event page_title is not defined'
			];
		}

		$title = Title::newFromDBkey( $jobEvent['page_title'] );

		if ( is_null( $title ) ) {
			return [
				'status'  => false,
				'message' => 'Page ' . $jobEvent['page_title'] . ' does not exist'
			];
		}

		$job = Job::factory( $jobType, $title, $jobEvent['params'] );

		if ( is_null( $job ) ) {
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
	 * Use like: self::logger()->info( $mesage )
	 * We use this so we don't have to check if the logger has been created
	 * before attempting to log a message.
	 */
	private static function logger() {
		if ( !self::$logger ) {
			self::$logger = LoggerFactory::getInstance( 'JobExecutor' );
		}
		return self::$logger;
	}
}
