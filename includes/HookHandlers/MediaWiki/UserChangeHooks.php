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

namespace MediaWiki\Extension\EventBus\HookHandlers\MediaWiki;

use MediaWiki\Auth\Hook\LocalUserCreatedHook;
use MediaWiki\Context\RequestContext;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\EventBus\EventBusFactory;
use MediaWiki\Extension\EventBus\Serializers\EventSerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\UserChangeEventSerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\UserEntitySerializer;
use MediaWiki\Extension\EventBus\StreamNameMapper;
use MediaWiki\Logging\Hook\ManualLogEntryBeforePublishHook;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\Hook\UserGroupsChangedHook;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserGroupManagerFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityUtils;
use UnexpectedValueException;
use Wikimedia\Assert\Assert;

/**
 * Listens for changes to user state (via various hooks),
 * serializes them into mediawiki/user/change events,
 * and produces them via EventBus->send().
 */
class UserChangeHooks implements
	LocalUserCreatedHook,
	UserGroupsChangedHook,
	ManualLogEntryBeforePublishHook
{
	public const USER_CHANGE_STREAM_NAME_DEFAULT = 'mediawiki.user_change.v1';

	private string $streamName;
	private EventBusFactory $eventBusFactory;
	private UserEntitySerializer $userEntitySerializer;
	private UserChangeEventSerializer $userChangeEventSerializer;
	private UserFactory $userFactory;
	private UserGroupManagerFactory $userGroupManagerFactory;

	public function __construct(
		EventBusFactory $eventBusFactory,
		StreamNameMapper $streamNameMapper,
		EventSerializer $eventSerializer,
		UserEntitySerializer $userEntitySerializer,
		UserFactory $userFactory,
		UserGroupManagerFactory $userGroupManagerFactory,
		TitleFactory $titleFactory,
		UserIdentityUtils $userIdentityUtils,
	) {
		$this->streamName = $streamNameMapper->resolve(
			self::USER_CHANGE_STREAM_NAME_DEFAULT
		);

		$this->eventBusFactory = $eventBusFactory;

		$this->userEntitySerializer = $userEntitySerializer;

		$this->userChangeEventSerializer = new UserChangeEventSerializer(
			$eventSerializer,
			$userEntitySerializer,
			$titleFactory,
			$userIdentityUtils,
		);

		$this->userFactory = $userFactory;
		$this->userGroupManagerFactory = $userGroupManagerFactory;
	}

	/**
	 * Emit real named user create events.
	 *
	 * @param User $user
	 * @param bool $autocreated
	 * @return void
	 */
	public function onLocalUserCreated( $user, $autocreated ) {
		// Skip emitting user create events for anonymous / temp users.
		if ( !$user->isNamed() ) {
			return;
		}

		// LocalUserCreated has no $performer argument; the initiating account is the main request user.
		// MediaWiki does not expose the main RequestContext as a DI service (see T218555 / RequestContext).
		$performer = RequestContext::getMain()->getUser();
		// Only set performer if they are a real named logged in user.
		// During auto-creation the session user may not be safe to load yet (T401400).
		if ( !$performer->isSafeToLoad() || !$performer->isNamed() ) {
			$performer = null;
		}

		// Local user account registration timestamp should be the event timestamp.
		$eventTimestamp = $this->userEntitySerializer->getRegistrationTimestamp( $user ) ?? wfTimestampNow();

		$event = $this->userChangeEventSerializer->toCreateEvent(
			$this->streamName,
			$eventTimestamp,
			$user,
			$performer,
			(bool)$autocreated
		);

		$this->sendEvents( $this->streamName, [ $event ] );
	}

	/**
	 * Emit user rename events from the renameuser LogEntry.
	 *
	 * We don't use the UserRenameComplete hook because the LogEntry
	 * gives us more information about the rename than the hook does.
	 * The LogEntry stores performer as well as the reason for the rename.
	 * This is important especially on WMF wikis, because renames are often
	 * done by Special:GlobalRenameUser, which submits jobs that rename
	 * the user on each wiki.  In async job execution, we can't get the performer
	 * from the RequestContext.  It is better to rely on the job params in this
	 * case.  Performer and reason is passed by the global rename job to
	 * RenameuserSQL, which creates the LogEntry with them.
	 *
	 * NOTE: even though this hook is called "BeforePublish",
	 * the LogEntry has been saved at this point.  $logEntry->publish() is called
	 * right after onRenameUserComplete in RenameuserSQL::renameUser().
	 *
	 * In the future, a nice User change Domain Event would be much preferrable.
	 * @param ManualLogEntry $logEntry
	 * @return void
	 */
	public function onManualLogEntryBeforePublish( $logEntry ): void {
		if ( !( $logEntry instanceof ManualLogEntry ) ) {
			return;
		}
		// Only match the RenameuserSQL log entry (type=renameuser, action=subtype=renameuser).
		if ( $logEntry->getType() !== 'renameuser' || $logEntry->getSubtype() !== 'renameuser' ) {
			return;
		}

		$params = $logEntry->getParameters();
		$comment = $logEntry->getComment();
		$userNameNew = $params['5::newuser'] ?? null;
		$userNameOld = $params['4::olduser'] ?? null;

		Assert::parameter(
			is_string( $userNameNew ),
			'newuser',
			'renameuser LogEntry parameter newuser must be a username string. Value: ' .
			var_export( $userNameNew, true )
		);
		Assert::parameter(
			is_string( $userNameOld ),
			'olduser',
			'renameuser LogEntry parameter olduser must be a username string. Value: ' .
			var_export( $userNameOld, true )
		);

		// Load the User that has been renamed.
		// We don't need extra username validation since
		// the rename has already happened (and hopefully been validated).
		$userNewFromNameResult = $this->userFactory->newFromName( $userNameNew, UserFactory::RIGOR_NONE );
		if ( !$userNewFromNameResult ) {
			throw new UnexpectedValueException(
				'When attempting to emit a user rename event from the renameuser LogEntry, ' .
				"newuser name '$userNameNew' is not a valid username. " .
				"This should not happen. olduser name is '$userNameOld'."
			);
		}
		// Use a new variable to make Phan happy and sure that $user is not null.
		$renamedUser = $userNewFromNameResult;
		$renamedUser->load();

		if ( !$renamedUser->isNamed() ) {
			throw new UnexpectedValueException(
				'When attempting to emit a user rename event from the renameuser LogEntry, ' .
				"newuser name '$userNameNew' is not a named user. " .
				"This should not happen. olduser name is '$userNameOld'."
			);
		}

		// Load the performer that triggered the rename.
		// This may be the same as the user that was renamed.
		$performer = $this->userFactory->newFromUserIdentity( $logEntry->getPerformerIdentity() );
		// Defensive check: non named users shouldn't be able to rename users,
		// but just in case, avoid emitting anonymous / temp performer info in the event.
		if ( !$performer->isNamed() ) {
			$performer = null;
		}

		$event = $this->userChangeEventSerializer->toRenameEvent(
			$this->streamName,
			// NOTE: As of 2026-05-07, the LogEntry timestamp is not set by RenameuserSQL::renameUser().
			//       $logEntry->getTimestamp() the current timestamp.
			//       We still use the LogEntry timestamp as event timestamp in case it is set in the future.
			$logEntry->getTimestamp(),
			$renamedUser,
			$performer,
			$userNameNew,
			$userNameOld,
			$comment,
		);

		$this->sendEvents( $this->streamName, [ $event ] );
	}

	/**
	 * Emit user groups change events.
	 *
	 * @param UserIdentity $userIdentity User whose groups changed
	 * @param string[] $added Groups added
	 * @param string[] $removed Groups removed
	 * @param User|false $performer
	 * @param string|false $reason
	 * @param array $oldUGMs
	 * @param array $newUGMs
	 * @return void
	 */
	public function onUserGroupsChanged(
		$userIdentity,
		$added,
		$removed,
		$performer,
		$reason,
		$oldUGMs,
		$newUGMs
	) {
		// Only set performer if they are a real named logged in user.
		if ( $performer !== false && !$performer->isNamed() ) {
			$performer = null;
		}

		// Pass the UserIdentity straight through to preserve cross-wiki context
		// (interwiki rights changes via Special:UserRights deliver a UserIdentity
		// whose getWikiId() refers to a foreign wiki).
		// A user's groups should include both implicit and explicit groups.
		// This hook gives us the explicit groups that have changed, but not
		// implicit ones.
		// But, because implicit groups are not modified by this hook,
		// we can get the list of effective current and prior groups
		// by unioning the explicit groups (changed) by this hook with the implicit groups.
		$groupsCurrent = $this->calculateUserEffectiveGroups( $userIdentity, $newUGMs );
		$groupsPrior = $this->calculateUserEffectiveGroups( $userIdentity, $oldUGMs );

		$event = $this->userChangeEventSerializer->toGroupsChangedEvent(
			$this->streamName,
			// UserGroupsChanged does not provide a timestamp,
			// so we use the current time as the event timestamp.
			wfTimestampNow(),
			$userIdentity,
			// Don't set performer if they are not a real logged in user.
			$performer !== false && $performer->isNamed() ? $performer : null,
			$groupsCurrent,
			$groupsPrior,
			$reason !== false ? $reason : null
		);

		$this->sendEvents( $this->streamName, [ $event ] );
	}

	/**
	 * Helper function to calculate the effective groups for a user.
	 * This is needed to calculate the effective groups current and prior
	 * effective groups from the onUserGroupsChanged hook params.
	 *
	 * calcualted effective groups =
	 * (current effective groups - all possible explicit groups) + $explicitUserGroupMembership param
	 *
	 * Uses the wiki-aware UserGroupManager keyed by $user->getWikiId(), so that
	 * implicit groups and the explicit-groups list are computed against the
	 * user's home wiki (not necessarily the current wiki).
	 *
	 * @param UserIdentity $user
	 * @param array $explicitUserGroupMembership
	 * @return array
	 */
	private function calculateUserEffectiveGroups(
		UserIdentity $user,
		array $explicitUserGroupMembership
	): array {
		$userGroupManager = $this->userGroupManagerFactory
			->getUserGroupManager( $user->getWikiId() );

		$currentEffectiveGroups = $userGroupManager->getUserEffectiveGroups( $user );
		$allExplicitGroups = $userGroupManager->listAllGroups();

		// Subtract all possible explicit groups from the current effective groups.
		// This let's us union the non-explicit groups with the explicitly provided groups parameter.
		$nonExplicitGroups = array_diff( $currentEffectiveGroups, $allExplicitGroups );

		$calculatedExplicitGroups = [];
		foreach ( $explicitUserGroupMembership as $key => $val ) {
			$calculatedExplicitGroups[] = $val->getGroup();
		}

		return array_unique( array_merge( $nonExplicitGroups, $calculatedExplicitGroups ) );
	}

	private function sendEvents(
		string $streamName,
		array $events
	): void {
		$eventBus = $this->eventBusFactory->getInstanceForStream( $streamName );
		DeferredUpdates::addCallableUpdate( static function () use ( $eventBus, $events ) {
			$eventBus->send( $events );
		} );
	}

}
