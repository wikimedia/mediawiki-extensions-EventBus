<?php

/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

namespace MediaWiki\Extension\EventBus\HookHandlers\MediaWiki;

use Campaign;
use CentralNoticeCampaignChangeHook;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\EventBus\EventBus;
use MediaWiki\Extension\EventBus\StreamNameMapper;
use MediaWiki\User\User;
use UnexpectedValueException;

class CampaignChangeHooks implements CentralNoticeCampaignChangeHook {
	private StreamNameMapper $streamNameMapper;

	public function __construct( StreamNameMapper $streamNameMapper ) {
		$this->streamNameMapper = $streamNameMapper;
	}

	/**
	 * Handle CentralNoticeCampaignChange hook. Send an event corresponding to the type
	 * of campaign change made (create, change or delete).
	 *
	 * This method is only expected to be called if CentralNotice is installed.
	 *
	 * @see https://www.mediawiki.org/wiki/Extension:CentralNotice/CentralNoticeCampaignChange
	 *
	 * @param string $changeType Type of change performed. Can be 'created', 'modified',
	 *   or 'removed'.
	 * @param string $time The time of the change. This is the same time that will be
	 *   recorded for the change in the cn_notice_log table.
	 * @param string $campaignName Name of the campaign created, modified or removed.
	 * @param User $user The user who performed the change.
	 * @param array|null $beginSettings Campaign settings before the change, if applicable.
	 *   These will include start, end, enabled, archived and banners. If not applicable,
	 *   this parameter will be null.
	 * @param array|null $endSettings Campaign settings after the change, if applicable.
	 *   These will include start, end, enabled, archived and banners. If not applicable,
	 *   this parameter will be null.
	 * @param string $summary Change summary provided by the user, or empty string if none
	 *   was provided.
	 */
	public function onCentralNoticeCampaignChange(
		$changeType,
		$time,
		$campaignName,
		$user,
		$beginSettings,
		$endSettings,
		$summary
	): void {
		// Since we're running this hook, we'll assume that CentralNotice is installed.
		$campaignUrl = Campaign::getCanonicalURL( $campaignName );

		switch ( $changeType ) {
			case 'created':
				if ( !$endSettings ) {
					return;
				}

				$stream = $this->streamNameMapper->resolve(
					'mediawiki.centralnotice.campaign-create' );
				$eventBus = EventBus::getInstanceForStream( $stream );
				$eventFactory = $eventBus->getFactory();
				$event = $eventFactory->createCentralNoticeCampaignCreateEvent(
					$stream,
					$campaignName,
					$user,
					$endSettings,
					$summary,
					$campaignUrl
				);
				break;

			case 'modified':
				if ( !$endSettings ) {
					return;
				}

				$stream = $this->streamNameMapper->resolve(
					'mediawiki.centralnotice.campaign-change' );
				$eventBus = EventBus::getInstanceForStream( $stream );
				$eventFactory = $eventBus->getFactory();
				$event = $eventFactory->createCentralNoticeCampaignChangeEvent(
					$stream,
					$campaignName,
					$user,
					$endSettings,
					$beginSettings ?: [],
					$summary,
					$campaignUrl
				);
				break;

			case 'removed':
				$stream = $this->streamNameMapper->resolve(
					'mediawiki.centralnotice.campaign-delete' );
				$eventBus = EventBus::getInstanceForStream( $stream );
				$eventFactory = $eventBus->getFactory();
				$event = $eventFactory->createCentralNoticeCampaignDeleteEvent(
					$stream,
					$campaignName,
					$user,
					$beginSettings ?: [],
					$summary,
					$campaignUrl
				);
				break;

			default:
				throw new UnexpectedValueException(
					'Bad CentralNotice change type: ' . $changeType );
		}

		DeferredUpdates::addCallableUpdate(
			static function () use ( $eventBus, $event ) {
				$eventBus->send( [ $event ] );
			}
		);
	}

}
