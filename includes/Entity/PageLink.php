<?php

namespace MediaWiki\Extension\EventBus\Entity;

use MediaWiki\Linker\LinkTarget;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Page\PageReference;

/**
 * Tuple representing a link to a page.
 * This can be a link can be local or cross wiki.
 * The link might represent a redirect.
 *
 * Holds at least a {@link LinkTarget}. The page representation is optional and…
 *
 * * …may be a {@link PageIdentity} if the target is a page in the same wiki as the source
 * * …may be a {@link PageReference} if the target is a special page
 * * …may be `null` if the target lies outside the source wiki, for example,
 *   {@link https://en.wikipedia.org/wiki/Help:Interwiki_linking interwiki links}, or
 *   in general {@link https://en.wikipedia.org/wiki/Wikipedia:External_links external links}
 */
class PageLink {

	/**
	 * @var LinkTarget
	 */
	private LinkTarget $link;

	/**
	 * @var PageReference|PageIdentity|null
	 */
	private ?PageReference $page;

	public function __construct( LinkTarget $link, ?PageReference $page = null ) {
		$this->link = $link;
		$this->page = $page;
	}

	/**
	 * @return LinkTarget
	 */
	public function getLink(): LinkTarget {
		return $this->link;
	}

	/**
	 * @return PageReference|PageIdentity|null
	 */
	public function getPage(): ?PageReference {
		return $this->page;
	}
}
