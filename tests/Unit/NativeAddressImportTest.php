<?php
/** Native import rules at native dependency boundaries; not WP/Woo persistence proof. @package POW */
declare( strict_types = 1 );

namespace {
// This file also supplies the focused standalone entry point; shared bootstrap stays unchanged.
if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) {
	require_once dirname( __DIR__ ) . '/bootstrap.php';
}
require_once __DIR__ . '/CompanyBookTest.php';
}

namespace POW\Tests\NativeImport {
function load_import(): void {
	\POW\Tests\CompanyBook\load_book();
	if ( class_exists( __NAMESPACE__ . '\\NativeImport', false ) ) { return; }
	$path = dirname( __DIR__, 2 ) . '/includes/Addresses/NativeImport.php';
	\PHPUnit\Framework\TestCase::assertTrue( is_file( $path ), 'NativeImport implementation must exist.' );
	$source = str_replace( 'namespace POW\\Addresses;', 'namespace POW\\Tests\\NativeImport; use POW\\Tests\\CompanyBook\\CompanyBook; use POW\\Tests\\CompanyBook\\Shape;', file_get_contents( $path ) );
	$source = str_replace( 'use WC_Customer;', 'use POW\\Tests\\NativeImport\\Customer as WC_Customer;', $source );
	eval( substr( $source, 5 ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- change native bindings only, never method bodies or final classes.
}

final class Customer {
	public static array $data = [];
	public static array $loaded = [];
	public static bool $fail = false;
	public static bool $missing = false;
	public static mixed $on_read = null;
	private array $addresses;
	public function __construct( private int $id = 0, bool $session = false ) {
		if ( $session ) { throw new \LogicException( 'Import cannot use session customer data.' ); }
		self::$loaded[] = $id;
		if ( self::$fail ) { throw new \RuntimeException( 'PRIVATE customer failure' ); }
		$this->addresses = self::$data[$id] ?? [];
		if ( self::$missing ) { $this->id = 0; }
	}
	public function get_id(): int { return $this->id; }
	public function get_shipping( string $context = 'view' ): mixed { return $this->read( 'shipping', $context ); }
	public function get_billing( string $context = 'view' ): mixed { return $this->read( 'billing', $context ); }
	private function read( string $type, string $context ): mixed {
		if ( 'edit' !== $context ) { throw new \LogicException( 'Display filters cannot substitute stored native addresses.' ); }
		if ( self::$on_read ) { $hook = self::$on_read; self::$on_read = null; $hook(); }
		return $this->addresses[$type] ?? [];
	}
	public function save(): void { throw new \LogicException( 'Import must never save the owner customer.' ); }
	public function __call( string $method, array $arguments ): never { throw new \LogicException( 'Import must not mutate customer properties.' ); }
}
}

namespace {
use PHPUnit\Framework\TestCase;
use POW\Tests\NativeImport\{NativeImport, Customer};
use POW\Tests\CompanyBook\{CompanyBook, Database, Audit, Countries};
use POW\Partners\{Registry, Secrets};

final class NativeAddressImportTest extends TestCase {
	private Database $db;
	private CompanyBook $book;
	private NativeImport $import;
	private array $saved = [];
	protected function setUp(): void {
		\POW\Tests\NativeImport\load_import();
		foreach ( [ 'wpdb', 'pow_test_users', 'pow_test_user_meta', 'pow_test_current_user_id' ] as $key ) { $this->saved[$key] = $GLOBALS[$key] ?? null; }
		$this->db = new Database(); $GLOBALS['wpdb'] = $this->db;
		$GLOBALS['pow_test_users'] = [
			20 => (object) [ 'ID' => 20, 'roles' => [ 'customer' ], 'allcaps' => [ 'read' => true ] ],
			21 => (object) [ 'ID' => 21, 'roles' => [ 'customer' ], 'allcaps' => [ 'read' => true ] ],
			30 => (object) [ 'ID' => 30, 'roles' => [ 'shop_manager' ], 'allcaps' => [ 'read' => true, 'manage_woocommerce' => true ] ],
			40 => (object) [ 'ID' => 40, 'roles' => [ \POW\Installer::ROLE ], 'allcaps' => [ 'read' => true, 'manage_woocommerce' => true ] ],
		];
		$GLOBALS['pow_test_user_meta'] = []; $GLOBALS['pow_test_current_user_id'] = 20;
		Countries::$unavailable = false;
		Customer::$data = [ 20 => [ 'shipping' => $this->address(), 'billing' => array_replace( $this->address(), [ 'address_1' => '45 Billing Street', 'email' => 'private@example.test' ] ) ], 21 => [ 'shipping' => array_replace( $this->address(), [ 'city' => 'Other company' ] ) ] ];
		Customer::$loaded = []; Customer::$fail = false; Customer::$missing = false; Customer::$on_read = null;
		$registry = new Registry( new Secrets( str_repeat( 'k', 32 ) ) );
		$this->book = new CompanyBook( $registry, new Audit() );
		$this->import = new NativeImport( $registry, $this->book );
	}
	protected function tearDown(): void {
		foreach ( $this->saved as $key => $value ) { if ( null === $value ) { unset( $GLOBALS[$key] ); } else { $GLOBALS[$key] = $value; } }
		Countries::$unavailable = false; Customer::$data = []; Customer::$loaded = []; Customer::$on_read = null;
	}
	private function address(): array { return [ 'first_name' => 'Zoë', 'last_name' => "O'Neil", 'company' => 'Example Company', 'address_1' => '12 Main Street', 'address_2' => '', 'city' => 'Oakland', 'state' => 'CA', 'postcode' => '94612', 'country' => 'US', 'phone' => '+1 555 123 4567' ]; }
	private function ok( mixed $result ): array { self::assertTrue( is_array( $result ), $result instanceof WP_Error ? $result->get_error_message() : 'Expected result array' ); return $result; }
	private function error( mixed $result, ?string $code = null ): void {
		self::assertInstanceOf( WP_Error::class, $result );
		if ( null !== $code ) { self::assertSame( $code, $result->get_error_code() ); }
		self::assertTrue( strlen( $result->get_error_message() ) < 256 ); self::assertStringNotContainsString( 'PRIVATE', $result->get_error_message() );
	}
	public function test_shipping_preview_is_canonical_labelled_and_has_no_writes(): void {
		$before = Customer::$data;
		Customer::$data[20]['shipping']['country'] = 'us'; Customer::$data[20]['shipping']['state'] = 'California';
		$result = $this->ok( $this->import->preview( 12, 20, 'shipping' ) );
		self::assertSame( $this->address(), $result['address'] ); self::assertSame( 0, $result['revision'] );
		self::assertSame( 'woocommerce_shipping', $result['source'] ); self::assertStringContainsString( 'shipping', $result['label'] );
		self::assertSame( [], $this->db->writes ); self::assertSame( [], $this->db->meta );
		self::assertSame( 'us', Customer::$data[20]['shipping']['country'] ); self::assertSame( $before[20]['billing'], Customer::$data[20]['billing'] );
	}
	public function test_billing_preview_and_copy_strip_email_and_keep_exact_shipping_shape(): void {
		$before = Customer::$data;
		$preview = $this->ok( $this->import->preview( 12, 20, 'billing' ) );
		self::assertSame( 'woocommerce_billing', $preview['source'] ); self::assertStringContainsString( 'billing', $preview['label'] );
		$copy = $this->ok( $this->import->copy( 12, 20, 0, 'billing', [] ) );
		self::assertSame( array_replace( $this->address(), [ 'address_1' => '45 Billing Street' ] ), $preview['address'] );
		self::assertSame( $preview['address'], $copy['entry']['address'] ); self::assertFalse( $copy['entry']['use_for_punchout'] );
		self::assertSame( $before, Customer::$data );
	}
	public function test_copy_uses_book_new_key_generation_and_preserves_enabled_entry_and_source(): void {
		$existing = $this->ok( $this->book->save( 12, 20, 0, null, [ 'label' => 'Enabled', 'address' => $this->address(), 'code' => 'EXISTING', 'use_for_punchout' => true ] ) );
		$before = Customer::$data;
		$copy = $this->ok( $this->import->copy( 12, 20, 1, 'shipping', [] ) );
		self::assertNotSame( $existing['key'], $copy['key'] ); self::assertSame( 'BUYER-001', $copy['entry']['code'] ); self::assertFalse( $copy['entry']['use_for_punchout'] );
		self::assertSame( $existing['entry'], $this->book->read( 12, 20 )['addresses'][$existing['key']] );
		self::assertSame( $before, Customer::$data ); self::assertSame( [ [ 20, '_pow_delivery_book_12' ], [ 20, '_pow_delivery_book_12' ] ], $this->db->writes );
	}
	public function test_copy_rereads_actual_source_instead_of_preview(): void {
		$this->ok( $this->import->preview( 12, 20, 'shipping' ) );
		Customer::$data[20]['shipping']['address_1'] = '99 Changed Street';
		$copy = $this->ok( $this->import->copy( 12, 20, 0, 'shipping', [] ) );
		self::assertSame( '99 Changed Street', $copy['entry']['address']['address_1'] );
	}
	public function test_deliberate_repeated_copies_allocate_separate_entries_but_stale_post_refuses(): void {
		$a = $this->ok( $this->import->copy( 12, 20, 0, 'shipping', [] ) );
		$this->error( $this->import->copy( 12, 20, 0, 'shipping', [] ), 'address_book_stale' );
		$b = $this->ok( $this->import->copy( 12, 20, 1, 'shipping', [] ) );
		self::assertNotSame( $a['key'], $b['key'] ); self::assertSame( 'BUYER-002', $b['entry']['code'] ); self::assertSame( 2, $b['revision'] ); self::assertCount( 2, $this->db->writes );
	}
	public function test_explicit_label_code_only_and_blank_prefix_remains_uncoded(): void {
		$this->db->row['delivery_code_prefix'] = '';
		$a = $this->ok( $this->import->copy( 12, 20, 0, 'shipping', [] ) ); self::assertSame( '', $a['entry']['code'] );
		$b = $this->ok( $this->import->copy( 12, 20, 1, 'shipping', [ 'label' => 'Reviewed depot', 'code' => ' manual ' ] ) );
		self::assertSame( 'Reviewed depot', $b['entry']['label'] ); self::assertSame( 'MANUAL', $b['entry']['code'] ); self::assertFalse( $b['entry']['use_for_punchout'] );
	}
	public function test_source_ids_address_key_and_enablement_overrides_refuse_without_writing(): void {
		foreach ( [ [ 'source_user_id' => 21 ], [ 'owner_user_id' => 21 ], [ 'user_id' => 21 ], [ 'partner_id' => 99 ], [ 'address' => $this->address() ], [ 'key' => 'existing' ], [ 'source' => 'billing' ], [ 'use_for_punchout' => true ], [ 'use_for_punchout' => false ] ] as $overrides ) { $this->error( $this->import->copy( 12, 20, 0, 'shipping', $overrides ) ); }
		self::assertSame( [], $this->db->writes );
	}
	public function test_invalid_label_and_code_refuse_via_book_without_writes(): void {
		foreach ( [ [ 'label' => '' ], [ 'label' => null ], [ 'label' => [] ], [ 'label' => str_repeat( '李', 191 ) ], [ 'code' => null ], [ 'code' => [] ], [ 'code' => str_repeat( 'A', 33 ) ] ] as $overrides ) { $this->error( $this->import->copy( 12, 20, 0, 'shipping', $overrides ) ); }
		self::assertSame( [], $this->db->writes );
	}
	public function test_duplicate_and_retired_codes_refuse_without_reusing_claim(): void {
		$a = $this->ok( $this->import->copy( 12, 20, 0, 'shipping', [ 'code' => 'DEPOT' ] ) );
		$this->error( $this->import->copy( 12, 20, 1, 'shipping', [ 'code' => 'DEPOT' ] ), 'address_book_invalid' );
		self::assertTrue( $this->book->remove( 12, 20, 1, $a['key'] ) );
		$this->error( $this->import->copy( 12, 20, 2, 'billing', [ 'code' => 'DEPOT' ] ), 'address_book_invalid' ); self::assertCount( 2, $this->db->writes );
	}
	public function test_only_current_owner_or_shop_admin_can_preview_or_copy(): void {
		foreach ( [ [ 20, 30 ], [ 30, 20 ], [ 0, 0 ], [ 21, 21 ], [ 40, 40 ] ] as [ $current, $actor ] ) {
			$GLOBALS['pow_test_current_user_id'] = $current;
			$this->error( $this->import->preview( 12, $actor, 'shipping' ), 'address_forbidden' );
			$this->error( $this->import->copy( 12, $actor, 0, 'billing', [] ), 'address_forbidden' );
		}
		self::assertSame( [], Customer::$loaded ); self::assertSame( [], $this->db->writes );
		$GLOBALS['pow_test_current_user_id'] = 30;
		$preview = $this->ok( $this->import->preview( 12, 30, 'shipping' ) ); self::assertSame( $this->address(), $preview['address'] );
		$this->ok( $this->import->copy( 12, 30, 0, 'billing', [] ) ); self::assertSame( [ 20, 20 ], Customer::$loaded );
	}
	public function test_meta_provisioned_buyer_and_cross_company_owner_refuse_before_customer_read(): void {
		$GLOBALS['pow_test_user_meta'][20]['_pow_partner_id'] = 12;
		$this->error( $this->import->preview( 12, 20, 'shipping' ), 'address_forbidden' );
		$GLOBALS['pow_test_user_meta'] = []; $this->db->row['owner_user_id'] = 21;
		$this->error( $this->import->preview( 12, 20, 'shipping' ), 'address_forbidden' );
		$this->error( $this->import->copy( 12, 20, 0, 'billing', [] ), 'address_forbidden' );
		$this->error( $this->import->copy( 99, 20, 0, 'shipping', [] ), 'address_forbidden' );
		self::assertSame( [], Customer::$loaded ); self::assertSame( [], $this->db->writes );
	}
	public function test_missing_or_provisioned_owner_refuses_for_admin_too(): void {
		$GLOBALS['pow_test_current_user_id'] = 30;
		foreach ( [ 0, 999, 40 ] as $owner ) {
			$this->db->row['owner_user_id'] = $owner;
			$this->error( $this->import->preview( 12, 30, 'shipping' ), 'address_forbidden' );
			$this->error( $this->import->copy( 12, 30, 0, 'shipping', [] ), 'address_forbidden' );
		}
		self::assertSame( [], Customer::$loaded ); self::assertSame( [], $this->db->writes );
	}
	public function test_source_type_is_an_exact_allowlist(): void {
		foreach ( [ '', 'Shipping', 'shipping_21', '21', '../billing', 'billing_email' ] as $type ) {
			$this->error( $this->import->preview( 12, 20, $type ) ); $this->error( $this->import->copy( 12, 20, 0, $type, [] ) );
		}
		self::assertSame( [], Customer::$loaded ); self::assertSame( [], $this->db->writes );
	}
	public function test_absent_malformed_and_invalid_sources_refuse_and_never_fallback_to_billing(): void {
		foreach ( [ [], null, 'bad', array_replace( $this->address(), [ 'first_name' => '' ] ), array_replace( $this->address(), [ 'country' => 'ZZ' ] ), array_replace( $this->address(), [ 'postcode' => 'wrong' ] ), array_replace( $this->address(), [ 'city' => [] ] ) ] as $source ) {
			Customer::$data[20]['shipping'] = $source;
			$this->error( $this->import->preview( 12, 20, 'shipping' ) ); $this->error( $this->import->copy( 12, 20, 0, 'shipping', [] ) );
		}
		self::assertSame( [], $this->db->writes );
	}
	public function test_native_customer_load_failure_and_zero_id_refuse(): void {
		Customer::$fail = true; $this->error( $this->import->preview( 12, 20, 'shipping' ) ); $this->error( $this->import->copy( 12, 20, 0, 'shipping', [] ) );
		Customer::$fail = false; Customer::$missing = true; $this->error( $this->import->preview( 12, 20, 'shipping' ) ); $this->error( $this->import->copy( 12, 20, 0, 'shipping', [] ) );
		self::assertSame( [], $this->db->writes );
	}
	public function test_copy_uses_fresh_validation_after_valid_preview(): void {
		$this->ok( $this->import->preview( 12, 20, 'shipping' ) );
		Customer::$data[20]['shipping']['country'] = 'ZZ';
		$this->error( $this->import->copy( 12, 20, 0, 'shipping', [] ), 'address_invalid' ); self::assertSame( [], $this->db->writes );
	}
	public function test_reassociation_during_source_read_refuses_even_for_admin(): void {
		$GLOBALS['pow_test_current_user_id'] = 30;
		foreach ( [ 'preview', 'copy' ] as $method ) {
			$this->db->row['owner_user_id'] = 20;
			Customer::$on_read = function () { $this->db->row['owner_user_id'] = 21; };
			$this->error( 'preview' === $method ? $this->import->preview( 12, 30, 'shipping' ) : $this->import->copy( 12, 30, 0, 'shipping', [] ) );
		}
		self::assertSame( [], $this->db->writes );
	}
	public function test_actor_change_during_source_read_refuses_before_write(): void {
		Customer::$on_read = static function () { $GLOBALS['pow_test_current_user_id'] = 21; };
		$this->error( $this->import->copy( 12, 20, 0, 'shipping', [] ), 'address_forbidden' ); self::assertSame( [], $this->db->writes );
	}
	public function test_concurrent_book_edit_during_source_read_is_stale_cas(): void {
		Customer::$on_read = function () { $this->ok( $this->book->save( 12, 20, 0, null, [ 'label' => 'Concurrent', 'address' => $this->address() ] ) ); };
		$this->error( $this->import->copy( 12, 20, 0, 'shipping', [] ), 'address_book_stale' ); self::assertCount( 1, $this->db->writes );
		self::assertSame( 'Concurrent', array_values( $this->book->read( 12, 20 )['addresses'] )[0]['label'] );
	}
	public function test_lock_read_validation_and_write_failures_never_report_success(): void {
		$this->db->lock_fail = true; $this->error( $this->import->preview( 12, 20, 'shipping' ) ); $this->error( $this->import->copy( 12, 20, 0, 'shipping', [] ) );
		$this->db->lock_fail = false; $this->db->read_fail = true; $this->error( $this->import->copy( 12, 20, 0, 'shipping', [] ) );
		$this->db->read_fail = false; Countries::$unavailable = true; $this->error( $this->import->copy( 12, 20, 0, 'shipping', [] ) );
		Countries::$unavailable = false; self::assertSame( [], $this->db->writes );
		$this->db->write_mode = 'false'; $this->error( $this->import->copy( 12, 20, 0, 'shipping', [] ), 'address_state_unavailable' ); self::assertSame( [], $this->db->meta );
	}
}

if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) {
	set_error_handler( static function ( int $severity, string $message, string $file, int $line ): never { throw new ErrorException( $message, 0, $severity, $file, $line ); } );
	$passed = 0; $failed = 0; $skipped = 0;
	foreach ( ( new ReflectionClass( NativeAddressImportTest::class ) )->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
		if ( ! str_starts_with( $method->name, 'test_' ) ) { continue; }
		try { ( new NativeAddressImportTest() )->runBare( $method->name ); ++$passed; echo 'PASS ' . $method->name . "\n"; }
		catch ( PHPUnit\Framework\SkippedTestError $error ) { ++$skipped; echo 'SKIP ' . $method->name . ': ' . $error->getMessage() . "\n"; }
		catch ( Throwable $error ) { ++$failed; echo 'FAIL ' . $method->name . ': ' . $error->getMessage() . "\n"; }
	}
	echo "Passed: $passed  Failed: $failed  Skipped: $skipped\n";
	exit( $failed > 0 || $skipped > 0 ? 1 : 0 );
}
}
