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

use MediaWiki\Config\Config;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Page\PageRecord;
use MediaWiki\Page\PageReference;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Page\WikiPage;
use MediaWiki\Title\TitleFormatter;

/**
 * Converts a WikiPage to an array matching the fragment/mediawiki/state/entity/page schema
 */
class PageEntitySerializer {
	/**
	 * @var TitleFormatter
	 */
	private TitleFormatter $titleFormatter;
	/**
	 * @var Config
	 */
	private Config $mainConfig;

	/**
	 * @param Config $mainConfig
	 * @param TitleFormatter $titleFormatter
	 */
	public function __construct(
		Config $mainConfig,
		TitleFormatter $titleFormatter
	) {
		$this->mainConfig = $mainConfig;
		$this->titleFormatter = $titleFormatter;
	}

	/**
	 * @param ProperPageIdentity $page
	 * @return array
	 */
	public function toArray(
		ProperPageIdentity $page
	): array {
		$isRedirect = false;
		// isRedirect() is only available on PageRecord instances.
		if ( $page instanceof PageRecord ) {
			$isRedirect = $page->isRedirect();
		}

		$serialized = [
			'page_id' => $page->getId(),
			'page_title' => $this->formatPageTitle( $page ),
			'namespace_id' => $page->getNamespace(),
			'is_redirect' => $isRedirect,
		];
		return $serialized;
	}

	/**
	 * Helper function to return a formatted title from a LinkTarget
	 * or PageReference instance.
	 *
	 * @param LinkTarget|PageReference $title
	 * @return string
	 */
	public function formatLinkTarget(
		/* LinkTarget|PageReference */ $title
	): string {
		return $this->titleFormatter->getPrefixedDBkey( $title );
	}

	/**
	 * Helper function to return a formatted page_title
	 *
	 * @param ProperPageIdentity $page
	 * @return string
	 */
	public function formatPageTitle(
		ProperPageIdentity $page
	): string {
		// FIXME: PageEntitySerializerTest explicitly asserts on
		// a LinkTarget instance. Unwrap to WikiPage for backward compatibility.
		$title = ( $page instanceof WikiPage ) ? $page->getTitle() : $page;
		return $this->formatLinkTarget( $title );
	}

	/**
	 * Helper function that creates a full page path URL
	 * for the page using CanonicalServer and ArticlePath from mainConfig.
	 *
	 * @param ProperPageIdentity $page
	 * @return string
	 */
	public function canonicalPageURL(
		ProperPageIdentity $page
	): string {
		$titleURL = wfUrlencode( $this->formatPageTitle( $page ) );
		// The ArticlePath contains '$1' string where the article title should appear.
		return $this->mainConfig->get( 'CanonicalServer' ) .
			str_replace( '$1', $titleURL, $this->mainConfig->get( 'ArticlePath' ) );
	}
}
