<?php
/** Real compatibility body; isolated WordPress storage boundaries, never a native acceptance claim. */
declare( strict_types = 1 );

namespace POW\Tests\QuoteCompatibility {

use POW\Addresses\DeliveryData;

final class State {
	public static array $posts = [], $meta = [], $hooks = [], $writes = [], $cache = [], $primary = [], $order_cache = [];
	public static int $primary_reads = 0;
	public static bool $hpos = true;
	public static string $failure = '';
	public static mixed $after_write = null;
	public static function reset(): void { self::$posts = self::$meta = self::$hooks = self::$writes = self::$cache = self::$primary = self::$order_cache = []; self::$primary_reads = 0; self::$hpos = true; self::$failure = ''; self::$after_write = null; }
	public static function written(): void { if ( self::$after_write ) { $callback = self::$after_write; self::$after_write = null; $callback(); } }
}
final class OrderUtil { public static function custom_orders_table_usage_is_enabled(): bool { return State::$hpos; } }
final class NativeOrder {
	public int $id = 901;
	public string $status = 'punchout-quote', $type = 'shop_order', $note = "First line\n\tCall Buyer\\receiving.";
	public array $meta;
	public function __construct() {
		$confirmation = [ 'schema' => 1, 'session_id' => 801, 'buyer_user_id' => 701, 'choice_hash' => DeliveryData::fingerprint( null ), 'cart_fingerprint' => str_repeat( 'a', 64 ), 'policy_fingerprint' => str_repeat( 'b', 64 ), 'delivery' => [ 'status' => 'not_required', 'amount_cents' => null, 'currency' => 'ZAR', 'code' => '', 'emit' => false, 'rates' => [], 'freight' => [ 'supplier_part_id' => 'FREIGHT', 'uom' => 'EA', 'classification_domain' => 'UNSPSC', 'classification' => '78121603' ] ], 'notes' => $this->note, 'confirmed_at' => 123456 ];
		$this->meta = [ '_pow_session_id' => ['801'], '_pow_partner_id' => ['601'], '_pow_delivery_choice' => ['null'], '_pow_delivery_confirmation' => [json_encode( $confirmation, JSON_THROW_ON_ERROR )], '_pow_delivery_notes' => [$this->note], '_pow_poom_xml' => ['<cXML note="Buyer\\receiving"/>'] ];
		State::$primary[$this->id] = clone $this;
		State::$order_cache[$this->id] = clone $this;
	}
	public function get_id(): int { return $this->id; }
	public function get_type(): string { return $this->type; }
	public function get_status( string $context = 'view' ): string { return $this->status; }
	public function get_customer_id( string $context = 'view' ): int { return 701; }
	public function get_customer_note( string $context = 'view' ): string { return $context === 'edit' ? $this->note : 'Filtered note must not be copied'; }
	public function get_meta_data(): array {
		$result = []; foreach ( $this->meta as $key => $values ) { foreach ( $values as $value ) { $result[] = new Meta( $key, $value ); } } return $result;
	}
	public function get_data_store(): NativeStore { return new NativeStore(); }
}
final class NativeStore {
	public function read(NativeOrder &$order): void {
		++State::$primary_reads;
		if (State::$failure === 'primary_read') { throw new \RuntimeException('Private primary read secret'); }
		// The installed HPOS read uses its option-controlled data cache and optionally imports the CPT copy on read.
		$cache = apply_filters('pre_option_woocommerce_hpos_datastore_caching_enabled', false) !== 'no';
		if (apply_filters('woocommerce_hpos_enable_sync_on_read', true)) { throw new \RuntimeException('Verification must not import the compatibility copy'); }
		$stored = ($cache ? State::$order_cache : State::$primary)[$order->id] ?? null;
		if (!$stored) { throw new \RuntimeException('Primary is absent'); }
		foreach (get_object_vars($stored) as $key => $value) { $order->$key = $value; }
		// Native HPOS initialization calls the public raw-meta filter after reading the rows.
		apply_filters('woocommerce_data_store_wp_post_read_meta', [], $order);
	}
}
final class Meta { public function __construct( private string $key, private mixed $value ) {} public function get_data(): array { return ['id' => 43, 'key' => $this->key, 'value' => $this->value]; } }
final class NativePost { public function __construct( public int $ID, public string $post_type = 'shop_order', public string $post_status = 'wc-punchout-quote', public string $post_excerpt = '', public string $post_modified = '2026-09-01 10:00:00', public string $post_modified_gmt = '2026-09-01 08:00:00', public string $post_title = 'Retained title' ) {} }
final class Audit extends \POW\Audit\Log {
	public array $events = []; public bool $ok = true; public function __construct() {}
	public function write_checked( string $event, array $context = [] ): bool { $this->events[] = [$event, $context]; return $this->ok; }
}
final class Logger extends \POW\Logger {
	public array $events = []; public bool $throws = false; public function __construct() {}
	public function error( string $message, array $context = [] ): void { $this->events[] = [$message, $context]; if ( $this->throws ) { throw new \RuntimeException( 'Private logger secret' ); } }
}
function add_action( string $hook, callable $callback, int $priority = 10, int $args = 1 ): void { add_filter( $hook, $callback, $priority, $args ); }
function add_filter( string $hook, callable $callback, int $priority = 10, int $args = 1 ): void { State::$hooks[$hook][$priority][] = [$callback, $args]; }
function remove_filter( string $hook, callable $callback, int $priority = 10 ): bool { foreach ( State::$hooks[$hook][$priority] ?? [] as $i => $entry ) { if ( $entry[0] === $callback ) { unset( State::$hooks[$hook][$priority][$i] ); } } return true; }
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed { $groups = State::$hooks[$hook] ?? []; ksort( $groups ); foreach ( $groups as $entries ) { foreach ( $entries as [$fn, $accepted] ) { $value = $fn( ...array_slice( [$value, ...$args], 0, $accepted ) ); } } return $value; }
function do_action( string $hook, mixed ...$args ): void { $groups = State::$hooks[$hook] ?? []; ksort( $groups ); foreach ( $groups as $entries ) { foreach ( $entries as [$fn, $accepted] ) { $fn( ...array_slice( $args, 0, $accepted ) ); } } }
function wp_slash( mixed $value ): mixed { return is_array( $value ) ? array_map( __NAMESPACE__ . '\\wp_slash', $value ) : ( is_string( $value ) ? addslashes( $value ) : $value ); }
function wp_unslash( mixed $value ): mixed { return is_array( $value ) ? array_map( __NAMESPACE__ . '\\wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value ); }
function get_post( int $id ): ?NativePost { return isset( State::$posts[$id] ) ? clone State::$posts[$id] : null; }
function get_post_meta( int $id, string $key = '', bool $single = false ): mixed {
	$check = apply_filters('get_post_metadata', null, $id, $key, $single, 'post');
	if (null !== $check) { return $single && is_array($check) ? $check[0] : $check; }
	update_meta_cache('post', [$id]);
	if ($key === '') { return wp_cache_get($id, 'post_meta'); }
	$values = State::$meta[$id][$key] ?? []; return $single ? ($values[0] ?? '') : $values;
}
function maybe_serialize(mixed $value): mixed { return is_string($value) && preg_match('/^(?:[aObisdC]:|N;)/', $value) ? serialize($value) : $value; }
function wp_cache_get(int $id, string $group): mixed { return State::$cache[$group][$id] ?? false; }
function wp_cache_delete(int $id, string $group): bool { unset(State::$cache[$group][$id]); return true; }
function update_meta_cache(string $type, array $ids): array|bool {
	$check = apply_filters('update_post_metadata_cache', null, $ids); if (null !== $check) { return (bool)$check; }
	foreach ($ids as $id) { State::$cache['post_meta'][$id] ??= array_map(static fn($values) => array_map(__NAMESPACE__ . '\\maybe_serialize', $values), State::$meta[$id] ?? []); }
	return array_intersect_key(State::$cache['post_meta'], array_flip($ids));
}
function delete_post_meta( int $id, string $key, mixed $value = '' ): bool {
	State::$writes[] = ['delete', $id, $key]; if ( State::$failure === 'delete' ) { return false; }
	$old = State::$meta[$id][$key] ?? []; $value = wp_unslash( $value );
	$remaining = $value === '' ? [] : array_values( array_filter( $old, static fn($v) => $v !== $value ) );
	if ( $remaining ) { State::$meta[$id][$key] = $remaining; } else { unset( State::$meta[$id][$key] ); }
	wp_cache_delete($id, 'post_meta');
	State::written(); return $remaining !== $old;
}
function add_post_meta( int $id, string $key, mixed $value, bool $unique = false ): int|false {
	State::$writes[] = ['add', $id, $key]; if ( State::$failure === 'add' || ($unique && !empty( State::$meta[$id][$key] )) ) { return false; }
	State::$meta[$id][$key][] = wp_unslash( $value ); wp_cache_delete($id, 'post_meta'); State::written(); return 123;
}
function wp_update_post( array $data, bool $wp_error = false ): int|\WP_Error {
	$id = $data['ID']; State::$writes[] = ['post', $id];
	if ( State::$failure === 'post' ) { return new \WP_Error( 'private', 'Private native secret' ); }
	// Core preserves old fields but resets modification dates, then unslashes after wp_insert_post_data.
	$slashed = array_replace( wp_slash( get_object_vars( State::$posts[$id] ) ), $data, ['post_modified' => '2099-01-01 00:00:00', 'post_modified_gmt' => '2099-01-01 00:00:00'] );
	// sanitize_post(..., 'db') runs both save filters before the final insertion filter and unslashing.
	$slashed['post_excerpt'] = apply_filters('pre_post_excerpt', $slashed['post_excerpt']);
	$slashed['post_excerpt'] = apply_filters('excerpt_save_pre', $slashed['post_excerpt']);
	$slashed = apply_filters( 'wp_insert_post_data', $slashed, $data );
	foreach ( wp_unslash( $slashed ) as $key => $value ) { if ( property_exists( State::$posts[$id], $key ) ) { State::$posts[$id]->$key = $value; } }
	State::written(); return $id;
}
/** Native registered post-status values; no WordPress runtime is loaded. */
function get_post_stati(): array { return ['auto-draft', 'draft', 'trash', 'publish', 'wc-pending', 'wc-on-hold', 'wc-cancelled', 'wc-processing', 'wc-completed', 'wc-failed', 'wc-refunded', 'wc-checkout-draft', 'wc-punchout-quote', 'wc-awaiting-stock']; }
function is_wp_error( mixed $value ): bool { return $value instanceof \WP_Error; }

/** Model the inspected Woo two-copy path, including raw-value comparison and WP add/delete unslashing. */
function native_backfill( NativeOrder $order ): void {
	State::$primary[$order->id] = clone $order;
	$id = $order->id; State::$posts[$id] ??= new NativePost( $id );
	// Exact native CPT results, independent of the production verifier. Core draft/trash statuses have no prefix.
	State::$posts[$id]->post_status = [ 'punchout-quote' => 'wc-punchout-quote', 'pending' => 'wc-pending', 'on-hold' => 'wc-on-hold', 'cancelled' => 'wc-cancelled', 'processing' => 'wc-processing', 'completed' => 'wc-completed', 'failed' => 'wc-failed', 'refunded' => 'wc-refunded', 'checkout-draft' => 'wc-checkout-draft', 'awaiting-stock' => 'wc-awaiting-stock' ][$order->status] ?? $order->status;
	for ( $pass = 0; $pass < 2; ++$pass ) {
		$existing = State::$meta[$id] ?? [];
		foreach ( $order->meta as $key => $values ) { foreach ( $values as $value ) {
			if ( ($existing[$key] ?? null) === [$value] ) { unset( $existing[$key] ); continue; }
			add_post_meta( $id, $key, $value );
		} }
		foreach ( $existing as $key => $values ) { if ( !str_starts_with( $key, '_pow_' ) ) { continue; } foreach ( $values as $value ) { delete_post_meta( $id, $key, $value ); } }
	}
	State::$posts[$id]->post_excerpt = wp_unslash( $order->note );
	do_action( 'woocommerce_hpos_post_record_backfilled', $order );
}
function load_source(): void {
	if ( class_exists( __NAMESPACE__ . '\\QuoteCompatibility', false ) ) { return; }
	$path = dirname( __DIR__, 2 ) . '/includes/Orders/QuoteCompatibility.php';
	\PHPUnit\Framework\TestCase::assertTrue( is_file( $path ), 'A supported hook must repair the confirmed Quote compatibility copy.' );
	$source = str_replace( ['namespace POW\\Orders;', 'use Automattic\\WooCommerce\\Utilities\\OrderUtil;', '\\WC_Order', '\\WP_Post'], ['namespace POW\\Tests\\QuoteCompatibility; use POW\\Orders\\QuoteOrder;', '', 'NativeOrder', 'NativePost'], file_get_contents( $path ) );
	eval( substr( $source, 5 ) );
}
}

namespace {
use POW\Tests\QuoteCompatibility as C;
final class QuoteCompatibilityTest extends \PHPUnit\Framework\TestCase {
	protected function setUp(): void { C\State::reset(); }
	private function service(): array { C\load_source(); $audit = new C\Audit(); $logger = new C\Logger(); $service = new C\QuoteCompatibility( $audit, $logger ); $service->register(); return [$service, $audit, $logger]; }
	private function damaged(): C\NativeOrder { $order = new C\NativeOrder(); C\native_backfill( $order ); C\State::$writes = []; return $order; }
	private function exact( C\NativeOrder $order ): void {
		$this->assertSame( $order->note, C\State::$posts[$order->id]->post_excerpt );
		foreach ( ['_pow_delivery_choice', '_pow_delivery_confirmation', '_pow_delivery_notes', '_pow_poom_xml'] as $key ) { $this->assertSame( $order->meta[$key] ?? [], C\get_post_meta( $order->id, $key ) ); }
	}
	public function test_repairs_observed_double_copy_defect_with_exact_singletons(): void {
		$order = $this->damaged();
		$this->assertSame( [], C\get_post_meta( 901, '_pow_delivery_notes' ) );
		$this->assertCount( 2, C\get_post_meta( 901, '_pow_delivery_confirmation' ) );
		$this->assertNotSame( $order->note, C\State::$posts[901]->post_excerpt );
		$primary = serialize( $order ); $post = clone C\State::$posts[901];
		C\State::$meta[901]['_unrelated'] = ['leave', 'duplicates']; C\State::$posts[902] = new C\NativePost( 902 ); $other = clone C\State::$posts[902];
		$this->service(); C\do_action( 'woocommerce_hpos_post_record_backfilled', $order ); $this->exact( $order );
		$this->assertSame( $primary, serialize( $order ) ); $this->assertSame( ['leave', 'duplicates'], C\State::$meta[901]['_unrelated'] );
		$this->assertEquals( $other, C\State::$posts[902] );
		$this->assertSame( $post->post_modified, C\State::$posts[901]->post_modified ); $this->assertSame( $post->post_modified_gmt, C\State::$posts[901]->post_modified_gmt );
		$this->assertSame( $post->post_title, C\State::$posts[901]->post_title );
	}
	public function test_repeated_sync_and_direct_repair_are_idempotent(): void {
		$order = new C\NativeOrder(); $this->service(); C\native_backfill( $order ); $this->exact( $order );
		C\State::$writes = []; C\do_action( 'woocommerce_hpos_post_record_backfilled', $order ); $this->assertSame( [], C\State::$writes );
		C\native_backfill( $order ); $this->exact( $order );
	}
	public function test_current_manual_note_and_absent_poom_preserve_historical_confirmation(): void {
		$order = $this->damaged(); $historical = $order->meta['_pow_delivery_notes']; $order->note = "Later manual\nBuyer\\dock"; unset( $order->meta['_pow_poom_xml'] );
		C\State::$primary[901] = clone $order;
		$this->service(); C\do_action( 'woocommerce_hpos_post_record_backfilled', $order ); $this->exact( $order ); $this->assertSame( $historical, C\State::$meta[901]['_pow_delivery_notes'] );
	}
	public function test_empty_notes_and_empty_present_poom_are_not_absence(): void {
		$order = new C\NativeOrder(); $order->note = ''; $order->meta['_pow_delivery_notes'] = ['']; $data = json_decode( $order->meta['_pow_delivery_confirmation'][0], true ); $data['notes'] = ''; $order->meta['_pow_delivery_confirmation'] = [json_encode( $data )]; $order->meta['_pow_poom_xml'] = [''];
		$this->service(); C\native_backfill( $order ); $this->exact( $order );
	}
	public function test_initial_and_legacy_orders_without_provenance_are_noops(): void {
		$this->service(); foreach ( [[], ['_pow_session_id' => ['801'], '_pow_partner_id' => ['601']], ['_pow_poom_xml' => ['legacy']]] as $meta ) { $order = new C\NativeOrder(); $order->meta = $meta; $order->status = 'auto-draft'; C\do_action( 'woocommerce_hpos_post_record_backfilled', $order ); }
		$this->assertSame( [], C\State::$writes );
	}
	public function test_confirmed_staging_cancelled_and_converted_records_remain_eligible(): void {
		$this->service();
		foreach ( ['auto-draft' => 'auto-draft', 'draft' => 'draft', 'trash' => 'trash', 'cancelled' => 'wc-cancelled', 'processing' => 'wc-processing', 'completed' => 'wc-completed', 'pending' => 'wc-pending', 'on-hold' => 'wc-on-hold', 'failed' => 'wc-failed', 'refunded' => 'wc-refunded', 'checkout-draft' => 'wc-checkout-draft', 'punchout-quote' => 'wc-punchout-quote', 'awaiting-stock' => 'wc-awaiting-stock', 'legacy-held' => 'legacy-held'] as $status => $post_status ) {
			$order = new C\NativeOrder(); $order->status = $status;
			$primary = serialize( $order );
			C\native_backfill( $order ); $this->exact( $order );
			$this->assertSame( $post_status, C\State::$posts[901]->post_status );
			$this->assertSame( $primary, serialize( $order ) );
			$this->assertSame( $primary, serialize( C\State::$primary[901] ) );
			C\State::$writes = []; C\do_action( 'woocommerce_hpos_post_record_backfilled', $order );
			$this->assertSame( [], C\State::$writes );
		}
	}
	public function test_core_and_order_status_mismatches_refuse_before_any_copy_repair(): void {
		foreach ( [ ['auto-draft', 'wc-auto-draft'], ['draft', 'wc-draft'], ['trash', 'wc-trash'], ['processing', 'processing'], ['cancelled', 'wc-completed'], ['legacy-held', 'wc-legacy-held'], ['', ''] ] as [$status, $wrong_status] ) {
			C\State::reset(); $order = new C\NativeOrder(); $order->status = $status;
			C\native_backfill( $order ); C\State::$posts[901]->post_status = $wrong_status;
			C\State::$writes = []; $primary = serialize( $order ); [$service, $audit] = $this->service();
			$caught = null; try { C\do_action( 'woocommerce_hpos_post_record_backfilled', $order ); } catch ( \RuntimeException $error ) { $caught = $error; }
			$this->assertNotNull( $caught ); $this->assertSame( 'quote_compatibility_post_state_invalid', $caught->getMessage() );
			$this->assertSame( [], C\State::$writes ); $this->assertCount( 1, $audit->events );
			$this->assertSame( $wrong_status, C\State::$posts[901]->post_status ); $this->assertSame( $primary, serialize( $order ) );
		}
	}

	public function test_cpt_authority_and_refunds_are_not_repaired(): void {
		$order = $this->damaged(); $this->service(); C\State::$hpos = false; C\do_action( 'woocommerce_hpos_post_record_backfilled', $order ); C\State::$hpos = true; $order->type = 'shop_order_refund'; C\do_action( 'woocommerce_hpos_post_record_backfilled', $order ); $this->assertSame( [], C\State::$writes );
	}
	public function test_malformed_claimed_provenance_refuses_without_copy_writes(): void {
		[$service, $audit] = $this->service();
		foreach ( ['missing', 'duplicate', 'foreign', 'invalid', 'notes'] as $kind ) {
			$order = new C\NativeOrder();
			if ( $kind === 'missing' ) { unset( $order->meta['_pow_delivery_confirmation'] ); }
			if ( $kind === 'duplicate' ) { $order->meta['_pow_delivery_choice'][] = 'null'; }
			if ( $kind === 'foreign' ) { $order->meta['_pow_session_id'] = ['802']; }
			if ( $kind === 'invalid' ) { $order->meta['_pow_delivery_confirmation'] = ['private malformed secret']; }
			if ( $kind === 'notes' ) { $order->meta['_pow_delivery_notes'] = ['different']; }
			$caught = null; try { C\do_action( 'woocommerce_hpos_post_record_backfilled', $order ); } catch ( \RuntimeException $e ) { $caught = $e; }
			$this->assertNotNull( $caught ); $this->assertStringContainsString( 'provenance', $caught->getMessage() );
		}
		$this->assertSame( [], C\State::$writes ); $this->assertCount( 5, $audit->events ); $this->assertStringNotContainsString( 'private malformed secret', json_encode( $audit->events ) );
	}
	public function test_write_failures_report_checked_failure_without_payloads_and_remove_filters(): void {
		foreach ( ['delete', 'add', 'post'] as $failure ) {
			C\State::reset(); $order = $this->damaged(); [$service, $audit, $logger] = $this->service(); C\State::$failure = $failure; $audit->ok = false; $logger->throws = true;
			$caught = null; try { C\do_action( 'woocommerce_hpos_post_record_backfilled', $order ); } catch ( \RuntimeException $e ) { $caught = $e; }
			$this->assertNotNull( $caught ); $this->assertNull( $caught->getPrevious() ); $this->assertStringNotContainsString( 'secret', $caught->getMessage() ); $this->assertCount( 1, $audit->events );
			$diagnostics = json_encode( [$audit->events, $logger->events] ); $this->assertStringNotContainsString( 'receiving', $diagnostics ); $this->assertStringNotContainsString( 'cXML', $diagnostics );
			$this->assertStringContainsString( 'audit', $caught->getMessage() );
			C\State::$failure = ''; C\do_action( 'woocommerce_hpos_post_record_backfilled', $order ); $this->exact( $order );
			$data = ['ID' => 901, 'post_excerpt' => 'Unrelated later update', 'post_modified' => 'NEW', 'post_modified_gmt' => 'NEW']; $this->assertSame( $data, C\apply_filters( 'wp_insert_post_data', $data, ['ID' => 901] ) );
		}
	}
	public function test_reentry_does_not_recurse_and_final_readback_detects_callback_corruption(): void {
		$order = $this->damaged(); [$service, $audit] = $this->service();
		C\State::$after_write = static function () use ($order): void { C\do_action( 'woocommerce_hpos_post_record_backfilled', $order ); };
		C\do_action( 'woocommerce_hpos_post_record_backfilled', $order ); $this->exact( $order );
		$order->note = 'New manual note'; C\State::$primary[901] = clone $order; C\State::$after_write = static function (): void { C\add_post_meta(901, '_pow_delivery_notes', 'callback corruption'); };
		$caught = null; try { C\do_action( 'woocommerce_hpos_post_record_backfilled', $order ); } catch ( \RuntimeException $e ) { $caught = $e; }
		$this->assertNotNull( $caught ); $this->assertCount( 1, $audit->events );
	}
	public function test_physical_choice_bytes_are_preserved_without_rechecking_current_book_policy(): void {
		$order = new C\NativeOrder();
		$choice = ['schema' => 1, 'partner_id' => 601, 'storage_user_id' => 501, 'provider' => 'native', 'key' => 'receiving', 'code' => 'BUYER-001', 'address' => ['first_name' => 'Ada', 'last_name' => 'Buyer', 'company' => 'Example Buyer Company', 'address_1' => '1 Example Road', 'address_2' => '', 'city' => 'Pretoria', 'state' => 'GP', 'postcode' => '0001', 'country' => 'ZA', 'phone' => '0125550100'], 'label' => 'Buyer\\receiving', 'source' => 'company_book', 'book_revision' => 1, 'entry_fingerprint' => str_repeat('c', 64)];
		$confirmation = json_decode($order->meta['_pow_delivery_confirmation'][0], true);
		$confirmation['choice_hash'] = \POW\Addresses\DeliveryData::fingerprint($choice);
		$confirmation['delivery']['status'] = 'unknown';
		$order->meta['_pow_delivery_choice'] = [json_encode($choice, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)];
		$order->meta['_pow_delivery_confirmation'] = [json_encode($confirmation, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)];
		$this->service(); C\native_backfill($order); $this->exact($order);
	}
	public function test_timestamp_filter_is_exact_id_and_does_not_leak_to_a_nested_other_post_update(): void {
		$order = $this->damaged(); $this->service();
		// Repair metadata first so the next write enters the excerpt update with its temporary filter installed.
		foreach (['_pow_delivery_choice', '_pow_delivery_confirmation', '_pow_delivery_notes', '_pow_poom_xml'] as $key) { C\State::$meta[901][$key] = $order->meta[$key]; }
		C\State::$posts[902] = new C\NativePost(902);
		C\State::$after_write = static function (): void { C\wp_update_post(['ID' => 902, 'post_excerpt' => C\wp_slash('Other\\note')], true); };
		C\do_action('woocommerce_hpos_post_record_backfilled', $order); $this->exact($order);
		$this->assertSame('2099-01-01 00:00:00', C\State::$posts[902]->post_modified);
		$this->assertSame('Other\\note', C\State::$posts[902]->post_excerpt);
		$this->assertSame('2026-09-01 10:00:00', C\State::$posts[901]->post_modified);
	}
	public function test_readback_refuses_late_timestamp_or_status_change_without_primary_correction(): void {
		foreach (['post_modified', 'post_status'] as $field) {
			C\State::reset(); $order = $this->damaged(); $primary = serialize($order); [$service, $audit] = $this->service();
			foreach (['_pow_delivery_choice', '_pow_delivery_confirmation', '_pow_delivery_notes', '_pow_poom_xml'] as $key) { C\State::$meta[901][$key] = $order->meta[$key]; }
			C\State::$after_write = static function () use ($field): void { C\State::$posts[901]->$field = 'Changed by native callback'; };
			$caught = null; try { C\do_action('woocommerce_hpos_post_record_backfilled', $order); } catch (\RuntimeException $e) { $caught = $e; }
			$this->assertNotNull($caught); $this->assertStringContainsString('readback_failed', $caught->getMessage()); $this->assertSame($primary, serialize($order)); $this->assertCount(1, $audit->events);
		}
	}
	public function test_missing_or_foreign_copy_is_reported_without_other_data_correction(): void {
		foreach (['missing', 'type', 'status'] as $kind) {
			C\State::reset(); $order = $this->damaged(); [$service, $audit] = $this->service();
			if ($kind === 'missing') { unset(C\State::$posts[901]); }
			if ($kind === 'type') { C\State::$posts[901]->post_type = 'post'; }
			if ($kind === 'status') { C\State::$posts[901]->post_status = 'wc-completed'; }
			$caught = null; try { C\do_action('woocommerce_hpos_post_record_backfilled', $order); } catch (\RuntimeException $e) { $caught = $e; }
			$this->assertNotNull($caught); $this->assertSame([], C\State::$writes); $this->assertCount(1, $audit->events);
		}
	}
	public function test_excerpt_repair_preserves_the_copy_title_despite_woo_post_title_filter(): void {
		$order = $this->damaged(); $this->service();
		C\State::$posts[901]->post_title = 'Existing Buyer\\title';
		// WC_Post_Data::wp_insert_post_data normally regenerates an order title on every post update.
		C\add_filter('wp_insert_post_data', static function (array $data): array { $data['post_title'] = 'Regenerated Woo title'; return $data; });
		C\do_action('woocommerce_hpos_post_record_backfilled', $order); $this->exact($order);
		$this->assertSame('Existing Buyer\\title', C\State::$posts[901]->post_title);
	}
	public function test_save_pre_sanitization_cannot_remove_literal_note_text_at_final_boundary(): void {
		$order = new C\NativeOrder(); $order->note = "Leave <red> box\nBuyer\\dock";
		C\State::$primary[901] = clone $order;
		C\State::$posts[901] = new C\NativePost(901, post_excerpt: 'Previous note'); C\State::$meta[901] = $order->meta;
		$this->service(); $calls = [];
		// Lifecycle contract: pre_post_excerpt then excerpt_save_pre may rewrite the slashed value. Real KSES is additionally exercised by the private DB-free review probe.
		C\add_filter('pre_post_excerpt', static function ($value) use (&$calls) { $calls[] = 'pre'; return $value; });
		C\add_filter('excerpt_save_pre', static function ($value) use (&$calls) { $calls[] = 'save'; return C\wp_slash("Leave  box\nBuyer\\dock"); });
		C\do_action('woocommerce_hpos_post_record_backfilled', $order); $this->exact($order); $this->assertSame(['pre', 'save'], $calls);
	}
	public function test_final_xml_read_mutation_of_earlier_notes_is_refused(): void {
		$order = $this->damaged(); $this->service(); $reads = 0;
		C\add_filter('get_post_metadata', static function ($value, $id, $key) use (&$reads) {
			if ($key === '_pow_poom_xml' && ++$reads === 2) { C\delete_post_meta($id, '_pow_delivery_notes'); C\add_post_meta($id, '_pow_delivery_notes', 'Late callback note'); }
			return $value;
		}, 10, 3);
		$caught = null; try { C\do_action('woocommerce_hpos_post_record_backfilled', $order); } catch (\RuntimeException $e) { $caught = $e; }
		$this->assertNotNull($caught); $this->assertSame(2, $reads);
	}
	public function test_separate_primary_change_during_excerpt_save_is_refused_even_with_stale_native_cache(): void {
		$order = $this->damaged(); [$service, $audit] = $this->service();
		C\add_filter('excerpt_save_pre', static function ($value) use ($order) { $latest = clone $order; $latest->note = 'Current converted quote note'; C\native_backfill($latest); return $value; });
		$caught = null; try { C\do_action('woocommerce_hpos_post_record_backfilled', $order); } catch (\RuntimeException $e) { $caught = $e; }
		$this->assertNotNull($caught); $this->assertCount(1, $audit->events); $this->assertSame('Current converted quote note', C\State::$primary[901]->note);
		$this->assertSame(1, C\State::$primary_reads);
	}
	public function test_fresh_primary_read_failure_cleans_temporary_filters_and_reports_without_payload(): void {
		$order = $this->damaged(); [$service, $audit] = $this->service(); C\State::$failure = 'primary_read';
		$caught = null; try { C\do_action('woocommerce_hpos_post_record_backfilled', $order); } catch (\RuntimeException $e) { $caught = $e; }
		$this->assertNotNull($caught); $this->assertStringNotContainsString('secret', $caught->getMessage()); $this->assertCount(1, $audit->events);
		$this->assertSame('yes', C\apply_filters('pre_option_woocommerce_hpos_datastore_caching_enabled', 'yes'));
		$this->assertTrue(C\apply_filters('woocommerce_hpos_enable_sync_on_read', true));
	}
	public function test_native_read_callback_invalidating_copy_cache_is_refused_without_retry(): void {
		$order = $this->damaged(); [$service, $audit] = $this->service();
		C\add_filter('woocommerce_data_store_wp_post_read_meta', static function ($value) { C\delete_post_meta(901, '_pow_delivery_notes'); C\add_post_meta(901, '_pow_delivery_notes', 'Changed during primary read'); return $value; });
		$caught = null; try { C\do_action('woocommerce_hpos_post_record_backfilled', $order); } catch (\RuntimeException $e) { $caught = $e; }
		$this->assertNotNull($caught); $this->assertSame(1, C\State::$primary_reads); $this->assertCount(1, $audit->events);
	}
	public function test_filtered_metadata_cannot_hide_physical_copy_mismatch(): void {
		$order = $this->damaged(); [$service, $audit] = $this->service();
		C\add_filter('get_post_metadata', static function ($value, $id, $key) use ($order) { return $key === '_pow_delivery_notes' ? $order->meta[$key] : $value; }, 10, 3);
		$caught = null; try { C\do_action('woocommerce_hpos_post_record_backfilled', $order); } catch (\RuntimeException $e) { $caught = $e; }
		$this->assertNotNull($caught); $this->assertCount(1, $audit->events);
	}
}
}
