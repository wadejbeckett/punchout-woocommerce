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
					'id'         => 42,
					'partner_id' => 7,
					'user_id'    => 99,
					'ship_to'    => '<ShipTo><Address addressID="LEMA-001" addressIDDomain="supplier"><Name xml:lang="en">Head office</Name></Address></ShipTo>',
				],
				$overrides
			)
		);
	}

	private function partner(): Partner {
		return Partner::from_row( [ 'id' => 7, 'name' => 'Coke', 'status' => 'active' ] );
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

	/** Make the address step throw, part-way through a saved order. */
	private function break_creation(): void {
		$GLOBALS['pow_test_filters']['pow_quote_shipping_address'] = static function ( $value ): array {
			throw new \RuntimeException( 'address lookup exploded' );
		};
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
		self::assertSame( '', $order->get_meta( QuoteOrder::META_POOM_XML ), 'the document does not exist until the build has run' );
		self::assertCount( 1, $order->notes );
		self::assertStringContainsString( '42', $order->notes[0] );
		self::assertStringContainsString( 'Coke', $order->notes[0] );
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

		self::assertSame( 'LEMA-001', $order->get_meta( QuoteOrder::META_DELIVERY_CODE ) );
		self::assertSame( 'Head office', $order->props['shipping_company'] );
	}

	public function test_the_filter_wins_over_the_inbound_ship_to(): void {
		$GLOBALS['pow_test_filters']['pow_quote_shipping_address'] = static fn ( $value ): array => [
			'address' => [ 'city' => 'Cape Town', 'company' => 'CCBSA Midrand' ],
			'code'    => 'LEMA-CCBSA-002',
		];

		$order = wc_get_order( $this->quotes->create_for_session( $this->session(), $this->partner(), $this->lines() ) );

		self::assertSame( 'LEMA-CCBSA-002', $order->get_meta( QuoteOrder::META_DELIVERY_CODE ) );
		self::assertSame( 'Cape Town', $order->props['shipping_city'] );
		self::assertSame( [ 'shipping_company', 'shipping_city' ], array_keys( $order->props ), 'only known shipping props are written' );
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
		self::assertStringContainsString( 'address lookup exploded', $GLOBALS['pow_test_mail'][0]['message'] );
	}

	/**
	 * wc_create_order() persists immediately, and add_product() and
	 * calculate_totals() save again, so a throw part-way through would
	 * otherwise leave a payable wc-pending order in the shop. It is
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
	 * guard is what turns it into the failure path — with the real
	 * message, and without claiming an order exists.
	 */
	public function test_a_wp_error_from_wc_create_order_is_the_failure_path(): void {
		$GLOBALS['pow_test_options']['admin_email']      = 'ops@example.com';
		$GLOBALS['pow_test_create_order_error']          = 'Could not insert the order into the database';

		self::assertSame( 0, $this->quotes->create_for_session( $this->session(), $this->partner(), $this->lines() ) );

		self::assertSame( [], $GLOBALS['pow_test_orders'], 'nothing was created' );
		self::assertSame( 0, QuoteOrderTestLog::$written[0][1]['order_id'] );
		self::assertStringContainsString( 'Could not insert the order into the database', QuoteOrderTestLog::$written[0][1]['detail']['error'] );
		self::assertStringContainsString( 'no order was created', $GLOBALS['pow_test_mail'][0]['message'] );
	}

	/**
	 * A double-submitted return must not leave two quotes behind.
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
		self::assertStringContainsString( 'order lookup exploded', QuoteOrderTestLog::$written[0][1]['detail']['error'] );
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
		$order_id = $this->quotes->create_for_session( $this->session(), $this->partner(), $this->lines() );

		wc_get_order( $order_id )->save_throws = true;

		$this->quotes->attach_poom( $order_id, '<cXML/>' );

		$last = QuoteOrderTestLog::$written[ count( QuoteOrderTestLog::$written ) - 1 ];

		self::assertSame( 'quote_poom_failed', $last[0] );
		self::assertSame( $order_id, $last[1]['order_id'] );
		self::assertStringContainsString( 'order save failed', $last[1]['detail']['error'] );
	}

	public function test_attach_poom_stores_the_document(): void {
		$order_id = $this->quotes->create_for_session( $this->session(), $this->partner(), $this->lines() );

		$this->quotes->attach_poom( $order_id, '<cXML/>' );

		self::assertSame( '<cXML/>', wc_get_order( $order_id )->get_meta( QuoteOrder::META_POOM_XML ) );
	}
}
