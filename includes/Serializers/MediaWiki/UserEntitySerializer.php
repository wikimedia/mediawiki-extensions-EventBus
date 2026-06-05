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
 * @author Andrew Otto <otto@wikimedia.org>
 */

namespace MediaWiki\Extension\EventBus\Serializers\MediaWiki;

use LogicException;
use MediaWiki\Extension\EventBus\Serializers\EventSerializer;
use MediaWiki\User\CentralId\CentralIdLookup;
use MediaWiki\User\Registration\UserRegistrationLookup;
use MediaWiki\User\UserEditTracker;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserGroupManagerFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityUtils;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Rdbms\IDBAccessObject;

/**
 * Converts a UserIdentity to an array matching the fragment/mediawiki/state/entity/user schema.
 *
 * This serializer is wiki-aware: it can serialize a UserIdentity whose
 * getWikiId() refers to a foreign wiki. For foreign users the local-only
 * fields is_bot and is_system are omitted because MediaWiki has no
 * first-class cross-wiki service for either (they depend on the local
 * PermissionManager and AuthManager respectively).
 *
 * @newable - used by WikimediaEvents
 */
class UserEntitySerializer {
	/**
	 * The earliest schema version supported by this serializer.
	 */
	private const SCHEMA_VERSION_EARLIEST = '1.1.0';

	/**
	 * Map of fields that were introduced after SCHEMA_VERSION_EARLIEST to the
	 * schema version in which they were introduced.
	 * Fields not listed are emitted at all supported schema versions.
	 * As new fields are added to new versions of the entity schema
	 * they should be added here and the code should gate the serialization
	 * of the field on the desired output $schemaVersion.
	 */
	private const FIELD_TO_SCHEMA_VERSION = [
		'wiki_id' => '1.2.0',
		'first_edit_dt' => '1.3.0',
	];

	public function __construct(
		// NOTE: UserFactory is only used in the local-wiki branch to coerce a
		// UserIdentity into a User instance, so we can call User::isBot() etc.
		private readonly UserFactory $userFactory,
		private readonly UserGroupManagerFactory $userGroupManagerFactory,
		private readonly CentralIdLookup $centralIdLookup,
		private readonly UserRegistrationLookup $userRegistrationLookup,
		private readonly UserIdentityUtils $userIdentityUtils,
		private readonly UserEditTracker $userEditTracker,
	) {
	}

	/**
	 * Given a UserIdentity $userIdentity, returns an array suitable for
	 * use as a mediawiki/state/entity/user JSON object in other MediaWiki
	 * state/entity schemas.
	 *
	 * If $userIdentity->getWikiId() refers to a foreign wiki, this serializer
	 * uses the wiki-aware UserGroupManager and CentralIdLookup paths and
	 * omits is_bot, is_system, and edit_count (no first-class cross-wiki
	 * service exists for those fields).
	 *
	 * @param UserIdentity $userIdentity
	 * @param string $schemaVersion Target schema version for the user entity fragment.
	 * @return array
	 */
	public function toArray(
		UserIdentity $userIdentity,
		string $schemaVersion = self::SCHEMA_VERSION_EARLIEST,
	): array {
		$isLocal = ( $userIdentity->getWikiId() === UserIdentity::LOCAL );

		// Always use the wiki-aware UserGroupManager - this gives correct
		// effective groups whether the user is local or foreign.
		$userGroupManager = $this->userGroupManagerFactory
			->getUserGroupManager( $userIdentity->getWikiId() );

		$userAttrs = [
			'user_text' => $userIdentity->getName(),
			'groups' => $userGroupManager->getUserEffectiveGroups( $userIdentity ),
			'is_temp' => $this->userIdentityUtils->isTemp( $userIdentity ),
		];

		// wiki_id is the only field gated on schema version >= 1.2.0.
		if ( $this->isFieldInVersion( 'wiki_id', $schemaVersion ) ) {
			$userAttrs['wiki_id'] = $userIdentity->getWikiId() ?: WikiMap::getCurrentWikiId();
		}

		// first_edit_dt is the only field gated on schema version >= 1.3.0.
		if ( $this->isFieldInVersion( 'first_edit_dt', $schemaVersion ) && $userIdentity->isRegistered() ) {
			$firstEditTimestamp = $this->userEditTracker->getFirstEditTimestamp( $userIdentity );
			if ( $firstEditTimestamp !== false ) {
				$userAttrs['first_edit_dt'] = EventSerializer::timestampToDt( $firstEditTimestamp );
			}
		}

		if ( $userIdentity->isRegistered() ) {
			// Pass the user's own wikiId to satisfy assertWiki().
			$userAttrs['user_id'] = $userIdentity->getId( $userIdentity->getWikiId() );

			// UserEditTracker is wiki-aware: getUserEditCount reads from the
			// replica DB for $user->getWikiId(). For foreign users the
			// user_editcount field is read from the foreign wiki's user table.
			// Defensive try/catch: when user_editcount is null on a foreign
			// wiki, UserEditTracker falls back to initializeUserEditCount(),
			// which throws LogicException for non-local users (it only knows
			// how to initialize on the local wiki).
			try {
				$editCount = $this->userEditTracker->getUserEditCount( $userIdentity );
				if ( $editCount !== null ) {
					$userAttrs['edit_count'] = $editCount;
				}
			} catch ( LogicException ) {
				// user_editcount is uninitialized on a foreign wiki - skip.
			}
		}

		// UserRegistrationLookup is already wiki-aware internally.
		$registrationTimestamp = $this->getRegistrationTimestamp( $userIdentity );
		if ( $registrationTimestamp ) {
			$userAttrs['registration_dt'] =
				EventSerializer::timestampToDt( $registrationTimestamp );
		}

		if ( $isLocal ) {
			// Local wiki user only fields.
			// Coerce to User so we can call methods that
			// depend on the local PermissionManager and AuthManager.
			$user = $this->userFactory->newFromUserIdentity( $userIdentity );
			$userAttrs['is_bot'] = $user->isRegistered() && $user->isBot();
			$userAttrs['is_system'] = $user->isSystemUser();

			// NOTE: centralIdFromLocalUser() returns 0 if the user's central id can't be obtained.
			$centralUserId = $this->centralIdLookup->centralIdFromLocalUser( $userIdentity );
			if ( $centralUserId ) {
				$userAttrs['user_central_id'] = $centralUserId;
			}
		} else {
			// Foreign user: look up the central id via the wiki-aware path.
			$name = $userIdentity->getName();
			$nameToId = $this->centralIdLookup->lookupAttachedUserNames(
				[ $name => 0 ],
				CentralIdLookup::AUDIENCE_PUBLIC,
				IDBAccessObject::READ_NORMAL,
				$userIdentity->getWikiId()
			);
			if ( !empty( $nameToId[$name] ) ) {
				$userAttrs['user_central_id'] = $nameToId[$name];
			}
		}

		return $userAttrs;
	}

	/**
	 * Convenience method to get the registration timestamp for a user.
	 * Simple proxy call to UserRegistrationLookup::getRegistration().
	 * Here so that users of UserEntitySerializer don't have to have their own UserRegistrationLookup instance.
	 *
	 * @param UserIdentity $user
	 * @return string|null|false Registration timestamp (TS::MW), null if not available, false if not registered.
	 */
	public function getRegistrationTimestamp( UserIdentity $user ): string|null|false {
		return $this->userRegistrationLookup->getRegistration( $user );
	}

	/**
	 * Convenience method to get the first registration timestamp for a user.
	 * Simple proxy call to UserRegistrationLookup::getFirstRegistration().
	 * Here so that users of UserEntitySerializer don't have to have their own UserRegistrationLookup instance.
	 *
	 * @param UserIdentity $user
	 * @return string|null Registration timestamp (TS::MW), null if not available.
	 */
	public function getFirstRegistrationTimestamp( UserIdentity $user ): ?string {
		return $this->userRegistrationLookup->getFirstRegistration( $user );
	}

	private function isFieldInVersion( string $field, string $schemaVersion ): bool {
		$fieldSinceVersion = self::FIELD_TO_SCHEMA_VERSION[$field] ?? self::SCHEMA_VERSION_EARLIEST;
		return version_compare( $schemaVersion, $fieldSinceVersion ) >= 0;
	}

}
