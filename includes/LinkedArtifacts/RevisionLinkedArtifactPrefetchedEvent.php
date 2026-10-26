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

namespace MediaWiki\Extension\EventBus\LinkedArtifacts;

use MediaWiki\DomainEvent\DomainEvent;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Dispatched after MediaWiki prefetches an artifact linked to a revision from the
 * Linked Artifacts Cache (LAC). It carries the artifact body, so listeners can use it
 * without another LAC request.
 *
 * A 'prefetch' is an intentional fetch ahead of read time use cases,
 * to ensure that the cache is populated for this revision.
 *
 * MediaWiki does not compute the artifact. The artifact can be:
 *  - computed by LAC for this prefetch, on a cache miss;
 *  - computed again by LAC, for a reconciliation request;
 *  - already cached, e.g. because a reader or another job fetched it first.
 *
 * The event timestamp is when MediaWiki prefetched the artifact, i.e. when LAC responded.
 * {@link getArtifactComputeTimestamp()} is when LAC computed the artifact. For an artifact
 * that was already cached, the compute time is earlier than the prefetch.
 * {@link getTriggerTimestamp()} is when the trigger occurred, e.g. the edit.
 *
 * Only prefetches that MediaWiki triggers dispatch this event. Reader fetches, and pages
 * outside an artifact's configured coverage, do not.
 *
 * isReconciliationRequest() comes from the upstream trigger. If a reconciliation request
 * (e.g. a null edit) triggered the prefetch, this event is also a reconciliation event.
 *
 * This event is dispatched for every artifact whose entity is `revision`.
 * Listeners should filter on {@link getArtifactName()}.
 * NOTE: it may be technically possible to declare an event with a more dynamic type,
 *       e.g. ArtifactNamePrefetched, based on $artifactName.  If this seems useful
 *       one day, we might consider it.  This idea may make listener interfaces a bit funky.
 *
 * @unstable
 */
class RevisionLinkedArtifactPrefetchedEvent extends DomainEvent {

	public const TYPE = 'RevisionLinkedArtifactPrefetched';

	/**
	 * @param string $artifactName The LAC cache artifact name.
	 * @param string $wikiId The wiki ID in the LAC URI.
	 * @param int $pageId
	 * @param int $revisionId
	 * @param string|null $contentType Content type of the artifact, from LAC.
	 * @param string $body The artifact content, from LAC.
	 * @param ConvertibleTimestamp $timestamp When MediaWiki prefetched the artifact.
	 *   This is the event timestamp, see {@link DomainEvent::getEventTimestamp()}.
	 * @param ConvertibleTimestamp|null $artifactComputeTimestamp When LAC computed the
	 *   artifact, from the LAC Last-Modified header. Null if LAC did not send it.
	 * @param bool $isReconciliationRequest Whether the trigger was a reconciliation request,
	 *   e.g. a null edit. This event then is also a reconciliation event, see
	 *   {@link DomainEvent::isReconciliationRequest()}.
	 * @param string|null $triggerEventType The DomainEvent type that triggered the prefetch,
	 *   e.g. `PageLatestRevisionChanged`. Null if no DomainEvent triggered it.
	 * @param string|null $triggerCause The cause of the triggering event, e.g. `edit`.
	 * @param ConvertibleTimestamp|null $triggerTimestamp When the trigger occurred.
	 *   Null if unknown.
	 */
	public function __construct(
		private readonly string $artifactName,
		private readonly string $wikiId,
		private readonly int $pageId,
		private readonly int $revisionId,
		private readonly ?string $contentType,
		private readonly string $body,
		ConvertibleTimestamp $timestamp,
		private readonly ?ConvertibleTimestamp $artifactComputeTimestamp,
		bool $isReconciliationRequest = false,
		private readonly ?string $triggerEventType = null,
		private readonly ?string $triggerCause = null,
		private readonly ?ConvertibleTimestamp $triggerTimestamp = null
	) {
		parent::__construct( $timestamp, $isReconciliationRequest );
		$this->declareEventType( self::TYPE );
	}

	/**
	 * When LAC computed the artifact. For an artifact that was already cached, this is
	 * earlier than the event timestamp.
	 *
	 * @return ConvertibleTimestamp|null Null if LAC did not send it.
	 */
	public function getArtifactComputeTimestamp(): ?ConvertibleTimestamp {
		return $this->artifactComputeTimestamp;
	}

	public function getArtifactName(): string {
		return $this->artifactName;
	}

	public function getWikiId(): string {
		return $this->wikiId;
	}

	public function getPageId(): int {
		return $this->pageId;
	}

	public function getRevisionId(): int {
		return $this->revisionId;
	}

	/**
	 * The content-type of the artifact body as reported by the lambda that computed it.
	 * @return string|null
	 */
	public function getContentType(): ?string {
		return $this->contentType;
	}

	/**
	 * The artifact body, as LAC returned it.
	 */
	public function getBody(): string {
		return $this->body;
	}

	/**
	 * The artifact body as decoded from JSON. See {@link LinkedArtifactResponse::decodeJsonBody()}.
	 *
	 * @return array|null The decoded JSON object or array, or null if the content is not one.
	 */
	public function getJsonBody(): ?array {
		return LinkedArtifactResponse::decodeJsonBody( $this->body );
	}

	/**
	 * The event type that triggered the prefetch, or null if none did.
	 */
	public function getTriggerEventType(): ?string {
		return $this->triggerEventType;
	}

	/**
	 * The cause of the triggering event, or null if unknown.
	 */
	public function getTriggerCause(): ?string {
		return $this->triggerCause;
	}

	/**
	 * When the trigger that caused the prefetch occurred, or null if unknown.
	 *
	 * @return ConvertibleTimestamp|null
	 */
	public function getTriggerTimestamp(): ?ConvertibleTimestamp {
		return $this->triggerTimestamp;
	}

	/**
	 * Describes the artifact for log and error messages, e.g.
	 * "revision_feature_counts artifact linked to enwiki page 42 revision 99". It does not include the body.
	 */
	public function artifactDescription(): string {
		return "{$this->artifactName} artifact linked to {$this->wikiId} page {$this->pageId} " .
			"revision {$this->revisionId}";
	}
}
