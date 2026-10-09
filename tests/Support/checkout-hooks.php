<?php
/**
 * Hook registration seams for the POW\Checkout namespace: recorded, not run (WordPress is not loaded in the unit
 * suite). Every checkout suite that calls a register() method requires this file, so whichever loads first defines
 * them. Each entry is [ priority, callback, accepted args ] under pow_payment_method_hooks[ hook ].
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Checkout;

if ( ! function_exists( __NAMESPACE__ . '\add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void { $GLOBALS['pow_payment_method_hooks'][ $hook ][] = [ $priority, $callback, $accepted_args ]; }
}
if ( ! function_exists( __NAMESPACE__ . '\add_action' ) ) {
	function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void { $GLOBALS['pow_payment_method_hooks'][ $hook ][] = [ $priority, $callback, $accepted_args ]; }
}
