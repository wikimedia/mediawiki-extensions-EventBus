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
use MediaWiki\Extension\EventBus\LinkedArtifacts\LinkedArtifactsPrefetcher;
use MediaWiki\JobQueue\Job;
use MediaWiki\JobQueue\JobSpecification;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\Settings\SettingsBuilder;
use Psr\Log\LoggerInterface;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use Wikimedia\Timestamp\TimestampException;
use Wikimedia\Timestamp\TimestampFormat;

/**
 * Prefetches one Linked Artifacts Cache (LAC) artifact via {@link LinkedArtifactsPrefetcher}.
 *
 * This job prefetches artifacts configured in $wgEventBusLinkedArtifacts.
 *
 * Each configured artifact has its own job type, see {@link getJobType()}.
 * Each artifact has its own queue (a Kafka topic at WMF)
 * and its own job runner settings. {@link onRegistration()} registers
 * the job types in $wgJobClasses.
 *
 * @unstable
 */
class LinkedArtifactPrefetchJob extends Job {

	/**
	 * Prefix of the job type of each artifact. See {@link getJobType()}.
	 */
	public const JOB_TYPE_PREFIX = 'linkedArtifactPrefetch';

	/**
	 * The $wgJobClasses ObjectFactory spec for the job type of each artifact.
	 */
	public const JOB_SPEC = [
		'class' => self::class,
		'needsPage' => false,
		'services' => [
			'EventBus.LinkedArtifactsPrefetcher',
			'EventBus.LinkedArtifactsConfig',
		],
	];

	/**
	 * Job params key for the artifact name. Used for logs, errors and metrics
	 * Also used to lookup some artifact's prefetch settings from config at runtime,
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
	 * Job params key for what triggered the prefetch. An array with the keys
	 * `is_reconciliation_request`, and `event_type`, `cause` and `dt` if they are known. Optional.
	 * It is part of job deduplication, because the RevisionLinkedArtifactPrefetchedEvent carries it.
	 * `dt` is the ISO 8601 time of the trigger with milliseconds.
	 */
	public const TRIGGER_PARAM = 'trigger';

	private readonly LoggerInterface $logger;

	public function __construct(
		array $params,
		private readonly LinkedArtifactsPrefetcher $prefetcher,
		private readonly LinkedArtifactsConfig $config
	) {
		// JobFactory sets the registered job type after construction. This sets the same type
		// when code constructs the job directly.
		parent::__construct( self::getJobType( (string)( $params[self::ARTIFACT_NAME_PARAM] ?? '' ) ), $params );
		$this->logger = LoggerFactory::getInstance( 'EventBus.LinkedArtifacts' );
	}

	/**
	 * The job type of the prefetch jobs of an artifact, e.g.
	 * `linkedArtifactPrefetch.revision_feature_counts`.
	 *
	 * @param string $artifactName
	 * @return string
	 */
	public static function getJobType( string $artifactName ): string {
		return self::JOB_TYPE_PREFIX . '.' . $artifactName;
	}

	/** @inheritDoc */
	public function run(): bool {
		$artifactName = (string)( $this->params[self::ARTIFACT_NAME_PARAM] ?? '' );
		$entityKind = (string)( $this->params[self::ENTITY_KIND_PARAM] ?? '' );

		$logContext = [
			'artifact_name' => $artifactName,
			'entity_kind' => $entityKind,
		];

		// Ensure that MediaWiki LinkedArtifacts integration is still enabled.
		if ( !$this->config->isEnabled() ) {
			$this->logger->info(
				'Ignoring LinkedArtifactsCache prefetch job for artifact {artifact_name}: '
					. 'LinkedArtifacts has been disabled since the job was enqueued',
				$logContext
			);
			return true;
		}

		// Ensure that prefetch is still enabled for this artifact.
		if ( !$this->config->isPrefetchEnabled( $artifactName ) ) {
			$this->logger->info(
				'Ignoring LinkedArtifactsCache prefetch job for artifact {artifact_name}: '
					. 'prefetch has been disabled for this artifact since the job was enqueued',
				$logContext
			);
			return true;
		}

		// If we need to support more entity kinds, add them here.
		switch ( $entityKind ) {
			case LinkedArtifactsConfig::REVISION_ENTITY_KIND:
				$response = $this->prefetchFromRevisionJobParams( $artifactName );
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

		// LAC has no artifact for this key. This is already logged by the client.
		// Return true to avoid retries.
		if ( $response->isNotFound() ) {
			return true;
		}

		// Happy case!
		if ( $response->isSuccess() ) {
			return true;
		}

		$this->setLastError(
			__METHOD__ . ": failed prefetching LAC artifact '$artifactName', "
				. "status {$response->getStatusCode()}"
		);
		return false;
	}

	/**
	 * Helper func that calls out to LinkedArtifactsPrefetcher using the revision identifiers in the job params.
	 *
	 * @param string $artifactName
	 * @return LinkedArtifactResponse|null Null if the job params have no valid revision identifiers.
	 */
	private function prefetchFromRevisionJobParams( string $artifactName ): ?LinkedArtifactResponse {
		$wikiId = (string)( $this->params[self::WIKI_ID_PARAM] ?? '' );
		$pageId = (int)( $this->params[self::PAGE_ID_PARAM] ?? 0 );
		$revisionId = (int)( $this->params[self::REVISION_ID_PARAM] ?? 0 );

		if ( $wikiId === '' || $pageId <= 0 || $revisionId <= 0 ) {
			$this->setLastError( __METHOD__ . ': job params carry no revision identifiers' );
			return null;
		}

		$trigger = $this->params[self::TRIGGER_PARAM] ?? [];

		return $this->prefetcher->prefetchRevisionArtifact(
			$artifactName,
			$wikiId,
			$pageId,
			$revisionId,
			(bool)( $trigger['is_reconciliation_request'] ?? false ),
			$trigger['event_type'] ?? null,
			$trigger['cause'] ?? null,
			$this->getTriggerTimestamp()
		);
	}

	/**
	 * The trigger time from the `dt` of the trigger job param.
	 *
	 * @return ConvertibleTimestamp|null Null if the job params have no valid trigger time,
	 *   e.g. for a job that was enqueued before the trigger time was added.
	 */
	private function getTriggerTimestamp(): ?ConvertibleTimestamp {
		$triggerDt = $this->params[self::TRIGGER_PARAM]['dt'] ?? null;
		// ConvertibleTimestamp makes the time now from an empty value.
		if ( !is_string( $triggerDt ) || $triggerDt === '' ) {
			return null;
		}
		try {
			return new ConvertibleTimestamp( $triggerDt );
		} catch ( TimestampException ) {
			return null;
		}
	}

	/**
	 * A JobSpecification to prefetch an artifact linked to a revision.
	 * Use this to submit a prefetch job with JobQueueGroup::lazyPush().
	 *
	 * This does not check config. Callers check that the artifact is configured and covers
	 * the page, e.g. with {@link LinkedArtifactsConfig::appliesToPage()}.
	 *
	 * The trigger describes what caused the prefetch. Listeners of
	 * RevisionLinkedArtifactPrefetchedEvent use it to select the prefetches that they handle.
	 * Code that is not a DomainEvent listener can set its own $triggerEventType, e.g. a name
	 * for a reader request.
	 *
	 * @param string $artifactName
	 * @param string $wikiId The wiki ID of the page. Not false: resolve the local wiki first,
	 *   e.g. with {@link LinkedArtifactsConfig::resolveWikiId()}.
	 * @param int $pageId
	 * @param int $revisionId
	 * @param bool $isReconciliationRequest If true, LAC will consult the lambda, even if
	 *   the artifact is already cached. Use this to refresh an artifact that might be stale.
	 * @param string|null $triggerEventType The upstream event that caused this prefetch, e.g. the DomainEvent type.
	 * @param string|null $triggerCause The description of the upstream event cause, e.g. `edit`.
	 * @param ConvertibleTimestamp|null $triggerTimestamp When the trigger occurred.
	 *   It is stored as `trigger.dt`: ISO 8601 in UTC, with milliseconds, e.g. `2026-10-21T10:00:00.123Z`.
	 *   The `prefetch_trigger_to_response_duration_seconds` metric measures from this time.
	 * @return JobSpecification
	 */
	public static function newRevisionJobSpec(
		string $artifactName,
		string $wikiId,
		int $pageId,
		int $revisionId,
		bool $isReconciliationRequest = false,
		?string $triggerEventType = null,
		?string $triggerCause = null,
		?ConvertibleTimestamp $triggerTimestamp = null
	): JobSpecification {
		$triggerDt = null;
		if ( $triggerTimestamp ) {
			// TimestampFormat::ISO_8601 has whole seconds only, so format with milliseconds.
			// getTimestamp() converts to UTC. Do not change the timezone of $triggerTimestamp itself.
			$utcTimestamp = new ConvertibleTimestamp( $triggerTimestamp->getTimestamp( TimestampFormat::UNIX_MICRO ) );
			$triggerDt = $utcTimestamp->format( 'Y-m-d\TH:i:s.v\Z' );
		}

		$trigger = [
			'event_type' => $triggerEventType,
			'cause' => $triggerCause,
			'is_reconciliation_request' => $isReconciliationRequest,
			'dt' => $triggerDt,
		];
		// Omit unknown trigger fields. Do not set them to null.
		// See https://wikitech.wikimedia.org/wiki/Event_Platform/Schemas/Guidelines#No_union_types_/_No_null_values
		$trigger = array_filter( $trigger, static fn ( $value ): bool => $value !== null );

		return new JobSpecification(
			self::getJobType( $artifactName ),
			[
				self::ARTIFACT_NAME_PARAM => $artifactName,
				self::ENTITY_KIND_PARAM => LinkedArtifactsConfig::REVISION_ENTITY_KIND,
				self::WIKI_ID_PARAM => $wikiId,
				self::PAGE_ID_PARAM => $pageId,
				self::REVISION_ID_PARAM => $revisionId,
				self::TRIGGER_PARAM => $trigger,
			],
			[
				'removeDuplicates' => true,
				// NOTE: we explicitly choose not to ignore TRIGGER_PARAM for
				// job deduplication. Jobs with different TRIGGER_PARAM all run, because
				// each job dispatches a RevisionLinkedArtifactPrefetchedEvent with its trigger,
				// e.g. for both 'reconciliation requests' and regular event triggers.
				// This costs little: only a reconciliation request makes LAC compute the
				// artifact again. Other prefetches get the artifact that LAC has cached.
				// The trigger `dt` is also part of deduplication. Thus jobs from two
				// DomainEvents for the same revision both run.
			]
		);
	}

	/**
	 * Extension registration callback. Registers a job type in $wgJobClasses for each artifact
	 * in EventBusLinkedArtifacts config, see {@link getJobType()}.
	 *
	 * Artifacts that have prefetch.enabled = false still have a job type.
	 * This allows jobs that are queued before prefetch is disabled to still run,
	 * and drop themselves gracefully.
	 *
	 * This must not throw: an exception here stops MediaWiki setup for every request.
	 * It does not validate the config. LinkedArtifactsConfig does that.
	 *
	 * @param array $info Extension credits.
	 * @param SettingsBuilder $settings
	 */
	public static function onRegistration( array $info, SettingsBuilder $settings ): void {
		$config = $settings->getConfig();
		$linkedArtifacts = $config->get( 'EventBusLinkedArtifacts' );
		$artifacts = is_array( $linkedArtifacts ) ? ( $linkedArtifacts['artifacts'] ?? null ) : null;
		if ( !is_array( $artifacts ) || !$artifacts ) {
			return;
		}

		$jobClasses = [];
		foreach ( array_keys( $artifacts ) as $artifactName ) {
			$jobClasses[self::getJobType( (string)$artifactName )] = self::JOB_SPEC;
		}

		// Job types that the site configures explicitly win.
		$settings->overrideConfigValue(
			MainConfigNames::JobClasses,
			$config->get( MainConfigNames::JobClasses ) + $jobClasses
		);
	}
}
