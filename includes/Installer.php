<?php
/**
 * Activation, deactivation and schema management.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the three custom tables (scope §4) and the punchout_buyer role.
 *
 * Custom indexed tables rather than options/postmeta, per the connector's
 * reasoning: sessions and audit rows are queried on every punchout request
 * and appended constantly; options would blow the alloptions cache and
 * postmeta indexes poorly. Everything is removed by uninstall.php.
 *
 * Schema notes (deviations from the scope's column types, both deliberate):
 * - ENUM columns are VARCHAR: dbDelta's parser mangles ENUM changes on
 *   upgrade; values are validated in code instead.
 * - Sealed secrets and client IPs are stored base64/presentation-form in
 *   VARCHAR rather than VARBINARY: wpdb has no binary-safe placeholder and
 *   silently corrupts non-UTF8 bytes on some charset configurations.
 */
final class Installer {

	public const DB_VERSION     = '6';
	public const DB_VERSION_KEY = 'pow_db_version';
	// Routing changes independently of the table schema.
	public const REWRITE_VERSION = '1';
	public const REWRITE_VERSION_KEY = 'pow_rewrite_version';

	public const ROLE = 'punchout_buyer';

	public static function partners_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'pow_partners';
	}

	public static function sessions_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'pow_sessions';
	}

	public static function log_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'pow_log';
	}

	public static function activate(): void {
		self::install_schema();
		self::register_role();
		update_option( self::DB_VERSION_KEY, self::DB_VERSION, false );
		self::install_rewrites();

		// Nothing is scheduled on activation. The GC job is (re)scheduled
		// lazily from Cron::register() on boot, so a deactivate/reactivate
		// cycle self-heals without duplicate schedules.
	}

	public static function deactivate(): void {
		// Cancel queued GC work so a deactivated plugin does not leave
		// orphaned Action Scheduler rows firing against missing callbacks.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Cron::HOOK, [], Cron::GROUP );
		}

		wp_clear_scheduled_hook( Cron::HOOK );

		// init already registered our endpoint in this request. Remove it before
		// rebuilding, otherwise the deactivation flush would preserve its rules.
		global $wp_rewrite;
		$wp_rewrite->endpoints = array_values( array_filter( $wp_rewrite->endpoints, static fn( array $endpoint ): bool => Account\IntegrationTab::ENDPOINT !== $endpoint[1] ) );
		flush_rewrite_rules( false );
		delete_option( self::REWRITE_VERSION_KEY );

		// Tables, settings and the role stay: deactivation is not
		// uninstallation, and live punchout sessions reference all three.
	}

	/**
	 * Run on admin_init so a plugin file update migrates the schema without
	 * requiring a deactivate/reactivate cycle.
	 */
	public static function maybe_upgrade(): void {
		if ( (string) get_option( self::DB_VERSION_KEY, '0' ) !== self::DB_VERSION ) {
			self::install_schema();
			self::register_role();
			self::drop_retired_columns();
			update_option( self::DB_VERSION_KEY, self::DB_VERSION, false );
		}

		if ( (string) get_option( self::REWRITE_VERSION_KEY, '0' ) !== self::REWRITE_VERSION ) {
			self::install_rewrites();
		}
	}

	private static function install_rewrites(): void {
		add_rewrite_endpoint( Account\IntegrationTab::ENDPOINT, EP_PAGES );
		flush_rewrite_rules( false );
		update_option( self::REWRITE_VERSION_KEY, self::REWRITE_VERSION, false );
	}

	/**
	 * v2 retired two features into extension points: partner group mapping
	 * became the pow_buyer_provisioned hook, and the SKU map (buyer-side
	 * part numbers are the buyer's own concern) became the pow_poom_lines
	 * filter. dbDelta never drops anything, so the leftovers are removed
	 * explicitly (guarded — MySQL has no DROP COLUMN IF EXISTS).
	 */
	private static function drop_retired_columns(): void {
		global $wpdb;

		$table = self::partners_table();

		foreach ( [ 'b2bking_company_user_id', 'b2bking_group_id' ] as $column ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( null !== $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ALTER TABLE {$table} DROP COLUMN {$column}" );
			}
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'pow_skumap' );
	}

	/**
	 * Register the punchout_buyer role.
	 *
	 * The role name is public API: site audience rules can gate on it, so treat a rename as a
	 * breaking change. Capabilities mirror the Woo customer role:
	 * read-only, no admin access.
	 */
	public static function register_role(): void {
		if ( get_role( self::ROLE ) ) {
			return;
		}

		$customer = get_role( 'customer' );
		$caps     = $customer ? $customer->capabilities : [ 'read' => true ];

		add_role( self::ROLE, __( 'Punchout Buyer', 'punchout-woocommerce' ), $caps );
	}

	private static function install_schema(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$from_version    = (int) get_option( self::DB_VERSION_KEY, '0' );
		$charset_collate = $wpdb->get_charset_collate();
		$partners        = self::partners_table();
		$sessions        = self::sessions_table();
		$log             = self::log_table();
		$previous_errors = $wpdb->suppress_errors( true );
		$prior_column    = $wpdb->get_results( "SHOW COLUMNS FROM {$partners} LIKE 'exit_policy'", ARRAY_A );
		$wpdb->suppress_errors( $previous_errors );
		$had_exit_policy = is_array( $prior_column ) && 1 === count( $prior_column );
		$wpdb->last_error = '';

		// Trading-partner registry (scope §4.1). One row per buyer-side
		// tenant; the (sender_domain, sender_identity) pair is the auth
		// lookup key for inbound PunchOutSetupRequests. `mode` retains the
		// client-required per-partner flag: requisition_only (RFQ exit only,
		// checkout blocked for that partner's punchout sessions) or
		// dual_exit (RFQ button plus the untouched stock checkout).
		$sql_partners = "CREATE TABLE {$partners} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'active',
			owner_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			from_domain VARCHAR(190) NOT NULL DEFAULT '',
			from_identity VARCHAR(190) NOT NULL DEFAULT '',
			sender_domain VARCHAR(190) NOT NULL,
			sender_identity VARCHAR(190) NOT NULL,
			to_domain VARCHAR(190) NOT NULL DEFAULT '',
			to_identity VARCHAR(190) NOT NULL DEFAULT '',
			secret_current VARCHAR(512) NOT NULL DEFAULT '',
			secret_previous VARCHAR(512) NOT NULL DEFAULT '',
			secret_rotated_at DATETIME NULL,
			cxml_version VARCHAR(16) NOT NULL DEFAULT '1.2.008',
			deployment_mode VARCHAR(16) NOT NULL DEFAULT 'test',
			return_encoding VARCHAR(16) NOT NULL DEFAULT 'base64',
			mode VARCHAR(32) NOT NULL DEFAULT 'requisition_only',
			exit_policy VARCHAR(32) NOT NULL DEFAULT 'punchout_and_checkout',
			allow_reentry TINYINT NOT NULL DEFAULT 0,
			allcaps_transform TINYINT NOT NULL DEFAULT 0,
			gateway_allowlist TEXT NULL,
			company_profile TEXT NULL,
			ip_allowlist TEXT NULL,
			session_ttl INT UNSIGNED NOT NULL DEFAULT 14400,
			token_ttl INT UNSIGNED NOT NULL DEFAULT 300,
			delivery_code_prefix VARCHAR(24) NOT NULL DEFAULT '',
			delivery_code_extrinsic_name VARCHAR(64) NOT NULL DEFAULT 'DeliveryAddressCode',
			emit_ship_to TINYINT NOT NULL DEFAULT 0,
			emit_delivery_code TINYINT NOT NULL DEFAULT 0,
			emit_delivery_line TINYINT NOT NULL DEFAULT 0,
			delivery_unknown_policy VARCHAR(24) NOT NULL DEFAULT 'require_rate',
			delivery_notes_policy VARCHAR(32) NOT NULL DEFAULT 'off',
			freight_supplier_part_id VARCHAR(190) NOT NULL DEFAULT 'DELIVERY',
			freight_uom VARCHAR(8) NOT NULL DEFAULT 'EA',
			freight_classification_domain VARCHAR(64) NOT NULL DEFAULT 'supplier',
			freight_classification VARCHAR(64) NOT NULL DEFAULT 'freight',
			created DATETIME NULL,
			updated DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY sender (sender_domain, sender_identity),
			KEY status (status),
			KEY owner_user_id (owner_user_id)
		) {$charset_collate};";

		// Punchout session store (scope §4.2). One row per
		// PunchOutSetupRequest; the state machine is
		// pending -> active -> returned|ordered|closed, plus expired via
		// cron. response_xml holds the exact SetupResponse for the
		// pending-state replay rule (§7): a duplicate payloadID with an
		// identical body while pending replays the stored response
		// byte-identically. cart_ready defers the create-login empty_cart()
		// to the first authenticated request, where WC()->cart is the
		// buyer's own session.
		$sql_sessions = "CREATE TABLE {$sessions} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			partner_id BIGINT UNSIGNED NOT NULL,
			buyer_cookie TEXT NULL,
			operation VARCHAR(16) NOT NULL DEFAULT 'create',
			browser_form_post_url TEXT NULL,
			selected_item TEXT NULL,
			ship_to TEXT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			wp_session_token VARCHAR(64) NOT NULL DEFAULT '',
			one_time_token_hash CHAR(64) NOT NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'pending',
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			payload_id VARCHAR(190) NOT NULL DEFAULT '',
			body_hash CHAR(64) NOT NULL DEFAULT '',
			response_xml MEDIUMTEXT NULL,
			cxml_version VARCHAR(16) NOT NULL DEFAULT '',
			deployment_mode VARCHAR(16) NOT NULL DEFAULT '',
			extrinsics TEXT NULL,
			itemout_lines TEXT NULL,
			cart_ready TINYINT NOT NULL DEFAULT 0,
			delivery_choice TEXT NULL,
			delivery_confirmation TEXT NULL,
			created DATETIME NULL,
			expires DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (one_time_token_hash),
			UNIQUE KEY partner_payload (partner_id, payload_id),
			KEY partner_status (partner_id, status),
			KEY user_id (user_id),
			KEY expires (expires)
		) {$charset_collate};";

		// Audit / compliance trail (scope §4.4). WooCommerce log files
		// rotate away; this is a financial integration and the dispute
		// evidence (prices quoted to a named buyer at a timestamp) must
		// not. Secrets are redacted before any row is written.
		$sql_log = "CREATE TABLE {$log} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ts DATETIME(3) NULL,
			partner_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			session_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			event VARCHAR(40) NOT NULL,
			direction VARCHAR(10) NOT NULL DEFAULT 'internal',
			payload_id VARCHAR(190) NOT NULL DEFAULT '',
			result VARCHAR(20) NOT NULL DEFAULT '',
			detail TEXT NULL,
			xml LONGTEXT NULL,
			ip VARCHAR(45) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY partner_ts (partner_id, ts),
			KEY session_id (session_id),
			KEY payload_id (payload_id),
			KEY event (event)
		) {$charset_collate};";

		dbDelta( $sql_partners );
		$exit_column = $wpdb->get_results( "SHOW COLUMNS FROM {$partners} LIKE 'exit_policy'", ARRAY_A );
		if ( '' !== $wpdb->last_error || count( $exit_column ?? [] ) !== 1 ) { throw new \RuntimeException( 'Exit policy schema upgrade failed.' ); }
		// Schema four had no explicit policy. A partial schema-five run may have the column and explicit rows already, so only its inherited rows are retryable.
		if ( $from_version < 5 ) {
			$where = $had_exit_policy ? " WHERE exit_policy = 'inherit'" : '';
			if ( false === $wpdb->query( "UPDATE {$partners} SET exit_policy = CASE WHEN mode = 'dual_exit' THEN 'punchout_and_checkout' ELSE 'punchout_only' END{$where}" ) ) {
				throw new \RuntimeException( 'Exit policy migration failed.' );
			}
		}
		if ( $from_version < 6 ) { self::freeze_inherited_exit_policies(); }
		dbDelta( $sql_sessions );
		dbDelta( $sql_log );
	}

	/** Freeze schema-five inheritance to its prior effective cap before the schema-six marker advances. */
	public static function freeze_inherited_exit_policies(): void {
		global $wpdb;
		$legacy = ( new Settings() )->exit_policy();
		if ( ! Checkout\ExitPolicy::valid( $legacy ) ) { throw new \RuntimeException( 'Legacy exit policy unavailable.' ); }
		$frozen = Checkout\ExitPolicy::CHECKOUT === $legacy ? Checkout\ExitPolicy::CHECKOUT : Checkout\ExitPolicy::ONLY;
		$wpdb->last_error = '';
		$result = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::partners_table() . ' SET exit_policy = %s WHERE exit_policy = %s', $frozen, Checkout\ExitPolicy::INHERIT ) );
		if ( false === $result || '' !== ( $wpdb->last_error ?? '' ) ) { throw new \RuntimeException( 'Exit policy migration failed.' ); }
		$remaining = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::partners_table() . ' WHERE exit_policy = %s', Checkout\ExitPolicy::INHERIT ) );
		if ( '' !== ( $wpdb->last_error ?? '' ) || 0 !== (int) $remaining ) { throw new \RuntimeException( 'Exit policy migration could not be verified.' ); }
	}
}
