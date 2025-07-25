<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\EventBus\EventBusFactory;
use MediaWiki\Extension\EventBus\EventFactory;
use MediaWiki\Extension\EventBus\StreamNameMapper;
use MediaWiki\Http\Telemetry;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;

return [
	'EventBus.EventBusFactory' => static function ( MediaWikiServices $services ): EventBusFactory {
		if ( ExtensionRegistry::getInstance()->isLoaded( 'EventStreamConfig' ) ) {
			// MediaWiki\Extension\EventStreamConfig\StreamConfigs instance.
			$streamConfigs = $services->get( 'EventStreamConfig.StreamConfigs' );
		} else {
			// If null, EventBus will always use EventServiceDefault
			// to produce all streams.
			$streamConfigs = null;
		}

		return new EventBusFactory(
			new ServiceOptions(
				EventBusFactory::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig()
			),
			$streamConfigs,
			$services->get( 'EventBus.EventFactory' ),
			$services->getHttpRequestFactory()->createMultiClient( [
				'telemetry' => $services->getTracer()
			] ),
			LoggerFactory::getInstance( 'EventBus' ),
			$services->getStatsFactory()->withComponent( 'EventBus' ),
		);
	},

	'EventBus.EventFactory' => static function ( MediaWikiServices $services ): EventFactory {
		return new EventFactory(
			new ServiceOptions(
				EventFactory::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig()
			),
			$services->getMainConfig()->get( 'DBname' ),
			$services->getContentLanguage(),
			$services->getRevisionStore(),
			$services->getTitleFormatter(),
			$services->getUserGroupManager(),
			$services->getUserEditTracker(),
			$services->getWikiPageFactory(),
			$services->getUserFactory(),
			$services->getContentHandlerFactory(),
			LoggerFactory::getInstance( 'EventBus' ),
			Telemetry::getInstance()
		);
	},

	'EventBus.StreamNameMapper' => static function ( MediaWikiServices $services ): StreamNameMapper {
		return new StreamNameMapper(
			$services->getMainConfig()
				->get( StreamNameMapper::STREAM_NAMES_MAP_CONFIG_KEY )
		);
	},

];
