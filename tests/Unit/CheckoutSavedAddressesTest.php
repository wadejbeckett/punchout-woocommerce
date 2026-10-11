<?php
/**
 * Saved addresses only in a visit (0.5.1).
 *
 * During a live PunchOut visit the buyer chooses one of the bound account's saved delivery addresses and cannot add,
 * edit or type one. With the setting "Saved addresses only in a visit" on (the default), WooCommerce's checkout
 * fields are rewritten last: an address-book selector loses its "add new" choice (and a default of add new moves to
 * the first saved entry), and every other billing and shipping field posts as a hidden input, so an address-book
 * script still fills the same ids on a card change while nothing can be typed. Server-side, a posted delivery
 * address that is not one of the account's saved entries is refused before any order exists, unless the account
 * has none saved (a shop without an address book must still work). Outside a visit, or with the setting off,
 * nothing changes.
 *
 * @package POW
 */

declare( strict_types = 1 );

namespace {
	require_once dirname( __DIR__ ) . '/Support/checkout-hooks.php';
	if ( ! function_exists( 'register_setting' ) ) { function register_setting( string $group, string $name, array $args = [] ): void {} }
	if ( ! function_exists( 'add_settings_section' ) ) { function add_settings_section( string $id, string $title, mixed $callback, string $page ): void {} }
	if ( ! function_exists( 'add_settings_field' ) ) { function add_settings_field( string $id, string $title, mixed $callback, string $page, string $section = 'default', array $args = [] ): void { $GLOBALS['pow_test_settings_fields'][ $id ] = $args; } }

	use PHPUnit\Framework\TestCase;
	use POW\Checkout\PaymentMethod;
	use POW\Checkout\SavedAddresses;
	use POW\Checkout\Validation;
	use POW\Sessions\Session;
	use POW\Settings;

	/** The connection registry's database: one connection, its status set by the test. */
	final class SavedAddressesDatabase {
		public string $prefix = 'sa_fixture_';
		public string $last_error = '';
		public string $status = 'active';
		public function prepare( string $sql, mixed ...$args ): string { return $sql; }
		public function get_row( string $sql, mixed $format = null ): ?array {
			if ( ! str_contains( $sql, 'pow_partners' ) ) { throw new LogicException( 'Unexpected query: ' . $sql ); }
			return [ 'id' => 5, 'name' => 'Example connection', 'status' => $this->status, 'owner_user_id' => 20, 'from_identity' => 'BUYER-TEST', 'to_identity' => 'supplier-test', 'emit_ship_to' => 1 ];
		}
	}

	/** The cart's lines, as WooCommerce's get_cart() hands them out. */
	final class SavedAddressesCart {
		public function get_cart(): array { return [ 'k1' => [ 'data' => new WC_Product( 11, 'Widget', 'W-1' ), 'quantity' => 1 ] ]; }
	}

	final class CheckoutSavedAddressesTest extends TestCase {
		private const ACCOUNT = 20;
		private const GLOBALS = [ 'wpdb', 'pow_test_options', 'pow_test_filters', 'pow_payment_method_hooks', 'pow_test_currency', 'pow_test_wc', 'pow_test_settings_fields' ];
		private array $saved = [];
		private SavedAddressesDatabase $db;
		/** @var list<int> The accounts whose saved entries were read. */
		private array $read = [];

		protected function setUp(): void {
			foreach ( self::GLOBALS as $key ) {
				$this->saved[ $key ] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null ];
				unset( $GLOBALS[ $key ] );
			}
			$GLOBALS['wpdb'] = $this->db = new SavedAddressesDatabase();
			$GLOBALS['pow_test_options']['woocommerce_currency'] = 'ZAR';
			WC()->cart = new SavedAddressesCart();
			$this->read = [];
		}

		protected function tearDown(): void {
			foreach ( $this->saved as $key => [ $existed, $value ] ) {
				if ( $existed ) { $GLOBALS[ $key ] = $value; } else { unset( $GLOBALS[ $key ] ); }
			}
		}

		/** The container answering "which visit is this request inside" with $visit (null: outside any). */
		private function plugin( ?Session $visit ): POW\Plugin {
			$plugin = ( new ReflectionClass( POW\Plugin::class ) )->newInstanceWithoutConstructor();
			( new ReflectionProperty( $plugin, 'settings' ) )->setValue( $plugin, new Settings() );
			( new ReflectionProperty( $plugin, 'session_resolved' ) )->setValue( $plugin, true );
			( new ReflectionProperty( $plugin, 'current_session' ) )->setValue( $plugin, $visit );
			return $plugin;
		}

		private function visit( string $status = 'active' ): Session {
			return Session::from_row( [ 'id' => 42, 'partner_id' => 5, 'user_id' => self::ACCOUNT, 'status' => $status, 'buyer_cookie' => '{C}', 'expires' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ] );
		}

		/**
		 * @param list<array<string, string>> $book The account's saved shipping entries, raw, as the reader finds them.
		 */
		private function rule( ?Session $visit, array $book = [], ?string $setting = null ): SavedAddresses {
			if ( null !== $setting ) { $GLOBALS['pow_test_options'][ Settings::OPTION_KEY ] = [ 'visit_saved_addresses_only' => $setting ]; }
			$plugin = $this->plugin( $visit );
			$method = new PaymentMethod( $plugin, new POW\Partners\Registry( new POW\Partners\Secrets( str_repeat( 's', 32 ) ) ) );
			return new SavedAddresses(
				$plugin,
				$method,
				new Settings(),
				function ( int $user_id ) use ( $book ): array {
					$this->read[] = $user_id;
					return $book;
				}
			);
		}

		/** WooCommerce's checkout fields as an address-book plugin leaves them: a selector per group, add new the shipping default. */
		private static function fields(): array {
			return [
				'billing'  => [
					'billing_first_name'   => [ 'label' => 'First name', 'required' => true, 'class' => [ 'form-row-first' ], 'autocomplete' => 'given-name', 'priority' => 10 ],
					'billing_country'      => [ 'type' => 'country', 'label' => 'Country / Region', 'required' => true, 'class' => [ 'form-row-wide', 'address-field', 'update_totals_on_change' ], 'priority' => 40 ],
					'billing_email'        => [ 'label' => 'Email address', 'required' => true, 'type' => 'email', 'validate' => [ 'email' ], 'priority' => 110 ],
					'billing_address_book' => [ 'type' => 'select', 'label' => 'Address book', 'class' => [ 'form-row-wide', 'address_book' ], 'options' => [ 'b1' => 'Head office', 'add_new' => 'Add new address' ], 'default' => 'b1', 'priority' => -1 ],
				],
				'shipping' => [
					'shipping_address_book' => [ 'type' => 'select', 'label' => 'Address book', 'class' => [ 'form-row-wide', 'address_book' ], 'options' => [ 'add_new' => 'Add new address', 'a1' => 'Head office', 'a2' => 'Depot' ], 'default' => 'add_new', 'priority' => -1 ],
					'shipping_first_name'   => [ 'label' => 'First name', 'required' => true, 'class' => [ 'form-row-first' ], 'priority' => 10 ],
					'shipping_address_1'    => [ 'label' => 'Street address', 'placeholder' => 'House number and street name', 'required' => true, 'class' => [ 'form-row-wide', 'address-field' ], 'priority' => 50 ],
					'shipping_state'        => [ 'type' => 'state', 'label' => 'Province', 'required' => true, 'class' => [ 'form-row-wide', 'address-field' ], 'validate' => [ 'state' ], 'priority' => 80 ],
					'shipping_country'      => [ 'type' => 'country', 'label' => 'Country / Region', 'required' => true, 'class' => [ 'form-row-wide', 'address-field', 'update_totals_on_change' ], 'priority' => 40 ],
				],
				'account'  => [ 'account_password' => [ 'type' => 'password', 'label' => 'Create account password', 'required' => true ] ],
				'order'    => [ 'order_comments' => [ 'type' => 'textarea', 'label' => 'Order notes', 'required' => false ] ],
			];
		}

		/** The posted shipping address, as WooCommerce hands it to its validation step (shipping_* keys). */
		private static function shipping( array $overrides = [] ): array {
			$address = $overrides + [ 'first_name' => 'Receiving', 'last_name' => 'Desk', 'company' => 'Buyer Co', 'address_1' => '1 Example Street', 'address_2' => '', 'city' => 'Example City', 'state' => 'GP', 'postcode' => '2001', 'country' => 'ZA', 'phone' => '' ];
			$posted  = [ 'payment_method' => 'punchout', 'order_comments' => 'Leave at the gate.' ];
			foreach ( $address as $field => $value ) { $posted[ 'shipping_' . $field ] = $value; }
			return $posted;
		}

		private const HEAD  = [ 'first_name' => 'Receiving', 'last_name' => 'Desk', 'company' => 'Buyer Co', 'address_1' => '1 Example Street', 'address_2' => '', 'city' => 'Example City', 'state' => 'GP', 'postcode' => '2001', 'country' => 'ZA' ];
		private const DEPOT = [ 'first_name' => 'Dock', 'last_name' => 'Two', 'company' => 'Buyer Co Depot', 'address_1' => '2 Depot Road', 'address_2' => '', 'city' => 'Depot Town', 'state' => 'GP', 'postcode' => '2001', 'country' => 'ZA', 'address_nickname' => 'Depot' ];

		// ----------------------------------------------------------------------------- the setting

		public function test_the_setting_is_on_by_default_and_its_filter_runs_last(): void {
			self::assertTrue( ( new Settings() )->visit_saved_addresses_only() );
			$GLOBALS['pow_test_options'][ Settings::OPTION_KEY ] = [ 'visit_saved_addresses_only' => 'no' ];
			self::assertFalse( ( new Settings() )->visit_saved_addresses_only() );
			$GLOBALS['pow_test_filters'] = [ Settings::SAVED_ADDRESSES_FILTER => static fn( bool $on ): bool => ! $on ];
			self::assertTrue( ( new Settings() )->visit_saved_addresses_only(), 'The filter has the last word' );
			self::assertSame( 'punchout_visit_saved_addresses_only', Settings::SAVED_ADDRESSES_FILTER );
		}

		public function test_the_settings_screen_offers_it_as_a_checkbox_beside_the_visit_settings_and_saves_it(): void {
			$admin = ( new ReflectionClass( POW\Admin\Page::class ) )->newInstanceWithoutConstructor();
			( new ReflectionProperty( $admin, 'settings' ) )->setValue( $admin, new Settings() );
			self::assertSame( 'yes', $admin->sanitize_settings( [ 'visit_saved_addresses_only' => 'yes' ] )['visit_saved_addresses_only'] );
			self::assertSame( 'no', $admin->sanitize_settings( [] )['visit_saved_addresses_only'], 'An unticked box saves off' );
			self::assertSame( 'no', $admin->sanitize_settings( [ 'visit_saved_addresses_only' => 'on' ] )['visit_saved_addresses_only'] );
			$GLOBALS['pow_test_settings_fields'] = [];
			$admin->register_settings();
			$fields = $GLOBALS['pow_test_settings_fields'];
			self::assertSame( 'checkbox', $fields['pow_visit_saved_addresses_only']['type'] ?? null );
			self::assertStringContainsString( 'saved', $fields['pow_visit_saved_addresses_only']['help'] );
			$keys = array_keys( $fields );
			self::assertSame( array_search( 'pow_checkout_button_label', $keys, true ) + 1, array_search( 'pow_visit_saved_addresses_only', $keys, true ), 'Next to the other visit settings' );
			self::assertSame( array_search( 'pow_visit_saved_addresses_only', $keys, true ) + 1, array_search( 'pow_leave_confirmation', $keys, true ) );
		}

		// ----------------------------------------------------------------------------- the checkout fields

		public function test_in_a_live_visit_the_add_new_choice_goes_and_a_default_of_add_new_moves_to_the_first_saved_entry(): void {
			$fields   = $this->rule( $this->visit() )->checkout_fields( self::fields() );
			$shipping = $fields['shipping']['shipping_address_book'];
			self::assertSame( [ 'a1' => 'Head office', 'a2' => 'Depot' ], $shipping['options'] );
			self::assertSame( 'a1', $shipping['default'], 'The first remaining entry' );
			self::assertSame( 'select', $shipping['type'], 'The selector itself stays visible' );
			self::assertSame( 'Address book', $shipping['label'] );
			$billing = $fields['billing']['billing_address_book'];
			self::assertSame( [ 'b1' => 'Head office' ], $billing['options'] );
			self::assertSame( 'b1', $billing['default'], 'A default that was a saved entry stays' );
		}

		public function test_in_a_live_visit_every_other_billing_and_shipping_field_posts_hidden_and_keeps_its_requirement(): void {
			$fields = $this->rule( $this->visit() )->checkout_fields( self::fields() );
			foreach ( [ 'billing' => [ 'billing_first_name', 'billing_country', 'billing_email' ], 'shipping' => [ 'shipping_first_name', 'shipping_address_1', 'shipping_state', 'shipping_country' ] ] as $group => $keys ) {
				foreach ( $keys as $key ) {
					$field = $fields[ $group ][ $key ];
					self::assertSame( 'hidden', $field['type'], $key );
					self::assertFalse( isset( $field['label'] ), $key . ': no label' );
					self::assertFalse( isset( $field['placeholder'] ), $key . ': no placeholder' );
					self::assertTrue( $field['required'], $key . ': the chosen saved address supplies it' );
				}
			}
			self::assertSame( [ 'form-row-wide', 'address-field', 'update_totals_on_change' ], $fields['shipping']['shipping_country']['class'], 'Classes, priority and validation stay' );
			self::assertSame( [ 'state' ], $fields['shipping']['shipping_state']['validate'] );
			self::assertSame( 40, $fields['shipping']['shipping_country']['priority'] );
			self::assertSame( self::fields()['account'], $fields['account'], 'Other groups are not touched' );
			self::assertSame( self::fields()['order'], $fields['order'] );
		}

		public function test_outside_a_live_visit_the_fields_are_left_as_they_are(): void {
			self::assertSame( self::fields(), $this->rule( null )->checkout_fields( self::fields() ), 'An ordinary shopper' );
			self::assertSame( self::fields(), $this->rule( $this->visit( 'ordered' ) )->checkout_fields( self::fields() ), 'A visit that has left active' );
			$this->db->status = 'disabled';
			self::assertSame( self::fields(), $this->rule( $this->visit() )->checkout_fields( self::fields() ), 'A switched-off connection' );
		}

		public function test_with_the_setting_off_nothing_changes_in_a_visit(): void {
			self::assertSame( self::fields(), $this->rule( $this->visit(), [], 'no' )->checkout_fields( self::fields() ) );
			$GLOBALS['pow_test_filters'] = [ Settings::SAVED_ADDRESSES_FILTER => static fn(): bool => false ];
			self::assertSame( self::fields(), $this->rule( $this->visit(), [], 'yes' )->checkout_fields( self::fields() ), 'The filter can switch it off' );
		}

		public function test_fields_that_are_not_an_array_are_left_to_woocommerce(): void {
			self::assertSame( 'not fields', $this->rule( $this->visit() )->checkout_fields( 'not fields' ) );
			$odd = [ 'shipping' => 'odd', 'billing' => [ 'billing_city' => 'odd', 'billing_address_book' => [ 'type' => 'select', 'options' => 'odd', 'default' => 'add_new' ] ] ];
			self::assertSame( $odd, $this->rule( $this->visit() )->checkout_fields( $odd ) );
		}

		public function test_a_selector_whose_only_choice_was_add_new_keeps_no_default(): void {
			$fields = [ 'shipping' => [ 'shipping_address_book' => [ 'type' => 'select', 'options' => [ 'add_new' => 'Add new address' ], 'default' => 'add_new' ] ] ];
			$result = $this->rule( $this->visit() )->checkout_fields( $fields )['shipping']['shipping_address_book'];
			self::assertSame( [], $result['options'] );
			self::assertFalse( isset( $result['default'] ) );
		}

		public function test_the_fields_filter_runs_after_any_address_book(): void {
			$rule = $this->rule( $this->visit() );
			$rule->register();
			$hooks = $GLOBALS['pow_payment_method_hooks']['woocommerce_checkout_fields'];
			self::assertSame( [ [ PHP_INT_MAX, [ $rule, 'checkout_fields' ], 1 ] ], $hooks );
			self::assertGreaterThan( 100000, $hooks[0][0] );
		}

		// ----------------------------------------------------------------------------- the saved entries, found by shape

		public function test_saved_entries_are_read_from_user_meta_by_shape(): void {
			$meta = [
				'shipping_address_1'         => [ '1 Example Street' ],
				'shipping_city'              => [ 'Example City' ],
				'shipping_postcode'          => [ '2001' ],
				'shipping_country'           => [ 'ZA' ],
				'shipping_a2_address_1'      => [ '2 Depot Road' ],
				'shipping_a2_city'           => [ 'Depot Town' ],
				'shipping_a2_postcode'       => [ '2001' ],
				'shipping_a2_country'        => [ 'ZA' ],
				'billing_address_1'          => [ '9 Billing Row' ],
				'billing_b2_address_1'       => [ '9 Billing Row' ],
				'some_book_address_shipping_a3' => [ serialize( [ 'first_name' => 'Yard', 'address_1' => '3 Yard Close', 'city' => 'Yard Town', 'postcode' => '2002', 'country' => 'ZA' ] ) ],
				'some_book_address_billing_b3'  => [ serialize( [ 'address_1' => '9 Billing Row', 'city' => 'Billing City', 'postcode' => '1', 'country' => 'ZA' ] ) ],
				'some_book_shipping'         => [ serialize( [ 'addresses' => [ 'a2', 'a3' ], 'default' => 'a2' ] ) ],
				'session_tokens'             => [ 'a:0:{}' ],
			];
			$entries = SavedAddresses::from_meta( $meta );
			self::assertSame(
				[
					[ 'address_1' => '1 Example Street', 'city' => 'Example City', 'postcode' => '2001', 'country' => 'ZA' ],
					[ 'address_1' => '2 Depot Road', 'city' => 'Depot Town', 'postcode' => '2001', 'country' => 'ZA' ],
					[ 'address_1' => '3 Yard Close', 'city' => 'Yard Town', 'postcode' => '2002', 'country' => 'ZA' ],
				],
				$entries,
				'The customer\'s own shipping address, a prefixed-key entry and a serialised shipping entry; billing entries, the book\'s index and other meta are not delivery addresses'
			);
			self::assertSame( [], SavedAddresses::from_meta( [] ) );
			self::assertSame( [], SavedAddresses::from_meta( [ 'shipping_address_1' => 'not a list', 'shipping_a2_address_1' => [] ] ) );
		}

		// ----------------------------------------------------------------------------- the server-side refusal

		/** @return list<string> What checkout validation refused for this posted address and these saved entries. */
		private function refusals( ?Session $visit, array $posted, array $book, ?string $setting = null ): array {
			$rule   = $this->rule( $visit, $book, $setting );
			$method = ( new ReflectionProperty( $rule, 'method' ) )->getValue( $rule );
			$errors = new WP_Error();
			( new Validation( $method, $rule ) )->validate( $posted, $errors );
			return $errors->get_error_messages();
		}

		public function test_a_chosen_saved_entry_passes(): void {
			self::assertSame( [], $this->refusals( $this->visit(), self::shipping( self::DEPOT ), [ self::HEAD, self::DEPOT ] ) );
			self::assertSame( [], $this->refusals( $this->visit(), self::shipping(), [ self::HEAD, self::DEPOT ] ) );
			self::assertSame( [ self::ACCOUNT, self::ACCOUNT ], $this->read, 'The visit\'s own account is read' );
		}

		public function test_the_comparison_ignores_case_and_surrounding_space_and_only_reads_the_core_fields(): void {
			$posted = self::shipping( [ 'address_1' => '  2 DEPOT road ', 'city' => 'depot town', 'postcode' => ' 2001', 'country' => ' ZA ', 'first_name' => 'Someone', 'company' => 'Else', 'state' => 'WC', 'phone' => '0100000000' ] );
			self::assertSame( [], $this->refusals( $this->visit(), $posted, [ self::HEAD, self::DEPOT ] ), 'Names, company, state and phone are the order\'s; the street, town, postcode and country decide' );
		}

		public function test_a_posted_address_that_is_not_a_saved_entry_is_refused_before_any_order(): void {
			$typed    = self::shipping( [ 'address_1' => '3 One-off Lane', 'city' => 'Oneoff City' ] );
			$refusals = $this->refusals( $this->visit(), $typed, [ self::HEAD, self::DEPOT ] );
			self::assertCount( 1, $refusals );
			self::assertStringContainsString( 'saved delivery addresses', $refusals[0] );
			self::assertFalse( str_contains( strtolower( $refusals[0] ), 'approv' ), 'Never the word approval' );
			self::assertCount( 1, $this->refusals( $this->visit(), self::shipping( [ 'postcode' => '2002' ] ), [ self::HEAD, self::DEPOT ] ), 'One core field apart is another address' );
			self::assertCount( 1, $this->refusals( $this->visit(), self::shipping( [ 'country' => 'NA' ] ), [ self::HEAD, self::DEPOT ] ) );
		}

		public function test_an_account_with_no_saved_delivery_address_takes_what_is_posted(): void {
			self::assertSame( [], $this->refusals( $this->visit(), self::shipping( [ 'address_1' => '3 One-off Lane' ] ), [] ), 'A shop without an address book must still work' );
			self::assertSame( [], $this->refusals( $this->visit(), self::shipping( [ 'address_1' => '3 One-off Lane' ] ), [ [ 'address_1' => '', 'city' => '', 'postcode' => '', 'country' => '' ], [ 'first_name' => 'No street' ] ] ), 'Blank entries are no saved address' );
		}

		public function test_an_empty_posted_address_is_nothing_to_compare(): void {
			$posted = [ 'payment_method' => 'punchout', 'order_comments' => '' ];
			self::assertSame( [], $this->refusals( $this->visit(), $posted, [ self::HEAD ] ), 'No shipping fields at all (nothing needs shipping)' );
			self::assertSame( [], $this->refusals( $this->visit(), self::shipping( [ 'address_1' => '', 'address_2' => '', 'city' => '', 'postcode' => '', 'country' => '' ] ), [ self::HEAD ] ) );
		}

		public function test_outside_a_live_visit_and_with_the_setting_off_nothing_is_refused(): void {
			$typed = self::shipping( [ 'address_1' => '3 One-off Lane' ] );
			self::assertSame( [], $this->refusals( null, $typed, [ self::HEAD ] ) );
			self::assertSame( [], $this->refusals( $this->visit(), $typed, [ self::HEAD ], 'no' ), 'Off: a typed address stays on that order only, as in 0.5.0' );
			self::assertSame( [], $this->read, 'Nothing is read when nothing is checked' );
		}

		public function test_an_account_whose_entries_cannot_be_read_refuses_nothing_of_its_own(): void {
			$plugin = $this->plugin( $this->visit() );
			$method = new PaymentMethod( $plugin, new POW\Partners\Registry( new POW\Partners\Secrets( str_repeat( 's', 32 ) ) ) );
			$rule   = new SavedAddresses( $plugin, $method, new Settings(), static function (): array { throw new RuntimeException( 'No such account.' ); } );
			$errors = new WP_Error();
			( new Validation( $method, $rule ) )->validate( self::shipping( [ 'address_1' => '3 One-off Lane' ] ), $errors );
			self::assertSame( [], $errors->get_error_messages(), 'The fields are hidden; an unreadable book is WooCommerce\'s own required-field checks to refuse' );
		}

		public function test_validation_without_the_rule_keeps_the_050_behaviour(): void {
			$rule   = $this->rule( $this->visit() );
			$method = ( new ReflectionProperty( $rule, 'method' ) )->getValue( $rule );
			$errors = new WP_Error();
			( new Validation( $method ) )->validate( self::shipping( [ 'address_1' => '3 One-off Lane' ] ), $errors );
			self::assertSame( [], $errors->get_error_messages() );
		}
	}
}
