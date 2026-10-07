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

/**
 * Response from a LAC artifact fetch.
 *
 * @unstable This class may change as we incorporate more LAC features.
 */
class LinkedArtifactResponse {

	private int $statusCode;
	private ?string $contentType;
	private string $body;

	public function __construct( int $statusCode, ?string $contentType, string $body = '', ) {
		$this->statusCode = $statusCode;
		$this->contentType = $contentType;
		$this->body = $body;
	}

	/**
	 * HTTP status of LAC response.
	 * @return int
	 */
	public function getStatusCode(): int {
		return $this->statusCode;
	}

	/**
	 * Content-Type header from LAC response.
	 * @return string|null
	 */
	public function getContentType(): ?string {
		return $this->contentType;
	}

	/**
	 * The LAC response body: the artifact content on success,
	 * otherwise whatever LAC returned (an RFC7807 problem object, for a 404).
	 */
	public function getBody(): string {
		return $this->body;
	}

	public function isSuccess(): bool {
		return $this->statusCode >= 200 && $this->statusCode < 300;
	}

	/**
	 * Whether LAC reported that it has no artifact: a 404 with an RFC7807
	 * `application/problem+json` body.
	 *
	 * For a revision artifact, LAC returns a 404 when the artifact name is not one of
	 * its configured caches. A retry cannot fix this. A cache miss is not a 404:
	 * without only-if-cached LAC computes the artifact, and with only-if-cached the
	 * miss is a 504, see {@link isNotCached()}.
	 */
	public function isNotFound(): bool {
		return $this->statusCode === 404 && $this->isProblem();
	}

	/**
	 * Whether LAC reported that it has no stored artifact for a request with
	 * `Cache-Control: only-if-cached`: a 504 with an RFC7807 `application/problem+json` body.
	 *
	 * An expected outcome rather than a failure. LAC did not compute the artifact.
	 * A 504 without a problem body is something else, e.g. a proxy timeout.
	 */
	public function isNotCached(): bool {
		return $this->statusCode === 504 && $this->isProblem();
	}

	private function isProblem(): bool {
		return $this->contentType !== null &&
			str_starts_with( $this->contentType, 'application/problem+json' );
	}
}
