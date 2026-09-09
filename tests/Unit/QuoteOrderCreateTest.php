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

		QuoteOrderTestStore::$updates = [];
		QuoteOrderTestLog::$written   = [];

		$this->quotes = new QuoteOrder(
			new QuoteOrderTestStore(),
			new QuoteOrderTestLog(),
			new QuoteOrderTestSettings(),
			new QuoteOrderTestLogger()
		);
	}

	private function session(): Session {
		return Session::from_row(
			[
				'id'         => 42,
				'partner_id' => 7,
				'user_id'    => 99,
				'ship_to'    => '<ShipTo><Address addressID="LEMA-001" addressIDDomain="supplier"><Name xml:lang="en">Head office</Name></Address></ShipTo>',
			]
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
		self::assertSame( 42, $order->get_meta( QuoteOrder::META_SESSION_ID ) );
		self::assertSame( 7, $order->get_meta( QuoteOrder::META_PARTNER_ID ) );
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
		self::assertSame( 'Head office', $order->shipping['shipping']['company'] );
	}

	public function test_the_filter_wins_over_the_inbound_ship_to(): void {
		$GLOBALS['pow_test_filters']['pow_quote_shipping_address'] = static fn ( $value ): array => [
			'address' => [ 'city' => 'Cape Town', 'company' => 'CCBSA Midrand' ],
			'code'    => 'LEMA-CCBSA-002',
		];

		$order = wc_get_order( $this->quotes->create_for_session( $this->session(), $this->partner(), $this->lines() ) );

		self::assertSame( 'LEMA-CCBSA-002', $order->get_meta( QuoteOrder::META_DELIVERY_CODE ) );
		self::assertSame( 'Cape Town', $order->shipping['shipping']['city'] );
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
		$GLOBALS['pow_test_options']['admin_email']                = 'ops@example.com';
		$GLOBALS['pow_test_filters']['pow_quote_shipping_address'] = static function ( $value ): array {
			throw new \RuntimeException( 'address lookup exploded' );
		};

		self::assertSame( 0, $this->quotes->create_for_session( $this->session(), $this->partner(), $this->lines() ) );
		self::assertSame( 0, $this->quotes->create_for_session( $this->session(), $this->partner(), $this->lines() ) );

		self::assertSame( [], QuoteOrderTestStore::$updates, 'a failed order is never linked to the session' );
		self::assertSame( 'quote_order_failed', QuoteOrderTestLog::$written[0][0] );
		self::assertCount( 2, QuoteOrderTestLog::$written, 'every failure is audited' );
		self::assertCount( 1, $GLOBALS['pow_test_mail'], 'the second failure within the hour is logged, not mailed' );
		self::assertStringContainsString( 'address lookup exploded', $GLOBALS['pow_test_mail'][0]['message'] );
	}

	public function test_attach_poom_stores_the_document(): void {
		$order_id = $this->quotes->create_for_session( $this->session(), $this->partner(), $this->lines() );

		$this->quotes->attach_poom( $order_id, '<cXML/>' );

		self::assertSame( '<cXML/>', wc_get_order( $order_id )->get_meta( QuoteOrder::META_POOM_XML ) );
	}
}

final class QuoteOrderTestStore extends \POW\Sessions\Store {

	/** @var array<int, array<string, mixed>> */
	public static array $updates = [];

	public function __construct() {}

	/**
	 * @param array<string, mixed> $data Column values.
	 */
	public function update( int $id, array $data ): bool {
		self::$updates[ $id ] = $data;

		return true;
	}
}

final class QuoteOrderTestLog extends \POW\Audit\Log {

	/** @var list<array{0: string, 1: array<string, mixed>}> */
	public static array $written = [];

	public function __construct() {}

	/**
	 * @param array<string, mixed> $context Audit context.
	 */
	public function write( string $event, array $context = [] ): void {
		self::$written[] = [ $event, $context ];
	}
}

final class QuoteOrderTestSettings extends \POW\Settings {

	public function __construct() {}

	public function int( string $key ): int {
		return 'quote_retention_days' === $key ? 90 : 0;
	}

	/**
	 * @param mixed $default Fallback when unset.
	 * @return mixed
	 */
	public function get( string $key, mixed $default = null ): mixed {
		return 'quote_convert_status' === $key ? 'pending' : $default;
	}
}

final class QuoteOrderTestLogger extends \POW\Logger {

	public function __construct() {}

	/**
	 * @param array<string, mixed> $context Log context.
	 */
	public function error( string $message, array $context = [] ): void {}
}
