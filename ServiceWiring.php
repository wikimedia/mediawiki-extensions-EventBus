<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\EventBus\EventBusFactory;
use MediaWiki\Extension\EventBus\EventFactory;
use MediaWiki\Extension\EventBus\Serializers\EventSerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\PageEntitySerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\RevisionEntitySerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\RevisionSlotEntitySerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\UserEntitySerializer;
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

	// Expose useful serializers to other extensions that might want to serialize and emit
	// external events according to this data model.
	'EventBus.EventSerializer' => static function ( MediaWikiServices $services ): EventSerializer {
		return new EventSerializer(
		// NOTE: To be removed as part of T392516
			$services->getMainConfig(),
			$services->getGlobalIdGenerator(),
			// NOTE: To be removed as part of T392516
			Telemetry::getInstance(),
		);
	},

	'EventBus.PageEntitySerializer' => static function ( MediaWikiServices $services ): PageEntitySerializer {
		return new PageEntitySerializer(
			$services->getMainConfig(),
			$services->getTitleFormatter(),
		);
	},

	'EventBus.UserEntitySerializer' => static function ( MediaWikiServices $services ): UserEntitySerializer {
		return new UserEntitySerializer(
			$services->getUserFactory(),
			$services->getUserGroupManager(),
			$services->getCentralIdLookup(),
		);
	},

	'EventBus.RevisionEntitySerializer' => static function ( MediaWikiServices $services ): RevisionEntitySerializer {
		return new RevisionEntitySerializer(
			new RevisionSlotEntitySerializer( $services->getContentHandlerFactory() ),
			$services->get( 'EventBus.UserEntitySerializer' ),
		);
	},
];
