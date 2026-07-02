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

namespace MediaWiki\Extension\EventBus;

use MediaWiki\Extension\CentralAuth\CentralAuthEditCounter;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUserHelper;

/**
 * Looks up a user's global (cross-wiki) edit count by central user id.
 *
 * The global edit count is tracked by CentralAuth, which is an optional
 * extension. When CentralAuth is not loaded, both dependencies are null and
 * getGlobalEditCount() always returns null.
 *
 * See https://phabricator.wikimedia.org/T432050
 */
class GlobalEditCountLookup {

	public function __construct(
		private readonly ?CentralAuthEditCounter $centralAuthEditCounter = null,
		private readonly ?CentralAuthUserHelper $centralAuthUserHelper = null,
	) {
	}

	/**
	 * Returns the global (cross-wiki) edit count for $centralUserId, or null if
	 * it cannot be determined.
	 *
	 * Resolving the account by central id means no username lookup (and no
	 * NormalizedException) is involved. Only the count CentralAuth has already
	 * stored is returned: getCountIfInitialized() reads the replica and returns
	 * null when the count is uninitialized, so this never triggers CentralAuth's
	 * expensive primary-DB recache in the request path.
	 *
	 * @param int $centralUserId Central (global) user id.
	 * @return int|null
	 */
	public function getGlobalEditCount( int $centralUserId ): ?int {
		if ( $this->centralAuthEditCounter === null || $this->centralAuthUserHelper === null ) {
			return null;
		}

		$centralAuthUserStatus = $this->centralAuthUserHelper->getCentralAuthUserById( $centralUserId );
		if ( !$centralAuthUserStatus->isGood() ) {
			return null;
		}

		return $this->centralAuthEditCounter->getCountIfInitialized( $centralAuthUserStatus->getValue() );
	}
}
