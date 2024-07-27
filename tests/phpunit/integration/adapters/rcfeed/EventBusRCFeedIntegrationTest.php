<?php

use MediaWiki\Extension\EventBus\Adapters\RCFeed\EventBusRCFeedFormatter;
use MediaWiki\MainConfigNames;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;

/**
 * @group medium
 * @group Database
 * @covers \MediaWiki\Extension\EventBus\Adapters\RCFeed\EventBusRCFeedEngine
 * @covers \MediaWiki\Extension\EventBus\Adapters\RCFeed\EventBusRCFeedFormatter
 */
class EventBusRCFeedIntegrationTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValues( [
			MainConfigNames::CanonicalServer => 'https://example.org',
			MainConfigNames::ServerName => 'example.org',
			MainConfigNames::ScriptPath => '/w',
			MainConfigNames::ArticlePath => '/wiki/$1',
			MainConfigNames::DBname => 'example',
			MainConfigNames::DBprefix => $this->dbPrefix(),
			MainConfigNames::RCFeeds => [],
			'EventStreams' => [
				EventBusRCFeedFormatter::STREAM => [
					'stream' => EventBusRCFeedFormatter::STREAM,
					'destination_event_service' => 'test_eventbus_instance'
				]
			],
			'EventServices' => [
				'test_eventbus_instance' => [
					'url' => 'http://test_event_bus_instance/test_url'
				],
				'eventbus' => [
					'url' => 'http://eventbus/test_url'
				]
			]
		] );
	}

	public function testNotify() {
		$feed = $this->getMockBuilder( FormattedRCFeed::class )
			->setConstructorArgs( [ [ 'formatter' => EventBusRCFeedFormatter::class ] ] )
			->onlyMethods( [ 'send' ] )
			->getMock();

		$feed->method( 'send' )
			->willReturn( true );

		$feed->expects( $this->once() )
			->method( 'send' )
			->with( $this->anything(), $this->callback( function ( $line ) {
				$line = FormatJson::decode( $line, true )[0];

				// meta and $schema might change, only assert that a few values are correct.
				$this->assertNotEmpty( $line['meta'] );
				$this->assertEquals( $line['meta']['dt'], wfTimestamp( TS_ISO_8601, 1301644800 ) );
				$this->assertEquals( EventBusRCFeedFormatter::STREAM, $line['meta']['stream'] );

				// Unset meta and $schema and verify assert that the rest of the event is correct.
				unset( $line['meta'] );
				unset( $line['$schema'] );

				$this->assertEquals(
					[
						'type' => 'log',
						'namespace' => 0,
						'title' => 'Example',
						'comment' => '',
						'parsedcomment' => '',
						'timestamp' => 1301644800,
						'user' => 'UTSysop',
						'bot' => false,
						'log_id' => 0,
						'log_type' => 'move',
						'log_action' => 'move',
						'log_params' => [
							'color' => 'green',
							'nr' => 42,
							'pet' => 'cat',
						],
						'log_action_comment' => '',
						'server_url' => 'https://example.org',
						'server_name' => 'example.org',
						'server_script_path' => '/w',
						'wiki' => 'example-' . $this->dbPrefix(),
						'title_url' => 'https://example.org/wiki/Example',
					],
					$line
				);
				return true;
			} ) );

		$this->overrideConfigValue( MainConfigNames::RCFeeds, [
			'myfeed' => [
				'class' => $feed,
				'formatter' => EventBusRCFeedFormatter::class,
			],
		] );
		$logpage = SpecialPage::getTitleFor( 'Log', 'move' );
		$user = $this->getTestSysop()->getUser();
		$rc = RecentChange::newLogEntry(
			'20110401080000',
			$logpage,
			$user,
			'',
			'127.0.0.1',
			'move',
			'move',
			Title::makeTitle( 0, 'Example' ),
			'',
			LogEntryBase::makeParamBlob( [
				'4::color' => 'green',
				'5:number:nr' => 42,
				'pet' => 'cat',
			] )
		);
		$rc->notifyRCFeeds();
	}
}
