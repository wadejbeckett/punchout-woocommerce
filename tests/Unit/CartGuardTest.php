<?php
/** Per-visit basket isolation, cart seeding scope and the bound account's own
 * cart at recorded WP/Woo boundaries.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Cart\Guard;
use POW\Plugin;
use POW\Sessions\Session;
use POW\Sessions\Store;

require_once dirname( __DIR__ ) . '/Support/cart-hooks.php';

final class CartGuardTest extends TestCase {

	/** One connection, one bound customer account, two visits of it. */
	private const ACCOUNT = 20;
	private const VISIT_A = 42;
	private const VISIT_B = 77;
	private const OTHER_ACCOUNT = 99;

	/** WooCommerce's "merge the saved cart on the next hydration" flag. */
	private const MERGE_FLAG = '_woocommerce_load_saved_cart_after_login';

	private const GLOBAL_KEYS = [ 'pow_test_wc', 'pow_test_cart_actions', 'pow_test_cart_filters', 'pow_test_filters', 'pow_test_products' ];

	private Plugin $plugin;
	private Store $store;
	private Guard $guard;

	/** @var array<int, object> Visit id => that visit's basket. */
	private array $carts = [];

	/** Visit A's basket: the default for the single-visit cases. */
	private object $cart;

	/** The bound account's own saved cart (`_woocommerce_persistent_cart_{blog}`). */
	private array $account_saved_cart = [];

	/** @var array<int, array<string, string>> User meta the shared account and others hold. */
	private array $usermeta = [];

	/** @var list<array{0: int, 1: string}> Meta rows actually read from / deleted in the store. */
	private array $meta_reads = [];
	private array $meta_deletes = [];

	private array $saved = [];

	protected function setUp(): void {
		foreach ( self::GLOBAL_KEYS as $key ) {
			if ( array_key_exists( $key, $GLOBALS ) ) { $this->saved[ $key ] = $GLOBALS[ $key ]; }
			unset( $GLOBALS[ $key ] );
		}
		QuoteOrderTestLog::$written = [];
		$this->plugin = ( new ReflectionClass( Plugin::class ) )->newInstanceWithoutConstructor();
		( new ReflectionProperty( Plugin::class, 'session_resolved' ) )->setValue( $this->plugin, true );
		$this->store = new class extends Store {
			/** @var array<int, array<string, mixed>> Visit id => row. */
			public array $rows = [];
			public bool $fails = false;
			public function update( int $id, array $data ): bool {
				if ( $this->fails || ! isset( $this->rows[ $id ] ) ) { return false; }
				$this->rows[ $id ] = array_replace( $this->rows[ $id ], $data );
				return true;
			}
		};
		// Two visits of ONE account: same user_id, each with its own login
		// token and its own per-visit WooCommerce basket key.
		$this->store->rows = [
			self::VISIT_A => [ 'id' => self::VISIT_A, 'partner_id' => 7, 'user_id' => self::ACCOUNT, 'wp_session_token' => 'login-a', 'wc_session_key' => 'pow_' . str_repeat( 'a', 28 ), 'status' => 'active', 'operation' => 'create', 'cart_ready' => 0 ],
			self::VISIT_B => [ 'id' => self::VISIT_B, 'partner_id' => 7, 'user_id' => self::ACCOUNT, 'wp_session_token' => 'login-b', 'wc_session_key' => 'pow_' . str_repeat( 'b', 28 ), 'status' => 'active', 'operation' => 'create', 'cart_ready' => 0 ],
		];
		$this->carts = [ self::VISIT_A => $this->basket(), self::VISIT_B => $this->basket() ];
		$this->cart = $this->carts[ self::VISIT_A ];
		$GLOBALS['pow_test_wc'] = new POW_Test_WC();
		$GLOBALS['pow_test_wc']->cart = $this->cart;
		$GLOBALS['pow_test_filters'] = [];
		$GLOBALS['pow_test_products'] = [];
		$this->account_saved_cart = [ 'account-holders-own-basket' ];
		$this->usermeta = [ self::ACCOUNT => [ self::MERGE_FLAG => '1' ] ];
		$this->meta_reads = [];
		$this->meta_deletes = [];
		$this->guard = new Guard( $this->plugin, $this->store, new QuoteOrderTestLog(), new QuoteOrderTestLogger() );
		$this->guard->register();
		$this->resolve();
	}

	protected function tearDown(): void {
		foreach ( self::GLOBAL_KEYS as $key ) {
			unset( $GLOBALS[ $key ] );
			if ( array_key_exists( $key, $this->saved ) ) { $GLOBALS[ $key ] = $this->saved[ $key ]; }
		}
		QuoteOrderTestLog::$written = [];
	}

	/** A WC()->cart stand-in that keeps the one core side effect under test. */
	private function basket(): object {
		return new class( $this ) {
			public array $items = [];
			public array $empty_calls = [];
			public function __construct( private CartGuardTest $test ) {}
			public function empty_cart( bool $persistent ): void {
				$this->items = [];
				$this->empty_calls[] = $persistent;
				// WC_Cart::empty_cart( true ) calls persistent_cart_destroy().
				if ( $persistent ) { $this->test->persistent_cart_destroy(); }
			}
		};
	}

	private function resolve( ?int $visit = self::VISIT_A ): void {
		( new ReflectionProperty( Plugin::class, 'current_session' ) )->setValue(
			$this->plugin,
			null === $visit ? null : Session::from_row( $this->store->rows[ $visit ] )
		);
	}

	/** Apply the callbacks the guard registered for one filter, in priority order. */
	private function filter( string $hook, mixed $value, mixed ...$args ): mixed {
		$callbacks = $GLOBALS['pow_test_cart_filters'][ $hook ] ?? [];
		ksort( $callbacks );

		foreach ( $callbacks as $priority ) {
			foreach ( $priority as $callback ) { $value = $callback( $value, ...$args ); }
		}

		return $value;
	}

	private function persistent_cart_enabled(): bool {
		return (bool) $this->filter( 'woocommerce_persistent_cart_enabled', true );
	}

	/** WC_Cart_Session::get_saved_cart(), on the one shared meta row. */
	public function get_saved_cart(): array {
		return $this->persistent_cart_enabled() ? $this->account_saved_cart : [];
	}

	/** WC_Cart_Session::persistent_cart_update(). */
	public function persistent_cart_update( array $cart ): void {
		if ( $this->persistent_cart_enabled() ) { $this->account_saved_cart = $cart; }
	}

	/** WC_Cart_Session::persistent_cart_destroy(). */
	public function persistent_cart_destroy(): void {
		if ( $this->persistent_cart_enabled() ) { $this->account_saved_cart = []; }
	}

	/** WP's get_metadata_raw() short-circuit, as core applies it. */
	public function get_user_meta( int $user_id, string $key, bool $single = true ): mixed {
		$check = $this->filter( 'get_user_metadata', null, $user_id, $key, $single );

		if ( null !== $check ) {
			return $single && is_array( $check ) ? ( $check[0] ?? '' ) : $check;
		}

		$this->meta_reads[] = [ $user_id, $key ];
		$value = $this->usermeta[ $user_id ][ $key ] ?? '';

		return $single ? $value : ( '' === $value ? [] : [ $value ] );
	}

	/** WP's delete_metadata() short-circuit, as core applies it. */
	public function delete_user_meta( int $user_id, string $key ): bool {
		$check = $this->filter( 'delete_user_metadata', null, $user_id, $key );

		if ( null !== $check ) { return (bool) $check; }

		$this->meta_deletes[] = [ $user_id, $key ];
		unset( $this->usermeta[ $user_id ][ $key ] );

		return true;
	}

	/**
	 * The three shared-account touches of WC_Cart_Session::get_cart_from_session():
	 * the merge-flag read, the saved-cart merge and the flag delete. Only the
	 * merge itself sits behind woocommerce_persistent_cart_enabled.
	 */
	public function hydrate( object $basket, ?array $session_cart ): void {
		$merge = (bool) $this->get_user_meta( self::ACCOUNT, self::MERGE_FLAG );

		if ( null === $session_cart || $merge ) {
			$session_cart = array_merge( $this->get_saved_cart(), $session_cart ?? [] );
			$this->delete_user_meta( self::ACCOUNT, self::MERGE_FLAG );
		}

		$basket->items = $session_cart;
	}

	/** Simulated native boundaries; the separate native HTTP regression uses actual Woo callbacks. */
	private function request( array $restored, ?string $add = null, int $visit = self::VISIT_A ): void {
		$basket = $this->carts[ $visit ];
		$this->run( $visit, static function() use ( $basket, $restored ): void { $basket->items = $restored; }, $add );
	}

	/** A request whose restore runs core's own saved-cart path for this visit's row. */
	private function native_request( int $visit, ?array $session_cart = null, ?string $add = null ): void {
		$basket = $this->carts[ $visit ];
		$this->run( $visit, fn() => $this->hydrate( $basket, $session_cart ), $add );
	}

	/** wp_loaded: Woo restores at 10, the guard seeds at 15, Woo's form handler adds at 20. */
	private function run( int $visit, callable $restore, ?string $add ): void {
		$basket = $this->carts[ $visit ];
		$GLOBALS['pow_test_wc']->cart = $basket;
		$actions = $GLOBALS['pow_test_cart_actions']['wp_loaded'];
		$actions[10][] = $restore;
		$actions[20][] = function() use ( $basket, $add ): void {
			if ( null === $add ) { return; }
			$basket->items[] = $add;
			$this->persistent_cart_update( $basket->items );
		};
		ksort( $actions );
		foreach ( $actions as $callbacks ) { foreach ( $callbacks as $callback ) { $callback(); } }
	}

	/** A product either inside or outside the account's visible range. */
	private function product( bool $in_range = true ): WC_Product {
		return new class( $in_range ) extends WC_Product {
			public function __construct( private bool $in_range ) { parent::__construct( 11 ); }
			public function is_visible(): bool { return $this->in_range; }
			public function is_purchasable(): bool { return $this->in_range; }
		};
	}

	public function test_first_add_replaces_restored_cart_and_survives_seeding(): void {
		$this->request( [ 'stale' ], 'first' );
		self::assertSame( [ 'first' ], $this->cart->items );
		self::assertSame( 1, $this->store->rows[ self::VISIT_A ]['cart_ready'] );
		self::assertSame( [ true ], $this->cart->empty_calls );
	}

	public function test_persisted_latch_preserves_later_restore_and_add(): void {
		$this->request( [ 'stale' ], 'first' );
		$this->resolve();
		$this->request( $this->cart->items, 'later' );
		self::assertSame( [ 'first', 'later' ], $this->cart->items );
		self::assertSame( [ true ], $this->cart->empty_calls );
	}

	public function test_no_cart_defers_latch_until_storefront(): void {
		$GLOBALS['pow_test_wc']->cart = null;
		$this->guard->seed_cart();
		self::assertSame( 0, $this->store->rows[ self::VISIT_A ]['cart_ready'] );
		$GLOBALS['pow_test_wc']->cart = $this->cart;
		$this->request( [ 'stale' ], 'first' );
		self::assertSame( [ 'first' ], $this->cart->items );
	}

	public function test_unresolved_login_or_ordinary_customer_cart_is_untouched(): void {
		$this->resolve( null );
		$this->request( [ 'guest-or-ordinary' ], 'first' );
		self::assertSame( [ 'guest-or-ordinary', 'first' ], $this->cart->items );
		self::assertSame( [], $this->cart->empty_calls );
		self::assertSame( 0, $this->store->rows[ self::VISIT_A ]['cart_ready'] );
		self::assertTrue( $this->guard->disable_persistent_cart( true ) );
		// Woo's persistent cart keeps working for an ordinary shopper.
		self::assertSame( [ 'guest-or-ordinary', 'first' ], $this->account_saved_cart );
	}

	public function test_non_create_and_ready_sessions_preserve_cart(): void {
		foreach ( [ [ 'edit', 0 ], [ 'inspect', 0 ], [ 'create', 1 ] ] as [ $operation, $ready ] ) {
			$this->store->rows[ self::VISIT_A ]['operation'] = $operation;
			$this->store->rows[ self::VISIT_A ]['cart_ready'] = $ready;
			$this->resolve();
			$this->request( [ 'existing' ], 'first' );
			self::assertSame( [ 'existing', 'first' ], $this->cart->items );
			self::assertSame( $ready, $this->store->rows[ self::VISIT_A ]['cart_ready'] );
		}
		self::assertSame( [], $this->cart->empty_calls );
	}

	public function test_failed_latch_stops_before_form_add_and_allows_retry(): void {
		$this->store->fails = true;
		try {
			$this->request( [ 'stale' ], 'first' );
			self::fail( 'The request must stop when the seed latch cannot be saved.' );
		} catch ( RuntimeException $error ) {
			self::assertSame( 503, $error->getCode() );
		}
		self::assertSame( [], $this->cart->items );
		self::assertSame( 0, $this->store->rows[ self::VISIT_A ]['cart_ready'] );
		$this->store->fails = false;
		$this->resolve();
		$this->request( [], 'first' );
		self::assertSame( [ 'first' ], $this->cart->items );
		self::assertSame( 1, $this->store->rows[ self::VISIT_A ]['cart_ready'] );
	}

	public function test_two_visits_of_one_account_keep_separate_baskets(): void {
		$this->resolve( self::VISIT_A );
		$this->native_request( self::VISIT_A, null, 'a-widget' );

		// The colleague's visit hydrates from its OWN row and seeds its own
		// basket; its punch-in must not empty or feed visit A's.
		$this->resolve( self::VISIT_B );
		$this->native_request( self::VISIT_B, null, 'b-gadget' );

		self::assertSame( [ 'a-widget' ], $this->carts[ self::VISIT_A ]->items );
		self::assertSame( [ 'b-gadget' ], $this->carts[ self::VISIT_B ]->items );
		self::assertSame( 1, $this->store->rows[ self::VISIT_A ]['cart_ready'] );
		self::assertSame( 1, $this->store->rows[ self::VISIT_B ]['cart_ready'] );
		self::assertSame( [ true ], $this->carts[ self::VISIT_A ]->empty_calls );
		self::assertSame( [ true ], $this->carts[ self::VISIT_B ]->empty_calls );
		self::assertNotSame(
			$this->store->rows[ self::VISIT_A ]['wc_session_key'],
			$this->store->rows[ self::VISIT_B ]['wc_session_key'],
			'Each visit of the shared account owns its own basket key'
		);
	}

	public function test_the_shared_persistent_cart_is_what_would_bleed_between_visits(): void {
		// Control for the test above: with no visit resolved the filter falls
		// through to Woo's default and the same two requests DO bleed through
		// the one shared meta row. That is what disable_persistent_cart()
		// prevents, and it is why an unresolved visit must never be cached.
		$this->account_saved_cart = [];
		$this->resolve( null );
		$this->native_request( self::VISIT_A, null, 'a-widget' );
		$this->native_request( self::VISIT_B );
		self::assertSame( [ 'a-widget' ], $this->carts[ self::VISIT_B ]->items );
	}

	public function test_punch_in_neither_reads_writes_nor_deletes_the_accounts_own_saved_cart(): void {
		$this->native_request( self::VISIT_A, null, 'a-widget' );
		self::assertSame( [ 'a-widget' ], $this->cart->items, 'The account holder\'s saved basket is not merged into a visit' );
		self::assertSame( [ true ], $this->cart->empty_calls );
		self::assertSame(
			[ 'account-holders-own-basket' ],
			$this->account_saved_cart,
			'empty_cart( true ) calls persistent_cart_destroy(): the shared saved cart must survive every punch-in'
		);
		self::assertFalse( $this->guard->disable_persistent_cart( true ) );
		self::assertSame( [], $this->get_saved_cart() );
	}

	public function test_saved_cart_merge_flag_is_neither_read_nor_deleted_during_a_visit(): void {
		$this->native_request( self::VISIT_A, null, 'a-widget' );
		self::assertSame( [], $this->meta_reads, 'The merge flag must not reach the database inside a visit' );
		self::assertSame( [], $this->meta_deletes, 'A visit may not delete a flag that belongs to the account holder' );
		self::assertSame( '1', $this->usermeta[ self::ACCOUNT ][ self::MERGE_FLAG ] );
		self::assertSame( [ 'a-widget' ], $this->cart->items );
		// Both read shapes short-circuit to "unset".
		self::assertSame( '', $this->get_user_meta( self::ACCOUNT, self::MERGE_FLAG ) );
		self::assertSame( [], $this->get_user_meta( self::ACCOUNT, self::MERGE_FLAG, false ) );
		self::assertTrue( $this->delete_user_meta( self::ACCOUNT, self::MERGE_FLAG ) );
		self::assertSame( '1', $this->usermeta[ self::ACCOUNT ][ self::MERGE_FLAG ] );
	}

	public function test_only_that_one_key_of_a_visiting_account_is_short_circuited(): void {
		$this->usermeta[ self::OTHER_ACCOUNT ] = [ self::MERGE_FLAG => 'someone-elses' ];
		$this->usermeta[ self::ACCOUNT ]['wp_capabilities'] = 'customer';
		self::assertSame( 'someone-elses', $this->get_user_meta( self::OTHER_ACCOUNT, self::MERGE_FLAG ) );
		self::assertSame( 'customer', $this->get_user_meta( self::ACCOUNT, 'wp_capabilities' ) );
		self::assertTrue( $this->delete_user_meta( self::OTHER_ACCOUNT, self::MERGE_FLAG ) );
		self::assertSame( [ [ self::OTHER_ACCOUNT, self::MERGE_FLAG ] ], $this->meta_deletes );
		// Outside a visit the flag is core's own business again.
		$this->resolve( null );
		self::assertSame( '1', $this->get_user_meta( self::ACCOUNT, self::MERGE_FLAG ) );
		self::assertTrue( $this->delete_user_meta( self::ACCOUNT, self::MERGE_FLAG ) );
		self::assertSame( [ 'wp_capabilities' => 'customer' ], $this->usermeta[ self::ACCOUNT ] );
	}

	public function test_add_to_cart_rejects_a_product_outside_the_accounts_visible_range(): void {
		$GLOBALS['pow_test_products'] = [ 11 => $this->product(), 12 => $this->product( false ) ];
		self::assertTrue( $this->guard->validate_add_to_cart( true, 11 ) );
		self::assertFalse( $this->guard->validate_add_to_cart( true, 12 ) );
		self::assertFalse( $this->guard->validate_add_to_cart( true, 13 ), 'A product id with no product is not in range' );
		self::assertFalse( $this->guard->validate_add_to_cart( false, 11 ), 'An earlier refusal is never overturned' );
		self::assertSame( [ 'cart_add_reject', 'cart_add_reject' ], array_column( QuoteOrderTestLog::$written, 0 ) );
		self::assertSame(
			[ 'partner_id' => 7, 'session_id' => self::VISIT_A, 'user_id' => self::ACCOUNT, 'result' => 'reject', 'detail' => [ 'product_id' => 12 ] ],
			QuoteOrderTestLog::$written[0][1]
		);
	}

	public function test_no_site_filter_can_widen_a_visits_range(): void {
		$GLOBALS['pow_test_products'] = [ 12 => $this->product( false ) ];
		$GLOBALS['pow_test_filters']['pow_product_in_range'] = static fn(): bool => true;
		self::assertFalse( $this->guard->validate_add_to_cart( true, 12 ) );
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/Cart/Guard.php' );
		self::assertStringNotContainsString( 'pow_product_in_range', $source, 'The range check offers no site extension point' );
	}

	public function test_an_ordinary_customer_is_not_range_validated(): void {
		$this->resolve( null );
		$GLOBALS['pow_test_products'] = [ 12 => $this->product( false ) ];
		self::assertTrue( $this->guard->validate_add_to_cart( true, 12 ) );
		self::assertFalse( $this->guard->validate_add_to_cart( false, 12 ) );
		self::assertSame( [], QuoteOrderTestLog::$written );
	}

	public function test_every_cart_guard_is_registered_on_the_hook_core_consults(): void {
		self::assertSame( [ 'woocommerce_persistent_cart_enabled', 'get_user_metadata', 'delete_user_metadata', 'woocommerce_add_to_cart_validation' ], array_keys( $GLOBALS['pow_test_cart_filters'] ) );
		self::assertSame( [ 'wp_loaded' ], array_keys( $GLOBALS['pow_test_cart_actions'] ) );
		self::assertSame( [ 15 ], array_keys( $GLOBALS['pow_test_cart_actions']['wp_loaded'] ) );
	}
}
