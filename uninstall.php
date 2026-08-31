<?php
/**
 * Uninstall routine.
 *
 * Runs only when the plugin is deleted from wp-admin, never on deactivation.
 *
 * Punchout BUYER USERS are deliberately LEFT IN PLACE: they carry order
 * attribution (who bought what, on which paid punchout order) and deleting
 * users from an uninstall hook would orphan WooCommerce orders. The
 * punchout_buyer role definition is removed; former buyers keep their user
 * rows but lose the role's capabilities.
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

remove_role( 'punchout_buyer' );

wp_clear_scheduled_hook( 'pow_gc' );

foreach ( [ 'pow_partners', 'pow_sessions', 'pow_skumap', 'pow_log' ] as $suffix ) { // pow_skumap: retired in v2, dropped here for sites that never ran the migration.
	$table = $wpdb->prefix . $suffix;

	// Table name is built from $wpdb->prefix and a hard-coded suffix, never
	// from input, so interpolation here is safe.
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

// Rate-limit counters are short-TTL transients keyed per partner+IP; sweep
// any stragglers so no plugin-prefixed rows outlive the uninstall.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_pow\_%'
	    OR option_name LIKE '\_transient\_timeout\_pow\_%'"
);
