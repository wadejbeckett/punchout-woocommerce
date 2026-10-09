<?php
/**
 * The PunchOut payment method (0.5.0): offered only while a live visit owns the request, and then as the only
 * method, whatever another plugin's gateway rules did before it; never offered outside a visit; its place-order
 * button is a setting with a neutral default, shown before any method is chosen.
 *
 * @package POW
 */

declare( strict_types = 1 );

namespace {
	// Registration is recorded, not run: WordPress is not loaded here.
	require_once dirname( __DIR__ ) . '/Support/checkout-hooks.php';

	use PHPUnit\Framework\TestCase;
	use POW\Checkout\Gateway;
	use POW\Checkout\PaymentMethod;
	use POW\Sessions\Session;

	/** The connection registry's database: one connection, its status set by the test. */
	final class PaymentMethodDatabase {
		public string $prefix = 'pm_fixture_';
		public string $last_error = '';
		public string $status = 'active';
		public function prepare( string $sql, mixed ...$args ): string { return $sql; }
		public function get_row( string $sql, mixed $format = null ): ?array {
			if ( ! str_contains( $sql, 'pow_partners' ) ) { throw new LogicException( 'Unexpected query: ' . $sql ); }
			return [ 'id' => 5, 'name' => 'Example connection', 'status' => $this->status, 'owner_user_id' => 20, 'from_identity' => 'BUYER-TEST', 'to_identity' => 'supplier-test' ];
		}
	}

	/** A session store whose visit lookup cannot answer. */
	final class PaymentMethodBrokenStore extends POW\Sessions\Store {
		public function find_for_login( int $user_id, string $wp_session_token, array $statuses = [ Session::ACTIVE ] ): ?Session { throw new RuntimeException( 'Session lookup failed.' ); }
	}

	/** Another plugin's gateway. */
	final class PaymentMethodOtherGateway extends WC_Payment_Gateway {
		public function __construct() { $this->id = 'other'; }
	}

	final class PaymentMethodTest extends TestCase {
		private array $saved = [];
		private PaymentMethodDatabase $db;

		protected function setUp(): void {
			foreach ( [ 'wpdb', 'pow_test_options', 'pow_test_filters', 'pow_payment_method_hooks', 'pow_test_current_user_id' ] as $key ) {
				$this->saved[ $key ] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null ];
				unset( $GLOBALS[ $key ] );
			}
			$GLOBALS['wpdb'] = $this->db = new PaymentMethodDatabase();
		}

		protected function tearDown(): void {
			foreach ( $this->saved as $key => [ $existed, $value ] ) {
				if ( $existed ) { $GLOBALS[ $key ] = $value; } else { unset( $GLOBALS[ $key ] ); }
			}
		}

		/** The container answering "which visit is this request inside" with $visit (false: the lookup cannot answer). */
		private function plugin( Session|false|null $visit ): POW\Plugin {
			$plugin = ( new ReflectionClass( POW\Plugin::class ) )->newInstanceWithoutConstructor();
			( new ReflectionProperty( $plugin, 'settings' ) )->setValue( $plugin, new POW\Settings() );
			if ( false === $visit ) {
				$GLOBALS['pow_test_current_user_id'] = 20;
				( new ReflectionProperty( $plugin, 'sessions' ) )->setValue( $plugin, new PaymentMethodBrokenStore() );
				return $plugin;
			}
			( new ReflectionProperty( $plugin, 'session_resolved' ) )->setValue( $plugin, true );
			( new ReflectionProperty( $plugin, 'current_session' ) )->setValue( $plugin, $visit );
			return $plugin;
		}

		private function visit( string $status = 'active' ): Session {
			return Session::from_row( [ 'id' => 42, 'partner_id' => 5, 'user_id' => 20, 'status' => $status, 'buyer_cookie' => '{C}', 'expires' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ] );
		}

		private function method( Session|false|null $visit ): PaymentMethod {
			return new PaymentMethod( $this->plugin( $visit ), new POW\Partners\Registry( new POW\Partners\Secrets( str_repeat( 'f', 32 ) ) ) );
		}

		public function test_outside_a_visit_the_method_is_never_offered_and_other_methods_are_untouched(): void {
			$method = $this->method( null );
			$other  = new PaymentMethodOtherGateway();
			self::assertFalse( $method->gateway()->is_available() );
			self::assertSame( [ 'other' => $other ], $method->available_gateways( [ 'other' => $other, PaymentMethod::ID => $method->gateway() ] ), 'Outside a visit PunchOut is removed and nothing else changes' );
		}

		public function test_in_a_live_visit_it_is_the_only_method_even_after_another_plugin_removed_it(): void {
			$method = $this->method( $this->visit() );
			self::assertTrue( $method->gateway()->is_available() );
			self::assertSame( [ PaymentMethod::ID => $method->gateway() ], $method->available_gateways( [ 'other' => new PaymentMethodOtherGateway(), PaymentMethod::ID => $method->gateway() ] ), 'PunchOut alone' );
			self::assertSame( [ PaymentMethod::ID => $method->gateway() ], $method->available_gateways( [ 'other' => new PaymentMethodOtherGateway() ] ), 'A gateway rule that dropped it does not stand' );
			self::assertSame( [ PaymentMethod::ID => $method->gateway() ], $method->available_gateways( [] ), 'PunchOut alone, from nothing' );
		}

		public function test_the_only_method_rule_runs_last(): void {
			$method = $this->method( $this->visit() );
			$method->register();
			self::assertSame( [ [ PHP_INT_MAX, [ $method, 'available_gateways' ], 1 ] ], $GLOBALS['pow_payment_method_hooks']['woocommerce_available_payment_gateways'] );
			self::assertSame( [ [ 10, [ $method, 'add_gateway' ], 1 ] ], $GLOBALS['pow_payment_method_hooks']['woocommerce_payment_gateways'] );
		}

		public function test_a_visit_that_is_not_live_gets_no_method_at_all(): void {
			$this->db->status = 'disabled';
			$method = $this->method( $this->visit() );
			self::assertFalse( $method->gateway()->is_available(), 'A switched-off connection cannot hand a cart back' );
			self::assertSame( [], $method->available_gateways( [ 'other' => new PaymentMethodOtherGateway() ] ), 'Inside a visit nothing else may be paid with either' );

			$this->db->status = 'active';
			$ordered = $this->method( $this->visit( 'ordered' ) );
			self::assertFalse( $ordered->gateway()->is_available() );
			self::assertSame( [], $ordered->available_gateways( [ 'other' => new PaymentMethodOtherGateway() ] ) );
		}

		public function test_a_visit_lookup_that_cannot_answer_offers_nothing(): void {
			$method = $this->method( false );
			self::assertFalse( $method->gateway()->is_available() );
			self::assertSame( [], $method->available_gateways( [ 'other' => new PaymentMethodOtherGateway() ] ) );
		}

		public function test_switched_off_in_woocommerce_it_is_not_offered_even_in_a_visit(): void {
			$GLOBALS['pow_test_options']['woocommerce_punchout_settings'] = [ 'enabled' => 'no' ];
			$method = $this->method( $this->visit() );
			self::assertFalse( $method->gateway()->is_available() );
			self::assertSame( [], $method->available_gateways( [ 'other' => new PaymentMethodOtherGateway() ] ) );
		}

		public function test_saved_settings_without_the_enabled_key_leave_it_on(): void {
			// A site that saved only the button label never switched the method off.
			$GLOBALS['pow_test_options']['woocommerce_punchout_settings'] = [ 'order_button_text' => 'Submit' ];
			self::assertTrue( $this->method( $this->visit() )->gateway()->is_available() );
		}

		public function test_the_place_order_label_is_a_setting_with_a_neutral_default(): void {
			self::assertSame( 'Place order', $this->method( null )->gateway()->order_button_text );
			$GLOBALS['pow_test_options']['woocommerce_punchout_settings'] = [ 'order_button_text' => '  Submit ' ];
			self::assertSame( 'Submit', $this->method( null )->gateway()->order_button_text );
			$GLOBALS['pow_test_options']['woocommerce_punchout_settings'] = [ 'order_button_text' => '   ' ];
			self::assertSame( 'Place order', $this->method( null )->gateway()->order_button_text );
		}

		public function test_the_label_filter_runs_last_and_a_blank_result_keeps_the_default(): void {
			$GLOBALS['pow_test_options']['woocommerce_punchout_settings'] = [ 'order_button_text' => 'Submit' ];
			$GLOBALS['pow_test_filters'][ PaymentMethod::PLACE_ORDER_FILTER ] = static fn( string $label ): string => $label . ' cart';
			self::assertSame( 'Submit cart', $this->method( null )->gateway()->order_button_text );
			$GLOBALS['pow_test_filters'][ PaymentMethod::PLACE_ORDER_FILTER ] = static fn(): string => ' ';
			self::assertSame( 'Place order', $this->method( null )->gateway()->order_button_text, 'A button is never drawn empty' );
			self::assertSame( 'punchout_place_order_label', PaymentMethod::PLACE_ORDER_FILTER );
		}

		public function test_the_button_shows_the_label_before_any_method_is_chosen_only_in_a_visit(): void {
			$GLOBALS['pow_test_options']['woocommerce_punchout_settings'] = [ 'order_button_text' => 'Submit' ];
			self::assertSame( 'Submit', $this->method( $this->visit() )->order_button_text( 'Place order' ) );
			self::assertSame( 'Place order', $this->method( null )->order_button_text( 'Place order' ), 'Ordinary shoppers keep the shop\'s own button' );
		}

		public function test_woocommerce_gets_one_gateway_with_its_own_settings(): void {
			$method   = $this->method( null );
			$gateways = $method->add_gateway( [ 'WC_Gateway_BACS' ] );
			self::assertSame( 'WC_Gateway_BACS', $gateways[0] );
			self::assertInstanceOf( Gateway::class, $gateways[1] );
			self::assertSame( $method->gateway(), $gateways[1], 'One instance per request' );
			$gateway = $method->gateway();
			self::assertSame( 'punchout', $gateway->id );
			self::assertSame( 'woocommerce_punchout_settings', $gateway->get_option_key() );
			foreach ( [ 'enabled', 'title', 'description', 'order_button_text' ] as $field ) { self::assertArrayHasKey( $field, $gateway->form_fields ); }
			self::assertSame( 'PunchOut', $gateway->title );
			foreach ( [ $gateway->title, $gateway->description, $gateway->method_description, $gateway->order_button_text ] as $text ) {
				self::assertStringNotContainsString( 'approv', strtolower( $text ) );
			}
		}
	}
}
