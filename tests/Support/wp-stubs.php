<?php
/**
 * The handful of WordPress functions the pure layers call.
 *
 * Every stub is function_exists-guarded, so nothing here is defined when
 * WordPress is loaded. It lives in the bootstrap rather than in one test
 * file so that any suite — the standalone runner or a single PHPUnit
 * class run on its own — has it, whatever order the files load in.
 *
 * The escaping stubs are the real WordPress implementations in miniature
 * (esc_html is htmlspecialchars with ENT_QUOTES), because a test that
 * proves output is escaped is worthless against a stub that escapes
 * nothing.
 *
 * Three stubs read a $GLOBALS key so a test can steer them: __() consults
 * pow_test_translations (proving a string really goes through
 * translation, which an identity stub cannot), apply_filters runs one
 * callback per hook out of pow_test_filters, and wp_verify_nonce accepts
 * only pow_test_valid_nonce. All three behave as the plain stub would
 * when their key is unset.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'POW_PLUGIN_DIR' ) ) {
	define( 'POW_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/' );
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Translation stub — never defined when WordPress is loaded.
	 */
	function __( string $text, string $domain = '' ): string { // phpcs:ignore
		return $GLOBALS['pow_test_translations'][ $text ] ?? $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string { // phpcs:ignore
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $text ): string { // phpcs:ignore
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_textarea' ) ) {
	function esc_textarea( string $text ): string { // phpcs:ignore
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( string $url ): string { // phpcs:ignore
		return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = '' ): string { // phpcs:ignore
		return esc_html( __( $text, $domain ) );
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( string $text, string $domain = '' ): void { // phpcs:ignore
		echo esc_html__( $text, $domain ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Stub of the WordPress hook runner: one callback per hook, taken from
	 * $GLOBALS['pow_test_filters'], which is enough to prove ordering.
	 * Unfiltered by default. Never defined when WordPress is loaded.
	 *
	 * @param mixed $value   Value to filter.
	 * @param mixed ...$args Further arguments; not passed on.
	 * @return mixed
	 */
	function apply_filters( string $hook, $value, ...$args ) { // phpcs:ignore
		$callback = $GLOBALS['pow_test_filters'][ $hook ] ?? null;

		return null === $callback ? $value : $callback( $value );
	}
}

if ( ! function_exists( 'locate_template' ) ) {
	/**
	 * No theme in the unit suite, so the plugin's own copy always wins.
	 *
	 * @param array<int, string> $names Candidate template files.
	 */
	function locate_template( array $names ): string { // phpcs:ignore
		return '';
	}
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( string $action = '-1', string $name = '_wpnonce', bool $referer = true, bool $display = true ): string { // phpcs:ignore
		$field = '<input type="hidden" name="' . esc_attr( $name ) . '" value="pow-test-nonce" />';

		if ( $display ) {
			echo $field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		return $field;
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	/**
	 * Accepts exactly the value a test declared valid; everything else is
	 * a forgery, which is what the production function means.
	 *
	 * @return int|false
	 */
	function wp_verify_nonce( string $nonce, string $action = '-1' ) { // phpcs:ignore
		return isset( $GLOBALS['pow_test_valid_nonce'] ) && $GLOBALS['pow_test_valid_nonce'] === $nonce ? 1 : false;
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * @param mixed $value Value to unslash.
	 * @return mixed
	 */
	function wp_unslash( $value ) { // phpcs:ignore
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $text ): string { // phpcs:ignore
		return trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', wp_strip_all_tags( $text ) ) );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $text ): string { // phpcs:ignore
		return strip_tags( $text );
	}
}
