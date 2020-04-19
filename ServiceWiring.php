<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\EventBus\EventBusFactory;
use MediaWiki\Extension\EventBus\EventFactory;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

return [
	'EventBus.EventBusFactory' => function ( MediaWikiServices $services ) : EventBusFactory {
		return new EventBusFactory(
			new ServiceOptions(
				EventBusFactory::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig()
			),
			$services->get( 'EventBus.EventFactory' ),
			new MultiHttpClient( [] ),
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
			$services->getRevisionLookup(),
			$services->getTitleFormatter()
		);
	}

];
