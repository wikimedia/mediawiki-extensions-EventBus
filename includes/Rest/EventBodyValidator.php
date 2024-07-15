<?php

namespace MediaWiki\Extension\EventBus\Rest;

use Exception;
use Job;
use MediaWiki\Extension\EventBus\EventBus;
use MediaWiki\Extension\EventBus\EventFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Rest\HttpException;
use Psr\Log\LoggerInterface;

/**
 * Validates the body
 */
class EventBodyValidator {

	/**
	 * @var string
	 */
	private $secretKey;

	/**
	 * @var LoggerInterface
	 */
	private $logger;

	public function __construct( $secretKey, LoggerInterface $logger ) {
		$this->secretKey = $secretKey;
		$this->logger = $logger;
	}

	public function validateEvent( array $event ): Job {
		// check that we have the needed components of the event
		if ( !isset( $event['database'] ) ||
			!isset( $event['type'] ) ||
			!isset( $event['params'] )
		) {
			$missingParams = [];
			if ( !isset( $event['database'] ) ) {
				$missingParams[] = 'database';
			}
			if ( !isset( $event['type'] ) ) {
				$missingParams[] = 'type';
			}
			if ( !isset( $event['params'] ) ) {
				$missingParams[] = 'params';
			}
			throw new HttpException( 'Invalid event received', 400, [ 'missing_params' => $missingParams ] );
		}

		if ( !isset( $event['mediawiki_signature'] ) ) {
			throw new HttpException( 'Missing mediawiki signature', 403 );
		}

		$signature = $event['mediawiki_signature'];
		unset( $event['mediawiki_signature'] );

		$serialized_event = EventBus::serializeEvents( $event );
		$expected_signature = EventFactory::getEventSignature(
			$serialized_event,
			$this->secretKey
		);

		$verified = is_string( $signature )
			&& hash_equals( $expected_signature, $signature );

		if ( !$verified ) {
			throw new HttpException( 'Invalid mediawiki signature', 403 );
		}

		// check if there are any base64-encoded parameters and if so decode them
		foreach ( $event['params'] as $key => &$value ) {
			if ( !is_string( $value ) ) {
				continue;
			}
			if ( preg_match( '/^data:application\/octet-stream;base64,([\s\S]+)$/', $value, $match ) ) {
				$value = base64_decode( $match[1], true );
				if ( $value === false ) {

					throw new HttpException(
						'Parameter base64_decode() failed',
						500,
						[
							'param_name'  => $key,
							'param_value' => $match[1]
						]
					);
				}
			}
		}
		unset( $value );

		return $this->getJobFromParams( $event );
	}

	/**
	 * @param array $jobEvent containing the job EventBus event
	 * @return Job|void
	 * @throws HttpException
	 */
	private function getJobFromParams( array $jobEvent ) {
		try {
			$jobFactory = MediaWikiServices::getInstance()->getJobFactory();
			$job = $jobFactory->newJob( $jobEvent['type'], $jobEvent['params'] );
		} catch ( Exception $e ) {
			$this->throwJobErrors( [
				'status'  => false,
				'error' => $e->getMessage(),
				'type' => $jobEvent['type']
			] );
		}

		if ( $job === null ) {
			$this->throwJobErrors( [
				'status'  => false,
				'error' => 'Could not create a job from event',
				'type' => $jobEvent['type']
			] );
		}

		return $job;
	}

	/**
	 * @param array $jobResults
	 * @throws HttpException
	 * @return never
	 */
	private function throwJobErrors( $jobResults ) {
		$this->logger->error( 'Failed creating job from description', [
			'job_type' => $jobResults['type'],
			'error' => $jobResults['error']
		] );

		throw new HttpException( "Failed creating job from description",
			400,
			[ 'error' => $jobResults['error'] ]
		);
	}
}
