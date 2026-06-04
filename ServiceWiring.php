<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\CentralAuth\CentralAuthServices;
use MediaWiki\Extension\EventBus\EventBusFactory;
use MediaWiki\Extension\EventBus\EventFactory;
use MediaWiki\Extension\EventBus\GlobalEditCountLookup;
use MediaWiki\Extension\EventBus\Serializers\EventSerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\PageEntitySerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\PageLinkEntitySerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\RevisionEntitySerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\RevisionSlotsEntitySerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\UserEntitySerializer;
use MediaWiki\Extension\EventBus\StreamNameMapper;
use MediaWiki\Extension\EventBus\WikibaseItemIdLookup;
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
		return new EventSerializer( $services->getGlobalIdGenerator() );
	},

	'EventBus.PageEntitySerializer' => static function ( MediaWikiServices $services ): PageEntitySerializer {
		return new PageEntitySerializer(
			$services->getMainConfig(),
			$services->getTitleFormatter(),
		);
	},

	'EventBus.PageLinkEntitySerializer' => static function ( MediaWikiServices $services ): PageLinkEntitySerializer {
		return new PageLinkEntitySerializer(
			$services->getTitleFormatter(),
		);
	},

	'EventBus.UserEntitySerializer' => static function ( MediaWikiServices $services ): UserEntitySerializer {
		return new UserEntitySerializer(
			$services->getUserFactory(),
			$services->getUserGroupManagerFactory(),
			$services->getCentralIdLookup(),
			$services->getUserRegistrationLookup(),
			$services->getUserIdentityUtils(),
			$services->getUserEditTracker(),
		);
	},

	// NOTE: This is a private service, internal to the EventBus event producers
	// (the *ChangeEventSerializers via the Ingress/Hooks). Unlike the entity
	// serializers above, it is NOT part of EventBus's reusable public API and
	// should not be used from outside this extension. It is expected to move to
	// WikimediaEvents together with the producer code. See
	// https://phabricator.wikimedia.org/T432050
	'EventBus.GlobalEditCountLookup' => static function (
		MediaWikiServices $services
	): GlobalEditCountLookup {
		// CentralAuth is an optional dependency. When absent, the lookup always
		// returns null and edit_global_count is omitted from events.
		$centralAuthLoaded = ExtensionRegistry::getInstance()->isLoaded( 'CentralAuth' );
		$centralAuthEditCounter = $centralAuthLoaded ? CentralAuthServices::getEditCounter( $services ) : null;
		$centralAuthUserHelper = $centralAuthLoaded ? CentralAuthServices::getUserHelper( $services ) : null;

		return new GlobalEditCountLookup(
			$centralAuthEditCounter,
			$centralAuthUserHelper,
		);
	},

	// NOTE: Private service, internal to the EventBus event producers, for the
	// same reasons as EventBus.GlobalEditCountLookup above.
	'EventBus.WikibaseItemIdLookup' => static function (
		MediaWikiServices $services
	): WikibaseItemIdLookup {
		// Wikibase Client is an optional dependency. When absent, the lookup
		// always returns null and wikibase_item_id is omitted from events.
		$entityIdLookup = ExtensionRegistry::getInstance()->isLoaded( 'WikibaseClient' )
			? $services->get( 'WikibaseClient.EntityIdLookup' )
			: null;

		return new WikibaseItemIdLookup(
			$services->getTitleFactory(),
			$entityIdLookup,
		);
	},

	'EventBus.RevisionSlotsEntitySerializer' => static function (
		MediaWikiServices $services
	): RevisionSlotsEntitySerializer {
		return new RevisionSlotsEntitySerializer( $services->getContentHandlerFactory() );
	},

	'EventBus.RevisionEntitySerializer' => static function ( MediaWikiServices $services ): RevisionEntitySerializer {
		return new RevisionEntitySerializer();
	},
];
