<?php
/**
 * Stands in for a file inside WooCommerce: a WooCommerce feature's account
 * content, defined under the directory a unit test names as WC_ABSPATH.
 *
 * @package POW
 */

declare( strict_types = 1 );

/** A feature's account page content, as a static method. */
final class POW_Test_Woo_Feature_Endpoint {
	public static function render(): void {}
}

/** A feature's account page content, as a plain function. */
function pow_test_woo_feature_render(): void {}

if ( ! function_exists( 'wp_normalize_path' ) ) {
	/** WordPress's path normaliser, as far as the form needs it. */
	function wp_normalize_path( string $path ): string { // phpcs:ignore
		return str_replace( '\\', '/', $path );
	}
}
