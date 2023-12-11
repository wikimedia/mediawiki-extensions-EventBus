<?php

namespace MediaWiki\Extension\EventBus\Adapters\EventRelayer;

use InvalidArgumentException;
use MediaWiki\Extension\EventBus\EventBus;
use MediaWiki\MediaWikiServices;
use MediaWiki\Utils\MWTimestamp;
use Wikimedia\Assert\Assert;
use Wikimedia\EventRelayer\EventRelayer;

/**
 * @package MediaWiki\Extension\EventBus
 * @since 1.35
 */
class CdnPurgeEventRelayer extends EventRelayer {

	/** @var string */
	private $purgeStream;

	/** @var EventBus */
	private $eventBus;

	/**
	 * @param array $params
	 *  - string 'stream' - the name of the stream the CDN purge events
	 *  will be produced to. Required.
	 * @throws InvalidArgumentException if $params are misconfigured
	 */
	public function __construct( array $params ) {
		parent::__construct( $params );
		if ( !isset( $params['stream'] ) ) {
			throw new InvalidArgumentException( 'purge_stream must be configured' );
		}

		$this->purgeStream = $params['stream'];
		$this->eventBus = MediaWikiServices::getInstance()
			->getService( 'EventBus.EventBusFactory' )
			->getInstanceForStream( $this->purgeStream );
	}

	/**
	 * @param string $channel
	 * @param array $events
	 * @return bool
	 */
	protected function doNotify( $channel, array $events ) {
		Assert::precondition(
			$channel === 'cdn-url-purges',
			"Invalid CdnPurgeEventRelayer configuration. Called on $channel"
		);
		return $this->eventBus->send(
			array_map( function ( $event ) {
				return $this->eventBus->getFactory()->createEvent(
					$event['url'],
					'/resource_change/1.0.0',
					$this->purgeStream,
					[ 'tags' => [ 'mediawiki' ] ],
					null,
					MWTimestamp::convert( TS_ISO_8601, $event['timestamp'] )
				);
			}, $events ),
			EventBus::TYPE_PURGE
		) === true;
	}
}
