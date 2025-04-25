<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */

namespace MediaWiki\Extension\EventBus\Rest;

use LogicException;
use MediaWiki\Config\Config;
use MediaWiki\JobQueue\Job;
use MediaWiki\JobQueue\JobRunner;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\Validator\Validator;
use Psr\Log\LoggerInterface;
use Wikimedia\Rdbms\ReadOnlyMode;

class RunSingleJobHandler extends Handler {

	/**
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * @var Config
	 */
	private $config;

	/**
	 * @var JobRunner
	 */
	private $jobRunner;

	/**
	 * @var ReadOnlyMode
	 */
	private $readOnly;

	public function __construct(
		ReadOnlyMode $readOnlyMode,
		Config $config,
		JobRunner $jobRunner
	) {
		$this->readOnly = $readOnlyMode;
		$this->config = $config;
		$this->jobRunner = $jobRunner;
		$this->logger = LoggerFactory::getInstance( 'RunSingleJobHandler' );
	}

	public function validate( Validator $restValidator ) {
		if ( !$this->config->get( 'EventBusEnableRunJobAPI' ) ) {
			throw new HttpException(
				'Set $wgEventBusEnableRunJobAPI to true to enable the internal EventBus API',
				501 );
		}

		if ( $this->readOnly->isReadOnly() ) {
			throw new HttpException( "Wiki is in read-only mode.", 423 );
		}
		parent::validate( $restValidator );
	}

	/**
	 * @return array
	 * @throws HttpException
	 */
	public function execute() {
		$event = $this->getRequest()->getParsedBody();

		if ( $event === null ) {
			throw new LogicException( 'No parsed body found, should have failed in parseBodyData()' );
		}

		$job = $this->makeJob( $event );

		// execute the job
		$response = $this->executeJob( $job );
		if ( $response['status'] === true ) {
			return $response;
		} else {
			throw new HttpException( 'Internal Server Error', 500, [ 'error' => $response['error'] ] );
		}
	}

	/**
	 * @param Job $job
	 * @return array containing the Job, status and potentially error message
	 */
	private function executeJob( Job $job ) {
		$result = $this->jobRunner->executeJob( $job );

		if ( !$job->allowRetries() ) {
			// Report success if the job doesn't allow retries
			// even if actually the job has failed.
			$result['status'] = true;
		}

		return $result;
	}

	private function makeJob( array $event ): Job {
		$validator = new EventBodyValidator(
			$this->config->get( 'SecretKey' ),
			$this->logger
		);
		return $validator->validateEvent( $event );
	}

}
