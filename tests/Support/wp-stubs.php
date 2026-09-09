<?php
/**
 * The handful of WordPress functions the pure layers call.
 *
 * Every stub is function_exists-guarded, so nothing here is defined when
 * WordPress is loaded. It lives in the bootstrap rather than in one test
 * file so that any suite — the standalone runner or a single PHPUnit
 * class run on its own — has it, whatever order the files load in.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

if ( ! function_exists( '__' ) ) {
	/**
	 * Translation stub — never defined when WordPress is loaded.
	 */
	function __( string $text, string $domain = '' ): string { // phpcs:ignore
		return $text;
	}
}
