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

use MediaWiki\Extension\EventBus\Serializers\EventSerializer;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentity;
use User;
use Wikimedia\Assert\Assert;

/**
 * Converts a User to an array matching the fragment/mediawiki/state/entity/user schema
 */
class UserEntitySerializer {
	/**
	 * @var UserGroupManager
	 */
	private UserGroupManager $userGroupManager;
	/**
	 * @var UserFactory
	 */
	private UserFactory $userFactory;

	/**
	 * @param UserFactory $userFactory
	 * @param UserGroupManager $userGroupManager
	 */
	public function __construct(
		// NOTE: It would be better not to need a UserFactory
		// and have toArray only take instances of User.
		// But we need to call ::toArray( $userIdentity ) from within RevisionEntitySerializer,
		// which gets the editor of the revision as a UserIdentity,
		// and the correct way to convert a UserIdentity to a User is with a UserFactory.
		// So either we need a UserFactory here, or in RevisionEntitySerializer.  It is more useful here.
		UserFactory $userFactory,
		UserGroupManager $userGroupManager
	) {
		$this->userFactory = $userFactory;
		$this->userGroupManager = $userGroupManager;
	}

	/**
	 * Given a User or UserIdentity $user, returns an array suitable for
	 * use as a mediawiki/state/entity/user JSON object in other Mediawiki
	 * state/entity schemas.
	 * @param User|UserIdentity $user
	 * @return array
	 */
	public function toArray( $user ): array {
		Assert::parameterType(
			[
				User::class,
				UserIdentity::class,
			],
			$user,
			'$user'
		);

		// If given a UserIdentity (that is not already a User), convert to a User.
		if ( !( $user instanceof User ) && $user instanceof UserIdentity ) {
			$user = $this->userFactory->newFromUserIdentity( $user );
		}

		$userAttrs = [
			'user_text' => $user->getName(),
			'groups' => $this->userGroupManager->getUserEffectiveGroups( $user ),
			'is_bot' => $user->isRegistered() && $user->isBot(),
			'is_registered' => $user->isRegistered(),
			'is_system' => $user->isSystemUser(),
			'is_temp' => $user->isTemp()
		];

		if ( $user->getId() ) {
			$userAttrs['user_id'] = $user->getId();
		}
		if ( $user->getRegistration() ) {
			$userAttrs['registration_dt'] =
				EventSerializer::timestampToDt( $user->getRegistration() );
		}
		if ( $user->isRegistered() ) {
			$userAttrs['edit_count'] = $user->getEditCount();
		}

		return $userAttrs;
	}

}
