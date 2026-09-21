<?php
/** Unwired company address editor boundary tests, not native/browser acceptance. @package POW */
declare( strict_types = 1 );

namespace {
if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) { require_once dirname( __DIR__ ) . '/bootstrap.php'; }
require_once __DIR__ . '/NativeAddressImportTest.php';
require_once __DIR__ . '/AccountIntegrationTest.php';
require_once dirname( __DIR__ ) . '/Admin/doubles.php';
}

namespace POW\Tests\Fields {
function load_fields(): void {
	\POW\Tests\NativeImport\load_import();
	if ( ! defined( 'POW_PLUGIN_DIR' ) ) { define( 'POW_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/' ); }
	if ( class_exists( __NAMESPACE__ . '\\Fields', false ) ) { return; }
	$path = dirname( __DIR__, 2 ) . '/includes/Addresses/Fields.php';
	\PHPUnit\Framework\TestCase::assertTrue( is_file( $path ), 'Fields implementation must exist.' );
	$source = str_replace( 'namespace POW\\Addresses;', 'namespace POW\\Tests\\Fields; use POW\\Tests\\CompanyBook\\CompanyBook; use POW\\Tests\\NativeImport\\NativeImport;', file_get_contents( $path ) );
	eval( substr( $source, 5 ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- bind native dependencies only, unchanged method bodies.
}
function load_wiring(): void {
	if ( class_exists( 'POW\\Tests\\FieldsAccount\\IntegrationTab', false ) ) { return; }
	$root = dirname( __DIR__, 2 ) . '/includes/';
	foreach ( [
		[ 'Account/IntegrationTab.php', 'POW\\Account', 'POW\\Tests\\FieldsAccount', [ 'is_account_page', 'is_wc_endpoint_url', 'wp_create_nonce', 'wc_get_page_permalink', 'wc_get_endpoint_url', 'get_posts', 'has_shortcode', 'get_permalink', 'header' ] ],
		[ 'Admin/Page.php', 'POW\\Admin', 'POW\\Tests\\FieldsAdmin', [ 'current_user_can', 'absint', 'sanitize_key', 'delete_transient', 'add_query_arg', 'selected', 'checked', 'submit_button' ] ],
	] as [ $file, $original, $target, $functions ] ) {
		$bindings = ''; foreach ( $functions as $function ) { $bindings .= ' use function ' . $original . '\\' . $function . ';'; }
		$source = str_replace( 'namespace ' . $original . ';', 'namespace ' . $target . ';' . $bindings, file_get_contents( $root . $file ) );
		$source = str_replace( 'use POW\\Addresses\\Fields;', 'use POW\\Tests\\Fields\\Fields;', $source );
		eval( substr( $source, 5 ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- same controller bodies with existing native I/O and Fields boundary bindings.
	}
}
/** Add the real Registry owner lookup to the unchanged Book fixture without altering peer fixtures. */
final class OwnerLookupDatabase {
	private array $prepared = [];
	public function __construct( private \POW\Tests\CompanyBook\Database $inner ) {}
	public function &__get( string $name ): mixed { return $this->inner->$name; }
	public function __set( string $name, mixed $value ): void { $this->inner->$name = $value; }
	public function __call( string $method, array $args ): mixed { return $this->inner->$method( ...$args ); }
	public function prepare( string $sql, mixed ...$args ): string { $key = $this->inner->prepare( $sql, ...$args ); $this->prepared[$key] = [ $sql, $args ]; return $key; }
	public function get_row( string $query, string $format ): ?array {
		[ $sql, $args ] = $this->prepared[$query];
		if ( str_contains( $sql, 'WHERE owner_user_id' ) ) { return $this->inner->row['owner_user_id'] === $args[0] ? $this->inner->row : null; }
		return $this->inner->get_row( $query, $format );
	}
}
function add_action( string $hook, callable $callback, int $priority = 10, int $args = 1 ): void { $GLOBALS['pow_fields_test']['hooks'][$hook] = $callback; }
function is_account_page(): bool { return $GLOBALS['pow_fields_test']['account']; }
function is_wc_endpoint_url( string $endpoint ): bool { return $endpoint === $GLOBALS['pow_fields_test']['endpoint']; }
function is_admin(): bool { return $GLOBALS['pow_fields_test']['admin']; }
function current_user_can( string $capability ): bool { return \user_can( \get_userdata( \get_current_user_id() ), $capability ); }
function wp_unslash( mixed $value ): mixed { return \POW\Tests\CompanyBook\unslash( $value ); }
function wp_create_nonce( string $action ): string { return 'nonce:' . $action; }
function wp_verify_nonce( string $nonce, string $action ): int|false { return hash_equals( wp_create_nonce( $action ), $nonce ) ? 1 : false; }
function nocache_headers(): void { ++$GLOBALS['pow_fields_test']['private']; }
function WC(): object { return (object) [ 'countries' => new Countries() ]; }
final class Countries {
	public function get_base_country(): string { return 'US'; }
	public function get_address_fields( string $country, string $prefix ): array {
		if ( $GLOBALS['wpdb']->held ) { throw new \LogicException( 'Rendering must not hold the partner mutex.' ); }
		$GLOBALS['pow_fields_test']['countries'][] = [ $country, $prefix ];
		if ( $GLOBALS['pow_fields_test']['fields_fail'] ) { throw new \RuntimeException( 'PRIVATE field failure' ); }
		$fields = [];
		foreach ( [ 'first_name', 'last_name', 'company', 'country', 'address_1', 'address_2', 'city', 'state', 'postcode' ] as $key ) {
			$fields[$prefix . $key] = [ 'label' => 'ZA' === $country && 'state' === $key ? 'Province' : $key, 'required' => ! in_array( $key, [ 'company', 'address_2' ], true ), 'type' => in_array( $key, [ 'country', 'state' ], true ) ? $key : 'text' ];
		}
		return $fields;
	}
}
function woocommerce_form_field( string $key, array $args, mixed $value = null ): ?string {
	if ( $GLOBALS['wpdb']->held ) { throw new \LogicException( 'Form callbacks must not hold the partner mutex.' ); }
	$GLOBALS['pow_fields_test']['fields'][$key] = [ $args, $value ];
	$html = '<label>' . esc_html( $args['label'] ?? '' ) . '<input name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '" /></label>';
	if ( $args['return'] ?? false ) { return $html; } echo $html; return null;
}
}

namespace {
use PHPUnit\Framework\TestCase;
use POW\Tests\Fields\Fields;
use POW\Tests\CompanyBook\{CompanyBook, Current, Database, Audit, Countries};
use POW\Tests\NativeImport\{NativeImport, Customer};
use POW\Partners\{Registry, Secrets};

final class CompanyAddressAccessTest extends TestCase {
	private Fields $fields;
	private CompanyBook $book;
	private Current $visits;
	private Database $db;
	private array $saved = [];
	protected function setUp(): void {
		\POW\Tests\Fields\load_fields();
		foreach ( [ 'wpdb', 'pow_test_users', 'pow_test_user_meta', 'pow_test_current_user_id', 'pow_fields_test', 'pow_account_test', 'pow_admin_test', 'pow_test_options', 'pow_test_valid_nonce', '_POST', '_GET', '_SERVER' ] as $key ) { $this->saved[$key] = $GLOBALS[$key] ?? null; }
		$this->db = new Database(); $GLOBALS['wpdb'] = $this->db;
		$GLOBALS['pow_test_users'] = [
			20 => (object) [ 'ID' => 20, 'roles' => [ 'customer' ], 'allcaps' => [ 'read' => true ] ],
			21 => (object) [ 'ID' => 21, 'roles' => [ 'customer' ], 'allcaps' => [ 'read' => true ] ],
			30 => (object) [ 'ID' => 30, 'roles' => [ 'shop_manager' ], 'allcaps' => [ 'read' => true, 'manage_woocommerce' => true ] ],
			// A privileged account that can no longer read the shop: still neither an editor nor a connection's login.
			40 => (object) [ 'ID' => 40, 'roles' => [ 'shop_manager' ], 'allcaps' => [ 'read' => false, 'manage_woocommerce' => true ] ],
		];
		$GLOBALS['pow_test_user_meta'] = []; $GLOBALS['pow_test_current_user_id'] = 20;
		$GLOBALS['pow_fields_test'] = [ 'account' => true, 'endpoint' => 'punchout-integration', 'admin' => false, 'hooks' => [], 'private' => 0, 'countries' => [], 'fields' => [], 'fields_fail' => false ];
		$_POST = []; $_GET = []; $_SERVER['REQUEST_METHOD'] = 'GET';
		Countries::$unavailable = false; Countries::$country_removed = false; Countries::$company_required = false;
		Customer::$data = [ 20 => [ 'shipping' => $this->address(), 'billing' => $this->address() + [ 'email' => 'private@example.test' ] ] ]; Customer::$loaded = []; Customer::$fail = false; Customer::$missing = false; Customer::$on_read = null;
		$registry = new Registry( new Secrets( str_repeat( 'k', 32 ) ) ); $this->visits = new Current(); $this->book = new CompanyBook( $registry, new Audit(), $this->visits );
		$this->fields = new Fields( $registry, $this->book, new NativeImport( $registry, $this->book ) );
	}
	protected function tearDown(): void {
		foreach ( $this->saved as $key => $value ) { if ( null === $value ) { unset( $GLOBALS[$key] ); } else { $GLOBALS[$key] = $value; } }
		Countries::$unavailable = false; Countries::$country_removed = false; Countries::$company_required = false;
		Customer::$data = []; Customer::$loaded = []; Customer::$on_read = null;
	}
	private function address(): array { return [ 'first_name' => 'Zoë', 'last_name' => "O'Neil", 'company' => 'Example Company', 'address_1' => '12 Main Street', 'address_2' => '', 'city' => 'Oakland', 'state' => 'CA', 'postcode' => '94612', 'country' => 'US', 'phone' => '+1 555 123 4567' ]; }
	private function post( string $action = 'save', int $revision = 0, string $key = '', string $type = '', array $changes = [] ): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$fields = [ 'pow_address_action' => $action, 'pow_address_partner' => '12', 'pow_address_revision' => (string) $revision, 'pow_address_key' => $key, 'pow_address_type' => $type, '_pow_address_nonce' => 'nonce:pow_address_' . $action . '_12_' . $key . '_' . $type ];
		if ( 'save' === $action ) { $fields += [ 'pow_address_label' => 'Depot', 'pow_address_code' => '' ]; foreach ( $this->address() as $name => $value ) { $fields['shipping_' . $name] = $value; } }
		$_POST = \POW\Tests\CompanyBook\wp_slash( array_replace( $fields, $changes ) );
	}
	private function submit( string $action = 'save', int $revision = 0, string $key = '', string $type = '', array $changes = [] ): string { $this->post( $action, $revision, $key, $type, $changes ); $this->fields->handle(); return $this->fields->markup( 12 ); }
	private function state(): array { $state = $this->book->read( 12, get_current_user_id() ); self::assertTrue( is_array( $state ) ); return $state; }
	private function seed(): array { $result = $this->book->save( 12, 20, 0, null, [ 'label' => 'Private depot', 'address' => $this->address(), 'code' => 'PRIVATE-DEPOT', 'use_for_punchout' => false ] ); self::assertTrue( is_array( $result ) ); return $result; }
	public function test_register_connects_only_scoped_handlers_and_no_shipping_filters(): void {
		$this->fields->register(); self::assertSame( [ 'template_redirect', 'admin_init' ], array_keys( $GLOBALS['pow_fields_test']['hooks'] ) );
		$this->post(); ( $GLOBALS['pow_fields_test']['hooks']['template_redirect'] )(); self::assertSame( 1, $this->state()['revision'] );
	}
	public function test_private_markup_owner_and_admin_only_blocked_unrelated_and_guest_denied(): void {
		$this->seed();
		foreach ( [ 20, 30 ] as $actor ) { $GLOBALS['pow_test_current_user_id'] = $actor; self::assertStringContainsString( 'PRIVATE-DEPOT', $this->fields->markup( 12 ) ); }
		foreach ( [ 0, 21, 40 ] as $actor ) { $GLOBALS['pow_test_current_user_id'] = $actor; $html = $this->fields->markup( 12 ); self::assertStringNotContainsString( 'PRIVATE-DEPOT', $html ); self::assertStringNotContainsString( '<form', $html ); }
	}
	public function test_owner_can_add_two_edit_enable_disable_remove_with_book_revision_and_claims(): void {
		$html = $this->submit(); self::assertStringContainsString( 'woocommerce-message', $html ); $a = array_key_first( $this->state()['addresses'] );
		$this->submit( 'save', 1, '', '', [ 'pow_address_label' => 'Other depot' ] ); $state = $this->state(); self::assertCount( 2, $state['addresses'] ); self::assertFalse( $state['addresses'][$a]['use_for_punchout'] );
		$this->submit( 'enable', 2, $a ); self::assertTrue( $this->state()['addresses'][$a]['use_for_punchout'] );
		$this->submit( 'save', 3, $a, '', [ 'pow_address_label' => 'Renamed', 'pow_address_code' => 'NEW-CODE' ] ); self::assertTrue( $this->state()['claims']['BUYER-001']['retired'] ); self::assertTrue( $this->state()['addresses'][$a]['use_for_punchout'] );
		$this->submit( 'disable', 4, $a ); self::assertFalse( $this->state()['addresses'][$a]['use_for_punchout'] );
		$this->submit( 'remove', 5, $a ); self::assertSame( 6, $this->state()['revision'] ); self::assertFalse( isset( $this->state()['addresses'][$a] ) ); self::assertTrue( $this->state()['claims']['NEW-CODE']['retired'] );
	}
	public function test_actual_admin_can_use_existing_admin_partner_page(): void {
		$GLOBALS['pow_test_current_user_id'] = 30; $GLOBALS['pow_fields_test']['account'] = false; $GLOBALS['pow_fields_test']['admin'] = true; $_GET = [ 'page' => 'punchout-woocommerce' ];
		$this->submit(); self::assertSame( 1, $this->state()['revision'] );
	}
	public function test_unrelated_actor_blocked_account_live_visit_and_forged_owner_cannot_mutate(): void {
		foreach ( [ 21, 40 ] as $actor ) { $GLOBALS['pow_test_current_user_id'] = $actor; $this->submit(); }
		// The editor is unreachable during a visit: the visit signs in as user 20, the account that owns the book.
		$GLOBALS['pow_test_current_user_id'] = 20;
		$this->visits->live = \POW\Sessions\Session::from_row( [ 'id' => 81, 'partner_id' => 12, 'user_id' => 20, 'status' => \POW\Sessions\Session::ACTIVE, 'wc_session_key' => 'pow_1a2b3c4d5e6f708192a3b4c5d6e7' ] );
		self::assertStringNotContainsString( '<form', $this->submit() );
		$this->visits->live = null;
		$this->submit( 'save', 0, '', '', [ 'owner_user_id' => '30' ] ); self::assertSame( [], $this->db->writes );
	}
	public function test_forged_partner_nonce_binding_and_foreign_key_cannot_mutate(): void {
		$this->submit( 'save', 0, '', '', [ 'pow_address_partner' => '99' ] );
		$this->submit( 'save', 0, 'foreign' ); $this->submit( 'remove', 0, 'foreign' ); $this->submit( 'enable', 0, 'foreign' ); self::assertSame( [], $this->db->writes );
	}
	public function test_get_and_unrelated_routes_and_ordinary_admin_access_cannot_write(): void {
		$this->post(); $_SERVER['REQUEST_METHOD'] = 'GET'; $this->fields->handle();
		$_SERVER['REQUEST_METHOD'] = 'POST'; $GLOBALS['pow_fields_test']['endpoint'] = 'edit-address'; $this->fields->handle();
		$GLOBALS['pow_fields_test']['account'] = false; $GLOBALS['pow_fields_test']['admin'] = true; $_GET['page'] = 'other'; $this->fields->handle();
		$_GET['page'] = 'punchout-woocommerce'; $this->fields->handle(); self::assertSame( [], $this->db->writes );
	}
	public function test_nonce_binds_action_partner_key_and_type_for_every_mutation(): void {
		$a = $this->seed();
		foreach ( [ 'save', 'enable', 'disable', 'remove', 'copy' ] as $action ) {
			$changes = [ '_pow_address_nonce' => 'bad' ]; if ( 'copy' === $action ) { $changes['pow_address_confirm'] = '1'; }
			$html = $this->submit( $action, 1, 'copy' === $action ? '' : $a['key'], 'copy' === $action ? 'shipping' : '', $changes ); self::assertStringNotContainsString( 'woocommerce-message', $html );
		}
		$this->post( 'remove', 1, $a['key'] ); $_POST['pow_address_action'] = 'enable'; $this->fields->handle();
		$this->post( 'copy', 1, '', 'shipping', [ 'pow_address_confirm' => '1' ] ); $_POST['pow_address_type'] = 'billing'; $this->fields->handle(); self::assertCount( 1, $this->db->writes );
	}
	public function test_expected_revision_is_required_bounded_integer_for_every_mutation(): void {
		$a = $this->seed();
		foreach ( [ 'save', 'enable', 'disable', 'remove', 'copy' ] as $action ) {
			$key = 'copy' === $action ? '' : $a['key']; $type = 'copy' === $action ? 'shipping' : ''; $extras = 'copy' === $action ? [ 'pow_address_confirm' => '1' ] : [];
			foreach ( [ '', '-1', '01', '1e2', [], str_repeat( '9', 30 ) ] as $bad ) { $this->submit( $action, 1, $key, $type, [ 'pow_address_revision' => $bad ] + $extras ); }
			$this->post( $action, 1, $key, $type, $extras ); unset( $_POST['pow_address_revision'] ); $this->fields->handle();
		}
		self::assertCount( 1, $this->db->writes ); self::assertSame( 1, $this->state()['revision'] );
	}
	public function test_stale_edit_preserves_submitted_values_and_original_revision_without_success(): void {
		$a = $this->seed(); $html = $this->submit( 'save', 0, $a['key'], '', [ 'pow_address_label' => 'Unsaved label', 'shipping_address_1' => 'Unsaved street' ] );
		self::assertStringContainsString( 'Unsaved label', $html ); self::assertStringContainsString( 'Unsaved street', $html ); self::assertStringContainsString( 'woocommerce-error', $html ); self::assertStringNotContainsString( 'woocommerce-message', $html );
		self::assertMatchesRegularExpression( '/name="pow_address_revision" value="0"/', $html ); self::assertSame( 1, $this->state()['revision'] );
	}
	public function test_failed_validation_preserves_exact_escaped_unslashed_input(): void {
		$label = 'Zoë "<script>alert(1)</script>" \\ East';
		$html = $this->submit( 'save', 0, '', '', [ 'pow_address_label' => $label, 'shipping_postcode' => 'BAD', 'shipping_address_1' => "O'Neil \\ Yard" ] );
		self::assertStringContainsString( esc_attr( $label ), $html ); self::assertStringContainsString( esc_attr( "O'Neil \\ Yard" ), $html ); self::assertStringNotContainsString( '<script>', $html ); self::assertStringNotContainsString( 'woocommerce-message', $html ); self::assertSame( [], $this->db->writes );
	}
	public function test_metadata_failure_keeps_input_and_never_success(): void {
		$this->db->write_mode = 'false'; $html = $this->submit( 'save', 0, '', '', [ 'pow_address_label' => 'Keep this failed entry' ] );
		self::assertStringContainsString( 'Keep this failed entry', $html ); self::assertStringContainsString( 'woocommerce-error', $html ); self::assertStringNotContainsString( 'woocommerce-message', $html ); self::assertSame( [], $this->db->meta );
	}
	public function test_readback_failure_keeps_own_failed_input_in_bounded_unavailable_view(): void {
		$this->db->write_mode = 'read_fail'; $html = $this->submit( 'save', 0, '', '', [ 'pow_address_label' => 'Recover my input' ] );
		self::assertStringContainsString( 'Recover my input', $html ); self::assertStringNotContainsString( 'woocommerce-message', $html ); self::assertStringNotContainsString( '<form', $html );
	}
	public function test_entitlements_enablement_and_source_ids_are_not_editor_fields(): void {
		foreach ( [ 'exit_policy', 'emit_ship_to', 'delivery_unknown_policy', 'use_for_punchout', 'source_user_id', 'pow_partner_id' ] as $key ) { $this->submit( 'save', 0, '', '', [ $key => '1' ] ); }
		self::assertSame( [], $this->db->writes ); $html = $this->fields->markup( 12 );
		foreach ( [ 'name="exit_policy"', 'name="emit_ship_to"', 'name="use_for_punchout"', 'name="source_user_id"' ] as $name ) { self::assertStringNotContainsString( $name, $html ); }
	}
	public function test_edit_selection_loads_existing_fields_without_writing_and_new_resets(): void {
		$a = $this->seed(); $html = $this->submit( 'edit', 1, $a['key'] ); self::assertStringContainsString( 'value="Private depot"', $html );
		$html = $this->submit( 'new', 1 ); self::assertStringNotContainsString( 'value="Private depot"', $html ); self::assertCount( 1, $this->db->writes );
	}
	public function test_native_country_fields_refresh_keeps_draft_and_does_not_save(): void {
		$html = $this->submit( 'save', 0, '', '', [ 'shipping_country' => 'ZA', 'pow_address_refresh' => '1', 'pow_address_label' => 'Draft label' ] );
		self::assertContains( [ 'ZA', 'shipping_' ], $GLOBALS['pow_fields_test']['countries'] ); self::assertStringContainsString( 'Province', $html ); self::assertStringContainsString( 'Draft label', $html );
		self::assertSame( [], $this->db->writes ); self::assertSame( 'ZA', $GLOBALS['pow_fields_test']['fields']['shipping_state'][0]['country'] );
	}
	public function test_native_field_rendering_uses_shipping_names_and_phone_without_customer_reads(): void {
		$html = $this->fields->markup( 12 ); self::assertStringContainsString( 'name="shipping_country"', $html ); self::assertStringContainsString( 'name="shipping_phone"', $html ); self::assertSame( [], Customer::$loaded );
		foreach ( $GLOBALS['pow_fields_test']['fields'] as [ $args ] ) { self::assertTrue( $args['return'] ); }
	}
	public function test_import_preview_is_explicit_and_copy_requires_deliberate_confirmation(): void {
		$before = Customer::$data; $html = $this->submit( 'preview', 0, '', 'billing' );
		self::assertStringContainsString( 'Company WooCommerce billing address', $html ); self::assertStringNotContainsString( 'private@example.test', $html ); self::assertSame( [], $this->db->writes );
		$this->submit( 'copy', 0, '', 'billing' ); self::assertSame( [], $this->db->writes );
		$html = $this->submit( 'copy', 0, '', 'billing', [ 'pow_address_confirm' => '1', 'pow_address_label' => 'Reviewed copy', 'pow_address_code' => 'REVIEWED' ] );
		$entry = array_values( $this->state()['addresses'] )[0]; self::assertSame( 'Reviewed copy', $entry['label'] ); self::assertSame( 'REVIEWED', $entry['code'] ); self::assertFalse( $entry['use_for_punchout'] ); self::assertSame( $before, Customer::$data );
	}
	public function test_import_copy_never_accepts_preview_address_or_enables_entry(): void {
		$this->submit( 'preview', 0, '', 'shipping' ); Customer::$data[20]['shipping']['address_1'] = 'Fresh source';
		$this->submit( 'copy', 0, '', 'shipping', [ 'pow_address_confirm' => '1', 'shipping_address_1' => 'Forged source' ] ); self::assertSame( [], $this->db->writes );
		$this->submit( 'copy', 0, '', 'shipping', [ 'pow_address_confirm' => '1' ] ); self::assertSame( 'Fresh source', array_values( $this->state()['addresses'] )[0]['address']['address_1'] );
	}
	public function test_failed_import_preserves_label_code_and_provenance_for_review(): void {
		$html = $this->submit( 'copy', 0, '', 'shipping', [ 'pow_address_confirm' => '1', 'pow_address_label' => 'Retain copy label', 'pow_address_code' => str_repeat( 'A', 33 ) ] );
		self::assertStringContainsString( 'Retain copy label', $html ); self::assertStringContainsString( str_repeat( 'A', 33 ), $html ); self::assertStringContainsString( 'Company WooCommerce shipping address', $html ); self::assertStringNotContainsString( 'woocommerce-message', $html ); self::assertSame( [], $this->db->writes );
	}
	public function test_notices_explain_disable_reconfirmation_retired_claims_and_historical_quotes(): void {
		$html = $this->fields->markup( 12 );
		foreach ( [ 'still-active confirmations', 'reconfirm', 'retired', 'Completed Quote' ] as $text ) { self::assertStringContainsString( $text, $html ); }
	}
	public function test_unknown_and_array_commands_refuse_without_notice_success_or_write(): void {
		foreach ( [ 'pow_address_action' => [], 'pow_address_partner' => [], 'pow_address_key' => [], 'pow_address_label' => [], 'shipping_city' => [], '_pow_address_nonce' => [] ] as $key => $value ) { $this->submit( 'save', 0, '', '', [ $key => $value ] ); }
		$this->submit( 'other' ); self::assertSame( [], $this->db->writes );
	}
	public function test_noop_edit_does_not_claim_changed_persistence(): void {
		$this->submit(); $key = array_key_first( $this->state()['addresses'] ); $html = $this->submit( 'save', 1, $key );
		self::assertStringContainsString( 'No changes were needed', $html ); self::assertCount( 1, $this->db->writes ); self::assertSame( 1, $this->state()['revision'] );
	}
	public function test_failed_copy_keeps_label_code_when_source_or_book_readback_becomes_unavailable(): void {
		Customer::$fail = true;
		$html = $this->submit( 'copy', 0, '', 'shipping', [ 'pow_address_confirm' => '1', 'pow_address_label' => 'Recover copy label', 'pow_address_code' => 'RECOVER' ] );
		self::assertStringContainsString( 'Recover copy label', $html ); self::assertStringContainsString( 'RECOVER', $html ); self::assertStringNotContainsString( 'woocommerce-message', $html );
		Customer::$fail = false; $this->db->write_mode = 'read_fail';
		$html = $this->submit( 'copy', 0, '', 'shipping', [ 'pow_address_confirm' => '1', 'pow_address_label' => 'Recover copy label', 'pow_address_code' => 'RECOVER' ] );
		self::assertStringContainsString( 'Recover copy label', $html ); self::assertStringContainsString( 'RECOVER', $html ); self::assertStringNotContainsString( '<form', $html ); self::assertStringNotContainsString( 'woocommerce-message', $html );
	}
	public function test_rendered_enable_form_roundtrips_its_scoped_nonce_and_revision(): void {
		$a = $this->seed(); $html = $this->fields->markup( 12 );
		preg_match_all( '/<form\b[^>]*>(.*?)<\/form>/s', $html, $forms );
		$posted = null;
		foreach ( $forms[1] as $form ) {
			if ( ! str_contains( $form, 'name="pow_address_action" value="enable"' ) ) { continue; }
			preg_match_all( '/<input type="hidden" name="([^"]+)" value="([^"]*)"/', $form, $inputs, PREG_SET_ORDER );
			$posted = []; foreach ( $inputs as $input ) { $posted[$input[1]] = html_entity_decode( $input[2], ENT_QUOTES, 'UTF-8' ); }
		}
		self::assertTrue( is_array( $posted ) ); $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = \POW\Tests\CompanyBook\wp_slash( $posted ); $this->fields->handle();
		self::assertTrue( $this->state()['addresses'][$a['key']]['use_for_punchout'] ); self::assertSame( 2, $this->state()['revision'] );
	}
	private function wiring(): array {
		\POW\Tests\Fields\load_wiring();
		$GLOBALS['wpdb'] = new \POW\Tests\Fields\OwnerLookupDatabase( $this->db );
		$GLOBALS['pow_account_test'] = [ 'account' => true, 'endpoint' => 'punchout-integration', 'headers' => [], 'pages' => [] ];
		$GLOBALS['pow_admin_test'] = [ 'headers' => [] ];
		$GLOBALS['pow_test_options'] = [ 'pow_settings' => [ 'enabled' => 'yes' ] ];
		$registry = new Registry( new Secrets( str_repeat( 'k', 32 ) ) ); $audit = new Audit();
		$registration = new \POW\Partners\Registration( $registry, new \POW\Sessions\Store(), $audit );
		$plugin = ( new ReflectionClass( \POW\Plugin::class ) )->newInstanceWithoutConstructor();
		return [ new \POW\Tests\FieldsAccount\IntegrationTab( $plugin, $registry, $registration, $audit, new \POW\Http\RateLimiter( 5 ), $this->fields ), new \POW\Tests\FieldsAdmin\Page( new \POW\Settings(), $registry, $audit, $this->fields ), new \POW\Admin\Actions( $registry, $audit, $registration ) ];
	}
	private function assert_no_nested_forms( string $html ): void {
		preg_match_all( '/<form\b|<\/form>/i', $html, $tokens ); $depth = 0;
		foreach ( $tokens[0] as $token ) { $depth += str_starts_with( strtolower( $token ), '</' ) ? -1 : 1; self::assertTrue( $depth >= 0 && $depth <= 1, 'Address forms must be outside connection and policy forms.' ); }
		self::assertSame( 0, $depth );
	}
	public function test_account_wiring_renders_server_owned_book_without_any_exit_policy(): void {
		$this->seed(); [ $tab ] = $this->wiring(); $_POST = [ 'pow_address_partner' => '99', 'owner_user_id' => '21' ];
		ob_start(); try { $tab->render(); $html = ob_get_contents(); } finally { ob_end_clean(); }
		self::assertStringContainsString( 'PRIVATE-DEPOT', $html ); self::assertStringContainsString( 'Rotate secret', $html ); self::assertStringContainsString( 'name="pow_address_partner" value="12"', $html );
		// The connection has no entitlement to display: punchout is its only exit.
		foreach ( [ 'Company exit policy', 'Company permission', 'punchout_and_checkout' ] as $text ) { self::assertStringNotContainsString( $text, $html ); }
		$this->assert_no_nested_forms( $html );
	}
	public function test_account_credential_handler_ignores_address_post_and_same_fields_instance_retains_failure(): void {
		[ $tab ] = $this->wiring(); $this->post( 'save', 0, '', '', [ 'shipping_postcode' => 'BAD', 'pow_address_label' => 'Wired failed draft' ] );
		self::assertNull( ( new ReflectionMethod( $tab, 'post_result' ) )->invoke( $tab ) );
		$this->fields->handle(); ob_start(); try { $tab->render(); $html = ob_get_contents(); } finally { ob_end_clean(); }
		self::assertStringContainsString( 'Wired failed draft', $html ); self::assertStringContainsString( 'woocommerce-error', $html ); self::assertSame( [], $this->db->writes );
	}
	public function test_admin_wiring_renders_editor_and_connection_forms_without_nesting(): void {
		$this->seed(); [ , $page ] = $this->wiring(); $GLOBALS['pow_test_current_user_id'] = 30;
		$_GET = [ 'page' => 'punchout-woocommerce', 'tab' => 'partners', 'action' => 'edit', 'partner' => '12' ];
		ob_start(); try { $page->render(); $html = ob_get_contents(); } finally { ob_end_clean(); }
		foreach ( [ 'PRIVATE-DEPOT', 'value="pow_save_partner"', 'name="pow_address_partner" value="12"' ] as $text ) { self::assertStringContainsString( $text, $html ); }
		// The per-buyer restriction screen and the checkout switch went with the dual exit.
		foreach ( [ 'name="exit_policy"', 'Buyer restrictions', 'Checkout access', 'value="pow_save_buyer_exit"' ] as $text ) { self::assertStringNotContainsString( $text, $html ); }
		$this->assert_no_nested_forms( $html );
	}
	public function test_admin_credential_actions_refuse_mixed_address_form_even_with_valid_admin_nonce(): void {
		[ , , $actions ] = $this->wiring(); $GLOBALS['pow_test_current_user_id'] = 30; $GLOBALS['pow_test_valid_nonce'] = 'valid';
		$_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = [ '_wpnonce' => 'valid', 'pow_address_action' => 'save' ];
		try { ( new ReflectionMethod( $actions, 'authorise' ) )->invoke( $actions, 'pow_save_partner' ); self::fail( 'Mixed address and credential forms must refuse.' ); }
		catch ( AdminResponse $response ) { self::assertSame( 400, $response->args['response'] ); }
		self::assertSame( [], $this->db->writes );
	}
}

if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) {
	set_error_handler( static function ( int $severity, string $message, string $file, int $line ): never { throw new ErrorException( $message, 0, $severity, $file, $line ); } );
	$passed = 0; $failed = 0; $skipped = 0;
	foreach ( ( new ReflectionClass( CompanyAddressAccessTest::class ) )->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
		if ( ! str_starts_with( $method->name, 'test_' ) ) { continue; }
		try { ( new CompanyAddressAccessTest() )->runBare( $method->name ); ++$passed; echo 'PASS ' . $method->name . "\n"; }
		catch ( PHPUnit\Framework\SkippedTestError $error ) { ++$skipped; echo 'SKIP ' . $method->name . ': ' . $error->getMessage() . "\n"; }
		catch ( Throwable $error ) { ++$failed; echo 'FAIL ' . $method->name . ': ' . $error->getMessage() . "\n"; }
	}
	echo "Passed: $passed  Failed: $failed  Skipped: $skipped\n"; exit( $failed > 0 || $skipped > 0 ? 1 : 0 );
}
}
