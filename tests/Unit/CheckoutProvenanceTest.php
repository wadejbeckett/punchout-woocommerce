<?php
/**
 * Provenance stamped as checkout creates the order (0.5.0).
 *
 * Classic checkout creates the order before it asks the payment method to process it. An order placed with the
 * PunchOut method in a live visit carries the visit's provenance from that moment (`woocommerce_checkout_create_order`,
 * before WooCommerce saves it), so a PunchOut order is never payable by another route, also while it is still pending
 * because a Submit could not be prepared. Submit later stamps the same keys again, with the cXML copy.
 *
 * @package POW
 */

declare( strict_types = 1 );

namespace {
	require_once dirname( __DIR__ ) . '/Support/checkout-hooks.php';

	use PHPUnit\Framework\TestCase;
	use POW\Checkout\PaymentMethod;
	use POW\Checkout\Provenance;
	use POW\Orders\QuoteOrder;
	use POW\Sessions\Session;

	/** The connection registry's database: one connection, its status set by the test, or a lookup that fails. */
	final class ProvenanceDatabase {
		public string $prefix = 'pv_fixture_';
		public string $last_error = '';
		public string $status = 'active';
		public bool $fails = false;
		public function prepare( string $sql, mixed ...$args ): string { return $sql; }
		public function get_row( string $sql, mixed $format = null ): ?array {
			if ( ! str_contains( $sql, 'pow_partners' ) ) { throw new LogicException( 'Unexpected query: ' . $sql ); }
			if ( $this->fails ) { throw new RuntimeException( 'Connection lookup failed.' ); }
			return [ 'id' => 5, 'name' => 'Example connection', 'status' => $this->status, 'owner_user_id' => 20, 'from_identity' => 'BUYER-TEST', 'to_identity' => 'supplier-test' ];
		}
	}

	final class CheckoutProvenanceTest extends TestCase {
		private array $saved = [];
		private ProvenanceDatabase $db;

		protected function setUp(): void {
			foreach ( [ 'wpdb', 'pow_test_options', 'pow_test_filters', 'pow_payment_method_hooks' ] as $key ) {
				$this->saved[ $key ] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null ];
				unset( $GLOBALS[ $key ] );
			}
			$GLOBALS['wpdb'] = $this->db = new ProvenanceDatabase();
		}

		protected function tearDown(): void {
			foreach ( $this->saved as $key => [ $existed, $value ] ) {
				if ( $existed ) { $GLOBALS[ $key ] = $value; } else { unset( $GLOBALS[ $key ] ); }
			}
		}

		private function visit( string $status = 'active' ): Session {
			return Session::from_row( [ 'id' => 42, 'partner_id' => 5, 'user_id' => 20, 'status' => $status, 'buyer_identity' => 'buyer.one@buyer.example.com', 'buyer_name' => 'Buyer One', 'buyer_cookie' => '{COOKIE}', 'expires' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ] );
		}

		private function provenance( ?Session $visit ): Provenance {
			$plugin = ( new ReflectionClass( POW\Plugin::class ) )->newInstanceWithoutConstructor();
			( new ReflectionProperty( $plugin, 'settings' ) )->setValue( $plugin, new POW\Settings() );
			( new ReflectionProperty( $plugin, 'session_resolved' ) )->setValue( $plugin, true );
			( new ReflectionProperty( $plugin, 'current_session' ) )->setValue( $plugin, $visit );
			$registry = new POW\Partners\Registry( new POW\Partners\Secrets( str_repeat( 'p', 32 ) ) );
			return new Provenance( $plugin, $registry, new PaymentMethod( $plugin, $registry ) );
		}

		private function order( string $method = PaymentMethod::ID ): WC_Order {
			$order = new WC_Order( 77 );
			$order->payment_method = $method;
			return $order;
		}

		public function test_a_punchout_order_carries_the_visits_provenance_as_checkout_creates_it(): void {
			$order = $this->order();
			$this->provenance( $this->visit() )->stamp_new_order( $order );
			self::assertSame(
				[
					QuoteOrder::META_SESSION_ID     => '42',
					QuoteOrder::META_PARTNER_ID     => '5',
					QuoteOrder::META_PARTNER_NAME   => 'Example connection',
					QuoteOrder::META_BUYER_IDENTITY => 'buyer.one@buyer.example.com',
					QuoteOrder::META_BUYER_NAME     => 'Buyer One',
					QuoteOrder::META_BUYER_COOKIE   => '{COOKIE}',
				],
				$order->meta
			);
			self::assertFalse( $order->saved, 'WooCommerce saves the order right after this hook' );
		}

		public function test_the_same_keys_submit_stamps(): void {
			self::assertSame(
				[ QuoteOrder::META_SESSION_ID, QuoteOrder::META_PARTNER_ID, QuoteOrder::META_PARTNER_NAME, QuoteOrder::META_BUYER_IDENTITY, QuoteOrder::META_BUYER_NAME, QuoteOrder::META_BUYER_COOKIE ],
				array_keys( Provenance::keys( $this->visit(), POW\Partners\Partner::from_row( [ 'id' => 5, 'name' => 'Example connection', 'status' => 'active' ] ) ) )
			);
		}

		public function test_an_order_placed_otherwise_or_outside_a_visit_is_left_alone(): void {
			$other = $this->order( 'other' );
			$this->provenance( $this->visit() )->stamp_new_order( $other );
			self::assertSame( [], $other->meta, 'Another method in a visit is the route guard\'s to refuse' );

			$outside = $this->order();
			$this->provenance( null )->stamp_new_order( $outside );
			self::assertSame( [], $outside->meta, 'PunchOut outside a visit is the route guard\'s to refuse' );

			$this->provenance( $this->visit() )->stamp_new_order( 'not an order' );
		}

		public function test_a_visit_that_is_not_live_creates_no_punchout_order(): void {
			foreach ( [ 'ordered' => fn() => null, 'disabled' => fn() => $this->db->status = 'disabled', 'unreadable' => fn() => $this->db->fails = true ] as $case => $arrange ) {
				$this->db->status = 'active';
				$this->db->fails  = false;
				$arrange();
				$order = $this->order();
				try {
					$this->provenance( $this->visit( 'ordered' === $case ? 'ordered' : 'active' ) )->stamp_new_order( $order );
					self::fail( $case . ': checkout must stop before the order is saved' );
				} catch ( Exception $error ) {
					self::assertStringContainsString( 'catalog session has ended', $error->getMessage(), $case );
				}
				self::assertSame( [], $order->meta, $case );
			}
		}

		public function test_it_runs_as_checkout_creates_the_order_before_the_route_guard(): void {
			$provenance = $this->provenance( $this->visit() );
			$provenance->register();
			self::assertSame( [ [ 10, [ $provenance, 'stamp_new_order' ], 1 ] ], $GLOBALS['pow_payment_method_hooks']['woocommerce_checkout_create_order'] );
		}
	}
}
