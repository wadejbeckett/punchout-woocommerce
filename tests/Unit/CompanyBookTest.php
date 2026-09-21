<?php
/** Company book rules using native-boundary doubles; not native persistence proof. @package POW */
declare( strict_types = 1 );

namespace POW\Tests\CompanyBook {

use POW\Addresses\Codes;

function load_book(): void {
	if ( class_exists( __NAMESPACE__ . '\\CompanyBook', false ) ) { return; }
	$root = dirname( __DIR__, 2 ) . '/includes/Addresses/';
	\PHPUnit\Framework\TestCase::assertTrue( is_file( $root . 'CompanyBook.php' ), 'CompanyBook implementation must exist.' );
	foreach ( [ 'Shape', 'CompanyBook' ] as $name ) {
		$source = str_replace( 'namespace POW\\Addresses;', 'namespace POW\\Tests\\CompanyBook; use POW\\Addresses\\Codes; use POW\\Addresses\\DeliveryData;', file_get_contents( $root . $name . '.php' ) );
		$source = str_replace( 'use WC_Validation;', 'use POW\\Tests\\CompanyBook\\Validation as WC_Validation;', $source );
		$source = str_replace( 'use POW\\Sessions\\Current;', '', $source );
		eval( substr( $source, 5 ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- native dependency binding only; unchanged method bodies.
	}
}

/** Sessions\Current's whole surface: which visit, if any, this request is inside. */
final class Current {
	public ?\POW\Sessions\Session $live = null;
	public bool $unreachable = false;
	public function visit( array $statuses = [] ): ?\POW\Sessions\Session { if ( $this->unreachable ) { throw new \RuntimeException( 'PRIVATE session lookup error' ); } return $this->live; }
}
final class Database {
	public string $prefix = 'fixture_';
	public string $usermeta = 'fixture_usermeta';
	public string $last_error = '';
	public bool $held = false;
	public bool $lock_fail = false;
	public bool $release_fail = false;
	public bool $read_fail = false;
	public bool $partner_fail = false;
	public string $write_mode = '';
	public array $row = [ 'id' => 12, 'owner_user_id' => 20, 'status' => 'active', 'delivery_code_prefix' => 'BUYER' ];
	public array $meta = [];
	public array $writes = [];
	public array $previous = [];
	public mixed $hook = null;
	public array $user_cache = [];
	public bool $user_read_fail = false;
	private array $prepared = [];
	public function prepare( string $sql, mixed ...$args ): string { $key = $sql . ' /*' . count( $this->prepared ) . '*/'; $this->prepared[$key] = [ $sql, $args ]; return $key; }
	public function suppress_errors( bool $value = true ): bool { return false; }
	public function get_var( string $query ): string {
		[ $sql ] = $this->prepared[$query];
		if ( str_contains( $sql, 'RELEASE_LOCK' ) ) { $this->held = false; return $this->release_fail ? '0' : '1'; }
		if ( ! str_contains( $sql, 'GET_LOCK' ) ) { throw new \LogicException( 'Unexpected SQL' ); }
		$this->held = ! $this->lock_fail;
		return $this->held ? '1' : '0';
	}
	public function get_row( string $query, string $format ): ?array {
		[ $sql, $args ] = $this->prepared[$query];
		$this->last_error = $this->partner_fail ? 'PRIVATE partner failure' : '';
		return ! $this->partner_fail && $args[0] === $this->row['id'] ? $this->row : null;
	}
	public function get_results( string $query, string $format ): ?array {
		[ $sql, $args ] = $this->prepared[$query];
		if ( ! $this->held || ! str_contains( $sql, $this->usermeta ) ) { throw new \LogicException( 'Unexpected metadata read or missing mutex' ); }
		$this->last_error = $this->read_fail ? 'PRIVATE read failure' : '';
		return $this->read_fail ? null : array_map( static fn( $value ) => [ 'meta_value' => serialize( $value ) ], $this->meta[$args[0]][$args[1]] ?? [] );
	}
	public function write( int $owner, string $key, array $value, ?array $previous ): bool {
		if ( ! $this->held ) { throw new \LogicException( 'Write outside mutex' ); }
		$this->writes[] = [ $owner, $key ]; $this->previous[] = $previous;
		if ( $this->hook ) { $hook = $this->hook; $this->hook = null; $hook(); }
		if ( 'throw' === $this->write_mode ) { throw new \RuntimeException( 'PRIVATE write failure' ); }
		if ( 'false' === $this->write_mode ) { return false; }
		if ( 'lie' === $this->write_mode ) { return true; }
		$rows = $this->meta[$owner][$key] ?? [];
		if ( null === $previous ? [] !== $rows : [ $previous ] !== $rows ) { return false; }
		$this->meta[$owner][$key] = [ $value ];
		if ( 'read_fail' === $this->write_mode ) { $this->read_fail = true; }
		if ( 'duplicate' === $this->write_mode ) { $this->meta[$owner][$key][] = $value; }
		return true;
	}
}
function wp_slash( mixed $value ): mixed { return is_array( $value ) ? array_map( __NAMESPACE__ . '\\wp_slash', $value ) : ( is_string( $value ) ? addslashes( $value ) : $value ); }
function unslash( mixed $value ): mixed { return is_array( $value ) ? array_map( __NAMESPACE__ . '\\unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value ); }
function add_user_meta( int $owner, string $key, array $value, bool $unique ): bool { \PHPUnit\Framework\TestCase::assertTrue( $unique ); return $GLOBALS['wpdb']->write( $owner, $key, unslash( $value ), null ); }
function update_user_meta( int $owner, string $key, array $value, array $previous ): bool { return $GLOBALS['wpdb']->write( $owner, $key, unslash( $value ), $previous ); }
function maybe_unserialize( string $value ): mixed { return unserialize( $value, [ 'allowed_classes' => false ] ); }
function wp_cache_delete( mixed $key, string $group = '' ): bool { unset( $GLOBALS['wpdb']->user_cache[$key] ); return true; }
function get_userdata( int $id ): object|false {
	$db=$GLOBALS['wpdb']; if ( $db->user_read_fail ) { $db->last_error='PRIVATE user read failure'; return false; }
	if ( isset($db->user_cache[$id]) ) { return $db->user_cache[$id]; }
	$user=\get_userdata($id); if($user){$db->user_cache[$id]=clone $user;} return $user;
}
function WC(): object { return (object) [ 'countries' => new Countries() ]; }
function wc_strtoupper( string $value ): string { return strtoupper( $value ); }
function wc_format_postcode( string $value, string $country ): string { return strtoupper( $value ); }
final class Countries {
	public static bool $unavailable = false;
	public static bool $country_removed = false;
	public static bool $company_required = false;
	public function country_exists( string $country ): bool { return 'US' === $country; }
	public function get_shipping_countries(): array { return self::$country_removed ? [] : [ 'US' => 'United States' ]; }
	public function get_states( string $country ): array { return [ 'CA' => 'California' ]; }
	public function get_address_fields( string $country, string $prefix ): array {
		if ( self::$unavailable ) { throw new \RuntimeException( 'PRIVATE Woo failure' ); }
		$fields = array_fill_keys( [ 'shipping_first_name', 'shipping_last_name', 'shipping_address_1', 'shipping_city', 'shipping_country', 'shipping_state', 'shipping_postcode' ], [ 'required' => true ] );
		if ( self::$company_required ) { $fields['shipping_company'] = [ 'required' => true ]; }
		return $fields;
	}
}
final class Validation {
	public static function is_postcode( string $value, string $country ): bool { return 1 === preg_match( '/^[0-9]{5}$/', $value ); }
	public static function is_phone( string $value, ?string $country = null ): bool { return 1 === preg_match( '/^[0-9+ ()-]+$/', $value ); }
}
final class Audit extends \POW\Audit\Log {
	public array $events = [];
	public bool $fail = false;
	public function __construct() {}
	public function write( string $event, array $context = [] ): void { if ( $this->fail ) { throw new \RuntimeException( 'PRIVATE audit failure' ); } $this->events[] = [ $event, $context ]; }
}
}

namespace {
use PHPUnit\Framework\TestCase;
use POW\Tests\CompanyBook\{CompanyBook, Current, Database, Audit, Countries};
use POW\Partners\{Registry, Secrets};
use POW\Sessions\Session;

final class CompanyBookTest extends TestCase {
	private Database $db;
	private Audit $audit;
	private CompanyBook $book;
	private Current $visits;
	private Registry $registry;
	private array $saved;
	protected function setUp(): void {
		\POW\Tests\CompanyBook\load_book();
		$this->saved = [];
		foreach ( [ 'wpdb', 'pow_test_users', 'pow_test_user_meta', 'pow_test_current_user_id' ] as $key ) { $this->saved[$key] = $GLOBALS[$key] ?? null; }
		$this->db = new Database(); $GLOBALS['wpdb'] = $this->db;
		$GLOBALS['pow_test_users'] = [
			20 => (object) [ 'ID' => 20, 'roles' => [ 'customer' ], 'allcaps' => [ 'read' => true ] ],
			21 => (object) [ 'ID' => 21, 'roles' => [ 'customer' ], 'allcaps' => [ 'read' => true ] ],
			30 => (object) [ 'ID' => 30, 'roles' => [ 'administrator' ], 'allcaps' => [ 'read' => true, 'manage_woocommerce' => true ] ],
			// A privileged account that can no longer read the shop: neither an editor nor a connection's login,
			// whatever else it holds. It is the only account-shaped refusal left now that no role marks a buyer.
			40 => (object) [ 'ID' => 40, 'roles' => [ 'shop_manager' ], 'allcaps' => [ 'read' => false, 'manage_woocommerce' => true ] ],
		];
		$GLOBALS['pow_test_user_meta'] = []; $GLOBALS['pow_test_current_user_id'] = 20;
		Countries::$unavailable = false;
		Countries::$country_removed = false; Countries::$company_required = false;
		$this->registry = new Registry( new Secrets( str_repeat( 'k', 32 ) ) ); $this->audit = new Audit(); $this->visits = new Current();
		$this->book = new CompanyBook( $this->registry, $this->audit, $this->visits );
	}
	protected function tearDown(): void { foreach ( $this->saved as $key => $value ) { if ( null === $value ) { unset( $GLOBALS[$key] ); } else { $GLOBALS[$key] = $value; } } }
	private function fields( array $changes = [] ): array {
		return array_replace( [ 'label' => 'Depot', 'address' => [ 'first_name' => 'Zoë', 'last_name' => "O'Neil", 'address_1' => '12 Main Street', 'city' => 'Oakland', 'country' => 'US', 'state' => 'CA', 'postcode' => '94612' ], 'code' => '', 'use_for_punchout' => false ], $changes );
	}
	private function add( array $changes = [], int $revision = 0 ): array { $result = $this->book->save( 12, 20, $revision, null, $this->fields( $changes ) ); self::assertTrue( is_array( $result ), $result instanceof WP_Error ? $result->get_error_message() : '' ); return $result; }
	private function state(): array { return $this->db->meta[20]['_pow_delivery_book_12'][0]; }
	/** A live visit of the connection's own bound account — the only account any visit of partner 12 ever signs in as. */
	private function visit(): Session { return Session::from_row( [ 'id' => 81, 'partner_id' => 12, 'user_id' => 20, 'status' => Session::ACTIVE, 'wc_session_key' => 'pow_1a2b3c4d5e6f708192a3b4c5d6e7' ] ); }
	private function error( mixed $result, ?string $code = null ): void { self::assertInstanceOf( WP_Error::class, $result ); if ( null !== $code ) { self::assertSame( $code, $result->get_error_code() ); } self::assertTrue( strlen( $result->get_error_message() ) < 256 ); self::assertStringNotContainsString( 'PRIVATE', $result->get_error_message() ); }
	public function test_first_create_exact_aggregate_two_codes_and_disabled_default(): void {
		self::assertSame( [ 'schema' => 1, 'revision' => 0, 'addresses' => [], 'claims' => [], 'next_sequence' => 1 ], $this->book->read( 12, 20 ) );
		$a = $this->add(); $b = $this->add( [], 1 ); $state = $this->state();
		self::assertSame( 'BUYER-001', $a['entry']['code'] ); self::assertSame( 'BUYER-002', $b['entry']['code'] );
		self::assertNotSame( $a['key'], $b['key'] ); self::assertSame( 2, $b['revision'] ); self::assertSame( 3, $state['next_sequence'] );
		self::assertSame( false, $a['entry']['use_for_punchout'] ); self::assertCount( 1, $this->db->meta[20]['_pow_delivery_book_12'] );
		self::assertSame( [ 'schema', 'revision', 'addresses', 'claims', 'next_sequence' ], array_keys( $state ) ); self::assertCount( 2, $this->audit->events );
	}
	public function test_verified_noop_preserves_revision_and_has_no_write_or_audit(): void {
		$a = $this->add(); $before = $this->state();
		$result = $this->book->save( 12, 20, 1, $a['key'], $this->fields() );
		self::assertSame( 1, $result['revision'] ); self::assertFalse( $result['changed'] ); self::assertSame( $before, $this->state() ); self::assertCount( 1, $this->db->writes ); self::assertCount( 1, $this->audit->events );
	}
	public function test_changed_country_policy_does_not_lock_the_owner_out_of_removing_an_old_entry(): void {
		$a = $this->add(); $before = $this->state(); $hash = CompanyBook::entry_fingerprint( $a['key'], $a['entry'] );
		Countries::$country_removed = true;
		self::assertSame( $before, $this->book->read( 12, 20 ) );
		self::assertSame( $hash, CompanyBook::entry_fingerprint( $a['key'], $a['entry'] ) );
		$this->error( $this->book->save( 12, 20, 1, null, $this->fields() ) );
		self::assertTrue( $this->book->remove( 12, 20, 1, $a['key'] ) );
		self::assertSame( [], $this->book->read( 12, 20 )['addresses'] );
	}
	public function test_new_required_field_allows_admin_to_correct_one_entry_while_other_old_entries_remain(): void {
		$a = $this->add(); $b = $this->add( [], 1 ); Countries::$company_required = true; $GLOBALS['pow_test_current_user_id'] = 30;
		self::assertTrue( is_array( $this->book->read( 12, 30 ) ) );
		$fields = $a['entry']; $fields['address']['company'] = 'Updated company';
		$result = $this->book->save( 12, 30, 2, $a['key'], $fields );
		self::assertTrue( is_array( $result ) ); self::assertSame( 'Updated company', $result['entry']['address']['company'] );
		self::assertSame( $b['entry'], $this->book->read( 12, 30 )['addresses'][$b['key']] );
	}
	public function test_stale_revision_cannot_write_even_same_values(): void {
		$a = $this->add(); $this->error( $this->book->save( 12, 20, 0, $a['key'], $this->fields() ), 'address_book_stale' ); $this->error( $this->book->remove( 12, 20, 0, $a['key'] ), 'address_book_stale' ); self::assertCount( 1, $this->db->writes );
	}
	public function test_manual_collision_and_permanent_retirement(): void {
		$a = $this->add( [ 'code' => ' manual ' ] );
		$this->error( $this->book->save( 12, 20, 1, null, $this->fields( [ 'code' => 'MANUAL' ] ) ) );
		$b = $this->book->save( 12, 20, 1, $a['key'], $this->fields( [ 'code' => 'NEW' ] ) ); self::assertSame( 2, $b['revision'] );
		self::assertSame( [ 'key' => $a['key'], 'retired' => true ], $this->state()['claims']['MANUAL'] );
		$this->error( $this->book->save( 12, 20, 2, null, $this->fields( [ 'code' => 'MANUAL' ] ) ) );
		$this->error( $this->book->save( 12, 20, 2, $a['key'], $this->fields( [ 'code' => 'MANUAL' ] ) ) );
		self::assertTrue( $this->book->remove( 12, 20, 2, $a['key'] ) ); self::assertSame( [], $this->state()['addresses'] ); self::assertTrue( $this->state()['claims']['NEW']['retired'] );
	}
	public function test_numeric_claim_projection_and_generation_after_retirement(): void {
		$a = $this->add( [ 'code' => '123' ] ); $b = $this->add( [ 'code' => 'BUYER-009' ], 1 );
		self::assertTrue( $this->book->remove( 12, 20, 2, $b['key'] ) );
		$c = $this->add( [], 3 ); self::assertSame( 'BUYER-010', $c['entry']['code'] ); self::assertArrayHasKey( 123, $this->state()['claims'] );
	}
	public function test_blank_prefix_uncoded_entry_and_existing_blank_preserves_code(): void {
		$this->db->row['delivery_code_prefix'] = ''; $a = $this->add(); self::assertSame( '', $a['entry']['code'] ); self::assertSame( [], $this->state()['claims'] );
		$b = $this->book->save( 12, 20, 1, $a['key'], $this->fields( [ 'code' => '001' ] ) ); self::assertSame( '001', $b['entry']['code'] );
		$c = $this->book->save( 12, 20, 2, $a['key'], $this->fields() ); self::assertSame( '001', $c['entry']['code'] ); self::assertFalse( $c['changed'] );
	}
	public function test_one_boundary_unslash_preserves_slashes_quotes_unicode_and_prev_value(): void {
		$fields = $this->fields( [ 'label' => 'Zoë 李 / "Depot" \\ West' ] ); $fields['address']['address_1'] = "12 O'Neil \\ Yard / 李";
		$posted = \POW\Tests\CompanyBook\wp_slash( $fields );
		$a = $this->book->save( 12, 20, 0, null, \POW\Tests\CompanyBook\unslash( $posted ) ); $before = $this->state();
		self::assertSame( $fields['label'], $a['entry']['label'] ); self::assertSame( $fields['address']['address_1'], $a['entry']['address']['address_1'] );
		$fields['use_for_punchout'] = true; $b = $this->book->save( 12, 20, 1, $a['key'], $fields );
		self::assertSame( $before, $this->db->previous[1] ); self::assertSame( $fields['address']['address_1'], $this->state()['addresses'][$a['key']]['address']['address_1'] ); self::assertTrue( $b['entry']['use_for_punchout'] );
	}
	public function test_actual_actor_owner_or_admin_never_a_blocked_account_or_forged_actor(): void {
		foreach ( [ 0, 21, 30, 40 ] as $actor ) { $this->error( $this->book->read( 12, $actor ) ); }
		$GLOBALS['pow_test_current_user_id'] = 21; $this->error( $this->book->save( 12, 20, 0, null, $this->fields() ) );
		$GLOBALS['pow_test_current_user_id'] = 40; $this->error( $this->book->save( 12, 40, 0, null, $this->fields() ) );
		$GLOBALS['pow_test_current_user_id'] = 30; self::assertTrue( is_array( $this->book->save( 12, 30, 0, null, $this->fields() ) ) );
	}
	public function test_cached_administrator_grant_is_refreshed_before_book_access(): void {
		$GLOBALS['pow_test_current_user_id']=30; \POW\Tests\CompanyBook\get_userdata(30);
		$GLOBALS['pow_test_users'][30]->allcaps['manage_woocommerce']=false;
		$this->error($this->book->read(12,30),'address_forbidden');
		$this->error($this->book->save(12,30,0,null,$this->fields()),'address_forbidden'); self::assertSame([],$this->db->writes);
	}
	public function test_cached_owner_capability_loss_is_seen_before_book_access(): void {
		\POW\Tests\CompanyBook\get_userdata(20); $GLOBALS['pow_test_users'][20]->allcaps['read']=false;
		$GLOBALS['pow_test_current_user_id']=30;
		$this->error($this->book->read(12,30),'address_forbidden'); self::assertSame([],$this->db->writes);
	}
	public function test_the_book_is_read_only_inside_a_live_visit_while_selection_still_reads(): void {
		$a = $this->add();
		$GLOBALS['pow_test_current_user_id'] = 30; self::assertTrue( is_array( $this->book->read( 12, 30 ) ) );
		$GLOBALS['pow_test_current_user_id'] = 20; $this->visits->live = $this->visit();
		// The visit is signed in as user 20, the account that owns this book. Only the visit itself can refuse it.
		$this->error( $this->book->read( 12, 20 ), 'address_forbidden' );
		$this->error( $this->book->save( 12, 20, 1, $a['key'], $this->fields() ), 'address_forbidden' );
		$this->error( $this->book->remove( 12, 20, 1, $a['key'] ), 'address_forbidden' );
		$GLOBALS['pow_test_current_user_id'] = 30; $this->error( $this->book->read( 12, 30 ), 'address_forbidden' );
		self::assertCount( 1, $this->db->writes );
		// Choosing a delivery address is a read, and it is what the visit exists to do.
		$partner = $this->registry->find( 12 );
		self::assertSame( $this->state(), $this->registry->with_partner_lock( 12, fn() => $this->book->read_for_partner_locked( $partner ) ) );
	}
	public function test_an_unreachable_visit_lookup_refuses_management_instead_of_allowing_it(): void {
		$this->visits->unreachable = true;
		$this->error( $this->book->read( 12, 20 ), 'address_state_unavailable' );
		$this->error( $this->book->save( 12, 20, 0, null, $this->fields() ), 'address_state_unavailable' );
		self::assertSame( [], $this->db->writes );
	}
	public function test_native_user_read_failure_is_unavailable_and_cannot_create_a_book(): void {
		$this->db->user_read_fail=true; $this->error($this->book->read(12,20),'address_state_unavailable');
		$this->error($this->book->save(12,20,0,null,$this->fields()),'address_state_unavailable'); self::assertSame([],$this->db->writes);
	}
	public function test_fresh_owner_resolution_and_a_missing_or_unreadable_owner_refuse(): void {
		foreach ( [ 0, 999, 40, 21 ] as $owner ) { $this->db->row['owner_user_id'] = $owner; $this->error( $this->book->save( 12, 20, 0, null, $this->fields() ) ); }
		self::assertSame( [], $this->db->writes );
	}
	public function test_read_error_distinct_from_missing_and_duplicates_refuse(): void {
		$this->db->read_fail = true; $this->error( $this->book->read( 12, 20 ), 'address_state_unavailable' ); $this->error( $this->book->save( 12, 20, 0, null, $this->fields() ) ); self::assertSame( [], $this->db->writes );
		$this->db->read_fail = false; $this->add(); $this->db->meta[20]['_pow_delivery_book_12'][] = $this->state();
		$this->error( $this->book->read( 12, 20 ), 'address_state_unavailable' ); $this->error( $this->book->save( 12, 20, 1, null, $this->fields() ) ); self::assertCount( 1, $this->db->writes );
	}
	public function test_metadata_write_false_throw_lie_readback_failure_and_duplicate_never_audit(): void {
		foreach ( [ 'false', 'throw', 'lie', 'read_fail', 'duplicate' ] as $mode ) {
			$this->db->meta = []; $this->db->read_fail = false; $this->db->write_mode = $mode;
			$this->error( $this->book->save( 12, 20, 0, null, $this->fields() ), 'address_state_unavailable' ); self::assertSame( [], $this->audit->events );
		}
	}
	public function test_lock_partner_read_and_release_failure_are_bounded(): void {
		$this->db->lock_fail = true; $this->error( $this->book->save( 12, 20, 0, null, $this->fields() ) ); self::assertSame( [], $this->db->writes );
		$this->db->lock_fail = false; $this->db->partner_fail = true; $this->error( $this->book->read( 12, 20 ) );
		$this->db->partner_fail = false; $this->db->release_fail = true; $this->error( $this->book->save( 12, 20, 0, null, $this->fields() ) ); self::assertSame( [], $this->audit->events );
	}
	public function test_all_saves_use_full_shape_even_disabled_entries(): void {
		foreach ( [ 'first_name' => '', 'country' => 'ZZ', 'state' => 'INVALID', 'postcode' => 'wrong', 'phone' => 'wrong', 'city' => [], 'company' => str_repeat( 'x', 191 ) ] as $field => $value ) {
			$fields = $this->fields(); $fields['address'][$field] = $value; $this->error( $this->book->save( 12, 20, 0, null, $fields ) );
		}
		Countries::$unavailable = true; $this->error( $this->book->save( 12, 20, 0, null, $this->fields() ) ); self::assertSame( [], $this->db->writes );
	}
	public function test_label_codes_fields_and_generation_bounds(): void {
		foreach ( [ [ 'label' => str_repeat( '李', 191 ) ], [ 'label' => "PRIVATE\0" ], [ 'label' => [] ], [ 'code' => [] ], [ 'code' => str_repeat( 'A', 33 ) ], [ 'use_for_punchout' => 'false' ], [ 'owner_user_id' => 21 ] ] as $bad ) { $this->error( $this->book->save( 12, 20, 0, null, $this->fields( $bad ) ) ); }
		$a = $this->add( [ 'label' => str_repeat( '李', 190 ), 'code' => 'BUYER-9999999' ] );
		$this->error( $this->book->save( 12, 20, 1, null, $this->fields() ) ); self::assertCount( 1, $this->db->writes );
	}
	public function test_corrupt_aggregate_claims_schema_and_types_refuse_without_repair(): void {
		$a = $this->add(); $good = $this->state();
		$bad = [ [], array_replace( $good, [ 'revision' => '1' ] ), array_replace( $good, [ 'schema' => 2 ] ), array_replace( $good, [ 'next_sequence' => 0 ] ), array_replace( $good, [ 'claims' => [] ] ), $good + [ 'extra' => true ] ];
		$b = $good; $b['claims']['BUYER-001']['key'] = 'other'; $bad[] = $b;
		$b = $good; $b['claims']['BUYER-001']['retired'] = true; $bad[] = $b;
		$b = $good; $b['claims']['lowercase'] = [ 'key' => 'other', 'retired' => true ]; $bad[] = $b;
		$b = $good; $b['addresses'][$a['key']]['use_for_punchout'] = 1; $bad[] = $b;
		foreach ( $bad as $state ) { $this->db->meta[20]['_pow_delivery_book_12'] = [ $state ]; $this->error( $this->book->read( 12, 20 ), 'address_state_unavailable' ); }
		self::assertCount( 1, $this->db->writes );
	}
	public function test_missing_key_removal_and_unknown_edit_refuse_without_allocating(): void {
		$this->error( $this->book->remove( 12, 20, 0, 'missing' ) ); $this->error( $this->book->save( 12, 20, 0, 'missing', $this->fields() ) ); self::assertSame( [], $this->db->writes );
	}
	public function test_save_hook_reentry_refuses_and_original_write_is_single(): void {
		$nested = null; $this->db->hook = function () use ( &$nested ) { $nested = $this->book->save( 12, 20, 0, null, $this->fields() ); };
		$this->add(); $this->error( $nested ); self::assertCount( 1, $this->db->writes );
	}
	public function test_readonly_locked_consumer_uses_fresh_checked_state_without_nested_lock(): void {
		$this->add(); $p = $this->registry->find( 12 );
		$result = $this->registry->with_partner_lock( 12, fn() => $this->book->read_for_partner_locked( $p ) ); self::assertSame( $this->state(), $result );
		$this->db->row['owner_user_id'] = 21; $this->error( $this->registry->with_partner_lock( 12, fn() => $this->book->read_for_partner_locked( $p ) ) );
	}
	public function test_audit_failure_does_not_undo_or_misreport_verified_change(): void {
		$this->audit->fail = true; $result = $this->add(); self::assertSame( 1, $result['revision'] ); self::assertSame( 1, $this->state()['revision'] );
	}
	public function test_entry_fingerprint_shares_deliverydata_canonical_keys_and_ignores_other_entries(): void {
		$a = $this->add();
		self::assertTrue( method_exists( CompanyBook::class, 'entry_fingerprint' ), 'CompanyBook must expose the shared entry fingerprint.' );
		$hash = CompanyBook::entry_fingerprint( $a['key'], $a['entry'] );
		self::assertSame( \POW\Addresses\DeliveryData::fingerprint( [ 'key' => $a['key'] ] + $a['entry'] ), $hash );
		$reordered = array_reverse( $a['entry'], true ); $reordered['address'] = array_reverse( $reordered['address'], true );
		self::assertSame( $hash, CompanyBook::entry_fingerprint( $a['key'], $reordered ) );
		$this->add( [ 'label' => 'Other' ], 1 );
		self::assertSame( $hash, CompanyBook::entry_fingerprint( $a['key'], $this->state()['addresses'][$a['key']] ) );
		foreach ( [ 'label' => 'Changed', 'code' => 'CHANGED', 'use_for_punchout' => true, 'address' => array_replace( $a['entry']['address'], [ 'city' => 'Changed' ] ) ] as $field => $value ) {
			self::assertNotSame( $hash, CompanyBook::entry_fingerprint( $a['key'], array_replace( $a['entry'], [ $field => $value ] ) ) );
		}
		self::assertNotSame( $hash, CompanyBook::entry_fingerprint( 'other', $a['entry'] ) );
	}
	public function test_entry_fingerprint_refuses_malformed_input_without_normalizing_claims(): void {
		$a = $this->add();
		self::assertTrue( method_exists( CompanyBook::class, 'entry_fingerprint' ), 'CompanyBook must expose the shared entry fingerprint.' );
		foreach ( [ [], $a['entry'] + [ 'revision' => 1 ], array_replace( $a['entry'], [ 'code' => 'lowercase' ] ), array_replace( $a['entry'], [ 'use_for_punchout' => 1 ] ), array_replace( $a['entry'], [ 'label' => '<b>Depot</b>' ] ) ] as $entry ) {
			try { CompanyBook::entry_fingerprint( $a['key'], $entry ); self::fail( 'Malformed fingerprint input must refuse.' ); }
			catch ( \DomainException $error ) { self::assertStringNotContainsString( 'PRIVATE', $error->getMessage() ); }
		}
	}
}
}
