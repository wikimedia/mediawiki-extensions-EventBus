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
 *
 * @file
 */

declare( strict_types=1 );

namespace MediaWiki\Extension\EventBus\LinkedArtifacts\Job;

use MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactsClient;
use MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactsConfig;
use MediaWiki\JobQueue\Job;
use MediaWiki\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * Precomputes one Linked Artifacts Cache (LAC) artifact: No-Cache fetches it so LAC
 * consults the artifact's lambda and stores the result.
 *
 * This job is artifact agnostic, and simply fetches the URI path it is constructed with.
 * @unstable
 */
class LinkedArtifactPrecomputeJob extends Job {

	public const JOB_NAME = 'linkedArtifactPrecompute';

	/**
	 * Job params key for the LAC artifact URI path to fetch.
	 */
	public const ARTIFACT_URI_PARAM = 'artifact_uri';

	/**
	 * Job params key for the artifact name. Used for logs, errors and metrics
	 * Also used to select the artifact's precompute timeout from config at runtime,
	 * in case timeouts need to be changed via config after jobs are enqueued.
	 */
	public const ARTIFACT_NAME_PARAM = 'artifact_name';

	private LinkedArtifactsClient $client;
	private LinkedArtifactsConfig $config;
	private LoggerInterface $logger;

	public function __construct(
		array $params,
		LinkedArtifactsClient $client,
		LinkedArtifactsConfig $config
	) {
		parent::__construct( self::JOB_NAME, $params );
		$this->client = $client;
		$this->config = $config;
		$this->logger = LoggerFactory::getInstance( 'EventBus.LinkedArtifacts' );
	}

	/** @inheritDoc */
	public function run(): bool {
		$artifactUri = (string)( $this->params[self::ARTIFACT_URI_PARAM] ?? '' );
		$artifactName = (string)( $this->params[self::ARTIFACT_NAME_PARAM] ?? '' );

		$logContext = [
			'artifact_name' => $artifactName,
			'artifact_uri' => $artifactUri,
		];

		// Ensure that LinkedArtifacts integration is still enabled.
		if ( !$this->config->isEnabled() ) {
			$this->logger->info(
				'Ignoring LinkedArtifactsCache precompute job for artifact {artifact_name}: '
					. 'LinkedArtifacts has been disabled since the job was enqueued',
				$logContext
			);
			return true;
		}

		// Ensure that precompute is still enabled for this artifact.
		if ( !$this->config->isPrecomputeEnabled( $artifactName ) ) {
			$this->logger->info(
				'Ignoring LinkedArtifactsCache precompute job for artifact {artifact_name}: '
					. 'precompute has been disabled for this artifact since the job was enqueued',
				$logContext
			);
			return true;
		}

		if ( $artifactUri === '' ) {
			// Nothing to request. Retrying cannot fix malformed params.
			$this->setLastError( __METHOD__ . ': job params carry no LAC path' );
			return true;
		}

		// TODO: rather than calling fetch here, we will probably want to call
		//       a specific precompute method that will handle emitting LACComputed DomainEvents.
		$response = $this->client->fetch(
			$artifactUri,
			$this->config->getPrecomputeTimeoutMs( $artifactName ),
			LinkedArtifactsClient::CACHE_CONTROL_NO_CACHE
		);

		if ( $response->isSuccess() ) {
			return true;
			// TODO: in future patch, emit new LAC precomputed DomainEvent.
			// TODO: we will probably need more job params to do this well,
			//       including some info about what triggered this precompute
			//       (which DomainEvent, etc.)
		}

		// LAC has no artifact for this key. This is already logged by the client.
		// Return true to avoid retries.
		if ( $response->isNotFound() ) {
			return true;
		}

		$this->setLastError(
			__METHOD__ . ": failed precomputing LAC artifact '$artifactName', "
				. "status {$response->getStatusCode()}"
		);

		return false;
	}
}
