<?php

class JobQueueEventBus extends JobQueue {
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
		$eventBus = EventBus::getInstance( 'eventbus' );
		$eventFactory = $eventBus->getFactory();

		foreach ( $jobs as $job ) {
			$item = $eventFactory->createJobEvent( $this->getDomain(), $job );
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

		$result = $eventBus->send( array_values( $events ), EventBus::TYPE_JOB );

		if ( is_string( $result ) ) {
			throw new JobQueueError( "Could not enqueue jobs: $result" );
		}
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

	 * @param RunnableJob $job
	 */
	protected function doAck( RunnableJob $job ) {
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
