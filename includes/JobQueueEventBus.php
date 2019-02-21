<?php


use Firebase\JWT\JWT;
use MediaWiki\MediaWikiServices;

class JobQueueEventBus extends JobQueue {

	private function createJobEvent( IJobSpecification $job ) {
		global $wgDBname;

		$attrs = [
			'database' => $this->getWiki() ?: $wgDBname,
			'type' => $job->getType(),
			'page_namespace' => $job->getTitle()->getNamespace(),
			'page_title' => $job->getTitle()->getPrefixedDBkey()
		];

		if ( !is_null( $job->getReleaseTimestamp() ) ) {
			$attrs['delay_until'] = $job->getReleaseTimestamp();
		}

		if ( $job->ignoreDuplicates() ) {
			$attrs['sha1'] = sha1( serialize( $job->getDeduplicationInfo() ) );
		}

		$params = $job->getParams();

		if ( isset( $params['rootJobTimestamp'] ) && isset( $params['rootJobSignature'] ) ) {
			$attrs['root_event'] = [
				'signature' => $params['rootJobSignature'],
				'dt'        => wfTimestamp( TS_ISO_8601, $params['rootJobTimestamp'] )
			];
		}

		$attrs['params'] = $params;

		$event = EventFactory::createEvent(
			EventBus::getArticleURL( $job->getTitle() ),
			'mediawiki.job.' . $job->getType(),
			$attrs,
			$this->getWiki()
		);

		// If the job provides a requestId - use it, otherwise try to get one ourselves
		if ( isset( $event['params']['requestId'] ) ) {
			$event['meta']['request_id'] = $event['params']['requestId'];
		} else {
			$event['meta']['request_id'] = WebRequest::getRequestId();
		}

		// Sign the event with mediawiki secret key
		$serialized_event = EventBus::serializeEvents( $event );
		if ( is_null( $serialized_event ) ) {
			return null;
		}
		$event['mediawiki_signature'] = self::getEventSignature( $serialized_event );

		return $event;
	}

	/**
	 * Creates a cryptographic signature for the event
	 *
	 * @param string $event the serialized event to sign
	 * @return string
	 */
	private static function getEventSignature( $event ) {
		$secret = MediaWikiServices::getInstance()->getMainConfig()->get( 'SecretKey' );
		return hash( 'sha256', JWT::sign( $event, $secret ) );
	}

	/**
	 * Get the allowed queue orders for configuration validation
	 *
	 * @return array Subset of (random, timestamp, fifo, undefined)
	 */
	protected function supportedOrders() {
		return [ 'fifo' ];
	}

	/**
	 * Find out if delayed jobs are supported for configuration validation
	 *
	 * @return bool Whether delayed jobs are supported
	 */
	protected function supportsDelayedJobs() {
		return true;
	}

	/**
	 * Get the default queue order to use if configuration does not specify one
	 *
	 * @return string One of (random, timestamp, fifo, undefined)
	 */
	protected function optimalOrder() {
		return 'fifo';
	}

	/**
	 * @see JobQueue::isEmpty()
	 * @return bool
	 */
	protected function doIsEmpty() {
		// not implemented
		return false;
	}

	/**
	 * @see JobQueue::getSize()
	 * @return int
	 */
	protected function doGetSize() {
		// not implemented
		return 0;
	}

	/**
	 * @see JobQueue::getAcquiredCount()
	 * @return int
	 */
	protected function doGetAcquiredCount() {
		// not implemented
		return 0;
	}

	/**
	 * @see JobQueue::batchPush()
	 * @param IJobSpecification[] $jobs
	 * @param int $flags
	 */
	protected function doBatchPush( array $jobs, $flags ) {
		// Convert the jobs into field maps (de-duplicated against each other)
		// (job ID => job fields map)
		$events = [];
		foreach ( $jobs as $job ) {
			$item = $this->createJobEvent( $job );
			if ( is_null( $item ) ) {
				continue;
			}
			// hash identifier => de-duplicate
			if ( isset( $item['sha1'] ) ) {
				$events[$item['sha1']] = $item;
			} else {
				$events[$item['meta']['id']] = $item;
			}
		}

		if ( !count( $events ) ) {
			// nothing to do
			return;
		}

		DeferredUpdates::addCallableUpdate(
			function () use ( $events ) {
				EventBus::getInstance()->send( array_values( $events ), EventBus::TYPE_JOB );
			}
		);
	}

	/**
	 * @see JobQueue::pop()
	 * @return Job|bool
	 */
	protected function doPop() {
		// not implemented
		return false;
	}

	/**
	 * @see JobQueue::ack()
	 * @param Job $job
	 */
	protected function doAck( Job $job ) {
		// not implemented
	}

	/**
	 * Get an iterator to traverse over all available jobs in this queue.
	 * This does not include jobs that are currently acquired or delayed.
	 * Note: results may be stale if the queue is concurrently modified.
	 *
	 * @return Iterator
	 * @throws JobQueueError
	 */
	public function getAllQueuedJobs() {
		// not implemented
		return new ArrayIterator( [] );
	}
}
