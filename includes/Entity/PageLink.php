<?php

namespace MediaWiki\Extension\EventBus\Entity;

use MediaWiki\Linker\LinkTarget;
use MediaWiki\Page\PageIdentity;

/**
 * Tuple representing a link to a page.
 * This can be a link can be local or cross wiki.
 * The link might represent a redirect.
 *
 * Holds at least a {@link LinkTarget}. The page representation is optional and…
 *
 * * …may be a {@link PageIdentity} if the target is a page in the same wiki as the source
 * * …may be `null` if the target lies outside the source wiki, for example,
 *   {@link https://en.wikipedia.org/wiki/Help:Interwiki_linking interwiki links}, or
 *   in general {@link https://en.wikipedia.org/wiki/Wikipedia:External_links external links}
 */
class PageLink {

	public function __construct(
		public readonly LinkTarget $link,
		public readonly ?PageIdentity $page = null,
	) {
	}

}
