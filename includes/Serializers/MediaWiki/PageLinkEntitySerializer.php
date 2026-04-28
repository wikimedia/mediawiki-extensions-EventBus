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

use MediaWiki\Extension\EventBus\Entity\PageLink;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Page\PageRecord;
use MediaWiki\Page\PageReference;
use MediaWiki\Title\TitleFormatter;

/**
 * Converts a PageLink to an array matching fragment/mediawiki/state/entity/page_link.
 */
class PageLinkEntitySerializer {
	private TitleFormatter $titleFormatter;

	public function __construct( TitleFormatter $titleFormatter ) {
		$this->titleFormatter = $titleFormatter;
	}

	/**
	 * @param PageLink $pageLink
	 * @return array
	 */
	public function toArray( PageLink $pageLink ): array {
		$linkTarget = $pageLink->getLink();

		$attrs = [
			'page_title' => $this->formatLinkTarget( $linkTarget ),
			'namespace_id' => $linkTarget->getNamespace(),
		];

		$pageIdentity = $pageLink->getPage();
		if ( $pageIdentity !== null && $pageIdentity->exists() ) {
			$attrs['page_id'] = $pageIdentity->getId();
			if ( $pageIdentity instanceof PageRecord ) {
				$attrs['is_redirect'] = $pageIdentity->isRedirect();
			}
		}

		$interwiki = $linkTarget->getInterwiki();
		if ( $interwiki ) {
			$attrs['interwiki_prefix'] = $interwiki;
		}

		return $attrs;
	}

	/**
	 * Helper function to return a formatted title from a LinkTarget or PageReference instance.
	 *
	 * @param LinkTarget|PageReference $title
	 * @return string
	 */
	private function formatLinkTarget(
		/* LinkTarget|PageReference */ $title
	): string {
		return $this->titleFormatter->getPrefixedDBkey( $title );
	}
}
