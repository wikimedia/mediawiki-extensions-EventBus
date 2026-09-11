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

use Psr\Log\LoggerInterface;
use Wikimedia\Http\MultiHttpClient;

/**
 * Locates and fetches artifacts in the Hoarde "Linked Artifacts Cache" (LAC).
 *
 * Used by the {@link LinkedArtifactPrecomputeJob} as well
 * as for regular readers fetching artifacts.
 *
 *  - {@link fetch()} GETs a LAC URI and reports the outcome.
 *    It knows nothing about artifacts or entities, just
 *    fetches from LAC.
 *  - Per-entity methods know LAC's route shapes and build those URIs.
 *
 * @see https://gitlab.wikimedia.org/repos/sre/hoarde/-/blob/main/API.md
 * @unstable
 */
class LinkedArtifactsFetcher {

	/**
	 * The `entity` value in $wgEventBusLinkedArtifacts served by the revision methods.
	 */
	public const REVISION_ENTITY = 'revision';

	private const REVISION_URI_FORMAT = '/revisions/v1/%s/%s/%s/%s';

	private LinkedArtifactsConfig $config;
	private MultiHttpClient $http;
	private LoggerInterface $logger;

	public function __construct(
		LinkedArtifactsConfig $config,
		MultiHttpClient $http,
		LoggerInterface $logger
	) {
		$this->config = $config;
		$this->http = $http;
		$this->logger = $logger;
	}

	/**
	 * Whether an artifact is configured to be available for the page.
	 *
	 * Readers should gate on this before fetching.
	 *
	 * @param string $artifactName
	 * @param string|false $wikiId The page's wiki ID; false for the local wiki.
	 * @param int $pageId
	 * @param int $namespace
	 * @return bool
	 */
	public function coversPage(
		string $artifactName,
		string|false $wikiId,
		int $pageId,
		int $namespace,
	): bool {
		return $this->config->isEnabled()
			&& $this->config->appliesToPage( $artifactName, $wikiId, $pageId, $namespace );
	}

	/**
	 * The LAC URI addressing an artifact of a revision.
	 *
	 * The returned URI is the LAC cache key.
	 *
	 * @param string $artifactName The LAC cache artifact name.
	 * @param string|false $wikiId The page's wiki ID; false for the local wiki. Resolved
	 *   here rather than by the caller, so that the URI and the page sampling hash in
	 *   {@link coversPage()} cannot disagree about which wiki this is.
	 * @param int $pageId
	 * @param int $revisionId
	 * @return string
	 */
	public function getRevisionArtifactUri(
		string $artifactName,
		string|false $wikiId,
		int $pageId,
		// TODO: we may consider making revisionId
		// optional as is with the LAC API.
		int $revisionId
	): string {
		return sprintf(
			self::REVISION_URI_FORMAT,
			rawurlencode( $artifactName ),
			rawurlencode( $this->config->resolveWikiId( $wikiId ) ),
			$pageId,
			$revisionId
		);
	}

	/**
	 * Fetch one revision's artifact.
	 *
	 * @param string $artifactName
	 * @param string|false $wikiId The page's wiki ID; false for the local wiki.
	 * @param int $pageId
	 * @param int $revisionId
	 * @param int $timeoutMs Request timeout in milliseconds, the caller's own budget.
	 * @param bool $noCache Force LAC to recompute rather than return what it has.
	 * @return LinkedArtifactResponse
	 */
	public function fetchRevisionArtifact(
		string $artifactName,
		string|false $wikiId,
		int $pageId,
		int $revisionId,
		int $timeoutMs,
		bool $noCache = false
	): LinkedArtifactResponse {
		return $this->fetch(
			$this->getRevisionArtifactUri( $artifactName, $wikiId, $pageId, $revisionId ),
			$timeoutMs,
			$noCache
		);
	}

	/**
	 * GET one LAC artifact URI.
	 *
	 * @param string $artifactUri A LAC URI path, as built by one of the methods above.
	 * @param int $timeoutMs Request timeout in milliseconds.
	 * @param bool $noCache When true, send `Cache-Control: no-cache` so LAC disregards any
	 *   stored output and consults the lambda — i.e. compute it. Only meaningful for a URI
	 *   naming one entity; LAC's page-scoped route has no single revision to recompute.
	 * @return LinkedArtifactResponse
	 */
	public function fetch(
		string $artifactUri,
		int $timeoutMs,
		bool $noCache = false
	): LinkedArtifactResponse {
		$url = rtrim( $this->config->getBaseUrl(), '/' ) . $artifactUri;

		$headers = [];
		if ( $noCache ) {
			$headers['Cache-Control'] = 'no-cache';
		}

		$responses = $this->http->runMulti(
			[
				[
					'url' => $url,
					'method' => 'GET',
					'headers' => $headers,
				],
			],
			[
				// MultiHttpClient takes seconds as a float (converting back to
				// CURLOPT_TIMEOUT_MS itself), so sub-second budgets survive the round trip.
				// Cast so the type does not vary: PHP's / yields int when it divides exactly.
				'reqTimeout' => (float)$timeoutMs / 1000,
			]
		);

		$httpResponse = $responses[0]['response'];
		$statusCode = (int)$httpResponse['code'];
		$contentType = ( $httpResponse['headers'] ?? [] )['content-type'] ?? null;
		$body = (string)( $httpResponse['body'] ?? '' );

		// The body is passed through as-is: on a failure it is LAC's RFC7807 problem
		// object, which is worth having when debugging.
		$response = new LinkedArtifactResponse( $statusCode, $contentType, $body );

		if ( !$response->isSuccess() ) {
			$context = [
				'url' => $url,
				'status_code' => $statusCode,
			];

			if ( $response->isNotFound() ) {
				$this->logger->warning( 'No Linked Artifact at {url}', $context );
			} else {
				$this->logger->error(
					'Failed requesting Linked Artifact at {url}',
					$context + [ 'error' => $httpResponse['error'] ?? null ]
				);
			}
		}

		return $response;
	}
}
