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

use Config;
use MediaWiki\CommentFormatter\CommentFormatter;
use MediaWiki\Linker\LinkTarget;
use WebRequest;
use WikiMap;
use Wikimedia\UUID\GlobalIdGenerator;

/**
 * EventSerializer should be used to create an event array suitable
 * for producing to WMF's Event Platform, usually via EventGate.
 */
class EventSerializer {
	/**
	 * @var Config
	 */
	private Config $mainConfig;
	/**
	 * @var GlobalIdGenerator
	 */
	private GlobalIdGenerator $globalIdGenerator;
	/**
	 * @var CommentFormatter
	 */
	private CommentFormatter $commentFormatter;

	/**
	 * @param Config $mainConfig
	 * @param GlobalIdGenerator $globalIdGenerator
	 * @param CommentFormatter $commentFormatter
	 */
	public function __construct(
		Config $mainConfig,
		GlobalIdGenerator $globalIdGenerator,
		CommentFormatter $commentFormatter
	) {
		$this->mainConfig = $mainConfig;
		$this->globalIdGenerator = $globalIdGenerator;
		$this->commentFormatter = $commentFormatter;
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
	 * Formats the comment about $linkTarget using $this->>commentFormatter
	 * Helper function for formatting comments.
	 *
	 * @param string $comment
	 * @param LinkTarget $linkTarget
	 * @return string
	 */
	public function formatComment( string $comment, LinkTarget $linkTarget ): string {
		return $this->commentFormatter->format( $comment, $linkTarget );
	}

	/**
	 * Adds a meta subobject to $eventAttrs based on uri and stream.
	 *
	 * @param string $schema
	 * 	Schema URI for '$schema' field.
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
	 *  wikiId to use for meta.domain. If null ServerName will be used.
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
	 * @return array $eventAttrs + $schema + meta sub object
	 */
	public function createEvent(
		string $schema,
		string $stream,
		string $uri,
		array $eventAttrs,
		?string $wikiId = null,
		$ingestionTimestamp = null
	): array {
		// If $wikiId is provided, and we can get a $wikiRef, then use $wikiRef->getDisplayName().
		// Else just use ServerName.
		$domain = $this->mainConfig->get( 'ServerName' );
		$wikiRef = $wikiId ? WikiMap::getWiki( $wikiId ) : null;
		if ( $wikiRef ) {
			$domain = $wikiRef->getDisplayName();
		}

		$metaDt = null;
		if ( $ingestionTimestamp === true ) {
			$metaDt = self::timestampToDt();
		} elseif ( $ingestionTimestamp !== false ) {
			$metaDt = self::timestampToDt( $ingestionTimestamp );
		}

		return array_merge(
			$eventAttrs,
			[
				'$schema' => $schema,
				'meta' => $this->createMeta( $stream,  $uri, $domain, $metaDt )
			]
		);
	}

	/**
	 * Creates the meta subobject that should be common for all events.
	 *
	 * @param string $stream
	 * @param string $uri
	 * @param string $domain
	 * @param string|null $dt
	 * 	If this is null, 'dt' will not be set.
	 * @return array
	 */
	private function createMeta(
		string $stream,
		string $uri,
		string $domain,
		?string $dt = null
	): array {
		$metaAttrs = [
			'stream'     => $stream,
			'uri'        => $uri,
			'id'         => $this->globalIdGenerator->newUUIDv4(),
			'request_id' => WebRequest::getRequestId(),
			'domain'     => $domain,
		];

		if ( $dt !== null ) {
			$metaAttrs['dt'] = $dt;
		}

		return $metaAttrs;
	}
}
