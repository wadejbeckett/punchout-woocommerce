<?php
/** Cart seeding scope and hook-order regression at recorded WP/Woo boundaries.
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
	private Plugin $plugin;
	private Store $store;
	private Guard $guard;
	private object $cart;
	private array $saved = [];

	protected function setUp(): void {
		foreach ( [ 'pow_test_wc', 'pow_test_cart_actions' ] as $key ) {
			if ( array_key_exists( $key, $GLOBALS ) ) { $this->saved[ $key ] = $GLOBALS[ $key ]; }
			unset( $GLOBALS[ $key ] );
		}
		$this->plugin = ( new ReflectionClass( Plugin::class ) )->newInstanceWithoutConstructor();
		( new ReflectionProperty( Plugin::class, 'session_resolved' ) )->setValue( $this->plugin, true );
		$this->store = new class extends Store {
			public array $row = [ 'id' => 42, 'status' => 'active', 'operation' => 'create', 'cart_ready' => 0 ];
			public bool $fails = false;
			public function update( int $id, array $data ): bool {
				if ( $this->fails || $id !== $this->row['id'] ) { return false; }
				$this->row = array_replace( $this->row, $data );
				return true;
			}
		};
		$this->cart = new class {
			public array $items = [];
			public array $empty_calls = [];
			public function empty_cart( bool $persistent ): void {
				$this->items = [];
				$this->empty_calls[] = $persistent;
			}
		};
		$GLOBALS['pow_test_wc'] = new POW_Test_WC();
		$GLOBALS['pow_test_wc']->cart = $this->cart;
		$this->guard = new Guard( $this->plugin, $this->store, new QuoteOrderTestLog(), new QuoteOrderTestLogger() );
		$this->guard->register();
		$this->resolve();
	}

	protected function tearDown(): void {
		foreach ( [ 'pow_test_wc', 'pow_test_cart_actions' ] as $key ) {
			unset( $GLOBALS[ $key ] );
			if ( array_key_exists( $key, $this->saved ) ) { $GLOBALS[ $key ] = $this->saved[ $key ]; }
		}
	}

	private function resolve( bool $ordinary = false ): void {
		( new ReflectionProperty( Plugin::class, 'current_session' ) )->setValue( $this->plugin, $ordinary ? null : Session::from_row( $this->store->row ) );
	}

	/** Simulated native boundaries; the separate native HTTP regression uses actual Woo callbacks. */
	private function request( array $restored, ?string $add = null ): void {
		$actions = $GLOBALS['pow_test_cart_actions']['wp_loaded'];
		$actions[10][] = function() use ( $restored ): void { $this->cart->items = $restored; };
		$actions[20][] = function() use ( $add ): void { if ( null !== $add ) { $this->cart->items[] = $add; } };
		ksort( $actions );
		foreach ( $actions as $callbacks ) { foreach ( $callbacks as $callback ) { $callback(); } }
	}

	public function test_first_add_replaces_restored_cart_and_survives_seeding(): void {
		$this->request( [ 'stale' ], 'first' );
		self::assertSame( [ 'first' ], $this->cart->items );
		self::assertSame( 1, $this->store->row['cart_ready'] );
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
		self::assertSame( 0, $this->store->row['cart_ready'] );
		$GLOBALS['pow_test_wc']->cart = $this->cart;
		$this->request( [ 'stale' ], 'first' );
		self::assertSame( [ 'first' ], $this->cart->items );
	}

	public function test_unresolved_login_or_ordinary_customer_cart_is_untouched(): void {
		$this->resolve( true );
		$this->request( [ 'guest-or-ordinary' ], 'first' );
		self::assertSame( [ 'guest-or-ordinary', 'first' ], $this->cart->items );
		self::assertSame( [], $this->cart->empty_calls );
		self::assertSame( 0, $this->store->row['cart_ready'] );
		self::assertTrue( $this->guard->disable_persistent_cart( true ) );
	}

	public function test_non_create_and_ready_sessions_preserve_cart(): void {
		foreach ( [ [ 'edit', 0 ], [ 'inspect', 0 ], [ 'create', 1 ] ] as [ $operation, $ready ] ) {
			$this->store->row['operation'] = $operation;
			$this->store->row['cart_ready'] = $ready;
			$this->resolve();
			$this->request( [ 'existing' ], 'first' );
			self::assertSame( [ 'existing', 'first' ], $this->cart->items );
			self::assertSame( $ready, $this->store->row['cart_ready'] );
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
		self::assertSame( 0, $this->store->row['cart_ready'] );
		$this->store->fails = false;
		$this->resolve();
		$this->request( [], 'first' );
		self::assertSame( [ 'first' ], $this->cart->items );
		self::assertSame( 1, $this->store->row['cart_ready'] );
	}
}
