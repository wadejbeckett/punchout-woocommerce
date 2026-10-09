<?php
/**
 * The order lifecycle around Submit (0.5.0, ticket 74), the parts WooCommerce asks about through its own hooks:
 *
 * - Before an order exists (woocommerce_after_checkout_validation): a line without a SKU, a cart priced in another
 *   currency, or a note cXML cannot carry is refused with a plain message, so WooCommerce creates no order.
 * - Zero-total carts: during a live visit the cart, and a pending PunchOut order, need payment, so a cart that totals
 *   zero still reaches the PunchOut method instead of WooCommerce's order-received page.
 * - WooCommerce's unpaid-order clean-up never cancels a PunchOut order (no cancelled e-mail to the store).
 *
 * @package POW
 */

declare( strict_types = 1 );

namespace {
	require_once dirname( __DIR__ ) . '/Support/checkout-hooks.php';

	use PHPUnit\Framework\TestCase;
	use POW\Checkout\Lifecycle;
	use POW\Checkout\PaymentMethod;
	use POW\Checkout\Validation;
	use POW\Orders\QuoteOrder;
	use POW\Sessions\Session;

	/** The connection registry's database: one connection, its status set by the test. */
	final class LifecycleDatabase {
		public string $prefix = 'wp_';
		public string $last_error = '';
		public string $status = 'active';
		public function prepare( string $sql, mixed ...$args ): string { return $sql; }
		public function get_row( string $sql, mixed $format = null ): ?array {
			if ( ! str_contains( $sql, 'pow_partners' ) ) { throw new LogicException( 'Unexpected query: ' . $sql ); }
			return [ 'id' => 5, 'name' => 'Example connection', 'status' => $this->status, 'owner_user_id' => 20, 'from_identity' => 'BUYER-TEST', 'to_identity' => 'supplier-test' ];
		}
	}

	/** The cart's lines, as WooCommerce's get_cart() hands them out. */
	final class LifecycleCart {
		/** @param list<WC_Product> $products */
		public function __construct( private array $products ) {}
		public function get_cart(): array {
			$lines = [];
			foreach ( $this->products as $index => $product ) { $lines[ 'key' . $index ] = [ 'data' => $product, 'quantity' => 1 ]; }
			return $lines;
		}
	}

	/** An order WooCommerce asks about: status, payment method and provenance. */
	final class LifecycleOrder extends WC_Order {
		public function __construct( string $status, string $method, array $meta = [] ) { parent::__construct( 9 ); $this->status = $status; $this->payment_method = $method; $this->meta = $meta; }
		public function has_status( string|array $status ): bool { return in_array( $this->status, (array) $status, true ); }
	}

	final class CheckoutLifecycleTest extends TestCase {
		private const GLOBALS = [ 'wpdb', 'pow_test_options', 'pow_test_filters', 'pow_payment_method_hooks', 'pow_test_currency', 'pow_test_wc' ];
		private array $saved = [];
		private LifecycleDatabase $db;

		protected function setUp(): void {
			foreach ( self::GLOBALS as $key ) {
				$this->saved[ $key ] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null ];
				unset( $GLOBALS[ $key ] );
			}
			$GLOBALS['wpdb'] = $this->db = new LifecycleDatabase();
			$GLOBALS['pow_test_options']['woocommerce_currency'] = 'ZAR';
			WC()->cart = new LifecycleCart( [ new WC_Product( 11, 'Widget', 'W-1' ) ] );
		}

		protected function tearDown(): void {
			foreach ( $this->saved as $key => [ $existed, $value ] ) {
				if ( $existed ) { $GLOBALS[ $key ] = $value; } else { unset( $GLOBALS[ $key ] ); }
			}
		}

		/** The payment method's view of the request: inside the visit $visit (null: outside any). */
		private function method( ?Session $visit ): PaymentMethod {
			$plugin = ( new ReflectionClass( POW\Plugin::class ) )->newInstanceWithoutConstructor();
			( new ReflectionProperty( $plugin, 'settings' ) )->setValue( $plugin, new POW\Settings() );
			( new ReflectionProperty( $plugin, 'session_resolved' ) )->setValue( $plugin, true );
			( new ReflectionProperty( $plugin, 'current_session' ) )->setValue( $plugin, $visit );
			return new PaymentMethod( $plugin, new POW\Partners\Registry( new POW\Partners\Secrets( str_repeat( 'f', 32 ) ) ) );
		}

		private function visit(): Session {
			return Session::from_row( [ 'id' => 42, 'partner_id' => 5, 'user_id' => 20, 'status' => 'active', 'buyer_cookie' => '{C}', 'expires' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ] );
		}

		/** @return list<string> What checkout validation refused, for this posted note. */
		private function refusals( ?Session $visit, string $note = 'Leave at the gate.' ): array {
			$errors = new WP_Error();
			( new Validation( $this->method( $visit ) ) )->validate( [ 'payment_method' => 'punchout', 'order_comments' => $note ], $errors );
			return $errors->get_error_messages();
		}

		public function test_a_cart_that_can_be_sent_passes(): void {
			self::assertSame( [], $this->refusals( $this->visit() ) );
		}

		public function test_a_line_without_a_sku_is_refused_by_name_before_any_order(): void {
			WC()->cart = new LifecycleCart( [ new WC_Product( 11, 'Widget', 'W-1' ), new WC_Product( 12, 'Gadget <b>large</b>', '' ) ] );
			$refusals = $this->refusals( $this->visit() );
			self::assertCount( 1, $refusals );
			self::assertStringContainsString( '"Gadget &lt;b&gt;large&lt;/b&gt;" has no product code', $refusals[0], 'The item is named, as text' );
			self::assertStringNotContainsString( 'Widget', $refusals[0] );
		}

		public function test_a_cart_priced_in_another_currency_is_refused(): void {
			$GLOBALS['pow_test_currency'] = 'USD'; // A currency switcher priced this request in another currency.
			$refusals = $this->refusals( $this->visit() );
			self::assertCount( 1, $refusals );
			self::assertStringContainsString( 'USD', $refusals[0] );
			self::assertStringContainsString( 'ZAR', $refusals[0], 'The store\'s own currency is the connection\'s' );
		}

		public function test_a_note_that_cannot_be_made_valid_for_cxml_is_refused(): void {
			$refusals = $this->refusals( $this->visit(), "Gate \xC3\x28 code" ); // Not UTF-8.
			self::assertCount( 1, $refusals );
			self::assertStringContainsString( 'note', $refusals[0] );
		}

		public function test_a_note_with_control_characters_or_past_the_limit_is_cleaned_and_capped_later_not_refused(): void {
			self::assertSame( [], $this->refusals( $this->visit(), "Gate\x01 code\x0b 7. " . str_repeat( 'x', 3000 ) ) );
		}

		public function test_outside_a_live_visit_nothing_is_checked(): void {
			WC()->cart = new LifecycleCart( [ new WC_Product( 12, 'Gadget', '' ) ] );
			self::assertSame( [], $this->refusals( null ), 'Ordinary shoppers keep WooCommerce\'s own checkout' );
			$this->db->status = 'disabled';
			self::assertSame( [], $this->refusals( $this->visit() ), 'A visit that is not live gets no PunchOut method to check for' );
		}

		public function test_validation_runs_on_woocommerces_validation_step(): void {
			$validation = new Validation( $this->method( null ) );
			$validation->register();
			self::assertSame( [ [ 10, [ $validation, 'validate' ], 2 ] ], $GLOBALS['pow_payment_method_hooks']['woocommerce_after_checkout_validation'] );
		}

		public function test_in_a_live_visit_a_zero_total_cart_needs_payment(): void {
			$lifecycle = new Lifecycle( $this->method( $this->visit() ) );
			self::assertTrue( $lifecycle->cart_needs_payment( false ), 'So the PunchOut method is offered and Submit reaches it' );
			self::assertTrue( $lifecycle->cart_needs_payment( true ) );
			$outside = new Lifecycle( $this->method( null ) );
			self::assertFalse( $outside->cart_needs_payment( false ), 'Ordinary shoppers keep WooCommerce\'s answer' );
			self::assertTrue( $outside->cart_needs_payment( true ) );
		}

		public function test_in_a_live_visit_a_pending_punchout_order_needs_payment(): void {
			$lifecycle = new Lifecycle( $this->method( $this->visit() ) );
			self::assertTrue( $lifecycle->order_needs_payment( false, new LifecycleOrder( 'pending', 'punchout' ) ) );
			self::assertFalse( $lifecycle->order_needs_payment( false, new LifecycleOrder( 'punchout-quote', 'punchout' ) ), 'A Quote is never payable' );
			self::assertFalse( $lifecycle->order_needs_payment( false, new LifecycleOrder( 'pending', 'other' ) ) );
			self::assertFalse( ( new Lifecycle( $this->method( null ) ) )->order_needs_payment( false, new LifecycleOrder( 'pending', 'punchout' ) ), 'Outside a visit WooCommerce answers' );
			self::assertTrue( ( new Lifecycle( $this->method( null ) ) )->order_needs_payment( true, new LifecycleOrder( 'pending', 'other' ) ) );
		}

		public function test_the_unpaid_order_clean_up_never_cancels_a_punchout_order(): void {
			self::assertFalse( Lifecycle::keep_unpaid( true, new LifecycleOrder( 'pending', 'punchout' ) ), 'Placed with the PunchOut method' );
			self::assertFalse( Lifecycle::keep_unpaid( true, new LifecycleOrder( 'pending', '', [ QuoteOrder::META_SESSION_ID => '42' ] ) ), 'Carrying PunchOut provenance' );
			self::assertFalse( Lifecycle::keep_unpaid( true, new LifecycleOrder( 'pending', '', [ QuoteOrder::META_PARTNER_ID => '5' ] ) ) );
			self::assertTrue( Lifecycle::keep_unpaid( true, new LifecycleOrder( 'pending', 'bacs' ) ), 'Every other order is WooCommerce\'s to cancel' );
			self::assertFalse( Lifecycle::keep_unpaid( false, new LifecycleOrder( 'pending', 'bacs' ) ) );
			self::assertTrue( Lifecycle::keep_unpaid( true, null ) );
		}

		public function test_the_rules_run_last_and_the_clean_up_rule_is_registered_on_its_own(): void {
			$lifecycle = new Lifecycle( $this->method( null ) );
			$lifecycle->register();
			Lifecycle::register_unpaid_rule();
			$hooks = $GLOBALS['pow_payment_method_hooks'];
			self::assertSame( [ [ PHP_INT_MAX, [ $lifecycle, 'cart_needs_payment' ], 2 ] ], $hooks['woocommerce_cart_needs_payment'] );
			self::assertSame( [ [ PHP_INT_MAX, [ $lifecycle, 'order_needs_payment' ], 3 ] ], $hooks['woocommerce_order_needs_payment'] );
			self::assertSame( [ [ PHP_INT_MAX, [ Lifecycle::class, 'keep_unpaid' ], 2 ] ], $hooks['woocommerce_cancel_unpaid_order'] );
		}
	}
}
