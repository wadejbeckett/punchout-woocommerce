<?php
/**
 * Creating a Punchout Quote order from a returning session. Stub-backed:
 * tests/Support/wc-stubs.php records what was set on the order, so these
 * prove our rules, not WooCommerce's.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace {

use PHPUnit\Framework\TestCase;
use POW\Orders\QuoteOrder;
use POW\Orders\Status;
use POW\Partners\Partner;
use POW\Sessions\Session;

final class QuoteOrderCreateTest extends TestCase {

	private QuoteOrder $quotes;

	protected function setUp(): void {
		$GLOBALS['pow_test_orders']     = [];
		$GLOBALS['pow_test_products']   = [];
		$GLOBALS['pow_test_filters']    = [];
		$GLOBALS['pow_test_options']    = [];
		$GLOBALS['pow_test_transients'] = [];
		$GLOBALS['pow_test_mail']       = [];
		$GLOBALS['pow_test_wc']         = new POW_Test_WC();

		unset( $GLOBALS['pow_test_create_order_error'], $GLOBALS['pow_test_get_order_error'] );

		QuoteOrderTestStore::$updates = [];
		QuoteOrderTestLog::$written   = [];

		$this->quotes = new QuoteOrder(
			new QuoteOrderTestStore(),
			new QuoteOrderTestLog(),
			new QuoteOrderTestSettings(),
			new QuoteOrderTestLogger()
		);
	}

	/**
	 * @param array<string, mixed> $overrides Row columns to change.
	 */
	private function session( array $overrides = [] ): Session {
		return Session::from_row(
			array_merge(
				[
					'id'             => 42,
					'partner_id'     => 7,
					'user_id'        => 99,
					'buyer_identity' => 'zoe@buyer.example.test',
					'buyer_name'     => 'Zoë Buyer',
					'buyer_cookie'   => 'basket-reference',
					'ship_to'        => '<ShipTo><Address addressID="BUYER-001" addressIDDomain="supplier"><Name xml:lang="en">Head office</Name></Address></ShipTo>',
				],
				$overrides
			)
		);
	}

	private function partner(): Partner {
		return Partner::from_row( [ 'id' => 7, 'name' => 'Example Buyer Company', 'status' => 'active' ] );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function lines(): array {
		return [
			'currency'    => 'ZAR',
			'total_cents' => 37533,
			'items'       => [
				[ 'quantity' => 3, 'supplier_part_id' => 'SKU-1001', 'aux_id' => '412|0', 'unit_price_cents' => 12511, 'description' => 'Example product' ],
			],
		];
	}

	private function destination(): array {
		return [
			'address' => [ 'first_name' => 'Ada', 'last_name' => 'Buyer', 'company' => 'Example & Co', 'address_1' => '1 Accepted Road', 'address_2' => 'Unit 2', 'city' => 'Pretoria', 'state' => 'GP', 'postcode' => '0001', 'country' => 'ZA', 'phone' => '+27 12 555 0100' ],
			'code' => '',
			'source' => 'company_book',
		];
	}

	/** Bind only native boundaries; the production QuoteOrder method bodies remain unchanged. */
	private function shipping_quotes(): object {
		if ( ! class_exists( POW\Tests\QuoteShipping\QuoteOrder::class, false ) ) {
			$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/Orders/QuoteOrder.php' );
			$source = str_replace( [ 'namespace POW\\Orders;', 'use WC_Order_Item_Shipping;' ], [ 'namespace POW\\Tests\\QuoteShipping; use POW\\Orders\\Status;', 'use POW\\Tests\\QuoteShipping\\ShippingItem as WC_Order_Item_Shipping;' ], $source );
			eval( substr( $source, 5 ) );
		}
		POW\Tests\QuoteShipping\Boundary::$failure = '';
		return new POW\Tests\QuoteShipping\QuoteOrder( new QuoteOrderTestStore(), new QuoteOrderTestLog(), new QuoteOrderTestSettings(), new QuoteOrderTestLogger() );
	}

	private function shipping_lines(): array {
		$lines = $this->lines();
		$lines['items'][0]['supplier_part_id'] = 'DELIVERY'; // A real merchandise SKU never becomes freight.
		$lines['total_cents'] = 41033;
		$lines['delivery_destination'] = $this->destination();
		$lines['delivery'] = [ 'status' => 'quoted', 'amount_cents' => 3500, 'currency' => 'ZAR', 'code' => '', 'emit' => true, 'rates' => [
			[ 'package_key' => 0, 'rate_id' => 'flat_rate:2', 'method_id' => 'flat_rate', 'instance_id' => 2, 'label' => 'Express', 'amount_cents' => 1250, 'taxes' => [ 1 => '1.875' ] ],
			[ 'package_key' => 'parcel-b', 'rate_id' => 'local_pickup:4', 'method_id' => 'local_pickup', 'instance_id' => 4, 'label' => 'Collect second parcel', 'amount_cents' => 2250, 'taxes' => [ 1 => '3.375' ] ],
		], 'freight' => [ 'supplier_part_id' => 'DELIVERY', 'uom' => 'EA', 'classification_domain' => 'UNSPSC', 'classification' => '78102200' ] ];
		return $lines;
	}

	public function test_shipping_items_match_each_immutable_package_and_the_freight_total(): void {
		$quotes = $this->shipping_quotes(); $lines = $this->shipping_lines(); $this->break_creation();
		$id = $quotes->create_for_session( $this->session(), $this->partner(), $lines );
		self::assertGreaterThan( 0, $id ); $order = wc_get_order( $id );
		self::assertCount( 1, $order->items ); self::assertSame( 'DELIVERY', $order->items[0]['sku'] );
		self::assertCount( 2, $order->get_items( 'shipping' ) );
		foreach ( $order->get_items( 'shipping' ) as $i => $item ) {
			$rate = $lines['delivery']['rates'][$i];
			self::assertSame( $rate['method_id'], $item->get_method_id() ); self::assertSame( $rate['instance_id'], (int) $item->get_instance_id() );
			self::assertSame( $rate['label'], $item->get_method_title() ); self::assertSame( (string) $rate['package_key'], $item->get_meta( '_pow_package_key' ) );
			self::assertSame( $rate['rate_id'], $item->get_meta( '_pow_rate_id' ) ); self::assertSame( $rate['amount_cents'], POW\Cxml\Money::to_cents( $item->get_total() ) );
			self::assertSame( [ 'total' => [] ], $item->get_taxes() );
		}
		self::assertSame( '35.00', $order->get_shipping_total() ); self::assertSame( '410.33', $order->get_total() );
		self::assertSame( $lines['delivery'], $order->get_meta( '_pow_delivery' ) ); self::assertSame( [ false ], $order->totals_calls );
		self::assertSame( $id, $quotes->create_for_session( $this->session( [ 'order_id' => $id ] ), $this->partner(), $lines ) );
		self::assertCount( 1, $GLOBALS['pow_test_orders'] ); self::assertCount( 2, $order->get_items( 'shipping' ) );
	}

	public function test_nonemitted_estimates_are_local_without_shipping_charges(): void {
		$quotes = $this->shipping_quotes();
		foreach ( [ 'disabled', 'unknown', 'not_required' ] as $status ) {
			$lines = $this->shipping_lines(); $lines['total_cents'] = 37533;
			$lines['delivery']['status'] = $status; $lines['delivery']['emit'] = false;
			if ( 'disabled' !== $status ) { $lines['delivery']['amount_cents'] = null; $lines['delivery']['rates'] = []; }
			if ( 'not_required' === $status ) { $lines['delivery_destination'] = null; }
			$id = $quotes->create_for_session( $this->session(), $this->partner(), $lines );
			self::assertGreaterThan( 0, $id ); $order = wc_get_order( $id );
			self::assertSame( [], $order->get_items( 'shipping' ) ); self::assertSame( '375.33', $order->get_total() );
			self::assertSame( $lines['delivery'], $order->get_meta( '_pow_delivery' ) );
			if ( 'not_required' !== $status ) { self::assertStringContainsString( 'Delivery estimate', implode( ' ', $order->notes ) ); }
		}
	}

	public function test_real_zero_shipping_is_an_item_and_payexit_link_is_preserved(): void {
		$quotes = $this->shipping_quotes(); $lines = $this->shipping_lines();
		$lines['delivery']['rates'] = [ array_replace( $lines['delivery']['rates'][0], [ 'amount_cents' => 0, 'taxes' => [] ] ) ];
		$lines['delivery']['amount_cents'] = 0; $lines['total_cents'] = 37533;
		$id = $quotes->create_for_session( $this->session( [ 'order_id' => 777 ] ), $this->partner(), $lines );
		self::assertGreaterThan( 0, $id ); self::assertCount( 1, wc_get_order( $id )->get_items( 'shipping' ) );
		self::assertSame( '0.00', wc_get_order( $id )->get_shipping_total() ); self::assertSame( [], QuoteOrderTestStore::$updates );
	}

	public function test_invalid_delivery_or_mapped_totals_are_cancelled_before_linking(): void {
		$quotes = $this->shipping_quotes(); $base = $this->shipping_lines(); $cases = [];
		$bad = $base; $bad['delivery']['currency'] = 'USD'; $cases[] = $bad;
		$bad = $base; $bad['delivery']['code'] = 'OTHER'; $cases[] = $bad;
		$bad = $base; $bad['delivery_destination'] = null; $cases[] = $bad;
		$bad = $base; unset( $bad['delivery_destination'] ); $cases[] = $bad;
		$bad = $base; $bad['total_cents']++; $cases[] = $bad;
		$bad = $base; $bad['items'][0]['unit_price_cents']++; $cases[] = $bad;
		$bad = $base; $bad['delivery']['rates'][1]['package_key'] = 0; $cases[] = $bad;
		$bad = $base; $bad['items'][0]['line_type'] = 'freight'; $cases[] = $bad;
		$bad = $base; $bad['items'] = []; $bad['total_cents'] = 3500; $cases[] = $bad;
		foreach ( $cases as $lines ) { self::assertSame( 0, $quotes->create_for_session( $this->session(), $this->partner(), $lines ) ); }
		self::assertSame( [], QuoteOrderTestStore::$updates );
		foreach ( $GLOBALS['pow_test_orders'] as $order ) { self::assertSame( 'cancelled', $order->get_status() ); }
	}

	public function test_shipping_boundary_and_persisted_readback_failures_remain_optional(): void {
		$quotes = $this->shipping_quotes();
		foreach ( [ 'setter', 'add', 'lost_meta', 'changed_item', 'changed_total' ] as $failure ) {
			POW\Tests\QuoteShipping\Boundary::$failure = $failure;
			self::assertSame( 0, $quotes->create_for_session( $this->session(), $this->partner(), $this->shipping_lines() ) );
		}
		POW\Tests\QuoteShipping\Boundary::$failure = '';
		self::assertSame( [], QuoteOrderTestStore::$updates );
		foreach ( $GLOBALS['pow_test_orders'] as $order ) { self::assertSame( 'cancelled', $order->get_status() ); }
	}

	public function test_shipping_snapshot_survives_later_policy_change_and_clears_blank_address_fields(): void {
		$quotes = $this->shipping_quotes(); $lines = $this->shipping_lines();
		$lines['delivery_destination']['address']['phone'] = '';
		$lines['delivery_destination']['address']['address_2'] = '';
		$GLOBALS['pow_test_after_create_order'] = static function( WC_Order $order ): void { $order->set_props( [ 'shipping_phone' => 'Old phone', 'shipping_address_2' => 'Old building' ] ); };
		try {
			$partner = Partner::from_row( [ 'id' => 7, 'name' => 'Changed policy', 'status' => 'active', 'emit_delivery_line' => false ] );
			$id = $quotes->create_for_session( $this->session(), $partner, $lines );
			self::assertGreaterThan( 0, $id ); $order = wc_get_order( $id );
			self::assertCount( 2, $order->get_items( 'shipping' ) ); self::assertSame( $lines['delivery'], $order->get_meta( '_pow_delivery' ) );
			self::assertSame( '', $order->props['shipping_phone'] ); self::assertSame( '', $order->props['shipping_address_2'] );
		} finally { unset( $GLOBALS['pow_test_after_create_order'] ); }
	}

	public function test_delivery_estimate_note_failure_does_not_cancel_a_complete_quote(): void {
		$quotes = $this->shipping_quotes(); $lines = $this->shipping_lines();
		$lines['delivery']['status'] = 'disabled'; $lines['delivery']['emit'] = false; $lines['total_cents'] = 37533;
		$GLOBALS['pow_test_order_note'] = static function(): int { throw new RuntimeException( 'Native note failed' ); };
		try {
			$id = $quotes->create_for_session( $this->session(), $this->partner(), $lines );
			self::assertGreaterThan( 0, $id ); self::assertSame( Status::SLUG, wc_get_order( $id )->get_status() );
			self::assertSame( [ 'order_id' => $id ], QuoteOrderTestStore::$updates[42] );
			self::assertSame( $lines['delivery'], wc_get_order( $id )->get_meta( '_pow_delivery' ) );
		} finally { unset( $GLOBALS['pow_test_order_note'] ); }
	}

	private function confirmed_lines(): array {
		$lines = $this->shipping_lines();
		$choice = [ 'schema' => 1, 'partner_id' => 7, 'storage_user_id' => 8, 'provider' => 'native', 'key' => 'depot', 'label' => 'Accepted depot', 'book_revision' => 2, 'entry_fingerprint' => str_repeat( 'a', 64 ) ] + $lines['delivery_destination'];
		$lines['delivery_choice'] = $choice;
		$lines['delivery_notes'] = "First line\r\n\tSecond line — call O'Neil\\receiving.";
		$lines['delivery_confirmation'] = [ 'schema' => 1, 'session_id' => 42, 'buyer_user_id' => 99, 'choice_hash' => POW\Addresses\DeliveryData::fingerprint( $choice ), 'cart_fingerprint' => str_repeat( 'b', 64 ), 'policy_fingerprint' => str_repeat( 'c', 64 ), 'delivery' => $lines['delivery'], 'notes' => $lines['delivery_notes'], 'confirmed_at' => 1788980000 ];
		return $lines;
	}

	public function test_confirmed_notes_and_complete_pair_are_persisted_without_rewriting(): void {
		$quotes = $this->shipping_quotes(); $lines = $this->confirmed_lines();
		$id = $quotes->create_for_session( $this->session( [ 'status' => Session::ACTIVE ] ), $this->partner(), $lines );
		self::assertGreaterThan( 0, $id ); $order = wc_get_order( $id );
		self::assertSame( $lines['delivery_notes'], $order->get_customer_note( 'edit' ) );
		self::assertSame( $lines['delivery_notes'], $order->get_meta( '_pow_delivery_notes' ) );
		self::assertSame( $lines['delivery_choice'], json_decode( $order->get_meta( '_pow_delivery_choice' ), true ) );
		self::assertSame( $lines['delivery_confirmation'], json_decode( $order->get_meta( '_pow_delivery_confirmation' ), true ) );
		self::assertStringNotContainsString( $lines['delivery_notes'], json_encode( QuoteOrderTestLog::$written ) );
	}

	public function test_confirmation_pair_must_match_session_destination_delivery_and_notes(): void {
		$quotes = $this->shipping_quotes(); $base = $this->confirmed_lines(); $cases = [];
		foreach ( [ 'delivery_choice', 'delivery_confirmation', 'delivery_notes' ] as $key ) { $bad = $base; unset( $bad[$key] ); $cases[] = $bad; }
		foreach ( [ 'session_id', 'buyer_user_id' ] as $key ) { $bad = $base; $bad['delivery_confirmation'][$key]++; $cases[] = $bad; }
		$bad = $base; $bad['delivery_choice']['partner_id']++; $cases[] = $bad;
		$bad = $base; $bad['delivery_destination']['address']['city'] = 'Changed'; $cases[] = $bad;
		$bad = $base; $bad['delivery_confirmation']['delivery']['rates'][0]['label'] = 'Changed'; $cases[] = $bad;
		$bad = $base; $bad['delivery_notes'] = 'Changed'; $cases[] = $bad;
		foreach ( $cases as $lines ) { self::assertSame( 0, $quotes->create_for_session( $this->session(), $this->partner(), $lines ) ); }
		self::assertSame( [], QuoteOrderTestStore::$updates );
	}

	public function test_confirmed_notes_refuse_invalid_or_overlong_input_without_truncation(): void {
		$quotes = $this->shipping_quotes();
		foreach ( [ str_repeat( 'x', 2001 ), "invalid\0note", "bad\xFF", '<b>unsanitized</b>', [ 'array' ] ] as $notes ) {
			$lines = $this->confirmed_lines(); $lines['delivery_notes'] = $notes; $lines['delivery_confirmation']['notes'] = $notes;
			self::assertSame( 0, $quotes->create_for_session( $this->session(), $this->partner(), $lines ) );
		}
		$lines = $this->confirmed_lines(); $lines['delivery_notes'] = str_repeat( '😀', 2000 ); $lines['delivery_confirmation']['notes'] = $lines['delivery_notes'];
		$id = $quotes->create_for_session( $this->session(), $this->partner(), $lines ); self::assertGreaterThan( 0, $id );
		self::assertSame( $lines['delivery_notes'], wc_get_order( $id )->get_customer_note() );
	}

	public function test_virtual_confirmation_retains_explicit_null_choice_and_clears_old_note(): void {
		$quotes = $this->shipping_quotes(); $lines = $this->confirmed_lines();
		$lines['delivery_choice'] = null; $lines['delivery_destination'] = null; $lines['delivery_notes'] = ''; $lines['total_cents'] = 37533;
		$lines['delivery'] = array_replace( $lines['delivery'], [ 'status' => 'not_required', 'amount_cents' => null, 'emit' => false, 'rates' => [] ] );
		$lines['delivery_confirmation'] = array_replace( $lines['delivery_confirmation'], [ 'choice_hash' => POW\Addresses\DeliveryData::fingerprint( null ), 'delivery' => $lines['delivery'], 'notes' => '' ] );
		$GLOBALS['pow_test_after_create_order'] = static fn( $order ) => $order->set_customer_note( 'Old note' );
		try {
			$id = $quotes->create_for_session( $this->session(), $this->partner(), $lines ); self::assertGreaterThan( 0, $id );
			self::assertSame( '', wc_get_order( $id )->get_customer_note() ); self::assertSame( 'null', wc_get_order( $id )->get_meta( '_pow_delivery_choice' ) );
		} finally { unset( $GLOBALS['pow_test_after_create_order'] ); }
	}

	public function test_lost_provenance_or_changed_native_customer_note_refuses_linking(): void {
		$quotes = $this->shipping_quotes();
		foreach ( [ 'lost_confirmation', 'changed_note' ] as $failure ) {
			POW\Tests\QuoteShipping\Boundary::$failure = $failure;
			self::assertSame( 0, $quotes->create_for_session( $this->session(), $this->partner(), $this->confirmed_lines() ) );
		}
		POW\Tests\QuoteShipping\Boundary::$failure = '';
		self::assertSame( [], QuoteOrderTestStore::$updates );
	}

	public function test_native_cpt_unslashing_preserves_confirmed_backslash_and_releases_its_filter(): void {
		$quotes = $this->shipping_quotes(); $lines = $this->confirmed_lines();
		POW\Tests\QuoteShipping\Boundary::$failure = 'cpt_unslash';
		$id = $quotes->create_for_session( $this->session(), $this->partner(), $lines );
		POW\Tests\QuoteShipping\Boundary::$failure = '';
		self::assertGreaterThan( 0, $id ); self::assertSame( $lines['delivery_notes'], wc_get_order( $id )->get_customer_note() );
		self::assertSame( [], POW\Tests\QuoteShipping\Boundary::$note_filters );
		POW\Tests\QuoteShipping\Boundary::$failure = 'lost_confirmation';
		self::assertSame( 0, $quotes->create_for_session( $this->session(), $this->partner(), $lines ) );
		POW\Tests\QuoteShipping\Boundary::$failure = '';
		self::assertSame( [], POW\Tests\QuoteShipping\Boundary::$note_filters );
	}

	public function test_prepared_destination_is_used_without_reresolving_after_return_claim(): void {
		$quotes = $this->shipping_quotes();
		$lines = $this->lines() + [ 'delivery_destination' => $this->destination() ];
		$before = $lines;
		$this->break_creation(); // The filter changed after preparation; a winning Quote must never call it.
		$GLOBALS['pow_test_wc']->customer = new WC_Customer( [ 'city' => 'Changed master city' ] );
		$order_id = $quotes->create_for_session( $this->session( [ 'delivery_choice' => 'later changed snapshot' ] ), $this->partner(), $lines );
		self::assertGreaterThan( 0, $order_id );
		$order = wc_get_order( $order_id );
		self::assertSame( 'Pretoria', $order->props['shipping_city'] );
		self::assertSame( '+27 12 555 0100', $order->props['shipping_phone'] );
		self::assertCount( 10, $order->props );
		self::assertSame( '', $order->get_meta( QuoteOrder::META_DELIVERY_CODE ) );
		self::assertSame( 'company_book', QuoteOrderTestLog::$written[0][1]['detail']['address_source'] );
		self::assertSame( $before, $lines );
	}

	public function test_explicit_null_destination_suppresses_all_legacy_sources(): void {
		$quotes = $this->shipping_quotes();
		$this->break_creation();
		$GLOBALS['pow_test_wc']->customer = new WC_Customer( [ 'city' => 'Must not use' ] );
		$id = $quotes->create_for_session( $this->session(), $this->partner(), $this->lines() + [ 'delivery_destination' => null ] );
		self::assertGreaterThan( 0, $id );
		self::assertSame( array_fill_keys( array_map( static fn( string $field ): string => 'shipping_' . $field, array_keys( $this->destination()['address'] ) ), '' ), wc_get_order( $id )->props );
		self::assertSame( '', wc_get_order( $id )->get_meta( QuoteOrder::META_DELIVERY_CODE ) );
		self::assertSame( 'none', QuoteOrderTestLog::$written[0][1]['detail']['address_source'] );
	}

	public function test_invalid_mapped_destination_fails_safely_without_legacy_fallback(): void {
		$GLOBALS['pow_test_wc']->customer = new WC_Customer( [ 'city' => 'Legacy valid' ] );
		foreach ( [ false, 'invalid', [], [ 'address' => [] ], array_replace( $this->destination(), [ 'address' => [ 'country' => 'ZA' ] ] ) ] as $bad ) {
			self::assertSame( 0, $this->quotes->create_for_session( $this->session(), $this->partner(), $this->lines() + [ 'delivery_destination' => $bad ] ) );
		}
		self::assertSame( [], QuoteOrderTestStore::$updates );
		foreach ( $GLOBALS['pow_test_orders'] as $order ) { self::assertSame( 'cancelled', $order->get_status() ); }
	}

	public function test_mapped_destination_preserves_existing_payexit_link(): void {
		$quotes = $this->shipping_quotes();
		$id = $quotes->create_for_session( $this->session( [ 'order_id' => 777 ] ), $this->partner(), $this->lines() + [ 'delivery_destination' => $this->destination() ] );
		self::assertGreaterThan( 0, $id );
		self::assertSame( '1 Accepted Road', wc_get_order( $id )->props['shipping_address_1'] );
		self::assertSame( [], QuoteOrderTestStore::$updates );
	}

	public function test_prepared_blank_fields_clear_creation_hook_defaults(): void {
		$quotes = $this->shipping_quotes();
		$destination = $this->destination();
		$destination['address']['phone'] = '';
		$destination['address']['address_2'] = '';
		$GLOBALS['pow_test_after_create_order'] = static function( WC_Order $order ): void {
			$order->set_props( [ 'shipping_phone' => 'Old phone', 'shipping_address_2' => 'Old building' ] );
		};
		try {
			$id = $quotes->create_for_session( $this->session(), $this->partner(), $this->lines() + [ 'delivery_destination' => $destination ] );
			self::assertGreaterThan( 0, $id );
			self::assertSame( '', wc_get_order( $id )->props['shipping_phone'] );
			self::assertSame( '', wc_get_order( $id )->props['shipping_address_2'] );
		} finally { unset( $GLOBALS['pow_test_after_create_order'] ); }
	}


	public function test_creation_item_callbacks_cannot_change_city_before_promotion_or_on_fresh_read(): void {
		$this->assert_creation_item_callback_refused( 'address' );
	}

	public function test_creation_item_callbacks_cannot_change_quantity_before_promotion_or_on_fresh_read(): void {
		$this->assert_creation_item_callback_refused( 'quantity' );
	}

	private function assert_creation_item_callback_refused( string $case ): void {
		foreach ( [ 'auto-draft', Status::SLUG ] as $target_status ) {
			$quotes = $this->shipping_quotes();
			POW\Tests\QuoteShipping\Boundary::$failure = 'save_during_totals';
			$armed = false; $statuses = [];
			$GLOBALS['pow_test_order_save'] = static function( $order ) use ( $target_status, $case, &$armed, &$statuses ): void {
				$statuses[] = $order->get_status();
				if ( ! $armed && $target_status === $order->get_status() ) {
					$armed = true;
					$GLOBALS['pow_test_order_get_items'] = static function( $current, string $type, array $items ) use ( $case ): array {
						if ( 'shipping' === $type ) {
							unset( $GLOBALS['pow_test_order_get_items'] );
							if ( 'address' === $case ) { $current->set_props( [ 'shipping_city' => 'Changed during final item read' ] ); }
							else { $current->items[0]['quantity'] = 6; }
							$current->save();
						}
						return $items;
					};
				}
			};
			try {
				self::assertSame( 0, $quotes->create_for_session( $this->session(), $this->partner(), $this->confirmed_lines() ), $target_status );
				self::assertTrue( $armed );
				self::assertSame( [], QuoteOrderTestStore::$updates );
				self::assertSame( 'cancelled', end( $GLOBALS['pow_test_orders'] )->get_status() );
				if ( 'auto-draft' === $target_status ) { self::assertFalse( in_array( Status::SLUG, $statuses, true ) ); }
			} finally { unset( $GLOBALS['pow_test_order_save'], $GLOBALS['pow_test_order_get_items'] ); POW\Tests\QuoteShipping\Boundary::$failure = ''; }
		}
	}

	public function test_item_groups_replaced_by_later_collection_or_metadata_read_are_refused(): void {
		foreach ( [ 'shipping_collection', 'metadata' ] as $boundary ) {
			$quotes = $this->shipping_quotes();
			$id = $quotes->create_for_session( $this->session(), $this->partner(), $this->confirmed_lines() );
			self::assertGreaterThan( 0, $id );
			$GLOBALS['pow_test_order_meta_save'] = static function() use ( $boundary ): void {
				if ( 'metadata' === $boundary ) {
					$GLOBALS['pow_test_order_metadata_read'] = static function( $order ): void {
						unset( $GLOBALS['pow_test_order_metadata_read'] ); $order->replace_merchandise_collection();
					};
				} else {
					$GLOBALS['pow_test_order_get_items'] = static function( $order, string $type, array $items ): array {
						if ( 'shipping' === $type ) { unset( $GLOBALS['pow_test_order_get_items'] ); $order->replace_merchandise_collection(); }
						return $items;
					};
				}
			};
			try {
				$quotes->attach_poom( $id, '<cXML/>' );
				self::assertSame( 'cancelled', wc_get_order( $id )->get_status(), $boundary );
				self::assertSame( 'quote_poom_failed', end( QuoteOrderTestLog::$written )[0] );
			} finally { unset( $GLOBALS['pow_test_order_meta_save'], $GLOBALS['pow_test_order_metadata_read'], $GLOBALS['pow_test_order_get_items'] ); }
		}
	}

	public function test_stable_item_collection_callbacks_keep_native_identity_and_remain_usable(): void {
		$quotes = $this->shipping_quotes(); $calls = 0;
		$GLOBALS['pow_test_order_get_items'] = static function( $order, string $type, array $items ) use ( &$calls ): array { ++$calls; return array_reverse( $items, true ); };
		try {
			$id = $quotes->create_for_session( $this->session(), $this->partner(), $this->confirmed_lines() );
			self::assertGreaterThan( 0, $id );
			$quotes->attach_poom( $id, '<cXML/>' );
			self::assertGreaterThan( 0, $calls );
			self::assertSame( Status::SLUG, wc_get_order( $id )->get_status() );
			self::assertSame( '<cXML/>', wc_get_order( $id )->get_meta( QuoteOrder::META_POOM_XML ) );
		} finally { unset( $GLOBALS['pow_test_order_get_items'] ); }
	}

	public function test_final_shipping_collection_callback_cannot_change_accepted_city(): void {
		$this->assert_final_item_callback_refused( 'address' );
	}

	public function test_final_shipping_collection_callback_cannot_change_accepted_quantity(): void {
		$this->assert_final_item_callback_refused( 'quantity' );
	}

	public function test_final_callback_mutating_the_same_native_item_object_is_refused(): void {
		$this->assert_final_item_callback_refused( 'native_quantity' );
	}

	private function assert_final_item_callback_refused( string $case ): void {
		$quotes = $this->shipping_quotes(); $lines = $this->confirmed_lines();
		$id = $quotes->create_for_session( $this->session(), $this->partner(), $lines );
		self::assertGreaterThan( 0, $id );
		$GLOBALS['pow_test_order_meta_save'] = static function() use ( $case ): void {
			$reads = 0;
			$GLOBALS['pow_test_order_get_items'] = static function( $order, string $type, array $items ) use ( $case, &$reads ): array {
				if ( 'shipping' === $type && 2 === ++$reads ) {
					unset( $GLOBALS['pow_test_order_get_items'] );
					if ( 'address' === $case ) { $order->set_props( [ 'shipping_city' => 'Changed during final item read' ] ); }
					elseif ( 'native_quantity' === $case ) { $order->get_items( 'line_item' )[0]->set_quantity( 6 ); }
					else { $order->items[0]['quantity'] = 6; }
					$order->save();
				}
				return $items;
			};
		};
		try {
			$quotes->attach_poom( $id, '<cXML/>' );
			self::assertSame( 'cancelled', wc_get_order( $id )->get_status() );
			self::assertSame( 'quote_poom_failed', end( QuoteOrderTestLog::$written )[0] );
			self::assertSame( 'confirmed', end( QuoteOrderTestLog::$written )[1]['detail']['cancellation'] );
			self::assertStringNotContainsString( 'Changed during final item read', json_encode( QuoteOrderTestLog::$written ) );
		} finally { unset( $GLOBALS['pow_test_order_meta_save'], $GLOBALS['pow_test_order_get_items'] ); }
	}

	public function test_attachment_metadata_callbacks_cannot_invalidate_any_accepted_quote_component(): void {
		$quotes = $this->shipping_quotes();
		$mutations = [
			'note' => static function( $order ): void { $order->set_customer_note( 'Changed during metadata attachment' ); },
			'choice' => static function( $order ): void { $order->update_meta_data( QuoteOrder::META_DELIVERY_CHOICE, 'null' ); },
			'confirmation' => static function( $order ): void { $order->update_meta_data( QuoteOrder::META_DELIVERY_CONFIRMATION, '{}' ); },
			'notes_meta' => static function( $order ): void { $order->update_meta_data( QuoteOrder::META_DELIVERY_NOTES, 'Changed protected note' ); },
			'address' => static function( $order ): void { $order->set_props( [ 'shipping_city' => 'Changed' ] ); },
			'shipping' => static function( $order ): void { $order->get_items( 'shipping' )[0]->set_total( '0.01' ); },
			'quantity' => static function( $order ): void { $order->items[0]['quantity'] = 6; },
			'subtotal' => static function( $order ): void { $order->items[0]['subtotal'] = 0.01; },
			'total' => static function( $order ): void { $order->items[0]['total'] = 0.01; },
			'count' => static function( $order ): void { $order->items[] = $order->items[0]; },
			'sku' => static function( $order ): void { $order->items[0]['sku'] = 'Changed'; },
			'session' => static function( $order ): void { $order->update_meta_data( QuoteOrder::META_SESSION_ID, '999' ); },
		];
		foreach ( $mutations as $case => $mutate ) {
			$id = $quotes->create_for_session( $this->session(), $this->partner(), $this->confirmed_lines() );
			self::assertGreaterThan( 0, $id, $case );
			$GLOBALS['pow_test_order_meta_save'] = static function( $order ) use ( $mutate ): void {
				$persisted = clone $order; $mutate( $persisted ); $GLOBALS['pow_test_orders'][$order->get_id()] = $persisted;
			};
			$GLOBALS['pow_test_order_save'] = static function( $order ): void { $GLOBALS['pow_test_orders'][$order->get_id()] = clone $order; };
			try {
				$quotes->attach_poom( $id, '<cXML/>' );
				self::assertSame( 'cancelled', wc_get_order( $id )->get_status(), $case );
				self::assertSame( 'quote_poom_failed', end( QuoteOrderTestLog::$written )[0], $case );
				self::assertSame( 'quote_attachment_failed', end( QuoteOrderTestLog::$written )[1]['detail']['error'] );
			} finally { unset( $GLOBALS['pow_test_order_meta_save'], $GLOBALS['pow_test_order_save'] ); }
		}
	}

	public function test_attachment_uses_metadata_save_without_running_full_order_save_hooks(): void {
		$quotes = $this->shipping_quotes(); $lines = $this->confirmed_lines();
		$id = $quotes->create_for_session( $this->session(), $this->partner(), $lines );
		self::assertGreaterThan( 0, $id );
		$full_saves = 0;
		$GLOBALS['pow_test_order_save'] = static function( WC_Order $order ) use ( &$full_saves ): void {
			++$full_saves; $order->set_customer_note( 'Changed during XML attachment' );
		};
		try {
			$quotes->attach_poom( $id, '<cXML/>' );
			self::assertSame( 0, $full_saves );
			self::assertSame( $lines['delivery_notes'], wc_get_order( $id )->get_customer_note( 'edit' ) );
			self::assertSame( '<cXML/>', wc_get_order( $id )->get_meta( QuoteOrder::META_POOM_XML ) );
			self::assertSame( Status::SLUG, wc_get_order( $id )->get_status() );
		} finally { unset( $GLOBALS['pow_test_order_save'] ); }
	}

	public function test_every_merchandise_invariant_is_checked_at_totals_and_persisted_save_boundaries(): void {
		$mutations = [
			'quantity' => static function( $order ): void { $order->items[0]['quantity'] = 6; },
			'subtotal' => static function( $order ): void { $order->items[0]['subtotal'] = 0.01; },
			'total' => static function( $order ): void { $order->items[0]['total'] = 0.01; },
			'product_id' => static function( $order ): void { $order->items[0]['product_id'] = 999; },
			'variation_id' => static function( $order ): void { $order->items[0]['variation_id'] = 999; },
			'sku' => static function( $order ): void { $order->items[0]['sku'] = 'CHANGED'; },
			'name' => static function( $order ): void { $order->items[0]['name'] = 'Changed'; },
			'missing' => static function( $order ): void { $order->items = []; },
			'extra' => static function( $order ): void { $order->items[] = $order->items[0]; },
		];
		foreach ( [ 'totals', 'save', 'fresh' ] as $schedule ) {
			foreach ( $mutations as $case => $mutate ) {
				$quotes = $this->shipping_quotes();
				POW\Tests\QuoteShipping\Boundary::$failure = 'totals' === $schedule ? 'save_during_totals' : '';
				$statuses = [];
				$GLOBALS['pow_test_order_save'] = static function( $order ) use ( $schedule, $mutate, &$statuses ): void {
					$statuses[] = $order->get_status();
					$target = 'totals' === $schedule ? 'auto-draft' : Status::SLUG;
					if ( $target === $order->get_status() ) {
						$stored = 'fresh' === $schedule ? clone $order : $order;
						$mutate( $stored ); $GLOBALS['pow_test_orders'][$order->get_id()] = $stored;
					} else { $GLOBALS['pow_test_orders'][$order->get_id()] = $order; }
				};
				try {
					self::assertSame( 0, $quotes->create_for_session( $this->session(), $this->partner(), $this->confirmed_lines() ), $schedule . '/' . $case );
					self::assertSame( [], QuoteOrderTestStore::$updates );
					self::assertSame( 'cancelled', end( $GLOBALS['pow_test_orders'] )->get_status() );
					if ( 'totals' === $schedule ) { self::assertSame( [ 'auto-draft', 'cancelled' ], $statuses ); }
				} finally { unset( $GLOBALS['pow_test_order_save'] ); POW\Tests\QuoteShipping\Boundary::$failure = ''; }
			}
		}
	}

	public function test_live_product_variation_and_duplicate_fallback_lines_keep_their_prepared_identity(): void {
		$quotes = $this->shipping_quotes();
		$GLOBALS['pow_test_products'][412] = new WC_Product( 412, 'Native product name', 'SKU-1001' );
		$GLOBALS['pow_test_products'][413] = new POW\Tests\QuoteShipping\Variation( 413, 'Native variation name', 'SKU-V' );
		$lines = $this->lines() + [ 'delivery_destination' => $this->destination() ];
		$lines['items'][] = array_replace( $lines['items'][0], [ 'aux_id' => '412|413', 'quantity' => 1 ] );
		$lines['items'][] = array_replace( $lines['items'][0], [ 'aux_id' => '', 'supplier_part_id' => 'DELETED', 'quantity' => 1 ] );
		$lines['items'][] = $lines['items'][2];
		$lines['total_cents'] = 75066;
		$GLOBALS['pow_test_order_save'] = static function( $order ): void { if ( Status::SLUG === $order->get_status() ) { $order->items = array_reverse( $order->items ); } };
		try {
			$id = $quotes->create_for_session( $this->session(), $this->partner(), $lines );
			self::assertGreaterThan( 0, $id );
			self::assertCount( 4, wc_get_order( $id )->get_items() );
			self::assertSame( 413, wc_get_order( $id )->items[2]['variation_id'] );
			self::assertSame( 412, wc_get_order( $id )->items[2]['product_id'] );
		} finally { unset( $GLOBALS['pow_test_order_save'] ); }
		QuoteOrderTestStore::$updates = [];
		foreach ( [ 'product_id', 'variation_id' ] as $field ) {
			$GLOBALS['pow_test_order_save'] = static function( $order ) use ( $field ): void { if ( Status::SLUG === $order->get_status() ) { $order->items[1][$field] = 999; } };
			try { self::assertSame( 0, $quotes->create_for_session( $this->session(), $this->partner(), $lines ) ); }
			finally { unset( $GLOBALS['pow_test_order_save'] ); }
		}
		self::assertSame( [], QuoteOrderTestStore::$updates );
	}

	public function test_attachment_rejects_changes_after_creation_before_metadata_write(): void {
		$quotes = $this->shipping_quotes(); $lines = $this->confirmed_lines();
		$id = $quotes->create_for_session( $this->session(), $this->partner(), $lines );
		self::assertGreaterThan( 0, $id );
		wc_get_order( $id )->items[0]['quantity'] = 6;
		$quotes->attach_poom( $id, '<cXML/>' );
		self::assertSame( 'cancelled', wc_get_order( $id )->get_status() );
		self::assertSame( '', wc_get_order( $id )->get_meta( QuoteOrder::META_POOM_XML ) );
		self::assertSame( 'confirmed', end( QuoteOrderTestLog::$written )[1]['detail']['cancellation'] );
	}

	public function test_attachment_keeps_cross_request_legacy_api_and_validates_stored_provenance(): void {
		$quotes = $this->shipping_quotes(); $lines = $this->confirmed_lines();
		$id = $quotes->create_for_session( $this->session(), $this->partner(), $lines );
		self::assertGreaterThan( 0, $id );
		$this->shipping_quotes()->attach_poom( $id, '<cXML/>' );
		self::assertSame( Status::SLUG, wc_get_order( $id )->get_status() );
		self::assertSame( '<cXML/>', wc_get_order( $id )->get_meta( QuoteOrder::META_POOM_XML ) );
		wc_get_order( $id )->set_customer_note( 'Changed before another request' );
		$this->shipping_quotes()->attach_poom( $id, '<cXML>retry</cXML>' );
		self::assertSame( 'cancelled', wc_get_order( $id )->get_status() );
	}

	public function test_metadata_failure_is_nonblocking_but_mutation_even_when_throwing_cancels(): void {
		$quotes = $this->shipping_quotes();
		foreach ( [ false, true ] as $mutate ) {
			$id = $quotes->create_for_session( $this->session(), $this->partner(), $this->confirmed_lines() );
			self::assertGreaterThan( 0, $id );
			$GLOBALS['pow_test_order_meta_save'] = static function( $order ) use ( $mutate ): void {
				if ( $mutate ) { $order->set_customer_note( 'Changed before throwing' ); }
				throw new RuntimeException( 'Private callback content' );
			};
			try {
				$quotes->attach_poom( $id, '<cXML/>' );
				self::assertSame( $mutate ? 'cancelled' : Status::SLUG, wc_get_order( $id )->get_status() );
				self::assertSame( $mutate ? 'confirmed' : 'not_needed', end( QuoteOrderTestLog::$written )[1]['detail']['cancellation'] );
				self::assertStringNotContainsString( 'Private callback content', json_encode( QuoteOrderTestLog::$written ) );
			} finally { unset( $GLOBALS['pow_test_order_meta_save'] ); }
		}
	}

	public function test_attachment_verifies_explicit_null_address_and_reports_failed_cancellation(): void {
		$quotes = $this->shipping_quotes(); $lines = $this->confirmed_lines();
		$lines['delivery_choice'] = null; $lines['delivery_destination'] = null; $lines['delivery_notes'] = ''; $lines['total_cents'] = 37533;
		$lines['delivery'] = array_replace( $lines['delivery'], [ 'status' => 'not_required', 'amount_cents' => null, 'emit' => false, 'rates' => [] ] );
		$lines['delivery_confirmation'] = array_replace( $lines['delivery_confirmation'], [ 'choice_hash' => POW\Addresses\DeliveryData::fingerprint( null ), 'delivery' => $lines['delivery'], 'notes' => '' ] );
		$id = $quotes->create_for_session( $this->session(), $this->partner(), $lines );
		self::assertGreaterThan( 0, $id );
		$this->shipping_quotes()->attach_poom( $id, '<cXML/>' );
		self::assertSame( Status::SLUG, wc_get_order( $id )->get_status() );
		$GLOBALS['pow_test_order_meta_save'] = static function( $order ): void {
			$persisted = clone $order; $persisted->set_props( [ 'shipping_city' => 'Injected city' ] );
			$GLOBALS['pow_test_orders'][$order->get_id()] = $persisted;
		};
		$GLOBALS['pow_test_order_save'] = static fn() => 0;
		try {
			$quotes->attach_poom( $id, '<cXML>retry</cXML>' );
			self::assertSame( 'quote_poom_failed', end( QuoteOrderTestLog::$written )[0] );
			self::assertSame( 'unconfirmed', end( QuoteOrderTestLog::$written )[1]['detail']['cancellation'] );
			self::assertStringNotContainsString( 'Injected city', json_encode( QuoteOrderTestLog::$written ) );
		} finally { unset( $GLOBALS['pow_test_order_meta_save'], $GLOBALS['pow_test_order_save'] ); }
	}

	public function test_missing_xml_readback_does_not_cancel_verified_quote(): void {
		$quotes = $this->shipping_quotes();
		$id = $quotes->create_for_session( $this->session(), $this->partner(), $this->confirmed_lines() );
		self::assertGreaterThan( 0, $id );
		$GLOBALS['pow_test_order_meta_save'] = static function( $order ): void { unset( $order->meta[QuoteOrder::META_POOM_XML] ); };
		try {
			$quotes->attach_poom( $id, '<cXML/>' );
			self::assertSame( Status::SLUG, wc_get_order( $id )->get_status() );
			self::assertSame( 'quote_poom_failed', end( QuoteOrderTestLog::$written )[0] );
			self::assertSame( 'not_needed', end( QuoteOrderTestLog::$written )[1]['detail']['cancellation'] );
		} finally { unset( $GLOBALS['pow_test_order_meta_save'] ); }
	}

	public function test_saved_merchandise_quantity_cannot_change_with_the_same_line_total(): void {
		$quotes = $this->shipping_quotes();
		$GLOBALS['pow_test_order_save'] = static function( WC_Order $order ): void {
			if ( Status::SLUG === $order->get_status() ) { $order->items[0]['quantity'] = 6; }
		};
		try {
			self::assertSame( 0, $quotes->create_for_session( $this->session(), $this->partner(), $this->confirmed_lines() ) );
			self::assertSame( [], QuoteOrderTestStore::$updates );
			self::assertSame( 'cancelled', end( $GLOBALS['pow_test_orders'] )->get_status() );
		} finally { unset( $GLOBALS['pow_test_order_save'] ); }
	}

	public function test_save_hook_cannot_change_any_prepared_shipping_field(): void {
		$quotes = $this->shipping_quotes();
		foreach ( array_keys( $this->destination()['address'] ) as $field ) {
			$GLOBALS['pow_test_order_save'] = static function( WC_Order $order ) use ( $field ): void {
				if ( Status::SLUG === $order->get_status() ) { $order->set_props( [ 'shipping_' . $field => 'Changed by save hook' ] ); }
			};
			try {
				self::assertSame( 0, $quotes->create_for_session( $this->session(), $this->partner(), $this->confirmed_lines() ), $field );
				self::assertSame( [], QuoteOrderTestStore::$updates );
				self::assertSame( 'cancelled', end( $GLOBALS['pow_test_orders'] )->get_status() );
				self::assertSame( 'quote_creation_failed', end( QuoteOrderTestLog::$written )[1]['detail']['error'] );
			} finally { unset( $GLOBALS['pow_test_order_save'] ); }
		}
	}

	public function test_totals_save_mutation_is_rejected_before_quote_promotion(): void {
		$quotes = $this->shipping_quotes();
		POW\Tests\QuoteShipping\Boundary::$failure = 'save_during_totals';
		$statuses = [];
		$GLOBALS['pow_test_order_save'] = static function( WC_Order $order ) use ( &$statuses ): void {
			$statuses[] = $order->get_status();
			if ( 'auto-draft' === $order->get_status() ) { $order->set_props( [ 'shipping_city' => 'Changed by save hook' ] ); }
		};
		try {
			self::assertSame( 0, $quotes->create_for_session( $this->session(), $this->partner(), $this->confirmed_lines() ) );
			self::assertSame( [ 'auto-draft', 'cancelled' ], $statuses );
			self::assertSame( [], QuoteOrderTestStore::$updates );
		} finally { unset( $GLOBALS['pow_test_order_save'] ); POW\Tests\QuoteShipping\Boundary::$failure = ''; }
	}

	public function test_shipping_is_verified_on_fresh_persisted_object(): void {
		$quotes = $this->shipping_quotes();
		$GLOBALS['pow_test_order_save'] = static function( WC_Order $order ): void {
			if ( Status::SLUG === $order->get_status() ) {
				$persisted = clone $order;
				$persisted->set_props( [ 'shipping_city' => 'Changed only in persistence' ] );
				$GLOBALS['pow_test_orders'][$order->get_id()] = $persisted;
			} else { $GLOBALS['pow_test_orders'][$order->get_id()] = clone $order; }
		};
		try {
			self::assertSame( 0, $quotes->create_for_session( $this->session(), $this->partner(), $this->confirmed_lines() ) );
			self::assertSame( [], QuoteOrderTestStore::$updates );
			self::assertSame( 'cancelled', end( $GLOBALS['pow_test_orders'] )->get_status() );
		} finally { unset( $GLOBALS['pow_test_order_save'] ); }
	}

	public function test_explicit_null_clears_every_creation_default_and_rejects_save_injection(): void {
		$quotes = $this->shipping_quotes();
		$props = [];
		foreach ( $this->destination()['address'] as $field => $value ) { $props['shipping_' . $field] = $value; }
		$GLOBALS['pow_test_after_create_order'] = static function( WC_Order $order ) use ( $props ): void { $order->set_props( $props ); };
		$lines = $this->lines() + [ 'delivery_destination' => null ];
		try {
			$id = $quotes->create_for_session( $this->session(), $this->partner(), $lines );
			self::assertGreaterThan( 0, $id );
			self::assertSame( array_fill_keys( array_keys( $props ), '' ), wc_get_order( $id )->props );
			QuoteOrderTestStore::$updates = [];
			foreach ( array_keys( $props ) as $prop ) {
				$GLOBALS['pow_test_order_save'] = static function( WC_Order $order ) use ( $prop ): void {
					if ( Status::SLUG === $order->get_status() ) { $order->set_props( [ $prop => 'Injected' ] ); }
				};
				self::assertSame( 0, $quotes->create_for_session( $this->session(), $this->partner(), $lines ), $prop );
				self::assertSame( [], QuoteOrderTestStore::$updates );
				self::assertSame( 'cancelled', end( $GLOBALS['pow_test_orders'] )->get_status() );
			}
		} finally { unset( $GLOBALS['pow_test_after_create_order'], $GLOBALS['pow_test_order_save'] ); }
	}

	/** Make the address step throw, part-way through a saved order: the saved-customer candidate is the last mutable legacy source left. */
	private function break_creation(): void {
		$GLOBALS['pow_test_wc']->customer = new QuoteOrderExplodingCustomer();
	}

	public function test_order_is_created_and_linked_to_the_session(): void {
		$order_id = $this->quotes->create_for_session( $this->session(), $this->partner(), $this->lines() );

		self::assertGreaterThan( 0, $order_id );
		self::assertSame( [ 'order_id' => $order_id ], QuoteOrderTestStore::$updates[42] );
	}

	public function test_order_carries_the_status_lines_meta_and_note(): void {
		$order_id = $this->quotes->create_for_session( $this->session(), $this->partner(), $this->lines() );
		$order    = wc_get_order( $order_id );

		self::assertSame( Status::SLUG, $order->get_status() );
		self::assertCount( 1, $order->items );
		self::assertSame( 375.33, $order->items[0]['total'] );
		self::assertSame( 42, (int) $order->get_meta( QuoteOrder::META_SESSION_ID ) );
		self::assertSame( 7, (int) $order->get_meta( QuoteOrder::META_PARTNER_ID ) );
		self::assertSame( 'Example Buyer Company', $order->get_meta( QuoteOrder::META_PARTNER_NAME ) );
		self::assertSame( 'zoe@buyer.example.test', $order->get_meta( QuoteOrder::META_BUYER_IDENTITY ) );
		self::assertSame( 'Zoë Buyer', $order->get_meta( QuoteOrder::META_BUYER_NAME ) );
		self::assertSame( 'basket-reference', $order->get_meta( QuoteOrder::META_BUYER_COOKIE ) );
		self::assertSame( '', $order->get_meta( QuoteOrder::META_POOM_XML ), 'the document does not exist until the build has run' );
		self::assertCount( 1, $order->notes );
		self::assertStringContainsString( '42', $order->notes[0] );
		self::assertStringContainsString( 'Example Buyer Company', $order->notes[0] );
		self::assertStringContainsString( 'Bought by Zoë Buyer <zoe@buyer.example.test> via PunchOut (Example Buyer Company)', $order->notes[0] );
		// The buyer is evidence on the order, never in the log.
		self::assertStringNotContainsString( 'zoe@buyer.example.test', (string) json_encode( QuoteOrderTestLog::$written ) );
	}

	/**
	 * The shared login means the order's customer is the connection's own
	 * account on every visit. It is evidence of which company bought, never
	 * of which person, and nothing may key a per-visit decision on it.
	 */
	public function test_the_quote_customer_stays_the_bound_account_for_every_visit(): void {
		$first  = wc_get_order( $this->quotes->create_for_session( $this->session(), $this->partner(), $this->lines() ) );
		$second = wc_get_order( $this->quotes->create_for_session( $this->session( [ 'id' => 43, 'buyer_identity' => 'sam@buyer.example.test', 'buyer_name' => 'Sam Buyer' ] ), $this->partner(), $this->lines() ) );

		self::assertSame( 99, $first->customer_id );
		self::assertSame( 99, $second->customer_id );
		self::assertSame( [ 99 ], array_unique( [ $first->customer_id, $second->customer_id ] ) );
		self::assertSame( 'zoe@buyer.example.test', $first->get_meta( QuoteOrder::META_BUYER_IDENTITY ) );
		self::assertSame( 'sam@buyer.example.test', $second->get_meta( QuoteOrder::META_BUYER_IDENTITY ) );
		self::assertNotSame( $first->get_meta( QuoteOrder::META_SESSION_ID ), $second->get_meta( QuoteOrder::META_SESSION_ID ) );
	}

	/**
	 * An unidentified buyer is a legitimate, audited outcome: the meta is
	 * written as an empty string rather than left off, and the note says so
	 * instead of printing a blank name.
	 */
	public function test_an_unidentified_buyer_is_stamped_and_named_explicitly(): void {
		$order = wc_get_order( $this->quotes->create_for_session( $this->session( [ 'buyer_identity' => null, 'buyer_name' => '' ] ), $this->partner(), $this->lines() ) );

		self::assertSame( '', $order->get_meta( QuoteOrder::META_BUYER_IDENTITY ) );
		self::assertSame( '', $order->get_meta( QuoteOrder::META_BUYER_NAME ) );
		self::assertSame( 'basket-reference', $order->get_meta( QuoteOrder::META_BUYER_COOKIE ) );
		self::assertStringContainsString( 'Bought by an unnamed buyer via PunchOut (Example Buyer Company); the purchasing system sent no name or e-mail', $order->notes[0] );
	}

	/** The e-mail alone when the purchasing system named nobody. */
	public function test_a_nameless_buyer_is_named_by_e_mail_alone(): void {
		$order = wc_get_order( $this->quotes->create_for_session( $this->session( [ 'buyer_name' => null ] ), $this->partner(), $this->lines() ) );

		self::assertStringContainsString( 'Bought by zoe@buyer.example.test via PunchOut (Example Buyer Company)', $order->notes[0] );
		self::assertStringNotContainsString( '<>', $order->notes[0] );
	}

	/** A note failure must not cost the quote its attribution meta. */
	public function test_a_refused_note_still_leaves_the_attribution_on_the_order(): void {
		$GLOBALS['pow_test_order_note'] = static fn() => false;
		try {
			$order = wc_get_order( $this->quotes->create_for_session( $this->session(), $this->partner(), $this->lines() ) );
			self::assertSame( 'zoe@buyer.example.test', $order->get_meta( QuoteOrder::META_BUYER_IDENTITY ) );
			self::assertSame( Status::SLUG, $order->get_status() );
		} finally { unset( $GLOBALS['pow_test_order_note'] ); }
	}

	/**
	 * Two visits of one bound account: the order's customer id is the same
	 * on both, so the accepted delivery confirmation binds through the
	 * session id alone. Visit A's confirmation cannot validate against
	 * visit B's order.
	 */
	public function test_one_visits_confirmation_cannot_validate_against_anothers_order(): void {
		$quotes = $this->shipping_quotes();
		$lines  = $this->confirmed_lines();

		$first = $quotes->create_for_session( $this->session(), $this->partner(), $lines );
		self::assertGreaterThan( 0, $first );

		// The colleague's visit, on the same login, with visit 42's accepted confirmation.
		self::assertSame( 0, $quotes->create_for_session( $this->session( [ 'id' => 43 ] ), $this->partner(), $lines ) );

		$theirs = $lines;
		$theirs['delivery_confirmation']['session_id'] = 43;
		$second = $quotes->create_for_session( $this->session( [ 'id' => 43 ] ), $this->partner(), $theirs );
		self::assertGreaterThan( 0, $second );
		self::assertSame( 99, wc_get_order( $first )->get_customer_id() );
		self::assertSame( 99, wc_get_order( $second )->get_customer_id() );
		self::assertSame( 43, (int) wc_get_order( $second )->get_meta( QuoteOrder::META_SESSION_ID ) );
		self::assertNotSame( wc_get_order( $first )->get_meta( QuoteOrder::META_DELIVERY_CONFIRMATION ), wc_get_order( $second )->get_meta( QuoteOrder::META_DELIVERY_CONFIRMATION ) );
	}

	public function test_the_bought_by_admin_line_names_the_buyer_and_escapes_both_values(): void {
		$order = wc_get_order( $this->quotes->create_for_session( $this->session( [ 'buyer_name' => 'Zoë <b>Buyer</b>', 'buyer_identity' => 'zoe+"one"@buyer.example.test' ] ), $this->partner(), $this->lines() ) );

		self::assertSame( 'Bought by Zoë <b>Buyer</b> <zoe+"one"@buyer.example.test> via PunchOut (Example Buyer Company)', $this->quotes->bought_by_for( $order ) );

		$markup = $this->rendered( $order );
		self::assertStringContainsString( 'Zoë &lt;b&gt;Buyer&lt;/b&gt;', $markup );
		self::assertStringContainsString( '&quot;one&quot;@buyer.example.test', $markup );
		self::assertStringNotContainsString( '<b>Buyer</b>', $markup );
	}

	public function test_the_bought_by_admin_line_names_the_anonymous_case_and_an_unnamed_connection(): void {
		$order = wc_get_order( $this->quotes->create_for_session( $this->session( [ 'buyer_identity' => '', 'buyer_name' => '' ] ), Partner::from_row( [ 'id' => 7, 'status' => 'active' ] ), $this->lines() ) );

		self::assertStringContainsString( 'Bought by an unnamed buyer via PunchOut (connection #7); the purchasing system sent no name or e-mail', $this->rendered( $order ) );
	}

	/** An order that is not ours carries no session meta and prints nothing at all. */
	public function test_the_bought_by_admin_line_is_silent_on_an_ordinary_order(): void {
		self::assertSame( '', $this->rendered( new WC_Order( 4242 ) ) );
		self::assertSame( '', $this->rendered( null ) );
	}

	public function test_the_admin_order_screen_registers_the_bought_by_line_outside_the_master_switch(): void {
		$quotes = $this->shipping_quotes();
		POW\Tests\QuoteShipping\Boundary::$hooks = [];
		$quotes->register_admin();

		self::assertContains( [ 'action', 'woocommerce_admin_order_data_after_billing_address' ], POW\Tests\QuoteShipping\Boundary::$hooks );
		self::assertContains( [ 'filter', 'woocommerce_order_actions' ], POW\Tests\QuoteShipping\Boundary::$hooks );
	}

	private function rendered( mixed $order ): string {
		ob_start();
		$this->quotes->render_bought_by( $order );
		return (string) ob_get_clean();
	}

	/**
	 * The line the buyer's system was quoted is attached to the product it
	 * came from, at the basket's own price.
	 */
	public function test_a_line_is_attached_to_its_product(): void {
		$GLOBALS['pow_test_products'][412] = new WC_Product( 412, 'Example product', 'SKU-1001' );

		$order = wc_get_order( $this->quotes->create_for_session( $this->session(), $this->partner(), $this->lines() ) );

		self::assertSame( 412, $order->items[0]['product_id'] );
		self::assertSame( 3.0, $order->items[0]['quantity'] );
		self::assertSame( 375.33, $order->items[0]['subtotal'] );
		self::assertSame( 375.33, $order->items[0]['total'] );
	}

	/**
	 * The product was deleted between the punchout and the return: the
	 * priced line survives as a bare item rather than vanishing from an
	 * order that must agree with the basket the buyer received.
	 */
	public function test_a_deleted_product_still_leaves_a_priced_line(): void {
		$order = wc_get_order( $this->quotes->create_for_session( $this->session(), $this->partner(), $this->lines() ) );

		self::assertSame( 0, $order->items[0]['product_id'] );
		self::assertSame( 'Example product', $order->items[0]['name'] );
		self::assertSame( 'SKU-1001', $order->items[0]['sku'] );
		self::assertSame( 375.33, $order->items[0]['total'] );
	}

	public function test_totals_are_calculated_without_taxes(): void {
		$order_id = $this->quotes->create_for_session( $this->session(), $this->partner(), $this->lines() );

		self::assertSame( [ false ], wc_get_order( $order_id )->totals_calls, 'the basket quotes ex-tax unit prices' );
	}

	public function test_inbound_ship_to_supplies_the_address_and_code(): void {
		if ( ! extension_loaded( 'dom' ) ) {
			self::markTestSkipped( 'ext-dom not available' );
		}

		$order = wc_get_order( $this->quotes->create_for_session( $this->session(), $this->partner(), $this->lines() ) );

		self::assertSame( 'BUYER-001', $order->get_meta( QuoteOrder::META_DELIVERY_CODE ) );
		self::assertSame( 'Head office', $order->props['shipping_company'] );
	}

	public function test_the_inbound_ship_to_wins_over_the_saved_customer_address(): void {
		$GLOBALS['pow_test_wc']->customer = new WC_Customer( [ 'city' => 'Cape Town', 'company' => 'Saved company' ] );

		$order = wc_get_order( $this->quotes->create_for_session( $this->session(), $this->partner(), $this->lines() ) );

		self::assertSame( 'BUYER-001', $order->get_meta( QuoteOrder::META_DELIVERY_CODE ) );
		self::assertSame( [ 'shipping_company' ], array_keys( $order->props ), 'only known shipping props are written' );
		self::assertSame( 'Head office', $order->props['shipping_company'] );
	}

	public function test_the_saved_customer_address_is_the_last_legacy_candidate(): void {
		$GLOBALS['pow_test_wc']->customer = new WC_Customer( [ 'city' => 'Cape Town', 'company' => 'Saved company' ] );

		$order = wc_get_order( $this->quotes->create_for_session( $this->session( [ 'ship_to' => null ] ), $this->partner(), $this->lines() ) );

		self::assertSame( '', $order->get_meta( QuoteOrder::META_DELIVERY_CODE ) );
		self::assertSame( [ 'shipping_company', 'shipping_city' ], array_keys( $order->props ) );
		self::assertSame( 'Cape Town', $order->props['shipping_city'] );
	}

	/**
	 * An empty basket is a legitimate outcome (the buyer cleared the cart),
	 * not a failure: the order still exists and the audit row records it.
	 */
	public function test_an_empty_basket_still_creates_an_order(): void {
		$order_id = $this->quotes->create_for_session( $this->session(), $this->partner(), [ 'currency' => 'ZAR', 'items' => [] ] );

		self::assertGreaterThan( 0, $order_id );
		self::assertSame( [], wc_get_order( $order_id )->items );
		self::assertSame( 'quote_order_created', QuoteOrderTestLog::$written[0][0] );
	}

	/**
	 * DESIGN §6: a failed quote order never blocks the basket. The call
	 * returns 0, nothing is written back to the session, every occurrence
	 * is audited, and the operator is mailed at most once an hour.
	 */
	public function test_a_failure_returns_zero_audits_and_mails_once(): void {
		$GLOBALS['pow_test_options']['admin_email'] = 'ops@example.com';

		$this->break_creation();

		self::assertSame( 0, $this->quotes->create_for_session( $this->session(), $this->partner(), $this->lines() ) );
		self::assertSame( 0, $this->quotes->create_for_session( $this->session(), $this->partner(), $this->lines() ) );

		self::assertSame( [], QuoteOrderTestStore::$updates, 'a failed order is never linked to the session' );
		self::assertSame( 'quote_order_failed', QuoteOrderTestLog::$written[0][0] );
		self::assertCount( 2, QuoteOrderTestLog::$written, 'every failure is audited' );
		self::assertCount( 1, $GLOBALS['pow_test_mail'], 'the second failure within the hour is logged, not mailed' );
		self::assertStringContainsString( 'quote_creation_failed', $GLOBALS['pow_test_mail'][0]['message'] );
	}

	/**
	 * wc_create_order() persists immediately, and add_product() and
	 * calculate_totals() save again, so a throw part-way through would
	 * leave an incomplete native auto-draft in the shop. It is
	 * cancelled — never deleted — and named everywhere the operator looks.
	 */
	public function test_a_part_built_order_is_cancelled_and_named(): void {
		$GLOBALS['pow_test_options']['admin_email'] = 'ops@example.com';

		$this->break_creation();

		self::assertSame( 0, $this->quotes->create_for_session( $this->session(), $this->partner(), $this->lines() ) );

		$order = array_values( $GLOBALS['pow_test_orders'] )[0];

		self::assertSame( 'cancelled', $order->get_status() );
		self::assertCount( 1, $order->notes );
		self::assertStringContainsString( 'part-built', $order->notes[0] );
		self::assertSame( $order->get_id(), QuoteOrderTestLog::$written[0][1]['order_id'] );
		self::assertSame( $order->get_id(), QuoteOrderTestLog::$written[0][1]['detail']['order_id'] );
		self::assertStringContainsString( '#' . $order->get_id(), $GLOBALS['pow_test_mail'][0]['message'] );
		self::assertStringContainsString( 'cancelled', $GLOBALS['pow_test_mail'][0]['message'] );
	}

	/**
	 * wc_create_order() returns a WP_Error rather than throwing, so the
	 * guard is what turns it into the failure path — with a safe
	 * diagnostic code, and without claiming an order exists.
	 */
	public function test_a_wp_error_from_wc_create_order_is_the_failure_path(): void {
		$GLOBALS['pow_test_options']['admin_email']      = 'ops@example.com';
		$GLOBALS['pow_test_create_order_error']          = 'Could not insert the order into the database';

		self::assertSame( 0, $this->quotes->create_for_session( $this->session(), $this->partner(), $this->lines() ) );

		self::assertSame( [], $GLOBALS['pow_test_orders'], 'nothing was created' );
		self::assertSame( 0, QuoteOrderTestLog::$written[0][1]['order_id'] );
		self::assertStringContainsString( 'quote_creation_failed', QuoteOrderTestLog::$written[0][1]['detail']['error'] );
		self::assertStringContainsString( 'no order ID was returned', $GLOBALS['pow_test_mail'][0]['message'] );
	}

	/**
	 * An explicitly linked quote can be reused sequentially; concurrency is enforced at the return endpoint.
	 */
	public function test_a_double_submitted_return_reuses_the_existing_quote(): void {
		$order_id = $this->quotes->create_for_session( $this->session(), $this->partner(), $this->lines() );

		$again = $this->quotes->create_for_session( $this->session( [ 'order_id' => $order_id ] ), $this->partner(), $this->lines() );

		self::assertSame( $order_id, $again );
		self::assertCount( 1, $GLOBALS['pow_test_orders'] );
		self::assertSame( 'quote_order_reused', QuoteOrderTestLog::$written[1][0] );
		self::assertSame( $order_id, QuoteOrderTestLog::$written[1][1]['order_id'] );
	}

	/**
	 * The reuse check reads the database, and it runs while the buyer's
	 * basket is waiting: a lookup that throws is a failed quote, not a
	 * failed return.
	 */
	public function test_a_throwing_reuse_check_never_reaches_the_caller(): void {
		$GLOBALS['pow_test_options']['admin_email'] = 'ops@example.com';
		$GLOBALS['pow_test_get_order_error']        = 'order lookup exploded';

		self::assertSame( 0, $this->quotes->create_for_session( $this->session( [ 'order_id' => 1001 ] ), $this->partner(), $this->lines() ) );

		self::assertSame( [], $GLOBALS['pow_test_orders'], 'nothing was created' );
		self::assertSame( 'quote_order_failed', QuoteOrderTestLog::$written[0][0] );
		self::assertSame( 0, QuoteOrderTestLog::$written[0][1]['order_id'] );
		self::assertStringContainsString( 'quote_creation_failed', QuoteOrderTestLog::$written[0][1]['detail']['error'] );
	}

	/**
	 * PayExit owns sessions.order_id for a paid checkout and leaves the
	 * session active until payment confirms; a quote must not overwrite
	 * that link. The order it does not recognise is not one of ours
	 * either, so a quote is still created — just not written back.
	 */
	public function test_an_existing_order_link_is_not_overwritten(): void {
		$order_id = $this->quotes->create_for_session( $this->session( [ 'order_id' => 777 ] ), $this->partner(), $this->lines() );

		self::assertGreaterThan( 0, $order_id );
		self::assertSame( [], QuoteOrderTestStore::$updates );
	}

	/**
	 * A customer who never saved a shipping address still has the full set
	 * of empty fields; that must not count as a match and stamp a blank
	 * address on the order.
	 */
	public function test_a_blank_customer_address_is_not_a_match(): void {
		$GLOBALS['pow_test_wc']->customer = new WC_Customer( [ 'first_name' => '', 'city' => '   ', 'country' => '' ] );

		$order = wc_get_order( $this->quotes->create_for_session( $this->session( [ 'ship_to' => '' ] ), $this->partner(), $this->lines() ) );

		self::assertSame( [], $order->props );
		self::assertSame( 'none', QuoteOrderTestLog::$written[0][1]['detail']['address_source'] );

		$GLOBALS['pow_test_wc']->customer = new WC_Customer( [ 'city' => 'Durban', 'country' => 'ZA' ] );

		$second = wc_get_order( $this->quotes->create_for_session( $this->session( [ 'ship_to' => '' ] ), $this->partner(), $this->lines() ) );

		self::assertSame( 'Durban', $second->props['shipping_city'] );
		self::assertSame( 'customer', QuoteOrderTestLog::$written[1][1]['detail']['address_source'] );
	}

	/**
	 * The basket is already on its way back by the time the document is
	 * attached, so a failing save here is logged and audited, never
	 * thrown into the return.
	 */
	public function test_attach_poom_survives_a_failing_save(): void {
		$quotes = $this->shipping_quotes();
		$order_id = $quotes->create_for_session( $this->session(), $this->partner(), $this->lines() );

		wc_get_order( $order_id )->save_throws = true;

		$quotes->attach_poom( $order_id, '<cXML/>' );

		$last = QuoteOrderTestLog::$written[ count( QuoteOrderTestLog::$written ) - 1 ];

		self::assertSame( 'quote_poom_failed', $last[0] );
		self::assertSame( $order_id, $last[1]['order_id'] );
		self::assertStringContainsString( 'quote_attachment_failed', $last[1]['detail']['error'] );
	}

	public function test_attach_poom_stores_the_document(): void {
		$quotes = $this->shipping_quotes();
		$order_id = $quotes->create_for_session( $this->session(), $this->partner(), $this->lines() );

		$quotes->attach_poom( $order_id, '<cXML/>' );

		self::assertSame( '<cXML/>', wc_get_order( $order_id )->get_meta( QuoteOrder::META_POOM_XML ) );
	}
}
}

namespace POW\Tests\QuoteShipping {

final class Boundary { public static string $failure = ''; public static array $note_filters = []; public static array $hooks = []; }

/** Native shipping API double, private to these tests. No global Woo stub changes. */
final class ShippingItem {
	private array $data = [ 'taxes' => [ 'total' => [] ], 'total' => '0.00' ];
	private array $meta = [];
	public function get_meta_data(): array { return $this->meta; }
	public function set_method_title( string $value ): void { $this->data['method_title'] = $value; }
	public function set_method_id( string $value ): void { $this->data['method_id'] = $value; }
	public function set_instance_id( int $value ): void { $this->data['instance_id'] = $value; }
	public function set_total( string $value ): void { if ( 'setter' === Boundary::$failure ) { throw new \RuntimeException( 'Native setter failed' ); } $this->data['total'] = $value; }
	public function set_taxes( array $value ): void { $this->data['taxes'] = $value; }
	public function add_meta_data( string $key, mixed $value, bool $unique = false ): void { $this->meta[$key] = $value; }
	public function get_meta( string $key, bool $single = true ): mixed { return $this->meta[$key] ?? ''; }
	public function get_method_title( string $context = 'view' ): string { return $this->data['method_title']; }
	public function get_method_id( string $context = 'view' ): string { return $this->data['method_id']; }
	public function get_instance_id( string $context = 'view' ): int { return $this->data['instance_id']; }
	public function get_total( string $context = 'view' ): string { return $this->data['total']; }
	public function get_total_tax( string $context = 'view' ): string { return '0'; }
	public function get_taxes( string $context = 'view' ): array { return $this->data['taxes']; }
}

/** Native edit getters backed by the existing mutable line fixture. */
final class MerchandiseItem extends \WC_Order_Item_Product {
	public function __construct( private array $data ) {}
	public function set_quantity( float $quantity ): void { $this->data['quantity'] = $quantity; }
	public function get_meta_data(): array { return []; }
	private function read( string $field, string $context ): mixed {
		if ( 'edit' !== $context ) { throw new \RuntimeException( 'Expected edit context' ); }
		return $this->data[$field] ?? 0;
	}
	public function get_product_id( string $context = 'view' ): int { return (int) $this->read( 'product_id', $context ); }
	public function get_variation_id( string $context = 'view' ): int { return (int) $this->read( 'variation_id', $context ); }
	public function get_quantity( string $context = 'view' ): float { return (float) $this->read( 'quantity', $context ); }
	public function get_subtotal( string $context = 'view' ): string { return (string) $this->read( 'subtotal', $context ); }
	public function get_total( string $context = 'view' ): string { return (string) $this->read( 'total', $context ); }
	public function get_name( string $context = 'view' ): string { return (string) $this->read( 'name', $context ); }
	public function get_meta( string $key, bool $single = true, string $context = 'view' ): mixed { return 'SKU' === $key ? $this->read( 'sku', $context ) : ''; }
}

final class Variation extends \WC_Product {
	public function get_parent_id(): int { return 412; }
}

final class Order extends \WC_Order {
	public function add_product( \WC_Product $product, float $quantity = 1, array $args = [] ): int {
		$id = parent::add_product( $product, $quantity, $args );
		if ( $id && $product instanceof Variation ) { $this->items[$id - 1]['product_id'] = $product->get_parent_id(); $this->items[$id - 1]['variation_id'] = $product->get_id(); }
		return $id;
	}
	public function get_shipping_first_name( string $context = 'view' ): string { if ( 'edit' !== $context ) { throw new \RuntimeException( 'Expected edit context' ); } return $this->props['shipping_first_name'] ?? ''; }
	public function get_shipping_last_name( string $context = 'view' ): string { if ( 'edit' !== $context ) { throw new \RuntimeException( 'Expected edit context' ); } return $this->props['shipping_last_name'] ?? ''; }
	public function get_shipping_company( string $context = 'view' ): string { if ( 'edit' !== $context ) { throw new \RuntimeException( 'Expected edit context' ); } return $this->props['shipping_company'] ?? ''; }
	public function get_shipping_address_1( string $context = 'view' ): string { if ( 'edit' !== $context ) { throw new \RuntimeException( 'Expected edit context' ); } return $this->props['shipping_address_1'] ?? ''; }
	public function get_shipping_address_2( string $context = 'view' ): string { if ( 'edit' !== $context ) { throw new \RuntimeException( 'Expected edit context' ); } return $this->props['shipping_address_2'] ?? ''; }
	public function get_shipping_city( string $context = 'view' ): string { if ( 'edit' !== $context ) { throw new \RuntimeException( 'Expected edit context' ); } return $this->props['shipping_city'] ?? ''; }
	public function get_shipping_state( string $context = 'view' ): string { if ( 'edit' !== $context ) { throw new \RuntimeException( 'Expected edit context' ); } return $this->props['shipping_state'] ?? ''; }
	public function get_shipping_postcode( string $context = 'view' ): string { if ( 'edit' !== $context ) { throw new \RuntimeException( 'Expected edit context' ); } return $this->props['shipping_postcode'] ?? ''; }
	public function get_shipping_country( string $context = 'view' ): string { if ( 'edit' !== $context ) { throw new \RuntimeException( 'Expected edit context' ); } return $this->props['shipping_country'] ?? ''; }
	public function get_shipping_phone( string $context = 'view' ): string { if ( 'edit' !== $context ) { throw new \RuntimeException( 'Expected edit context' ); } return $this->props['shipping_phone'] ?? ''; }
	private string $customer_note = '';
	public function set_customer_note( string $note ): void { $this->customer_note = $note; }
	public function get_customer_note( string $context = 'view' ): string { return $this->customer_note; }
	private array $shipping_items = [];
	private string $total = '0.00';
	private string $shipping_total = '0.00';
	public function add_item( mixed $item ): mixed {
		if ( ! $item instanceof ShippingItem ) { return parent::add_item( $item ); }
		if ( 'add' === Boundary::$failure ) { return false; }
		$this->shipping_items[] = $item; return null;
	}
	private array $merchandise_items = [];
	private array $cached_rows = [];
	/** Mirror native loaded item groups while retaining the older fixture's public rows for existing callers. */
	public function replace_merchandise_collection(): void {
		$this->merchandise_items = array_map( static fn( $item ) => clone $item, $this->merchandise_items );
		$this->merchandise_items[0]->set_quantity( 6 );
	}
	public function native_item_groups(): array {
		if ( $this->cached_rows !== $this->items ) {
			$this->merchandise_items = array_map( static fn( array $data ): MerchandiseItem => new MerchandiseItem( $data ), $this->items );
			$this->cached_rows = $this->items;
		}
		return [ 'line_items' => $this->merchandise_items, 'shipping_lines' => $this->shipping_items ];
	}
	public function get_items( string $type = 'line_item' ): array {
		$groups = $this->native_item_groups();
		$items = 'shipping' === $type ? $groups['shipping_lines'] : $groups['line_items'];
		return isset( $GLOBALS['pow_test_order_get_items'] ) ? ( $GLOBALS['pow_test_order_get_items'] )( $this, $type, $items ) : $items;
	}
	public function get_total( string $context = 'view' ): string { return $this->total; }
	public function get_shipping_total( string $context = 'view' ): string { return $this->shipping_total; }
	public function get_total_tax( string $context = 'view' ): string { return '0'; }
	public function get_currency( string $context = 'view' ): string { return $this->currency; }
	public function calculate_totals( bool $and_taxes = true ): float {
		$this->totals_calls[] = $and_taxes;
		$shipping = array_sum( array_map( static fn( ShippingItem $item ): int => \POW\Cxml\Money::to_cents( $item->get_total() ), $this->shipping_items ) );
		$this->shipping_total = \POW\Cxml\Money::format( $shipping );
		$this->total = \POW\Cxml\Money::format( $shipping + array_sum( array_map( static fn( array $item ): int => \POW\Cxml\Money::to_cents( $item['total'] ), $this->items ) ) );
		if ( 'save_during_totals' === Boundary::$failure ) { $this->save(); }
		return (float) $this->total;
	}
	public function get_meta_data(): array {
		if ( isset( $GLOBALS['pow_test_order_metadata_read'] ) ) { ( $GLOBALS['pow_test_order_metadata_read'] )( $this ); }
		return $this->meta;
	}
	public function save_meta_data(): void {
		if ( isset( $GLOBALS['pow_test_order_meta_save'] ) ) { ( $GLOBALS['pow_test_order_meta_save'] )( $this ); }
		if ( $this->save_throws ) { throw new \RuntimeException( 'Native metadata save failed' ); }
	}
	public function get_customer_id( string $context = 'view' ): int { return $this->customer_id; }
	public function save(): int {
		if ( \POW\Orders\Status::SLUG === $this->get_status() ) {
			if ( 'cpt_unslash' === Boundary::$failure ) {
				$data = [ 'post_excerpt' => $this->customer_note ];
				foreach ( Boundary::$note_filters as $filter ) { $data = $filter( $data, [ 'ID' => $this->get_id() ] ); }
				$this->customer_note = stripslashes( $data['post_excerpt'] );
			}
			if ( 'lost_confirmation' === Boundary::$failure ) { unset( $this->meta['_pow_delivery_confirmation'] ); }
			if ( 'changed_note' === Boundary::$failure ) { $this->customer_note = 'Changed'; }
			if ( 'lost_meta' === Boundary::$failure ) { unset( $this->meta['_pow_delivery'] ); }
			if ( 'changed_item' === Boundary::$failure && $this->shipping_items ) { $this->shipping_items[0]->set_total( '0.01' ); }
			if ( 'changed_total' === Boundary::$failure ) { $this->total = '0.01'; }
		}
		return parent::save();
	}
}

function wc_create_order( array $args = [] ): Order {
	static $next = 5000;
	$order = new Order( ++$next ); $order->set_customer_id( $args['customer_id'] ); $order->set_status( $args['status'] );
	$GLOBALS['pow_test_orders'][$order->get_id()] = $order;
	if ( isset( $GLOBALS['pow_test_after_create_order'] ) ) { ( $GLOBALS['pow_test_after_create_order'] )( $order ); }
	return $order;
}

/** Read-only native cache boundary; the shared historical WC stub exposes flat rows instead of Woo's protected grouped item cache. */
function get_mangled_object_vars( object $order ): array {
	return [ "\0*\0items" => $order->native_item_groups() ] + \get_mangled_object_vars( $order );
}

function sanitize_textarea_field( string $value ): string { return trim( strip_tags( $value ) ); }
function wp_slash( string $value ): string { return addslashes( $value ); }
function add_filter( string $hook, callable $callback, int $priority = 10, int $args = 1 ): void {
	Boundary::$hooks[] = [ 'filter', $hook ];
	// Only the note boundary is replayed on save; an admin registration must never be mistaken for one.
	if ( 'wp_insert_post_data' === $hook ) { Boundary::$note_filters[spl_object_id( $callback )] = $callback; }
}
function remove_filter( string $hook, callable $callback, int $priority = 10 ): void { unset( Boundary::$note_filters[spl_object_id( $callback )] ); }
function add_action( string $hook, callable $callback, int $priority = 10, int $args = 1 ): void { Boundary::$hooks[] = [ 'action', $hook ]; }
}

namespace POW\Tests\ReturnShipping {
/** The peer return fixture reuses the same native Order double. */
function get_mangled_object_vars( object $order ): array { return \POW\Tests\QuoteShipping\get_mangled_object_vars( $order ); }
}
