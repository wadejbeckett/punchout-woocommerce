<?php
/**
 * Opt-in CompanyBook persistence and independent-connection barrier worker.
 * Requires the coordinator's disposable local WordPress/WooCommerce CLI, with this candidate installed.
 * POW_NATIVE_TESTS=disposable; load this file using wp --user=<admin> eval 'require getenv("POW_NATIVE_SCRIPT");'.
 * Default POW_BOOK_MODE=suite. Barrier harness modes seed/race/inspect/hold use private JSON files named by POW_BOOK_FIXTURE/POW_BOOK_RESULT and a private POW_BOOK_BARRIER directory. Fixtures remain for inspection; no production bootstrap, cleanup or shared configuration writes occur here.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */
declare( strict_types = 1 );

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'disposable' !== getenv( 'POW_NATIVE_TESTS' ) || 'local' !== wp_get_environment_type() || ! current_user_can( 'manage_woocommerce' ) || ! function_exists( 'WC' ) ) {
	throw new RuntimeException( 'Requires opted-in disposable local WordPress/WooCommerce CLI and a capable fixture administrator.' );
}

final class CompanyBookNative {
	private POW\Partners\Registry $registry;
	private POW\Addresses\CompanyBook $book;
	private int $admin;
	private int $passed = 0;
	private int $failed = 0;
	public function __construct() {
		$this->registry = POW\Plugin::instance()->registry();
		$this->book = new POW\Addresses\CompanyBook( $this->registry, POW\Plugin::instance()->audit(), new POW\Sessions\Current( POW\Plugin::instance()->sessions() ) );
		$this->admin = get_current_user_id();
	}
	private function check( bool $ok, string $label ): void { if ( ! $ok ) { ++$this->failed; throw new RuntimeException( 'FAIL ' . $label ); } ++$this->passed; echo 'PASS ' . $label . "\n"; }
	private function user( string $role = 'customer' ): int {
		$suffix = bin2hex( random_bytes( 8 ) );
		$id = wp_insert_user( [ 'user_login' => 'book-' . $suffix, 'user_email' => $suffix . '@example.invalid', 'user_pass' => wp_generate_password(), 'role' => $role ] );
		if ( is_wp_error( $id ) || $id <= 0 ) { throw new RuntimeException( 'Native fixture creation failed.' ); }
		return (int) $id;
	}
	public function seed(): array {
		$owner = $this->user(); $suffix = bin2hex( random_bytes( 8 ) );
		$id = $this->registry->insert( [ 'name' => 'Example delivery company', 'owner_user_id' => $owner, 'status' => 'active', 'from_domain' => 'NetworkID', 'from_identity' => 'buyer-' . $suffix, 'sender_domain' => 'NetworkID', 'sender_identity' => 'buyer-' . $suffix, 'to_domain' => 'NetworkID', 'to_identity' => 'supplier', 'mode' => 'requisition_only', 'deployment_mode' => 'test', 'return_encoding' => 'base64', 'cxml_version' => '1.2.008', 'delivery_code_prefix' => 'BUYER' ] );
		if ( $id <= 0 ) { throw new RuntimeException( 'Native partner creation failed.' ); }
		return [ 'id' => $id, 'owner' => $owner, 'meta_key' => '_pow_delivery_book_' . $id ];
	}
	private function fields( array $changes = [] ): array {
		return array_replace( [ 'label' => 'Zoë 李 / "Depot" \\ West', 'address' => [ 'first_name' => 'Zoë', 'last_name' => "O'Neil", 'company' => 'A&B / "Depot"', 'address_1' => "12 O'Neil \\ Yard / 李", 'city' => 'Oakland', 'state' => 'CA', 'country' => 'US', 'postcode' => '94612', 'phone' => '+1 (510) 555-0100' ], 'code' => '', 'use_for_punchout' => false ], $changes );
	}
	private function save( array $f, int $revision, ?string $key = null, array $changes = [] ): array|WP_Error { wp_set_current_user( $f['owner'] ); return $this->book->save( $f['id'], $f['owner'], $revision, $key, $this->fields( $changes ) ); }
	private function snapshot( array $f ): array {
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT meta_value FROM ' . $wpdb->usermeta . ' WHERE user_id = %d AND meta_key = %s', $f['owner'], $f['meta_key'] ) );
		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) { throw new RuntimeException( 'Native observer failed.' ); }
		return [ 'rows' => count( $rows ), 'book' => 1 === count( $rows ) ? unserialize( $rows[0], [ 'allowed_classes' => false ] ) : null, 'audit' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . POW\Installer::log_table() . ' WHERE partner_id = %d AND event IN (%s, %s)', $f['id'], 'address_book_saved', 'address_book_removed' ) ) ];
	}
	private function refused( mixed $result ): bool { return is_wp_error( $result ) && strlen( $result->get_error_message() ) < 256 && ! str_contains( $result->get_error_message(), 'PRIVATE' ); }
	public function suite(): void {
		global $wpdb;
		try {
			$f = $this->seed(); wp_set_current_user( $f['owner'] );
			$this->check( 0 === $this->book->read( $f['id'], $f['owner'] )['revision'], 'missing aggregate reads revision zero' );
			$fields = $this->fields(); $a = $this->book->save( $f['id'], $f['owner'], 0, null, wp_unslash( wp_slash( $fields ) ) );
			$this->check( is_array( $a ) && 'BUYER-001' === $a['entry']['code'], 'first native create allocates code' );
			$this->check( POW\Addresses\DeliveryData::fingerprint( [ 'key' => $a['key'] ] + $a['entry'] ) === POW\Addresses\CompanyBook::entry_fingerprint( $a['key'], $a['entry'] ), 'native checked entry shares DeliveryData canonical fingerprint' );
			$s = $this->snapshot( $f );
			$this->check( 1 === $s['rows'] && 1 === $s['audit'] && $s['book']['addresses'][$a['key']] === $a['entry'], 'one aggregate and verified audit on raw database readback' );
			$this->check( $fields['label'] === $a['entry']['label'] && $fields['address']['address_1'] === $a['entry']['address']['address_1'] && ! $a['entry']['use_for_punchout'], 'native quotes slashes Unicode exact and import default disabled' );
			$b = $this->save( $f, 1, $a['key'], [ 'use_for_punchout' => true ] );
			$this->check( is_array( $b ) && 2 === $b['revision'] && $b['entry']['use_for_punchout'], 'previous-value native CAS preserves unslashed punctuation while enabling' );
			$c = $this->save( $f, 2, $a['key'], [ 'use_for_punchout' => true ] );
			$this->check( is_array( $c ) && ! $c['changed'] && 2 === $this->snapshot( $f )['audit'], 'verified no-op advances neither revision nor audit' );
			$this->check( $this->refused( $this->save( $f, 1, $a['key'] ) ), 'stale save refuses' );
			$this->check( $this->refused( $this->book->remove( $f['id'], $f['owner'], 1, $a['key'] ) ), 'stale removal refuses' );
			$d = $this->save( $f, 2 ); $this->check( is_array( $d ) && 'BUYER-002' === $d['entry']['code'], 'second generated code advances' );
			$this->check( $this->refused( $this->save( $f, 3, null, [ 'code' => 'buyer-001' ] ) ), 'manual normalized collision refuses' );
			$n = $this->save( $f, 3, null, [ 'code' => '123' ] );
			$this->check( is_array( $n ) && '123' === $n['entry']['code'], 'native numeric claim remains a string entry code' );
			$e = $this->save( $f, 4 ); $this->check( is_array( $e ) && 'BUYER-003' === $e['entry']['code'], 'numeric persisted map key projects to Codes string list' );
			$this->check( true === $this->book->remove( $f['id'], $f['owner'], 5, $e['key'] ), 'remove retires code atomically' );
			$g = $this->save( $f, 6 ); $this->check( is_array( $g ) && 'BUYER-004' === $g['entry']['code'], 'retired generated suffix never recycles' );
			$r = $this->save( $f, 7, $a['key'], [ 'code' => 'NEW' ] );
			$this->check( is_array( $r ) && $this->snapshot( $f )['book']['claims']['BUYER-001']['retired'], 'rename retains permanent retired claim' );
			$this->check( $this->refused( $this->save( $f, 8, null, [ 'code' => 'BUYER-001' ] ) ), 'retired claim cannot move to another key' );
			wp_set_current_user( $this->admin );
			$this->check( is_array( $this->book->read( $f['id'], $this->admin ) ), 'actual shop administrator reads owner book' );
			$buyer = $this->user( POW\Installer::ROLE ); update_user_meta( $buyer, '_pow_partner_id', $f['id'] ); wp_set_current_user( $buyer );
			$this->check( $this->refused( $this->book->save( $f['id'], $buyer, 8, null, $this->fields() ) ), 'provisioned buyer cannot mutate master book' );
			$this->check( $this->refused( $this->book->read( $f['id'], $f['owner'] ) ), 'posted owner cannot impersonate actual actor' );
			$other = $this->user(); wp_set_current_user( $other );
			$this->check( $this->refused( $this->book->save( $f['id'], $other, 8, null, $this->fields() ) ), 'other ordinary company actor refused' );
			wp_set_current_user( $f['owner'] );
			$invalid = $this->fields(); $invalid['address']['postcode'] = 'INVALID';
			$this->check( $this->refused( $this->book->save( $f['id'], $f['owner'], 8, null, $invalid ) ), 'actual Woo postcode validation refuses disabled entry too' );
			$this->check( $this->refused( $this->save( $f, 8, null, [ 'label' => str_repeat( '李', 191 ) ] ) ), 'native label bound rejects overflow' );
			$before = $this->snapshot( $f );
			foreach ( [ 'false', 'throw', 'lie' ] as $mode ) {
				$filter = static function ( $value, $owner, $key ) use ( $f, $mode ) { if ( $owner !== $f['owner'] || $key !== $f['meta_key'] ) { return $value; } if ( 'throw' === $mode ) { throw new RuntimeException( 'PRIVATE failure' ); } return 'lie' === $mode; };
				add_filter( 'update_user_metadata', $filter, 10, 3 );
				try { $result = $this->save( $f, 8, null, [ 'code' => 'FAULT' ] ); } finally { remove_filter( 'update_user_metadata', $filter, 10 ); }
				$this->check( $this->refused( $result ) && $before === $this->snapshot( $f ), 'metadata ' . $mode . ' refusal has no changed aggregate or success audit' );
			}
			$read_filter = static function ( string $sql ) use ( $f ): string { return str_contains( $sql, 'SELECT meta_value FROM' ) && str_contains( $sql, $f['meta_key'] ) ? 'SELECT PRIVATE_missing_column FROM PRIVATE_missing_table' : $sql; };
			add_filter( 'query', $read_filter );
			try { $result = $this->book->read( $f['id'], $f['owner'] ); $mutation = $this->save( $f, 8 ); } finally { remove_filter( 'query', $read_filter ); }
			$this->check( $this->refused( $result ) && 'address_state_unavailable' === $result->get_error_code() && $this->refused( $mutation ) && $before === $this->snapshot( $f ), 'SQL read error is not missing and cannot create replacement book' );
			$nested = null; $hook = function ( $meta_id, $owner, $key ) use ( $f, &$nested ) { if ( $owner === $f['owner'] && $key === $f['meta_key'] ) { $nested = $this->save( $f, 8 ); } };
			add_action( 'updated_user_meta', $hook, 10, 3 );
			try { $result = $this->save( $f, 8 ); } finally { remove_action( 'updated_user_meta', $hook, 10 ); }
			$this->check( is_array( $result ) && $this->refused( $nested ) && 9 === $this->snapshot( $f )['book']['revision'], 'native metadata hook cannot recursively mutate book' );
			$before = $this->snapshot( $f );
			add_user_meta( $f['owner'], $f['meta_key'], wp_slash( $before['book'] ), false );
			$this->check( $this->refused( $this->book->read( $f['id'], $f['owner'] ) ) && $this->refused( $this->save( $f, 9 ) ) && 2 === $this->snapshot( $f )['rows'] && $before['audit'] === $this->snapshot( $f )['audit'], 'native duplicate metadata rows refuse without merge or success audit' );
			$registered = get_registered_meta_keys( 'user' );
			$this->check( ! isset( $registered[$f['meta_key']] ) && is_protected_meta( $f['meta_key'], 'user' ), 'private aggregate not registered as generic writable REST metadata' );
			foreach ( [ 'insert_failure', 'readback_failure', 'lock_failure' ] as $mode ) {
				wp_set_current_user( $this->admin ); $fault = $this->seed(); $written = false;
				$after = static function ( $meta_id, $owner, $key ) use ( $fault, &$written ) { if ( $owner === $fault['owner'] && $key === $fault['meta_key'] ) { $written = true; } };
				$query = static function ( string $sql ) use ( $mode, $fault, &$written ): string {
					if ( 'lock_failure' === $mode && str_contains( $sql, 'GET_LOCK(' ) ) { return 'SELECT 0'; }
					if ( str_contains( $sql, $fault['meta_key'] ) && ( ( 'insert_failure' === $mode && str_starts_with( $sql, 'INSERT INTO' ) ) || ( 'readback_failure' === $mode && $written && str_contains( $sql, 'SELECT meta_value FROM' ) ) ) ) { return 'SELECT PRIVATE_missing_column FROM PRIVATE_missing_table'; }
					return $sql;
				};
				add_action( 'added_user_meta', $after, 10, 3 ); add_filter( 'query', $query );
				try { $result = $this->save( $fault, 0 ); } finally { remove_filter( 'query', $query ); remove_action( 'added_user_meta', $after, 10 ); }
				$state = $this->snapshot( $fault );
				$this->check( $this->refused( $result ) && 0 === $state['audit'] && ( 'readback_failure' === $mode ? 1 === $state['rows'] && 1 === count( $state['book']['claims'] ) : 0 === $state['rows'] ), $mode . ' is truthful with no half-book or success audit' );
			}
			$policy_fixture = $this->seed(); $entry = $this->save( $policy_fixture, 0 );
			$this->check( is_array( $entry ), 'native policy recovery fixture persisted' );
			$policy = static function ( array $countries ): array { unset( $countries['US'] ); return $countries; };
			add_filter( 'woocommerce_countries_shipping_countries', $policy );
			try {
				$this->check( is_array( $this->book->read( $policy_fixture['id'], $policy_fixture['owner'] ) ), 'removed shipping country does not prevent owner management read' );
				$this->check( $this->refused( $this->save( $policy_fixture, 1 ) ), 'new address still obeys changed native shipping country policy' );
				$this->check( true === $this->book->remove( $policy_fixture['id'], $policy_fixture['owner'], 1, $entry['key'] ), 'owner can remove an old address after its country becomes ineligible' );
			} finally { remove_filter( 'woocommerce_countries_shipping_countries', $policy ); }
		} finally { wp_set_current_user( $this->admin ); echo "Native CompanyBook: {$this->passed} passed, {$this->failed} failed, 0 skipped\n"; }
	}
	private function lock_name( array $f ): string { return 'pow_partner_' . substr( hash( 'sha256', DB_NAME . '|' . POW\Installer::partners_table() . '|' . $f['id'] ), 0, 52 ); }
	private function signal( string $name ): void { if ( false === file_put_contents( getenv( 'POW_BOOK_BARRIER' ) . '/' . $name, 'ready', LOCK_EX ) ) { throw new RuntimeException( 'Barrier signal failed.' ); } }
	private function await_release(): void {
		$end = microtime( true ) + 15;
		while ( ! is_file( getenv( 'POW_BOOK_BARRIER' ) . '/release' ) ) { if ( microtime( true ) > $end ) { throw new RuntimeException( 'Barrier timed out.' ); } usleep( 20000 ); }
	}
	public function worker( string $mode, array $f ): array {
		global $wpdb;
		if ( 'inspect' === $mode ) { return $this->snapshot( $f ); }
		if ( 'hold' === $mode ) {
			$this->registry->with_partner_lock( $f['id'], function () { $this->signal( 'holder' ); $this->await_release(); } );
			return [ 'held' => true ];
		}
		if ( 'race' !== $mode ) { throw new RuntimeException( 'Unknown barrier mode.' ); }
		$lane = getenv( 'POW_BOOK_LANE' ); $lock = $this->lock_name( $f );
		$filter = function ( string $sql ) use ( $f, $lane, $lock ): string {
			if ( str_contains( $sql, 'GET_LOCK(' ) && str_contains( $sql, $lock ) ) { $this->signal( $lane . '-lock' ); }
			if ( 'first' === $lane && str_contains( $sql, $f['meta_key'] ) && ( str_starts_with( $sql, 'INSERT INTO' ) || str_starts_with( $sql, 'UPDATE ' ) ) ) { $this->signal( 'first-write' ); $this->await_release(); }
			return $sql;
		};
		add_filter( 'query', $filter ); $start = microtime( true );
		try { $result = $this->save( $f, (int) ( $f['revision'] ?? 0 ), $f['key'] ?? null, $f['changes'] ?? [] ); }
		finally { remove_filter( 'query', $filter ); }
		return [ 'ok' => is_array( $result ), 'error' => is_wp_error( $result ) ? $result->get_error_code() : null, 'result' => is_array( $result ) ? $result : null, 'elapsed' => microtime( true ) - $start ];
	}
}

$native = new CompanyBookNative();
$mode = getenv( 'POW_BOOK_MODE' ) ?: 'suite';
if ( 'suite' === $mode ) { $native->suite(); }
else {
	$fixture = 'seed' === $mode ? [] : json_decode( file_get_contents( getenv( 'POW_BOOK_FIXTURE' ) ), true, 512, JSON_THROW_ON_ERROR );
	$result = 'seed' === $mode ? $native->seed() : $native->worker( $mode, $fixture );
	if ( false === file_put_contents( getenv( 'POW_BOOK_RESULT' ), wp_json_encode( $result, JSON_THROW_ON_ERROR ), LOCK_EX ) ) { throw new RuntimeException( 'Private native result write failed.' ); }
}
