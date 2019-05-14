<?php

/**
 * Class LegacyEventFactory
 * Temporary class to create legacy EventBus events. Only created for
 * the time of transition from EventBus service to EventGate and should be
 * removed after.
 * @deprecated since 1.34.0
 */
class LegacyEventFactory extends EventFactory {
	protected function createEvent( $uri, $schema, $topic, array $attrs, $wiki = null ) {
		global $wgServerName;

		if ( !is_null( $wiki ) ) {
			$wikiRef = WikiMap::getWiki( $wiki );
			if ( is_null( $wikiRef ) ) {
				$domain = $wgServerName;
			} else {
				$domain = $wikiRef->getDisplayName();
			}
		} else {
			$domain = $wgServerName;
		}

		$event = [
			'meta' => [
				'uri'        => $uri,
				'request_id' => WebRequest::getRequestId(),
				'id'         => UIDGenerator::newUUIDv1(),
				'dt'         => gmdate( 'c' ),
				'domain'     => $domain,
				'topic'      => $topic,
			],
		];

		return $event + $attrs;
	}
}
