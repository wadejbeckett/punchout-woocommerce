<?php
/** Actual Plugin::boot() fixture for the master-Off cart shortcode contract. @package POW */
declare( strict_types = 1 );

require_once dirname( __DIR__ ) . '/bootstrap.php';

if ( ! defined( 'POW_PLUGIN_FILE' ) ) { define( 'POW_PLUGIN_FILE', dirname( __DIR__, 2 ) . '/punchout-woocommerce.php' ); }
if ( ! defined( 'POW_PLUGIN_URL' ) ) { define( 'POW_PLUGIN_URL', 'https://shop.example.test/wp-content/plugins/punchout-woocommerce/' ); }
if ( ! defined( 'POW_SECRET_KEY' ) ) { define( 'POW_SECRET_KEY', 'eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHg=' ); }
if ( ! class_exists( 'WooCommerce' ) ) { final class WooCommerce {} }

if ( ! function_exists( 'plugin_basename' ) ) { function plugin_basename( string $file ): string { return basename( $file ); } }
if ( ! function_exists( 'load_plugin_textdomain' ) ) { function load_plugin_textdomain( string $domain, bool $deprecated = false, string $path = '' ): bool { return true; } }
if ( ! function_exists( 'is_admin' ) ) { function is_admin(): bool { return false; } }
if ( ! function_exists( 'add_action' ) ) { function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void { $GLOBALS['pow_boot_hooks']['action'][$hook][] = $callback; } }
if ( ! function_exists( 'add_filter' ) ) { function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void { $GLOBALS['pow_boot_hooks']['filter'][$hook][] = $callback; } }
if ( ! function_exists( 'add_shortcode' ) ) { function add_shortcode( string $tag, callable $callback ): void { $GLOBALS['pow_boot_hooks']['shortcode'][$tag] = $callback; } }
if ( ! function_exists( 'wc_get_checkout_url' ) ) { function wc_get_checkout_url(): string { return 'https://shop.example.test/checkout/'; } }
if ( ! function_exists( 'sanitize_html_class' ) ) { function sanitize_html_class( string $class ): string { return preg_replace( '/[^A-Za-z0-9_-]/', '', $class ); } }

$GLOBALS['pow_boot_hooks'] = [];
$GLOBALS['pow_test_options'] = [ POW\Settings::OPTION_KEY => [ 'enabled' => 'no' ] ];
$GLOBALS['pow_test_current_user_id'] = 20;
$GLOBALS['pow_test_users'] = [ 20 => (object) [ 'ID' => 20, 'roles' => [ 'customer' ], 'allcaps' => [ 'read' => true ] ] ];

$plugin = POW\Plugin::instance();
$plugin->boot();
$callback = $GLOBALS['pow_boot_hooks']['shortcode']['punchout_cart_exits'] ?? null;
$ordinary = is_callable( $callback ) ? (string) $callback() : '';

// A second signed-in account with no live visit: there are no orphaned
// buyers under one bound login, so it is an ordinary shopper too.
$GLOBALS['pow_test_current_user_id'] = 30;
$GLOBALS['pow_test_users'][30] = (object) [ 'ID' => 30, 'roles' => [ 'customer' ], 'allcaps' => [ 'read' => true ] ];
$signed_in_no_visit = is_callable( $callback ) ? (string) $callback() : '';

// Provisioning is gone: nothing in boot() may load a user-creating class,
// and the class list is the only honest way to see it from outside — the
// autoloader loads a POW class exactly when boot() first names one.
$provisioning = array_values( array_filter(
	get_declared_classes(),
	static fn( string $class ): bool => str_starts_with( $class, 'POW\\' ) && ( str_contains( $class, 'Provisioner' ) || str_contains( $class, 'PayExit' ) )
) );

echo json_encode( [
	'registered' => is_callable( $callback ),
	'ordinary' => $ordinary,
	'signed_in_no_visit' => $signed_in_no_visit,
	'return_shortcode_registered' => isset( $GLOBALS['pow_boot_hooks']['shortcode']['punchout_return_button'] ),
	'router_registered' => isset( $GLOBALS['pow_boot_hooks']['action']['parse_request'] ),
	'filters' => array_keys( $GLOBALS['pow_boot_hooks']['filter'] ?? [] ),
	'actions' => array_keys( $GLOBALS['pow_boot_hooks']['action'] ?? [] ),
	'provisioning_classes' => $provisioning,
], JSON_THROW_ON_ERROR );
