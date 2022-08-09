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

use Config;
use MediaWiki\Linker\LinkTarget;
use TitleFormatter;
use WikiPage;

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
	 * @param WikiPage $wikiPage
	 * @return array
	 */
	public function toArray( WikiPage $wikiPage ): array {
		return [
			'page_id' => $wikiPage->getId(),
			'page_title' => $this->formatWikiPageTitle( $wikiPage ),
			'namespace_id' => $wikiPage->getNamespace(),
			'is_redirect' => $wikiPage->isRedirect(),
		];
	}

	/**
	 * Helper function to return a formatted LinkTarget (Title)
	 * @param LinkTarget $linkTarget
	 * @return string
	 */
	public function formatLinkTarget( LinkTarget $linkTarget ): string {
		return $this->titleFormatter->getPrefixedDBkey( $linkTarget );
	}

	/**
	 * Helper function to return a formatted page_title
	 * @param WikiPage $wikiPage
	 * @return string
	 */
	public function formatWikiPageTitle( WikiPage $wikiPage ): string {
		return $this->formatLinkTarget( $wikiPage->getTitle() );
	}

	/**
	 * Helper function that creates a full page path URL
	 * for the $wikiPage using CanonicalServer and ArticalPath from mainConfig.
	 *
	 * @param WikiPage $wikiPage
	 * @return string
	 */
	public function canonicalPageURL( WikiPage $wikiPage ): string {
		$titleURL = wfUrlencode( $this->formatWikiPageTitle( $wikiPage ) );
		// The ArticlePath contains '$1' string where the article title should appear.
		return $this->mainConfig->get( 'CanonicalServer' ) .
			str_replace( '$1', $titleURL, $this->mainConfig->get( 'ArticlePath' ) );
	}
}
