<?php

namespace MediaWiki\Extension\EventBus;

/**
 * Maps from default names of streams to the actual stream name that will be
 * produced. Unconfigured streams will produce to the default name. This allows
 * wikis with special considerations, such as access restricted wikis in the
 * same cluster as public wikis, to produce their events to a separate stream
 * such that the public streams will not leak private details. Additionally
 * this allows developers to vary the stream name used in testing and staging
 * environments.
 * This class is not generically applied to all events, rather HookHandlers
 * should take this as a constructor argument and use the `resolve` method
 * to determine what stream to send events to.
 */
class StreamNameMapper {
	/**
	 * Key in MainConfig which will be used to map from EventBus owned 'stream names'
	 * to the concrete stream to emit events to.
	 * This config can be used to override the name of the stream that
	 * will be produced to.
	 */
	public const STREAM_NAMES_MAP_CONFIG_KEY = 'EventBusStreamNamesMap';

	/**
	 * Map from the default stream name to it's alias. Unlisted streams will
	 * use the default stream name.
	 * @var array<string, string>
	 */
	private array $streamNamesMap;

	/**
	 * @param array<string, string> $streamNamesMap Map from the default stream
	 *  name to it's alias.
	 */
	public function __construct( array $streamNamesMap ) {
		$this->streamNamesMap = $streamNamesMap;
	}

	/**
	 * @param string $name The default name of the stream
	 * @return string The stream to produce events to
	 */
	public function resolve( string $name ): string {
		return $this->streamNamesMap[$name] ?? $name;
	}
}
