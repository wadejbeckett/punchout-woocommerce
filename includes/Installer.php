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
 * Creates the three custom tables (scope §4). No user, role or capability
 * is ever created: buyers shop as a customer account the site already had.
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

	public const DB_VERSION     = '7';
	public const DB_VERSION_KEY = 'pow_db_version';
	// Routing changes independently of the table schema.
	public const REWRITE_VERSION = '1';
	public const REWRITE_VERSION_KEY = 'pow_rewrite_version';

	/**
	 * The session columns schema seven adds, read back after dbDelta().
	 *
	 * dbDelta() runs its ALTERs and discards every failure — it returns the
	 * changes it intended, not the ones the server applied — so an ALTER
	 * killed by a lock wait or a DDL timeout is otherwise indistinguishable
	 * from success. Recording DB_VERSION over a migration that did not land
	 * breaks every visit permanently: the setup insert and bind_login() name
	 * columns that are not there, and maybe_upgrade() never comes back.
	 */
	private const SESSIONS_COLUMNS = [ 'wc_session_key', 'buyer_identity', 'buyer_name', 'buyer_identity_hash' ];

	/**
	 * The session indexes schema seven adds, and whether each must be UNIQUE.
	 *
	 * `wc_session_key` UNIQUE is what keeps one basket to one visit; the other
	 * two are the lookups the per-identity supersede and the login resolver
	 * ride on.
	 *
	 * @var array<string, bool>
	 */
	private const SESSIONS_INDEXES = [ 'wc_session_key' => true, 'partner_buyer' => false, 'login' => false ];

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
		if ( self::install_schema() ) {
			update_option( self::DB_VERSION_KEY, self::DB_VERSION, false );
		}

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

		// Tables and settings stay: deactivation is not uninstallation,
		// and live punchout visits reference both.
	}

	/**
	 * Run on admin_init so a plugin file update migrates the schema without
	 * requiring a deactivate/reactivate cycle.
	 *
	 * The version marker only advances when install_schema() proved the
	 * migration landed. A migration that did not land leaves the marker where
	 * it was, so this runs again on the next admin request — and logs one
	 * error line rather than fataling a wp-admin page load.
	 */
	public static function maybe_upgrade(): void {
		if ( (string) get_option( self::DB_VERSION_KEY, '0' ) !== self::DB_VERSION && self::install_schema() ) {
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
	 * Create or migrate the three tables.
	 *
	 * @return bool True when the schema this release needs is present afterwards.
	 *              False means the migration did not land and the caller must
	 *              leave the version marker alone.
	 */
	private static function install_schema(): bool {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$from_version    = (int) get_option( self::DB_VERSION_KEY, '0' );
		$charset_collate = $wpdb->get_charset_collate();
		$partners        = self::partners_table();
		$sessions        = self::sessions_table();
		$log             = self::log_table();

		// Trading-partner registry (scope §4.1). One row per buyer-side
		// tenant; the (sender_domain, sender_identity) pair is the auth
		// lookup key for inbound PunchOutSetupRequests. owner_user_id is the
		// customer account every buyer of that tenant punches in as; a row
		// without one cannot start a visit. `mode` and `exit_policy` no
		// longer select behaviour — checkout is blocked inside every visit,
		// so only requisition_only / punchout_only are ever written.
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
			exit_policy VARCHAR(32) NOT NULL DEFAULT 'punchout_only',
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

		// Punchout session store (scope §4.2). One row per visit, and many
		// rows per user_id: a connection has one bound customer account and
		// every buyer of that customer punches in as it. What separates two
		// visits of the same account is wc_session_key (that visit's own
		// WooCommerce basket, 32 characters, `pow_`-prefixed and UNIQUE) and
		// wp_session_token (that visit's own WordPress login) — never
		// user_id. buyer_identity/buyer_name attribute the visit and may be
		// empty; buyer_identity_hash is the indexed form used to supersede a
		// buyer's own earlier visit. wc_session_key is nullable with no
		// default because a claim row is inserted before its key is minted:
		// MySQL allows many NULLs under one UNIQUE key, where a
		// NOT NULL DEFAULT '' column would reject the second claim.
		//
		// The state machine is pending -> active -> returned|ordered|closed,
		// plus expired via cron. response_xml holds the exact SetupResponse
		// for the pending-state replay rule (§7): a duplicate payloadID with
		// an identical body while pending replays the stored response
		// byte-identically. cart_ready defers the empty_cart() to the first
		// authenticated request, where WC()->cart is this visit's basket.
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
			wc_session_key VARCHAR(32) NULL DEFAULT NULL,
			buyer_identity VARCHAR(190) NULL,
			buyer_name VARCHAR(190) NULL,
			buyer_identity_hash CHAR(64) NULL,
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
			UNIQUE KEY wc_session_key (wc_session_key),
			KEY partner_status (partner_id, status),
			KEY partner_buyer (partner_id, buyer_identity_hash),
			KEY login (user_id, wp_session_token),
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
		dbDelta( $sql_sessions );
		if ( $from_version < 7 ) { self::close_visits_without_a_key(); }
		dbDelta( $sql_log );

		$faults = self::sessions_schema_faults();

		if ( [] !== $faults ) {
			// One line, and no exception: this runs on admin_init, where a
			// throw would take the whole wp-admin page with it. The version
			// marker stays behind, so the next admin request tries again.
			( new Logger( new Settings() ) )->error(
				'Schema ' . self::DB_VERSION . ' did not land on ' . $sessions . ': ' . implode( '; ', $faults )
				. '. The recorded schema version stays at ' . $from_version . ' and the migration is retried on the next admin request.'
			);

			return false;
		}

		return true;
	}

	/**
	 * What schema seven asked for and the sessions table does not have, as a
	 * list of one-line faults. Empty means the migration landed.
	 *
	 * A table that cannot be read at all is itself a fault: an unreadable
	 * answer is not evidence of a completed migration.
	 *
	 * @return list<string>
	 */
	private static function sessions_schema_faults(): array {
		global $wpdb;

		$sessions = self::sessions_table();
		$faults   = [];

		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$columns = $wpdb->get_results( "SHOW COLUMNS FROM {$sessions}", ARRAY_A );
		if ( ! is_array( $columns ) || [] === $columns || '' !== (string) ( $wpdb->last_error ?? '' ) ) {
			return [ 'its columns could not be read' ];
		}

		$present = array_map( 'strval', array_column( $columns, 'Field' ) );
		foreach ( self::SESSIONS_COLUMNS as $column ) {
			if ( ! in_array( $column, $present, true ) ) { $faults[] = 'column ' . $column . ' is missing'; }
		}

		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$sessions}", ARRAY_A );
		if ( ! is_array( $indexes ) || [] === $indexes || '' !== (string) ( $wpdb->last_error ?? '' ) ) {
			$faults[] = 'its indexes could not be read';

			return $faults;
		}

		foreach ( self::SESSIONS_INDEXES as $index => $unique ) {
			$rows = array_values( array_filter( $indexes, static fn( array $row ): bool => $index === (string) ( $row['Key_name'] ?? '' ) ) );

			if ( [] === $rows ) {
				$faults[] = 'index ' . $index . ' is missing';

				continue;
			}

			// SHOW INDEX reports Non_unique 0 for a UNIQUE key. A visit key
			// that is merely indexed lets two visits share one basket row.
			if ( $unique && 0 !== (int) ( $rows[0]['Non_unique'] ?? 1 ) ) {
				$faults[] = 'index ' . $index . ' is not UNIQUE';
			}
		}

		return $faults;
	}

	/**
	 * Schema seven: close every visit an earlier schema left open.
	 *
	 * Before seven a punchout login was one WordPress user per buyer. From
	 * seven it is the connection's own customer account, one basket per
	 * visit, separated by a per-visit wc_session_key that no older row
	 * carries. Resuming such a row would put a live auth cookie on the
	 * shared account with no basket of its own, so the rows are expired and
	 * the logins they recorded are destroyed.
	 *
	 * Tokens go first, and an unverifiable destroy throws before any row
	 * changes status: install_schema() then fails, the version marker never
	 * advances, and the retry still finds the same open rows to work on.
	 */
	private static function close_visits_without_a_key(): void {
		global $wpdb;

		$sessions = self::sessions_table();
		$open     = [ Sessions\Session::PENDING, Sessions\Session::ACTIVE, Sessions\Session::ORDERED ];

		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, user_id, wp_session_token FROM {$sessions} WHERE status IN (%s, %s, %s)", ...$open ), ARRAY_A );
		if ( '' !== (string) ( $wpdb->last_error ?? '' ) ) { throw new \RuntimeException( 'Open punchout visits could not be read.' ); }
		if ( ! $rows ) { return; }

		foreach ( $rows as $row ) {
			self::destroy_recorded_login( (int) ( $row['user_id'] ?? 0 ), (string) ( $row['wp_session_token'] ?? '' ) );
		}

		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$sessions} SET status = %s WHERE status IN (%s, %s, %s)", Sessions\Session::EXPIRED, ...$open ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$remaining = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$sessions} WHERE status IN (%s, %s, %s)", ...$open ) );
		if ( false === $updated || '' !== (string) ( $wpdb->last_error ?? '' ) || 0 !== (int) $remaining ) {
			throw new \RuntimeException( 'Open punchout visits could not be verified as expired.' );
		}

		// One line, after the fact: the count is the evidence an operator
		// needs for baskets that vanished mid-flow. No identities are named.
		( new Audit\Log( new Logger( new Settings() ) ) )->write(
			'schema_visits_closed',
			[ 'detail' => [ 'schema' => self::DB_VERSION, 'visits' => count( $rows ) ] ]
		);
	}

	/**
	 * Destroy one recorded WordPress login, or refuse to continue.
	 *
	 * Native destroy() is void and the token map is cached in user meta, so a
	 * fresh verification after the write — not destroy()'s return — is what
	 * confirms the login is gone.
	 */
	private static function destroy_recorded_login( int $user_id, string $token ): void {
		global $wpdb;

		if ( $user_id <= 0 || '' === $token ) { return; }

		wp_cache_delete( $user_id, 'user_meta' );
		if ( ! \WP_Session_Tokens::get_instance( $user_id )->verify( $token ) ) { return; }

		$wpdb->last_error = '';
		\WP_Session_Tokens::get_instance( $user_id )->destroy( $token );
		wp_cache_delete( $user_id, 'user_meta' );
		if ( '' !== (string) ( $wpdb->last_error ?? '' ) || \WP_Session_Tokens::get_instance( $user_id )->verify( $token ) ) {
			throw new \RuntimeException( 'A punchout login could not be verifiably destroyed.' );
		}
	}
}
