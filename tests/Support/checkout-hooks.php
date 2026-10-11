<?php
/**
 * Seams for the POW\Checkout namespace (WordPress is not loaded in the unit suite). Every checkout suite requires
 * this file, so whichever loads first defines them:
 *
 * - hook registration, recorded, not run: each entry is [ priority, callback, accepted args ] under
 *   pow_payment_method_hooks[ hook ];
 * - the answers that end a request: wp_send_json() and wp_safe_redirect() throw a LateSubmitAnswer carrying what
 *   was answered;
 * - header(): recorded in pow_test_setup_io['headers'] while a test sets pow_test_setup_io, else PHP's own.
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

/** What a checkout route answered: wp_send_json() and wp_safe_redirect() end the request. */
if ( ! class_exists( __NAMESPACE__ . '\LateSubmitAnswer' ) ) {
	final class LateSubmitAnswer extends \RuntimeException {
		public function __construct( public string $kind, public mixed $payload ) { parent::__construct( $kind ); }
	}
}
if ( ! function_exists( __NAMESPACE__ . '\wp_send_json' ) ) {
	function wp_send_json( mixed $response, ?int $status = null, int $flags = 0 ): void { throw new LateSubmitAnswer( 'json', $response ); }
}
if ( ! function_exists( __NAMESPACE__ . '\wp_safe_redirect' ) ) {
	function wp_safe_redirect( string $location, int $status = 302, string $by = 'WordPress' ): bool { throw new LateSubmitAnswer( 'redirect', [ $location, $status ] ); }
}
if ( ! function_exists( __NAMESPACE__ . '\header' ) ) {
	function header( string $header, bool $replace = true, int $response_code = 0 ): void {
		if ( isset( $GLOBALS['pow_test_setup_io'] ) ) { $GLOBALS['pow_test_setup_io']['headers'][] = $header; return; }
		\header( $header, $replace, $response_code );
	}
}
