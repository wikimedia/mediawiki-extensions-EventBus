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
 * Dispatched after MediaWiki successfully precomputes a Linked Artifacts Cache (LAC)
 * artifact linked to a revision. It carries the artifact body, so listeners can use it
 * without another LAC request.
 *
 * MediaWiki does not compute the artifact. A precompute is a no-cache fetch from LAC,
 * ideally before a reader asks for the artifact. The cache miss makes LAC compute the artifact
 * with its lambda.
 *
 * This event is dispatched for every artifact whose entity is `revision`.
 * Listeners filter on {@link getArtifactName()}.
 *
 * Only precomputes that MediaWiki triggers dispatch this event. Artifacts that LAC computes
 * on a read miss, and pages outside an artifact's configured coverage, do not.
 *
 * isReconciliationRequest() comes from the upstream trigger. If a reconciliation request
 * (e.g. a null edit) triggered the precompute, this event is also a reconciliation event.
 *
 * @unstable
 */
class RevisionLinkedArtifactPrecomputedEvent extends DomainEvent {

	public const TYPE = 'RevisionLinkedArtifactPrecomputed';

	/**
	 * @param string $artifactName The LAC cache artifact name.
	 * @param string $wikiId The wiki ID in the LAC URI.
	 * @param int $pageId
	 * @param int $revisionId
	 * @param string|null $contentType Content type of the artifact, from LAC.
	 * @param string $body The artifact content, from LAC.
	 * @param ConvertibleTimestamp $timestamp When LAC computed the artifact.
	 *   This is the event timestamp, see {@link DomainEvent::getEventTimestamp()}.
	 * @param string|null $triggerEventType The DomainEvent type that triggered the precompute,
	 *   e.g. `PageLatestRevisionChanged`. Null if no DomainEvent triggered it.
	 * @param string|null $triggerCause The cause of the triggering event, e.g. `edit`.
	 * @param bool $isReconciliationRequest Whether the trigger was a reconciliation request,
	 *   e.g. a null edit. This event then is also a reconciliation event, see
	 *   {@link DomainEvent::isReconciliationRequest()}.
	 */
	public function __construct(
		private readonly string $artifactName,
		private readonly string $wikiId,
		private readonly int $pageId,
		private readonly int $revisionId,
		private readonly ?string $contentType,
		private readonly string $body,
		ConvertibleTimestamp $timestamp,
		private readonly ?string $triggerEventType = null,
		private readonly ?string $triggerCause = null,
		bool $isReconciliationRequest = false
	) {
		parent::__construct( $timestamp, $isReconciliationRequest );
		$this->declareEventType( self::TYPE );
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
	 * The DomainEvent type that triggered the precompute, or null if none did.
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
	 * Describes the artifact for log and error messages, e.g.
	 * "revision_feature_counts artifact linked to enwiki page 42 revision 99". It does not include the body.
	 */
	public function artifactDescription(): string {
		return "{$this->artifactName} artifact linked to {$this->wikiId} page {$this->pageId} " .
			"revision {$this->revisionId}";
	}
}
