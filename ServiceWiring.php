<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\EventBus\EventBusFactory;
use MediaWiki\Extension\EventBus\EventFactory;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

return [
	'EventBus.EventBusFactory' => function ( MediaWikiServices $services ) : EventBusFactory {
		if ( ExtensionRegistry::getInstance()->isLoaded( 'EventStreamConfig' ) ) {
			// Mediawiki\Extension\EventStreamConfig\StreamConfigs instance.
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
			$services->getHttpRequestFactory()->createMultiClient(),
			LoggerFactory::getInstance( 'EventBus' )
		);
	},

	'EventBus.EventFactory' => function ( MediaWikiServices $services ) : EventFactory {
		return new EventFactory(
			new ServiceOptions(
				EventFactory::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig()
			),
			$services->getMainConfig()->get( 'DBname' ),
			$services->getContentLanguage(),
			$services->getRevisionStore(),
			$services->getTitleFormatter(),
			LoggerFactory::getInstance( 'EventBus' )
		);
	}

];
