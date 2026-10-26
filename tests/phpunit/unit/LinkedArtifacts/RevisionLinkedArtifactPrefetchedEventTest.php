<?php

namespace MediaWiki\Extension\EventBus\Tests\Unit\LinkedArtifacts;

use MediaWiki\Extension\EventBus\LinkedArtifacts\RevisionLinkedArtifactPrefetchedEvent;
use MediaWikiUnitTestCase;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @covers \MediaWiki\Extension\EventBus\LinkedArtifacts\RevisionLinkedArtifactPrefetchedEvent
 */
class RevisionLinkedArtifactPrefetchedEventTest extends MediaWikiUnitTestCase {

	public function testGetters(): void {
		$timestamp = new ConvertibleTimestamp( '20261006120000' );
		$computeTimestamp = new ConvertibleTimestamp( '20261006110000' );
		$triggerTimestamp = new ConvertibleTimestamp( '20261006115959' );
		$event = new RevisionLinkedArtifactPrefetchedEvent(
			'my-artifact', 'enwiki', 42, 99, 'application/json', '{"a":1}',
			$timestamp, $computeTimestamp, true, 'PageLatestRevisionChanged', 'edit', $triggerTimestamp
		);

		$this->assertSame( RevisionLinkedArtifactPrefetchedEvent::TYPE, $event->getEventType() );
		$this->assertSame( 'my-artifact', $event->getArtifactName() );
		$this->assertSame( 'enwiki', $event->getWikiId() );
		$this->assertSame( 42, $event->getPageId() );
		$this->assertSame( 99, $event->getRevisionId() );
		$this->assertSame( 'application/json', $event->getContentType() );
		$this->assertSame( '{"a":1}', $event->getBody() );
		$this->assertSame( $timestamp, $event->getEventTimestamp() );
		$this->assertSame( $computeTimestamp, $event->getArtifactComputeTimestamp() );
		$this->assertSame( [ 'a' => 1 ], $event->getJsonBody() );
		$this->assertSame( 'PageLatestRevisionChanged', $event->getTriggerEventType() );
		$this->assertSame( 'edit', $event->getTriggerCause() );
		$this->assertTrue( $event->isReconciliationRequest() );
		$this->assertSame( $triggerTimestamp, $event->getTriggerTimestamp() );
		$this->assertSame( 'my-artifact artifact linked to enwiki page 42 revision 99', $event->artifactDescription() );
	}

	public function testTriggerDefaults(): void {
		$event = new RevisionLinkedArtifactPrefetchedEvent(
			'my-artifact', 'enwiki', 42, 99, null, '', new ConvertibleTimestamp(), null
		);

		$this->assertNull( $event->getContentType() );
		$this->assertNull( $event->getJsonBody() );
		$this->assertNull( $event->getTriggerEventType() );
		$this->assertNull( $event->getTriggerCause() );
		$this->assertFalse( $event->isReconciliationRequest() );
		$this->assertNull( $event->getTriggerTimestamp() );
	}
}
