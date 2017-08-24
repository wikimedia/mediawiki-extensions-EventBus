<?php


class JobQueueEventBus extends JobQueue {

	private static function createJobEvent( IJobSpecification $job ) {
		global $wgDBname;

		$attrs = [
			'database' => $wgDBname,
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

		return EventBus::createEvent(
			EventBus::getArticleURL( $job->getTitle() ),
			'mediawiki.job.' . $job->getType(),
			$attrs
		);
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
			$item = self::createJobEvent( $job );
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
				EventBus::getInstance()->send( array_values( $events ) );
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