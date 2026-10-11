<?php
/**
 * The handoff and the state page (0.5.0, ticket 77). The page that posts the cart stays a plain /punchout/ route and
 * is served once. Once a site has chosen a state page, every browser that comes after it goes there instead of to a
 * plain page: the Sent state after the return, and, for a handoff that cannot be served, Sent when that order's page
 * was served (a refresh, the back button, a second tab), Not sent when the order's visit returned with it but its page
 * never reached a browser (it expired first), and Session ended for anything else. Without a state page nothing
 * changes: the plain Sent page and the plain no-form page.
 *
 * @package POW
 */

declare( strict_types = 1 );

namespace {
	// The POW\Checkout seams (header(), the answers that end a request) must be in place before Handoff::handle()
	// first runs: PHP settles an unqualified call on the global function for good once it has fallen back to it.
	require_once dirname( __DIR__ ) . '/Support/checkout-hooks.php';

	use PHPUnit\Framework\TestCase;
	use POW\Checkout\Handoff;
	use POW\Orders\QuoteOrder;
	use POW\Sessions\Session;
	use POW\Settings;

	/** An order as the handoff reads it: its key and the visit it names. */
	final class HandoffStateOrder extends WC_Order {
		public function __construct( int $id, private string $key ) { parent::__construct( $id ); }
		public function get_order_key(): string { return $this->key; }
	}

	/** Visit rows by id. */
	final class HandoffStateStore extends POW\Sessions\Store {
		/** @var array<int, Session> */
		public array $rows = [];
		public function __construct() {}
		public function find( int $id ): ?Session { return $this->rows[ $id ] ?? null; }
	}

	final class CheckoutHandoffStateTest extends TestCase {
		private const PAGE = 314;
		private const MARKUP = '<form method="post" action="https://buyer.example.com/return" id="pow-handoff-form"><input type="hidden" name="cxml-base64" value="PD94"></form>';
		private const GLOBALS = [ 'pow_test_transients', 'pow_test_login_token', 'pow_test_cookies_cleared', 'pow_test_cookies_set', 'pow_test_orders', 'pow_test_options', 'pow_test_posts' ];
		private array $saved = [];
		private array $server = [];
		private HandoffStateStore $store;

		protected function setUp(): void {
			foreach ( self::GLOBALS as $key ) {
				$this->saved[ $key ] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null ];
				unset( $GLOBALS[ $key ] );
			}
			$this->server = [ $_SERVER['REQUEST_METHOD'] ?? null, $_GET ];
			$_SERVER['REQUEST_METHOD'] = 'GET';
			$GLOBALS['pow_test_posts'][ self::PAGE ] = [ 'status' => 'publish', 'permalink' => 'https://shop.example.test/visit/' ];
			$this->store = new HandoffStateStore();
			$this->store->rows[42] = Session::from_row( [ 'id' => 42, 'partner_id' => 5, 'user_id' => 20, 'status' => 'returned', 'wp_session_token' => 'login-a', 'order_id' => 501 ] );
			$order = new HandoffStateOrder( 501, 'wc_order_k1' );
			$order->update_meta_data( QuoteOrder::META_SESSION_ID, '42' );
			$GLOBALS['pow_test_orders'][501] = $order;
		}

		protected function tearDown(): void {
			foreach ( $this->saved as $key => [ $existed, $value ] ) {
				if ( $existed ) { $GLOBALS[ $key ] = $value; } else { unset( $GLOBALS[ $key ] ); }
			}
			[ $method, $get ] = $this->server;
			if ( null === $method ) { unset( $_SERVER['REQUEST_METHOD'] ); } else { $_SERVER['REQUEST_METHOD'] = $method; }
			$_GET = $get;
		}

		private function choose_page(): void {
			$GLOBALS['pow_test_options'][ Settings::OPTION_KEY ] = [ 'state_page_id' => self::PAGE ];
		}

		private function serve( string $token, array $query ): string {
			$GLOBALS['pow_test_login_token'] = $token;
			$_GET = $query;
			ob_start();
			( new Handoff( $this->store ) )->handle();
			return (string) ob_get_clean();
		}

		public function test_after_the_return_a_browser_is_sent_to_the_sent_state_on_the_chosen_page(): void {
			self::assertSame( 'https://shop.example.test/punchout/sent', Handoff::sent_url(), 'No page chosen: the plain Sent page' );
			$this->choose_page();
			self::assertSame( 'https://shop.example.test/visit/?pow_state=sent', Handoff::sent_url() );
			self::assertSame( 'https://shop.example.test/visit/?pow_state=sent', Handoff::after_return( $this->store->rows[42], 'login-a' ), 'Nothing kept for this browser any more' );
			Handoff::remember( 'login-a', 'wc_order_k1', 42, 501, self::MARKUP );
			self::assertSame( Handoff::url( 501, 'wc_order_k1' ), Handoff::after_return( $this->store->rows[42], 'login-a' ), 'A page never served is still served first' );
		}

		public function test_a_handoff_already_served_sends_the_browser_to_sent(): void {
			Handoff::remember( 'login-a', 'wc_order_k1', 42, 501, self::MARKUP );
			self::assertSame( self::MARKUP, $this->serve( 'login-a', [ 'order' => '501', 'key' => 'wc_order_k1' ] ) );
			$handoff = new Handoff( $this->store );
			self::assertNull( $handoff->spent_target( 501, 'wc_order_k1' ), 'No page chosen: the plain no-form page' );
			$this->choose_page();
			self::assertSame( 'https://shop.example.test/visit/?pow_state=sent', $handoff->spent_target( 501, 'wc_order_k1' ) );
		}

		public function test_a_returned_order_whose_page_never_reached_a_browser_is_not_sent(): void {
			$this->choose_page();
			self::assertSame( 'https://shop.example.test/visit/?pow_state=not-sent', ( new Handoff( $this->store ) )->spent_target( 501, 'wc_order_k1' ) );
		}

		public function test_anything_else_is_session_ended(): void {
			$this->choose_page();
			$handoff = new Handoff( $this->store );
			$ended   = 'https://shop.example.test/visit/?pow_state=ended';
			self::assertSame( $ended, $handoff->spent_target( 501, 'wc_order_k2' ), 'Another key names nothing' );
			self::assertSame( $ended, $handoff->spent_target( 777, 'wc_order_k1' ), 'No such order' );
			self::assertSame( $ended, $handoff->spent_target( 0, '' ) );
			$this->store->rows[42] = Session::from_row( [ 'id' => 42, 'partner_id' => 5, 'user_id' => 20, 'status' => 'active', 'wp_session_token' => 'login-a', 'order_id' => 0 ] );
			self::assertSame( $ended, $handoff->spent_target( 501, 'wc_order_k1' ), 'A visit that has not returned with this order' );
			$GLOBALS['pow_test_get_order_errors'][501] = new RuntimeException( 'Order lookup failed.' );
			try {
				self::assertSame( $ended, $handoff->spent_target( 501, 'wc_order_k1' ), 'A lookup that fails proves nothing' );
			} finally {
				unset( $GLOBALS['pow_test_get_order_errors'] );
			}
		}

		public function test_only_a_hash_of_the_order_key_marks_a_served_page(): void {
			Handoff::remember( 'login-a', 'wc_order_k1', 42, 501, self::MARKUP );
			$this->serve( 'login-a', [ 'order' => '501', 'key' => 'wc_order_k1' ] );
			foreach ( array_keys( $GLOBALS['pow_test_transients'] ?? [] ) as $name ) {
				self::assertStringNotContainsString( 'wc_order_k1', $name );
			}
			self::assertCount( 1, $GLOBALS['pow_test_transients'] ?? [], 'The served mark, and the page is gone' );
		}

		public function test_without_a_state_page_a_spent_handoff_still_answers_the_plain_page(): void {
			$page = $this->serve( 'login-a', [ 'order' => '501', 'key' => 'wc_order_k1' ] );
			self::assertStringContainsString( 'This page has already been used or has expired.', $page );
			self::assertStringNotContainsString( 'pow-handoff-form', $page );
		}
	}
}
