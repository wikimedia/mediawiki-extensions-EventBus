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

declare( strict_types=1 );

namespace MediaWiki\Extension\EventBus\Serializers\MediaWiki;

use MediaWiki\Extension\EventBus\Serializers\EventSerializer;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityUtils;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Assert\Assert;

/**
 * Converts local MediaWiki user changes into a mediawiki/user/change event.
 */
class UserChangeEventSerializer {
	/**
	 * All user_change events will have their $schema URI set to this.
	 */
	public const USER_CHANGE_SCHEMA_URI = '/development/mediawiki_user_change/1.2.0';

	/**
	 * The schema version of the user entity used when serializing user entities.
	 */
	public const USER_ENTITY_SCHEMA_VERSION = '1.3.0';

	/**
	 * There are many kinds of changes that can happen to a MediaWiki users,
	 * but only a few kinds of changes in a 'changelog' stream.
	 * This maps from a MediaWiki user change kind to a changelog kind.
	 */
	private const USER_CHANGE_KIND_TO_CHANGELOG_KIND_MAP = [
		'create' => 'insert',
		'rename' => 'update',
		'groups_change' => 'update',
	];

	/**
	 * @var EventSerializer
	 */
	private EventSerializer $eventSerializer;
	/**
	 * @var UserEntitySerializer
	 */
	private UserEntitySerializer $userEntitySerializer;
	/**
	 * @var TitleFactory
	 */
	private TitleFactory $titleFactory;
	/**
	 * @var UserIdentityUtils
	 */
	private UserIdentityUtils $userIdentityUtils;

	public function __construct(
		EventSerializer $eventSerializer,
		UserEntitySerializer $userEntitySerializer,
		TitleFactory $titleFactory,
		UserIdentityUtils $userIdentityUtils,
	) {
		$this->eventSerializer = $eventSerializer;
		$this->userEntitySerializer = $userEntitySerializer;
		$this->titleFactory = $titleFactory;
		$this->userIdentityUtils = $userIdentityUtils;
	}

	/**
	 * Creates a mediawiki/user/change user create event.
	 * @param string $stream
	 * @param string $eventTimestamp Timestamp of the change (MW TS14 or any wfTimestamp()-compatible format)
	 * @param User $user
	 * @param User|null $performer Actor that caused this change, if known
	 * @param bool $autocreated
	 * @return array
	 */
	public function toCreateEvent(
		string $stream,
		string $eventTimestamp,
		User $user,
		?User $performer,
		bool $autocreated,
	): array {
		$eventAttrs = $this->toCommonAttrs(
			'create',
			EventSerializer::timestampToDt( $eventTimestamp ),
			$user,
			$performer
		);
		// set event.is_autocreate on user create events.
		$eventAttrs['is_autocreate'] = $autocreated;

		return $this->toEvent( $stream, $user, $eventAttrs );
	}

	/**
	 * Creates a mediawiki/user/change user rename event.
	 * @param string $stream
	 * @param string $eventTimestamp Timestamp of the change (MW TS14 or any wfTimestamp()-compatible format)
	 * @param User $user Renamed user (current name); must match post-rename state from the caller
	 * @param User|null $performer Actor that caused this change, if known
	 * @param string $currentUserName Current username
	 * @param string $priorUserName Prior username
	 * @return array
	 */
	public function toRenameEvent(
		string $stream,
		string $eventTimestamp,
		User $user,
		?User $performer,
		string $currentUserName,
		string $priorUserName,
		?string $comment,
	): array {
		$eventAttrs = $this->toCommonAttrs(
			'rename',
			EventSerializer::timestampToDt( $eventTimestamp ),
			$user,
			$performer,
			$comment,
		);

		// Override the user_text value set by userEntitySerializer via $user.
		// The currentUserName should be more accurate, the change may have just been
		// triggered and the $user object may be loaded from a database replica.
		$eventAttrs['user']['user_text'] = $currentUserName;

		$eventAttrs['prior_state'] = [
			'user' => [
				'user_text' => $priorUserName,
			],
		];

		return $this->toEvent( $stream, $user, $eventAttrs );
	}

	/**
	 * Creates a mediawiki/user/change user groups changed event.
	 *
	 * Accepts a UserIdentity (rather than a User) so that interwiki rights
	 * changes - where the target user lives on a different wiki - can be
	 * serialized correctly.
	 *
	 * @param string $stream
	 * @param string $eventTimestamp Timestamp of the change (MW TS14 or any wfTimestamp()-compatible format)
	 * @param UserIdentity $user User whose groups changed (may belong to a foreign wiki)
	 * @param User|null $performer Actor that caused this change; null for autopromotion (hook passes false)
	 * @param string[] $groupsCurrent All groups after the change (implicit + explicit)
	 * @param string[] $groupsPrior All groups before the change (implicit + explicit)
	 * @param string|null $comment Comment (reason) for the change
	 * @return array
	 */
	public function toGroupsChangedEvent(
		string $stream,
		string $eventTimestamp,
		UserIdentity $user,
		?User $performer,
		array $groupsCurrent,
		array $groupsPrior,
		?string $comment,
	): array {
		sort( $groupsCurrent );
		sort( $groupsPrior );

		$eventAttrs = $this->toCommonAttrs(
			'groups_change',
			EventSerializer::timestampToDt( $eventTimestamp ),
			$user,
			$performer,
			$comment
		);

		$eventAttrs['user']['groups'] = $groupsCurrent;

		$eventAttrs['prior_state'] = [
			'user' => [
				'groups' => $groupsPrior,
			],
		];

		return $this->toEvent( $stream, $user, $eventAttrs );
	}

	/**
	 * Uses EventSerializer to create the mediawiki/user/change event for the given $eventAttrs
	 * @param string $stream
	 * @param UserIdentity $user
	 * @param array $eventAttrs
	 * @return array
	 */
	private function toEvent(
		string $stream,
		UserIdentity $user,
		array $eventAttrs,
	): array {
		return $this->eventSerializer->createEvent(
			self::USER_CHANGE_SCHEMA_URI,
			$stream,
			$this->canonicalUserPageURL( $user ),
			$eventAttrs,
			WikiMap::getCurrentWikiId(),
		);
	}

	/**
	 * DRY helper to set event fields common to all user change events.
	 *
	 * @param string $user_change_kind
	 * @param string $dt
	 * @param UserIdentity $user
	 * @param User|null $performer
	 * @return array
	 */
	private function toCommonAttrs(
		string $user_change_kind,
		string $dt,
		UserIdentity $user,
		?User $performer,
		?string $comment = null
	): array {
		// Defense: we never want to emit events about any non named users.
		// Non anons, no temps, etc.
		Assert::parameter(
			$this->userIdentityUtils->isNamed( $user ),
			'user',
			'user_change events shoud only emit information about named (real) user accounts. ' .
			"User {$user->getName()} is not a named user."
		);

		if ( $performer !== null ) {
			Assert::parameter(
				$this->userIdentityUtils->isNamed( $performer ),
				'performer',
				'user_change events shoud only emit information about named (real) user accounts. ' .
				"Performer {$performer->getName()} is not a named user."
			);
		}

		$eventAttrs = [
			'changelog_kind' => self::getChangelogKind( $user_change_kind ),
			'wiki_id' => WikiMap::getCurrentWikiId(),
			'user_change_kind' => $user_change_kind,
			'dt' => $dt,
			'user' => $this->userEntitySerializer->toArray( $user, self::USER_ENTITY_SCHEMA_VERSION ),
		];

		// TODO: until first_registration_dt is moved into the user entity fragment,
		//       and into UserEntitySerializer, set it explicitly here.
		$eventAttrs['user']['first_registration_dt'] = EventSerializer::timestampToDt(
			$this->userEntitySerializer->getFirstRegistrationTimestamp( $user )
		);

		if ( $comment !== null ) {
			$eventAttrs['comment'] = $comment;
		}

		if ( $performer !== null ) {
			$eventAttrs['performer'] = $this->userEntitySerializer->toArray(
				$performer,
				self::USER_ENTITY_SCHEMA_VERSION,
			);
		}

		return $eventAttrs;
	}

	/**
	 * Returns the appropriate changelog kind given a userChangeKind.
	 *
	 * @param string $userChangeKind
	 * @return string
	 */
	private static function getChangelogKind( string $userChangeKind ): string {
		Assert::parameter(
			array_key_exists( $userChangeKind, self::USER_CHANGE_KIND_TO_CHANGELOG_KIND_MAP ),
			'$userChangeKind',
			"Unsupported userChangeKind '$userChangeKind'. Must be one of " .
				implode( ',', array_keys( self::USER_CHANGE_KIND_TO_CHANGELOG_KIND_MAP ) )
		);

		return self::USER_CHANGE_KIND_TO_CHANGELOG_KIND_MAP[$userChangeKind];
	}

	/**
	 * Returns the canonical URL for the user's page.
	 *
	 * For local users, the URL is built using the local wiki's namespace
	 * localization, e.g. "https://de.wikipedia.org/wiki/Benutzer:Foo".
	 *
	 * For users from a foreign wiki, the URL is resolved via WikiMap to the
	 * foreign wiki's canonical server. We use the canonical English "User:"
	 * namespace prefix, because MediaWiki accepts canonical namespace names
	 * in URLs regardless of the foreign wiki's content language, and we
	 * don't have access to the foreign wiki's namespace localization from
	 * here.
	 *
	 * If the foreign wiki cannot be resolved (i.e. not registered in the
	 * local WikiMap), we fall back to building a local URL.
	 *
	 * @param UserIdentity $userIdentity
	 * @return string
	 */
	private function canonicalUserPageURL( UserIdentity $userIdentity ): string {
		$wikiId = $userIdentity->getWikiId();

		// If $userIdentity is for a user on a foreign wiki, attempt to resolve the
		// User page URL via WikiReference.  This method works, but
		// the User namespace prefix probably won't be localized.
		// Usually wikis will 301 redirect to the localized User namespace URL,
		// so this is better than nothing!
		if ( $wikiId !== UserIdentity::LOCAL ) {
			$foreignWiki = WikiMap::getWiki( $wikiId );
			if ( $foreignWiki !== null ) {
				return $foreignWiki->getCanonicalUrl( 'User:' . $userIdentity->getName() );
			}
		}

		// If we get here, either we couldn't lookup the foreign WikiReference, or
		// the user is local. Use the local wiki User localized namespace URL.
		$userTitle = $this->titleFactory->makeTitle( NS_USER, $userIdentity->getName() );
		return $userTitle->getCanonicalURL();
	}
}
