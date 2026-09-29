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

use MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactResponse;
use MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactsConfig;
use MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactsPrecompute;
use MediaWiki\JobQueue\Job;
use MediaWiki\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * Precomputes one Linked Artifacts Cache (LAC) artifact via {@link LinkedArtifactsPrecompute}.
 *
 * This job is respsonsible for triggering precompute for all artifacts configured in
 * $wgEventBusLinkedArtifacts.  This is nice, because users don't have to implement a new Job class
 * just to configure artifact precompute.  However, it also means that all artifact precompute jobs
 * will use the same queue (Kafka topic at WMF). It would be nice to consider allowing JobQueue to
 * support jobs specifying a subqueue (topic) even if they use the same Job class implementation.
 *
 * @unstable
 */
class LinkedArtifactPrecomputeJob extends Job {

	public const JOB_NAME = 'linkedArtifactPrecompute';

	/**
	 * Job params key for the artifact name. Used for logs, errors and metrics
	 * Also used to lookup some artifact's precompute settings from config at runtime,
	 * in case e.g. timeouts need to be changed via config after jobs are enqueued.
	 */
	public const ARTIFACT_NAME_PARAM = 'artifact_name';

	/**
	 * Job params key for the entity kind the artifact is linked to,
	 * e.g. {@link LinkedArtifactsConfig::REVISION_ENTITY_KIND}.
	 */
	public const ENTITY_KIND_PARAM = 'entity_kind';

	/**
	 * Job params keys for the revision entity identifiers.
	 */
	public const WIKI_ID_PARAM = 'wiki_id';
	public const PAGE_ID_PARAM = 'page_id';
	public const REVISION_ID_PARAM = 'revision_id';

	/**
	 * Job params key for what triggered the precompute. An array with the keys
	 * `event_type`, `cause` and `is_reconciliation_request`. Optional.
	 * It is part of job deduplication, because the precomputed DomainEvent carries it.
	 */
	public const TRIGGER_PARAM = 'trigger';

	private readonly LoggerInterface $logger;

	public function __construct(
		array $params,
		private readonly LinkedArtifactsPrecompute $precompute,
		private readonly LinkedArtifactsConfig $config
	) {
		parent::__construct( self::JOB_NAME, $params );
		$this->logger = LoggerFactory::getInstance( 'EventBus.LinkedArtifacts' );
	}

	/** @inheritDoc */
	public function run(): bool {
		$artifactName = (string)( $this->params[self::ARTIFACT_NAME_PARAM] ?? '' );
		$entityKind = (string)( $this->params[self::ENTITY_KIND_PARAM] ?? '' );

		$logContext = [
			'artifact_name' => $artifactName,
			'entity_kind' => $entityKind,
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

		// If we need to support more entity kinds, add them here.
		switch ( $entityKind ) {
			case LinkedArtifactsConfig::REVISION_ENTITY_KIND:
				$response = $this->precomputeFromRevisionJobParams( $artifactName );
				break;
			default:
				// Retrying cannot fix malformed params.
				$this->setLastError(
					__METHOD__ . ": unsupported " . self::ENTITY_KIND_PARAM . " '$entityKind' in job params"
				);
				return true;
		}

		// Null means that the job params are malformed, so no LAC request was made.
		// Return true to avoid retries.
		if ( $response === null ) {
			return true;
		}

		// Happy case!
		if ( $response->isSuccess() ) {
			return true;
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

	/**
	 * Helper func that calls out to LinkedArtifactsPrecompute using the revision identifiers in the job params.
	 *
	 * @param string $artifactName
	 * @return LinkedArtifactResponse|null Null if the job params have no valid revision identifiers.
	 */
	private function precomputeFromRevisionJobParams( string $artifactName ): ?LinkedArtifactResponse {
		$wikiId = (string)( $this->params[self::WIKI_ID_PARAM] ?? '' );
		$pageId = (int)( $this->params[self::PAGE_ID_PARAM] ?? 0 );
		$revisionId = (int)( $this->params[self::REVISION_ID_PARAM] ?? 0 );

		if ( $wikiId === '' || $pageId <= 0 || $revisionId <= 0 ) {
			$this->setLastError( __METHOD__ . ': job params carry no revision identifiers' );
			return null;
		}

		$trigger = $this->params[self::TRIGGER_PARAM] ?? [];

		return $this->precompute->precomputeRevisionArtifact(
			$artifactName,
			$wikiId,
			$pageId,
			$revisionId,
			$trigger['event_type'] ?? null,
			$trigger['cause'] ?? null,
			(bool)( $trigger['is_reconciliation_request'] ?? false )
		);
	}
}
