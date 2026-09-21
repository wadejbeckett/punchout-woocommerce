<?php
/**
 * Uninstall routine.
 *
 * Runs only when the plugin is deleted from wp-admin, never on deactivation.
 *
 * NO USER IS TOUCHED. The plugin creates no users, no roles and no
 * capabilities: a connection punches in as a customer account the site
 * owner already had, buyer identity is data on a session row, and those
 * accounts are ordinary WooCommerce customers that outlive the plugin with
 * their orders. A site upgraded from a version that did create a buyer role
 * keeps that role definition and whatever accounts still hold it — removing
 * a role here would strip capabilities from accounts this plugin does not
 * own, and it no longer knows which those are.
 *
 * Per-visit WooCommerce sessions ARE ours: every visit owns a
 * `pow_`-prefixed row in WooCommerce's own session table, so those rows are
 * swept here. Rows belonging to ordinary shoppers are left alone.
 *
 * The audit log table IS dropped with the rest — it is the plugin's own
 * bookkeeping. If the compliance trail must outlive the plugin, export it
 * first, or define POW_KEEP_DATA in wp-config.php to keep all
 * tables and options.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( defined( 'POW_KEEP_DATA' ) && POW_KEEP_DATA ) {
	return;
}

global $wpdb;

delete_option( 'pow_settings' );
delete_option( 'pow_db_version' );
delete_option( 'pow_rewrite_version' );

wp_clear_scheduled_hook( 'pow_gc' );

foreach ( [ 'pow_partners', 'pow_sessions', 'pow_skumap', 'pow_log' ] as $suffix ) { // pow_skumap: retired in v2, dropped here for sites that never ran the migration.
	$table = $wpdb->prefix . $suffix;

	// Table name is built from $wpdb->prefix and a hard-coded suffix, never
	// from input, so interpolation here is safe.
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

// Per-visit baskets live in WooCommerce's session table under a `pow_` key.
// Guarded: WooCommerce may already have been removed, and MySQL has no
// DELETE ... IF EXISTS.
$wc_sessions = $wpdb->prefix . 'woocommerce_sessions';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wc_sessions ) ) ) === $wc_sessions ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( "DELETE FROM {$wc_sessions} WHERE session_key LIKE 'pow\_%'" );
}

// Rate-limit counters are short-TTL transients keyed per partner+IP; sweep
// any stragglers so no plugin-prefixed rows outlive the uninstall.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_pow\_%'
	    OR option_name LIKE '\_transient\_timeout\_pow\_%'"
);
