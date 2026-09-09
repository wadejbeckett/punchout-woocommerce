<?php
/** Guard-only hook and fatal-response seams; other namespaces keep their existing stubs.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );
namespace POW\Cart;

function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	$GLOBALS['pow_test_cart_actions'][ $hook ][ $priority ][] = $callback;
}

function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	// Registration is not executed by the seed-cart unit fixture.
}

function wp_die( $message, $title = '', $args = [] ): void {
	throw new \RuntimeException( (string) $message, (int) ( $args['response'] ?? 500 ) );
}
