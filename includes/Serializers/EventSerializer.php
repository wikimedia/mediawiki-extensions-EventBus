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
 * @author Andrew Otto <otto@wikimedia.org>
 */

namespace MediaWiki\Extension\EventBus\Serializers;

use MediaWiki\WikiMap\WikiMap;
use Wikimedia\UUID\GlobalIdGenerator;

/**
 * EventSerializer should be used to create an event array suitable
 * for producing to WMF's Event Platform, usually via EventGate.
 */
class EventSerializer {
	/**
	 * @var GlobalIdGenerator
	 */
	private GlobalIdGenerator $globalIdGenerator;

	/**
	 * @param GlobalIdGenerator $globalIdGenerator
	 */
	public function __construct(
		GlobalIdGenerator $globalIdGenerator,
	) {
		$this->globalIdGenerator = $globalIdGenerator;
	}

	/**
	 * Format a timestamp for a date-time attribute in an event in ISO_8601 format.
	 *
	 * @param string|null $timestamp Timestamp, in a format supported by wfTimestamp(), or null for current timestamp.
	 * @return string
	 */
	public static function timestampToDt( ?string $timestamp = null ): string {
		return wfTimestamp( TS_ISO_8601, $timestamp );
	}

	/**
	 * Adds a meta subobject to $eventAttrs based on uri and stream.
	 *
	 * @param string $schema
	 *  Schema URI for '$schema' field.
	 *
	 * @param string $stream
	 *  Name of stream for meta.stream field.
	 *
	 * @param string $uri
	 *  Uri of this entity for meta.uri field.
	 *
	 * @param array $eventAttrs
	 *  Additional event attributes to include.
	 *
	 * @param string|null $wikiId
	 *  wikiId to use when looking up value for meta.domain.
	 *  If null, meta.domain will not be set.
	 *  NOTE: It would be better if wiki domain name was fetched and passed into createEvent,
	 *        rather than forcing createEvent to look up the domain itself.
	 *        However, this would require changing the createEvent method signature, which is used
	 *        by CirrusSearch extension.
	 *        See: https://phabricator.wikimedia.org/T392516
	 *
	 * @param bool|string|null $ingestionTimestamp
	 *  If true, meta.dt will be set to the current timestamp.
	 *  If a string, meta.dt will be set to value of timestampToDt($timestampToDt).
	 *  If null, meta.dt will not be set.
	 *  It is preferred to leave this value at null,
	 *  as EventBus produces through EventGate, which will handle setting meta.dt
	 *  to its ingestion time.
	 *  See: https://phabricator.wikimedia.org/T267648
	 *
	 * @param string|null $requestId
	 *  Will be set as meta.request_id if provided.
	 *  This should typically be obtained via Telemetry::getInstance()->getRequestId();
	 *
	 * @return array $eventAttrs + $schema + meta sub object
	 */
	public function createEvent(
		string $schema,
		string $stream,
		string $uri,
		array $eventAttrs,
		?string $wikiId = null,
		$ingestionTimestamp = null,
		?string $requestId = null,
	): array {
		// Get canonical wiki domain 'display name' via wikiId.
		// If WikiMap::getWiki is null, then we can't get a domain (and are likely in a unit test).
		// In that case, don't provide a domain name.
		$wikiDomainName = null;
		if ( $wikiId !== null ) {
			$wikiReference = WikiMap::getWiki( $wikiId );
			$wikiDomainName = $wikiReference ? $wikiReference->getDisplayName() : null;
		}

		$metaDt = null;
		if ( $ingestionTimestamp === true ) {
			$metaDt = self::timestampToDt();
		} elseif ( $ingestionTimestamp !== null ) {
			$metaDt = self::timestampToDt( $ingestionTimestamp );
		}
		return array_merge(
			$eventAttrs,
			[
				'$schema' => $schema,
				'meta' => $this->createMeta( $stream, $uri, $wikiDomainName, $metaDt, $requestId )
			]
		);
	}

	/**
	 * Creates the meta subobject that should be common for all events.
	 *
	 * @param string $stream
	 * @param string $uri
	 * @param string|null $domain
	 *    If null, 'domain' will not be set.
	 * @param string|null $dt
	 *    If null, 'dt' will not be set.
	 * @param string|null $requestId
	 *    If null, 'request_id' will not be set.
	 * @return array
	 */
	private function createMeta(
		string $stream,
		string $uri,
		?string $domain = null,
		?string $dt = null,
		?string $requestId = null,
	): array {
		$metaAttrs = [
			'stream' => $stream,
			'uri' => $uri,
			'id' => $this->globalIdGenerator->newUUIDv4(),
		];

		if ( $domain !== null ) {
			$metaAttrs['domain'] = $domain;
		}

		if ( $dt !== null ) {
			$metaAttrs['dt'] = $dt;
		}

		if ( $requestId !== null ) {
			$metaAttrs['request_id'] = $requestId;
		}

		return $metaAttrs;
	}
}
