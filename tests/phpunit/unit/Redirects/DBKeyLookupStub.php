<?php

use PHPUnit\Framework\MockObject\Invocation;
use PHPUnit\Framework\MockObject\Stub\Stub;

/**
 * Method stub for looking up an object by a DB key.
 * Expects to be used for methods with a signature like `(PageReference [, …])`
 * So the first param should be a {@link \MediaWiki\Page\PageReference}.
 * It must at least offer a method like {@link \MediaWiki\Page\PageReference::getDBkey}.
 */
class DBKeyLookupStub implements Stub {
	/**
	 * @var array associative array of `DB key -> object`
	 */
	private array $lut = [];
	/**
	 * @var string name of the stub
	 */
	private string $name;
	/**
	 * @var mixed|null value to be returned if the {@link $lut} does not contain a key.
	 */
	private $default;

	public function __construct( array &$lut, $name, $default = null ) {
		$this->lut = &$lut;
		$this->name = $name;
		$this->default = $default;
	}

	public function toString(): string {
		return $this->name;
	}

	/**
	 * @param Invocation $invocation
	 * @return mixed
	 * @throws Throwable
	 */
	public function invoke( Invocation $invocation ) {
		$pageReference = $invocation->getParameters()[0];
		if ( !method_exists( $pageReference, "getDBkey" ) ) {
			throw new InvalidArgumentException(
				"Expected invocation with `PageReference`-like object as first argument,
				exposing at least a method named `getDBkey`  but got " .
				( $pageReference == null ? 'null' : get_class( $pageReference ) )
			);
		}
		$key = $pageReference->getDBkey();
		$exists = array_key_exists( $key, $this->lut );
		return $exists ? $this->lookupAndReturnOrThrow( $key ) : $this->default;
	}

	/**
	 * @param string $key key to be looked up
	 * @return mixed looked up (existing) value
	 * @throws Throwable if value under `$key` is a {@link Throwable}
	 */
	private function lookupAndReturnOrThrow( string $key ) {
		$value = $this->lut[$key];
		if ( $value instanceof Throwable ) {
			throw $value;
		}
		return $value;
	}

}
