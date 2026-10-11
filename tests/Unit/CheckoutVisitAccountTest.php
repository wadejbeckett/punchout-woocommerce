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

	// A user meta row read by its id, as WordPress reads it for the by-id writes (user_id, meta_key); a test lists them.
	if ( ! function_exists( 'get_metadata_by_mid' ) ) {
		function get_metadata_by_mid( string $meta_type, mixed $meta_id ): object|false { return $GLOBALS['pow_test_user_meta_rows'][ (int) $meta_id ] ?? false; }
	}

	/** The audit trail as the fence writes it. */
	final class VisitAccountLog extends POW\Audit\Log {
		/** @var list<array{0: string, 1: array<string, mixed>}> */
		public array $rows = [];
		public function __construct() {}
		public function write_checked( string $event, array $context = [] ): bool { $this->rows[] = [ $event, $context ]; return true; }
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
			foreach ( [ 'wpdb', 'pow_test_options', 'pow_test_filters', 'pow_payment_method_hooks', 'pow_test_current_user_id', 'pow_test_user_meta_rows' ] as $key ) {
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

		private VisitAccountLog $log;

		/** The container answering "which visit is this request inside" with $visit (false: the lookup cannot answer). */
		private function plugin( Session|false|null $visit ): POW\Plugin {
			$plugin = ( new ReflectionClass( POW\Plugin::class ) )->newInstanceWithoutConstructor();
			( new ReflectionProperty( $plugin, 'settings' ) )->setValue( $plugin, new POW\Settings() );
			( new ReflectionProperty( $plugin, 'audit' ) )->setValue( $plugin, $this->log = new VisitAccountLog() );
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

		// ----------------------------------------------------------------------------- the account fence (0.5.0)

		/** The refusal as WordPress's metadata filters see it: null lets the write through, false refuses it. */
		private function write( VisitAccount $account, string $operation, int $user_id, string $key, mixed $check = null ): mixed {
			return $account->{ 'refuse_' . $operation }( $check, $user_id, $key, 'value' );
		}

		public function test_inside_a_live_visit_nothing_else_writes_the_bound_accounts_meta_either(): void {
			$account = $this->account( $this->visit() );
			foreach ( [ 'add', 'update', 'delete' ] as $operation ) {
				self::assertFalse( $this->write( $account, $operation, self::ACCOUNT, 'wc_address_book_shipping' ), $operation . ': an address-book entry is refused' );
				self::assertFalse( $this->write( $account, $operation, self::ACCOUNT, 'shipping_address_1' ), $operation . ': a profile field is refused' );
				self::assertFalse( $this->write( $account, $operation, self::ACCOUNT, 'wc_address_book_address_shipping_depot' ), $operation );
				self::assertFalse( $this->write( $account, $operation, self::ACCOUNT, '_woocommerce_load_saved_cart_after_login' ), $operation . ': the merge flag is not an allowed key' );
			}
		}

		public function test_the_keys_a_visits_own_life_writes_pass(): void {
			$account = $this->account( $this->visit() );
			foreach ( [ 'session_tokens', 'wc_last_active', 'last_update', '_woocommerce_persistent_cart_1', '_woocommerce_persistent_cart_12' ] as $key ) {
				foreach ( [ 'add', 'update', 'delete' ] as $operation ) {
					self::assertNull( $this->write( $account, $operation, self::ACCOUNT, $key ), $operation . ' ' . $key . ' is the login\'s, WooCommerce\'s activity stamp or the cart row' );
				}
			}
			self::assertSame( [], $this->log->rows, 'Nothing allowed is audited' );
		}

		public function test_another_users_meta_and_every_write_outside_a_visit_are_left_to_wordpress(): void {
			self::assertNull( $this->write( $this->account( $this->visit() ), 'update', 21, 'wc_address_book_shipping' ), 'Another account\'s row is not the visit\'s' );
			self::assertNull( $this->write( $this->account( $this->visit() ), 'update', 0, 'wc_address_book_shipping' ) );
			self::assertNull( $this->write( $this->account( null ), 'update', self::ACCOUNT, 'wc_address_book_shipping' ), 'The account holder on its own login keeps its address book' );
			self::assertNull( $this->write( $this->account( null ), 'delete', self::ACCOUNT, 'shipping_address_1' ) );
		}

		public function test_a_write_another_filter_already_answered_keeps_that_answer(): void {
			$account = $this->account( $this->visit() );
			self::assertTrue( $this->write( $account, 'delete', self::ACCOUNT, '_woocommerce_load_saved_cart_after_login', true ), 'The cart guard\'s "kept" answer for the merge flag stands' );
			self::assertFalse( $this->write( $account, 'update', self::ACCOUNT, 'wc_address_book_shipping', false ) );
			self::assertSame( [], $this->log->rows );
		}

		public function test_a_request_whose_visit_cannot_be_established_writes_nothing_to_its_own_account(): void {
			$account = $this->account( false );
			self::assertFalse( $this->write( $account, 'update', self::ACCOUNT, 'wc_address_book_shipping' ), 'The signed-in account is not provably an ordinary shopper' );
			self::assertNull( $this->write( $account, 'update', 21, 'wc_address_book_shipping' ), 'Another account is not this request\'s' );
			self::assertNull( $this->write( $account, 'update', self::ACCOUNT, 'session_tokens' ) );
		}

		public function test_each_refusal_is_audited_once_per_request_and_key_naming_the_visit_and_the_key(): void {
			$account = $this->account( $this->visit() );
			$this->write( $account, 'update', self::ACCOUNT, 'wc_address_book_shipping' );
			$this->write( $account, 'update', self::ACCOUNT, 'wc_address_book_shipping' );
			$this->write( $account, 'delete', self::ACCOUNT, 'wc_address_book_shipping' );
			$this->write( $account, 'delete', self::ACCOUNT, 'wc_address_book_address_shipping_depot' );
			self::assertCount( 2, $this->log->rows, 'One row per key, however often the request retries' );
			[ $event, $context ] = $this->log->rows[0];
			self::assertSame( 'visit_account_write_refused', $event );
			self::assertSame( [ 5, 42, self::ACCOUNT, 'refused' ], [ $context['partner_id'], $context['session_id'], $context['user_id'], $context['result'] ] );
			self::assertSame( 'wc_address_book_shipping', $context['detail']['meta_key'] );
			self::assertSame( 'update', $context['detail']['operation'] );
			self::assertSame( 'wc_address_book_address_shipping_depot', $this->log->rows[1][1]['detail']['meta_key'] );
		}

		public function test_an_audit_that_fails_never_lets_the_write_through(): void {
			$account = $this->account( $this->visit() );
			( new ReflectionProperty( $this->plugin( $this->visit() ), 'audit' ) );
			$plugin = ( new ReflectionProperty( $account, 'plugin' ) )->getValue( $account );
			( new ReflectionProperty( $plugin, 'audit' ) )->setValue( $plugin, null );
			self::assertFalse( $this->write( $account, 'update', self::ACCOUNT, 'wc_address_book_shipping' ), 'Without an audit trail the refusal still stands' );
		}

		/**
		 * WooCommerce's own data store (WC_Customer::save(), an address book's entries through it) updates and
		 * deletes user meta by its row id, which WordPress short-circuits through the *_by_mid filters, not the
		 * keyed ones: the fence answers those too, reading the row the id names.
		 */
		public function test_a_write_by_meta_row_id_is_fenced_by_the_row_it_names(): void {
			$GLOBALS['pow_test_user_meta_rows'] = [
				7 => (object) [ 'umeta_id' => 7, 'user_id' => self::ACCOUNT, 'meta_key' => 'wc_address_book_shipping', 'meta_value' => '' ],
				8 => (object) [ 'umeta_id' => 8, 'user_id' => self::ACCOUNT, 'meta_key' => 'session_tokens', 'meta_value' => '' ],
				9 => (object) [ 'umeta_id' => 9, 'user_id' => 21, 'meta_key' => 'wc_address_book_shipping', 'meta_value' => '' ],
			];
			$account = $this->account( $this->visit() );
			$log     = $this->log;
			self::assertFalse( $account->refuse_update_by_mid( null, 7, 'value', false ), 'The visit\'s account\'s book, by row id' );
			self::assertFalse( $account->refuse_delete_by_mid( null, '7' ) );
			self::assertNull( $account->refuse_update_by_mid( null, 8, 'value', false ), 'The login\'s row is allowed' );
			self::assertNull( $account->refuse_delete_by_mid( null, 9 ), 'Another account\'s row' );
			self::assertNull( $account->refuse_delete_by_mid( null, 99 ), 'A row that does not exist is WordPress\'s to refuse' );
			self::assertTrue( $account->refuse_delete_by_mid( true, 7 ), 'An earlier answer stands' );
			self::assertNull( $this->account( null )->refuse_delete_by_mid( null, 7 ), 'Outside a visit' );
			self::assertSame( [ 'wc_address_book_shipping' ], array_map( static fn( array $row ): string => $row[1]['detail']['meta_key'], $log->rows ), 'Audited once, by key' );
			self::assertSame( 'update_by_mid', $log->rows[0][1]['detail']['operation'] );
		}

		/** Early, so the refusal is the first answer, and again last, so nothing in between can turn it back into a write. */
		public function test_the_fence_is_registered_on_every_user_meta_write_early_and_last(): void {
			$account = $this->account( $this->visit() );
			$account->register();
			$hooks = $GLOBALS['pow_payment_method_hooks'];
			foreach ( [ 'add' => 'add_user_metadata', 'update' => 'update_user_metadata', 'delete' => 'delete_user_metadata' ] as $operation => $hook ) {
				self::assertSame( [ [ 1, [ $account, 'refuse_' . $operation ], 3 ], [ PHP_INT_MAX, [ $account, 'refuse_' . $operation ], 3 ] ], $hooks[ $hook ], $hook );
			}
			self::assertSame( [ [ 1, [ $account, 'refuse_update_by_mid' ], 2 ], [ PHP_INT_MAX, [ $account, 'refuse_update_by_mid' ], 2 ] ], $hooks['update_user_metadata_by_mid'] );
			self::assertSame( [ [ 1, [ $account, 'refuse_delete_by_mid' ], 2 ], [ PHP_INT_MAX, [ $account, 'refuse_delete_by_mid' ], 2 ] ], $hooks['delete_user_metadata_by_mid'] );
		}
	}
}
