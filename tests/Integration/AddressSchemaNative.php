<?php
/**
 * Opt-in schema acceptance on newly cloned fixture tables in disposable local WP/Woo.
 * Run via WP-CLI eval requiring this file (eval-file prepends code before strict_types).
 * Requires POW_NATIVE_TESTS=disposable, an actual administrator and this candidate installed.
 * Keeps invented tables for inspection; restores the original table prefix and version options.
 * Raw snapshot SQL here tests TEXT readback, not the downstream Store::save_delivery writer.
 * @package POW
 * @license AGPL-3.0-or-later
 */
declare( strict_types = 1 );

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'disposable' !== getenv( 'POW_NATIVE_TESTS' ) || 'local' !== wp_get_environment_type() || ! function_exists( 'WC' ) || ! current_user_can( 'manage_options' ) || ! current_user_can( 'manage_woocommerce' ) || get_current_user_id() <= 0 ) {
	throw new RuntimeException( 'Requires opted-in disposable local WP/Woo CLI and an actual fixture administrator.' );
}

final class AddressSchemaNative {
	private int $passed = 0;
	private const CONFIG = [ 'delivery_code_prefix' => 'varchar(24)', 'delivery_code_extrinsic_name' => 'varchar(64)', 'emit_ship_to' => 'tinyint', 'emit_delivery_code' => 'tinyint', 'emit_delivery_line' => 'tinyint', 'delivery_unknown_policy' => 'varchar(24)', 'delivery_notes_policy' => 'varchar(32)', 'freight_supplier_part_id' => 'varchar(190)', 'freight_uom' => 'varchar(8)', 'freight_classification_domain' => 'varchar(64)', 'freight_classification' => 'varchar(64)' ];
	// Schema seven: the per-visit key and the buyer identity carried as data on the row. Every one nullable — a claim row has no key yet and identity is optional.
	private const VISIT = [ 'wc_session_key' => 'varchar(32)', 'buyer_identity' => 'varchar(190)', 'buyer_name' => 'varchar(190)', 'buyer_identity_hash' => 'char(64)' ];
	private const VISIT_INDEXES = [ 'wc_session_key' => [ 'wc_session_key' ], 'partner_buyer' => [ 'partner_id', 'buyer_identity_hash' ], 'login' => [ 'user_id', 'wp_session_token' ] ];
	private function check( bool $ok, string $label ): void {
		if ( ! $ok ) { throw new RuntimeException( $label ); }
		++$this->passed; echo 'PASS ' . $label . "\n";
	}
	private function sql( string $sql ): void {
		global $wpdb;
		if ( false === $wpdb->query( $sql ) ) { throw new RuntimeException( 'Fixture SQL failed.' ); }
	}
	private function columns( string $table ): array {
		global $wpdb;
		$rows = $wpdb->get_results( "SHOW COLUMNS FROM `$table`", ARRAY_A );
		if ( ! $rows || '' !== $wpdb->last_error ) { throw new RuntimeException( 'Column readback failed.' ); }
		return array_column( $rows, null, 'Field' );
	}
	/** @return array<string, array{unique: bool, columns: list<string>}> */
	private function indexes( string $table ): array {
		global $wpdb;
		$rows = $wpdb->get_results( "SHOW INDEX FROM `$table`", ARRAY_A );
		if ( ! $rows || '' !== $wpdb->last_error ) { throw new RuntimeException( 'Index readback failed.' ); }
		$indexes = [];
		foreach ( $rows as $row ) {
			$name = (string) $row['Key_name'];
			$indexes[ $name ]['unique'] = '0' === (string) $row['Non_unique'];
			$indexes[ $name ]['columns'][ (int) $row['Seq_in_index'] ] = (string) $row['Column_name'];
		}
		foreach ( $indexes as &$index ) { ksort( $index['columns'] ); $index['columns'] = array_values( $index['columns'] ); }
		return $indexes;
	}
	private function row( string $table, int $id ): array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `$table` WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row || '' !== $wpdb->last_error ) { throw new RuntimeException( 'Row readback failed.' ); }
		return $row;
	}
	public function run(): void {
		global $wpdb;
		$prefix = $wpdb->prefix;
		$fixture = $prefix . 'address_schema_' . bin2hex( random_bytes( 5 ) ) . '_';
		$saved = [];
		foreach ( [ 'pow_db_version', 'pow_rewrite_version' ] as $key ) { $saved[ $key ] = get_option( $key, null ); }
		$rewrites = 0;
		$observe_rewrites = static function () use ( &$rewrites ): void { ++$rewrites; };
		add_action( 'generate_rewrite_rules', $observe_rewrites );
		try {
			// Each table is new and isolated. Never downgrade or delete an existing candidate table.
			foreach ( [ 'partners', 'sessions', 'log' ] as $name ) { $this->sql( "CREATE TABLE `{$fixture}pow_{$name}` LIKE `{$prefix}pow_{$name}`" ); }
			$wpdb->prefix = $fixture;
			$partners = POW\Installer::partners_table(); $sessions = POW\Installer::sessions_table();
			foreach ( array_keys( self::VISIT_INDEXES ) as $index ) {
				if ( isset( $this->indexes( $sessions )[ $index ] ) ) { $this->sql( "ALTER TABLE `$sessions` DROP INDEX `$index`" ); }
			}
			foreach ( [ $partners => array_keys( self::CONFIG ), $sessions => array_merge( [ 'delivery_choice', 'delivery_confirmation' ], array_keys( self::VISIT ) ) ] as $table => $new_columns ) {
				$columns = $this->columns( $table );
				foreach ( $new_columns as $column ) { if ( isset( $columns[ $column ] ) ) { $this->sql( "ALTER TABLE `$table` DROP COLUMN `$column`" ); } }
			}
			$secrets = new POW\Partners\Secrets( str_repeat( 'k', 32 ) );
			$before_partner = [ 'id' => 12, 'name' => 'Example schema fixture', 'sender_domain' => 'NetworkID', 'sender_identity' => 'BUYER', 'owner_user_id' => get_current_user_id(), 'status' => 'active', 'mode' => 'dual_exit', 'secret_current' => $secrets->seal( 'old-current' ), 'secret_previous' => $secrets->seal( 'old-previous' ), 'secret_rotated_at' => '2026-01-01 00:00:00', 'company_profile' => '{"book":"preserve"}' ];
			$this->check( 1 === $wpdb->insert( $partners, $before_partner ), 'seed legacy partner' );
			$wp_token = WP_Session_Tokens::get_instance( get_current_user_id() )->create( time() + HOUR_IN_SECONDS );
			$before_session = [ 'id' => 21, 'partner_id' => 12, 'user_id' => get_current_user_id(), 'status' => 'ordered', 'order_id' => 987, 'payload_id' => 'schema-fixture', 'one_time_token_hash' => str_repeat( 'a', 64 ), 'wp_session_token' => $wp_token, 'ship_to' => '{"legacy":"preserve"}' ];
			$this->check( 1 === $wpdb->insert( $sessions, $before_session ), 'seed legacy linked session' );
			$legacy_partner = $this->row( $partners, 12 );
			$legacy_session = $this->row( $sessions, 21 );
			update_option( 'pow_db_version', '3', false ); update_option( 'pow_rewrite_version', '1', false );
			POW\Installer::maybe_upgrade();
			$this->check( POW\Installer::DB_VERSION === get_option( 'pow_db_version' ), 'schema 3 advances to the candidate schema' );
			$partner_columns = $this->columns( $partners ); $session_columns = $this->columns( $sessions );
			foreach ( self::CONFIG as $key => $type ) {
				$this->check( isset( $partner_columns[ $key ] ) && ( 'tinyint' === $type ? str_starts_with( $partner_columns[ $key ]['Type'], 'tinyint' ) : $type === $partner_columns[ $key ]['Type'] ), 'native column type ' . $key );
			}
			foreach ( [ 'delivery_choice', 'delivery_confirmation' ] as $key ) {
				$this->check( 'text' === $session_columns[ $key ]['Type'] && 'YES' === $session_columns[ $key ]['Null'] && null === $session_columns[ $key ]['Default'], 'nullable TEXT ' . $key );
			}
			foreach ( self::VISIT as $key => $type ) {
				$this->check( isset( $session_columns[ $key ] ) && $type === $session_columns[ $key ]['Type'] && 'YES' === $session_columns[ $key ]['Null'] && null === $session_columns[ $key ]['Default'], 'nullable visit column ' . $key );
			}
			$session_indexes = $this->indexes( $sessions );
			foreach ( self::VISIT_INDEXES as $name => $columns ) {
				$this->check( $columns === ( $session_indexes[ $name ]['columns'] ?? [] ) && ( 'wc_session_key' === $name ) === ( $session_indexes[ $name ]['unique'] ?? false ), 'visit index ' . $name );
			}
			$p = $this->row( $partners, 12 ); $s = $this->row( $sessions, 21 );
			$this->check( $legacy_partner === array_intersect_key( $p, $legacy_partner ), 'all legacy partner columns preserved' );
			// Schema seven deliberately closes the open row: its basket carried no
			// per-visit key, and the login it recorded now opens the connection's
			// shared customer account. Every other column survives untouched.
			$preserved_session = array_diff_key( $legacy_session, [ 'status' => null ] );
			$this->check( $preserved_session === array_intersect_key( $s, $preserved_session ) && 'expired' === $s['status'], 'legacy session columns and order reference preserved except the status the upgrade closed' );
			wp_cache_delete( get_current_user_id(), 'user_meta' );
			$this->check( ! WP_Session_Tokens::get_instance( get_current_user_id() )->verify( $wp_token ), 'the upgrade destroyed the WordPress login the open row recorded' );
			$closed = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . POW\Installer::log_table() . ' WHERE event = %s ORDER BY id DESC LIMIT 1', 'schema_visits_closed' ), ARRAY_A );
			$this->check( is_array( $closed ) && str_contains( (string) $closed['detail'], '"visits":1' ) && ! str_contains( (string) $closed['detail'], $wp_token ), 'one audit line counts the closed visits and carries no credential' );
			// Two claims exist before either has minted a key, so the UNIQUE column
			// must be nullable; and one key must never be claimed twice.
			foreach ( [ 22, 23 ] as $claim ) {
				$this->check( 1 === $wpdb->insert( $sessions, [ 'id' => $claim, 'partner_id' => 12, 'status' => 'pending', 'payload_id' => 'schema-fixture-' . $claim, 'one_time_token_hash' => str_repeat( (string) $claim, 32 ) ] ), 'keyless claim row ' . $claim . ' is accepted under the UNIQUE per-visit key' );
			}
			$visit_key = 'pow_' . bin2hex( random_bytes( 14 ) );
			$this->check( 32 === strlen( $visit_key ) && 1 === $wpdb->update( $sessions, [ 'wc_session_key' => $visit_key ], [ 'id' => 22 ] ), 'the first visit claims a 32-character key' );
			$suppressed = $wpdb->suppress_errors( true );
			$duplicate = $wpdb->update( $sessions, [ 'wc_session_key' => $visit_key ], [ 'id' => 23 ] );
			$wpdb->suppress_errors( $suppressed ); $wpdb->last_error = '';
			$this->check( false === $duplicate && null === $this->row( $sessions, 23 )['wc_session_key'], 'the same per-visit key cannot be claimed twice' );
			$this->sql( "DELETE FROM `$sessions` WHERE id IN (22, 23)" );
			$defaults = [ 'delivery_code_prefix' => '', 'delivery_code_extrinsic_name' => 'DeliveryAddressCode', 'emit_ship_to' => '0', 'emit_delivery_code' => '0', 'emit_delivery_line' => '0', 'delivery_unknown_policy' => 'require_rate', 'delivery_notes_policy' => 'off', 'freight_supplier_part_id' => 'DELIVERY', 'freight_uom' => 'EA', 'freight_classification_domain' => 'supplier', 'freight_classification' => 'freight' ];
			$this->check( $defaults === array_intersect_key( $p, $defaults ), 'upgraded rows get safe database defaults' );
			$this->check( null === POW\Sessions\Session::from_row( $s )->delivery_choice() && null === POW\Sessions\Session::from_row( $s )->delivery_confirmation(), 'old session remains unselected and unconfirmed' );
			$registry = new POW\Partners\Registry( $secrets );
			$config = [ 'delivery_code_prefix' => ' abc ', 'emit_ship_to' => true, 'emit_delivery_code' => '1', 'emit_delivery_line' => 1, 'delivery_unknown_policy' => 'quote_separately', 'delivery_notes_policy' => 'item_detail_extrinsic', 'freight_supplier_part_id' => str_repeat( 'é', 190 ), 'freight_uom' => 'EA', 'freight_classification_domain' => 'supplier', 'freight_classification' => 'Livraison', 'delivery_code_extrinsic_name' => 'Destination' ];
			$this->check( $registry->update( 12, $config ), 'checked native configuration write' );
			$hydrated = $registry->find( 12 );
			$this->check( 'ABC' === $hydrated->delivery_code_prefix && $hydrated->emit_delivery_line && $config['freight_supplier_part_id'] === $hydrated->freight_supplier_part_id && 'quote_separately' === $hydrated->delivery_unknown_policy, 'native configuration hydration including Unicode boundary' );
			$before = $this->row( $partners, 12 );
			$this->check( ! $registry->update( 12, [ 'name' => 'must not save', 'freight_uom' => str_repeat( 'é', 9 ) ] ) && $before === $this->row( $partners, 12 ), 'invalid native configuration refuses atomically' );
			$this->check( $registry->update( 12, [ 'emit_delivery_line' => 'false', 'delivery_unknown_policy' => 'unknown', 'delivery_notes_policy' => 'unknown' ] ) && ! $registry->find( 12 )->emit_delivery_line && 'require_rate' === $registry->find( 12 )->delivery_unknown_policy && 'off' === $registry->find( 12 )->delivery_notes_policy, 'native malformed flags and policies fail closed' );
			$this->check( POW\Partners\Secrets::SLOT_CURRENT === $registry->verify_secret( $registry->find( 12 ), 'old-current' ) && POW\Partners\Secrets::SLOT_PREVIOUS === $registry->verify_secret( $registry->find( 12 ), 'old-previous' ), 'both original sealed secrets survive' );
			$choice = [ 'schema' => 1, 'partner_id' => 12, 'storage_user_id' => get_current_user_id(), 'provider' => 'native', 'key' => 'address-a', 'code' => 'ABC-001', 'address' => [ 'first_name' => 'Jane', 'last_name' => 'Buyer', 'company' => 'Example', 'address_1' => '12 Main Road', 'address_2' => '', 'city' => 'Cape Town', 'state' => 'WC', 'postcode' => '8001', 'country' => 'ZA', 'phone' => '' ], 'label' => 'Receiving', 'source' => 'company_book', 'book_revision' => 2, 'entry_fingerprint' => str_repeat( 'b', 64 ) ];
			$raw = wp_json_encode( $choice );
			$this->check( 1 === $wpdb->update( $sessions, [ 'delivery_choice' => $raw ], [ 'id' => 21 ] ), 'native TEXT destination write' );
			$session = POW\Sessions\Session::from_row( $this->row( $sessions, 21 ) );
			$this->check( $choice === $session->delivery_choice() && null === $session->delivery_confirmation(), 'full destination readback does not imply confirmation' );
			$choice['partner_id'] = 13;
			$wpdb->update( $sessions, [ 'delivery_choice' => wp_json_encode( $choice ) ], [ 'id' => 21 ] );
			$refused = false;
			try { POW\Sessions\Session::from_row( $this->row( $sessions, 21 ) )->delivery_choice(); } catch ( DomainException $e ) { $refused = true; }
			$this->check( $refused, 'foreign persisted association is refused' );
			$wpdb->update( $sessions, [ 'delivery_choice' => $raw ], [ 'id' => 21 ] );
			$choice['partner_id'] = 12;
			$confirmation = [ 'schema' => 1, 'session_id' => 21, 'buyer_user_id' => get_current_user_id(), 'choice_hash' => POW\Addresses\DeliveryData::fingerprint( $choice ), 'cart_fingerprint' => str_repeat( 'c', 64 ), 'policy_fingerprint' => str_repeat( 'd', 64 ), 'delivery' => [ 'status' => 'unknown', 'amount_cents' => null, 'currency' => 'ZAR', 'code' => 'ABC-001', 'emit' => false, 'rates' => [], 'freight' => [ 'supplier_part_id' => 'DELIVERY', 'uom' => 'EA', 'classification_domain' => 'supplier', 'classification' => 'freight' ] ], 'notes' => 'Leave at receiving.', 'confirmed_at' => time() ];
			$this->check( 1 === $wpdb->update( $sessions, [ 'delivery_confirmation' => wp_json_encode( $confirmation ) ], [ 'id' => 21 ] ), 'native TEXT confirmation write' );
			$this->check( $confirmation === POW\Sessions\Session::from_row( $this->row( $sessions, 21 ) )->delivery_confirmation(), 'full bound confirmation readback preserves unknown rate and notes' );
			$wpdb->update( $sessions, [ 'delivery_confirmation' => '{}' ], [ 'id' => 21 ] );
			$refused = false;
			try { POW\Sessions\Session::from_row( $this->row( $sessions, 21 ) )->delivery_confirmation(); } catch ( DomainException $e ) { $refused = true; }
			$this->check( $refused, 'malformed persisted confirmation is distinct from absent confirmation' );
			$wpdb->update( $sessions, [ 'delivery_confirmation' => wp_json_encode( $confirmation ) ], [ 'id' => 21 ] );
			$before_p = $this->row( $partners, 12 ); $before_s = $this->row( $sessions, 21 );
			update_option( 'pow_db_version', '3', false ); POW\Installer::maybe_upgrade();
			$this->check( $before_p === $this->row( $partners, 12 ) && $before_s === $this->row( $sessions, 21 ) && $partner_columns === $this->columns( $partners ) && $session_columns === $this->columns( $sessions ) && $session_indexes === $this->indexes( $sessions ), 'second actual dbDelta migration is idempotent, indexes included' );
			$ddl = [];
			$observe_ddl = static function ( string $sql ) use ( &$ddl ): string { if ( preg_match( '/\A\s*(CREATE|ALTER|DROP)\b/i', $sql ) ) { $ddl[] = $sql; } return $sql; };
			add_filter( 'query', $observe_ddl );
			try { POW\Installer::maybe_upgrade(); } finally { remove_filter( 'query', $observe_ddl ); }
			$this->check( [] === $ddl, 'current revision skips all schema DDL' );
			$this->check( 0 === $rewrites && '1' === get_option( 'pow_rewrite_version' ), 'schema migration preserves independent rewrite revision without flush' );
			echo "Native address schema: {$this->passed} passed, 0 failed, 0 skipped\n";
			echo 'Retained fixture prefix: ' . $fixture . "\n";
		} finally {
			$wpdb->prefix = $prefix;
			remove_action( 'generate_rewrite_rules', $observe_rewrites );
			foreach ( $saved as $key => $value ) { if ( null === $value ) { delete_option( $key ); } else { update_option( $key, $value, false ); } }
		}
	}
}
( new AddressSchemaNative() )->run();
