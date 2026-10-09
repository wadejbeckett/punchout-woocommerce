<?php
/**
 * Checkout in a visit and the bound account (0.5.0, ticket 76).
 *
 * Every buyer of a connection shares one WooCommerce account, so checkout in a visit must write nothing to it:
 * WooCommerce saves the posted billing and shipping fields onto the customer through
 * `woocommerce_checkout_update_customer_data`, and an address-book plugin that keeps saved addresses does its own
 * save from the same filter, but only while the value it receives is still true. During a visit PunchOut answers
 * false there early (priority 10) and again last.
 *
 * Billing opens on the account's own saved billing profile, read from the account and not from the visit's session
 * customer (which holds whatever was typed during this visit), through `woocommerce_checkout_get_value`. PunchOut
 * owns no field: it only answers the value a billing field opens with.
 *
 * @package POW
 */

declare( strict_types = 1 );

namespace {
	require_once dirname( __DIR__ ) . '/Support/checkout-hooks.php';

	use PHPUnit\Framework\TestCase;
	use POW\Checkout\PaymentMethod;
	use POW\Checkout\VisitAccount;
	use POW\Sessions\Session;

	/** The connection registry's database: one connection, its status set by the test. */
	final class VisitAccountDatabase {
		public string $prefix = 'va_fixture_';
		public string $last_error = '';
		public string $status = 'active';
		public function prepare( string $sql, mixed ...$args ): string { return $sql; }
		public function get_row( string $sql, mixed $format = null ): ?array {
			if ( ! str_contains( $sql, 'pow_partners' ) ) { throw new LogicException( 'Unexpected query: ' . $sql ); }
			return [ 'id' => 5, 'name' => 'Example connection', 'status' => $this->status, 'owner_user_id' => 20, 'from_identity' => 'BUYER-TEST', 'to_identity' => 'supplier-test' ];
		}
	}

	/** A session store whose visit lookup cannot answer. */
	final class VisitAccountBrokenStore extends POW\Sessions\Store {
		public function find_for_login( int $user_id, string $wp_session_token, array $statuses = [ Session::ACTIVE ] ): ?Session { throw new RuntimeException( 'Session lookup failed.' ); }
	}

	/** The account as WooCommerce reads it from the database: its saved billing profile, and one shipping field. */
	final class VisitAccountCustomer {
		public function __construct( public int $id, private array $billing ) {}
		public function get_billing_first_name(): string { return $this->billing['first_name'] ?? ''; }
		public function get_billing_company(): string { return $this->billing['company'] ?? ''; }
		public function get_billing_address_1(): string { return $this->billing['address_1'] ?? ''; }
		public function get_billing_phone(): string { return $this->billing['phone'] ?? ''; }
		public function get_shipping_city(): string { return 'Shipping City'; }
	}

	final class CheckoutVisitAccountTest extends TestCase {
		private const ACCOUNT = 20;
		private array $saved = [];
		private VisitAccountDatabase $db;
		/** @var list<int> The accounts the reader was asked for. */
		private array $read = [];

		protected function setUp(): void {
			foreach ( [ 'wpdb', 'pow_test_options', 'pow_test_filters', 'pow_payment_method_hooks', 'pow_test_current_user_id' ] as $key ) {
				$this->saved[ $key ] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null ];
				unset( $GLOBALS[ $key ] );
			}
			$GLOBALS['wpdb'] = $this->db = new VisitAccountDatabase();
			$this->read = [];
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
				$GLOBALS['pow_test_current_user_id'] = self::ACCOUNT;
				( new ReflectionProperty( $plugin, 'sessions' ) )->setValue( $plugin, new VisitAccountBrokenStore() );
				return $plugin;
			}
			( new ReflectionProperty( $plugin, 'session_resolved' ) )->setValue( $plugin, true );
			( new ReflectionProperty( $plugin, 'current_session' ) )->setValue( $plugin, $visit );
			return $plugin;
		}

		private function visit( string $status = 'active' ): Session {
			return Session::from_row( [ 'id' => 42, 'partner_id' => 5, 'user_id' => self::ACCOUNT, 'status' => $status, 'buyer_cookie' => '{C}', 'expires' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ] );
		}

		/** @param array<string, string>|\Throwable $profile The account's saved billing profile, or what reading it throws. */
		private function account( Session|false|null $visit, array|\Throwable $profile = [] ): VisitAccount {
			$plugin = $this->plugin( $visit );
			$method = new PaymentMethod( $plugin, new POW\Partners\Registry( new POW\Partners\Secrets( str_repeat( 'v', 32 ) ) ) );
			return new VisitAccount(
				$plugin,
				$method,
				function ( int $user_id ) use ( $profile ): object {
					$this->read[] = $user_id;
					if ( $profile instanceof \Throwable ) { throw $profile; }
					return new VisitAccountCustomer( $user_id, $profile );
				}
			);
		}

		public function test_checkout_in_a_live_visit_saves_nothing_to_the_bound_account(): void {
			self::assertFalse( $this->account( $this->visit() )->update_customer_data( true ) );
		}

		public function test_a_visit_that_is_not_live_or_cannot_be_established_saves_nothing_either(): void {
			self::assertFalse( $this->account( $this->visit( 'ordered' ) )->update_customer_data( true ), 'A visit that has left active is still the shared account' );
			$this->db->status = 'disabled';
			self::assertFalse( $this->account( $this->visit() )->update_customer_data( true ), 'A switched-off connection is still the shared account' );
			self::assertFalse( $this->account( false )->update_customer_data( true ), 'A request that cannot be proved ordinary writes nothing' );
		}

		public function test_an_ordinary_shopper_keeps_woocommerces_answer(): void {
			$outside = $this->account( null );
			self::assertTrue( $outside->update_customer_data( true ), 'The bound account on its own password login saves as any customer does' );
			self::assertFalse( $outside->update_customer_data( false ), 'Another plugin\'s false stands' );
		}

		/**
		 * Early, so an address-book plugin that saves from the same filter (later, and only while the value is still
		 * true) never runs its own save; and again last, so the answer WooCommerce itself reads stays false whatever
		 * ran in between.
		 */
		public function test_the_refusal_runs_early_and_again_last(): void {
			$account = $this->account( $this->visit() );
			$account->register();
			self::assertSame(
				[ [ 10, [ $account, 'update_customer_data' ], 1 ], [ PHP_INT_MAX, [ $account, 'update_customer_data' ], 1 ] ],
				$GLOBALS['pow_payment_method_hooks']['woocommerce_checkout_update_customer_data']
			);
		}

		public function test_billing_opens_on_the_accounts_saved_profile_in_a_live_visit(): void {
			$account = $this->account( $this->visit(), [ 'first_name' => 'Head', 'company' => 'Buyer Co', 'address_1' => '1 Example Street' ] );
			self::assertSame( 'Head', $account->checkout_value( null, 'billing_first_name' ) );
			self::assertSame( 'Buyer Co', $account->checkout_value( null, 'billing_company' ) );
			self::assertSame( '1 Example Street', $account->checkout_value( null, 'billing_address_1' ) );
			self::assertSame( [ self::ACCOUNT, self::ACCOUNT, self::ACCOUNT ], $this->read, 'The visit\'s own account is read, every time, from its saved profile' );
		}

		public function test_a_billing_field_the_account_leaves_empty_is_woocommerces_to_fill(): void {
			$account = $this->account( $this->visit(), [ 'first_name' => 'Head' ] );
			self::assertNull( $account->checkout_value( null, 'billing_phone' ), 'An empty saved value opens as WooCommerce would open it (what the buyer typed in this visit, or the default)' );
		}

		public function test_only_billing_fields_the_account_has_are_prefilled(): void {
			$account = $this->account( $this->visit(), [ 'first_name' => 'Head' ] );
			self::assertNull( $account->checkout_value( null, 'shipping_city' ), 'Shipping is the address book\'s and WooCommerce\'s' );
			self::assertNull( $account->checkout_value( null, 'order_comments' ) );
			self::assertNull( $account->checkout_value( null, 'billing_unknown_field' ), 'A field the account has no getter for' );
			self::assertNull( $account->checkout_value( null, [ 'billing_first_name' ] ) );
		}

		public function test_a_value_another_plugin_already_gave_stands(): void {
			$account = $this->account( $this->visit(), [ 'first_name' => 'Head' ] );
			self::assertSame( 'Owner', $account->checkout_value( 'Owner', 'billing_first_name' ) );
			self::assertSame( [], $this->read );
		}

		public function test_outside_a_live_visit_nothing_is_prefilled(): void {
			self::assertNull( $this->account( null, [ 'first_name' => 'Head' ] )->checkout_value( null, 'billing_first_name' ) );
			self::assertNull( $this->account( $this->visit( 'ordered' ), [ 'first_name' => 'Head' ] )->checkout_value( null, 'billing_first_name' ) );
			self::assertNull( $this->account( false, [ 'first_name' => 'Head' ] )->checkout_value( null, 'billing_first_name' ) );
			self::assertSame( [], $this->read );
		}

		public function test_an_account_that_cannot_be_read_leaves_the_field_to_woocommerce(): void {
			self::assertNull( $this->account( $this->visit(), new RuntimeException( 'No such account.' ) )->checkout_value( null, 'billing_first_name' ) );
		}

		public function test_the_prefill_is_registered_for_woocommerces_checkout_values(): void {
			$account = $this->account( $this->visit() );
			$account->register();
			self::assertSame( [ [ 10, [ $account, 'checkout_value' ], 2 ] ], $GLOBALS['pow_payment_method_hooks']['woocommerce_checkout_get_value'] );
		}
	}
}
