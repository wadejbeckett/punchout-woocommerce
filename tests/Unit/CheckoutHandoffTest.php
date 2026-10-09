<?php
/**
 * The GET handoff after checkout (0.5.0): WooCommerce's checkout answer can only redirect, so Submit keeps the
 * prepared auto-posting page and the browser fetches it from /punchout/handoff. It is served once, only to the
 * browser whose visit won (its login token) and only for that order (its order key), and serving it ends the
 * login in that browser.
 *
 * @package POW
 */

declare( strict_types = 1 );

namespace {
	use PHPUnit\Framework\TestCase;
	use POW\Checkout\Handoff;
	use POW\Sessions\Session;

	/** An order as the handoff reads it: its key. */
	final class CheckoutHandoffOrder extends WC_Order {
		public function __construct( int $id, private string $key ) { parent::__construct( $id ); }
		public function get_order_key(): string { return $this->key; }
	}

	/** Visit rows by id, as the handoff reads them back. */
	final class CheckoutHandoffStore extends POW\Sessions\Store {
		/** @var array<int, Session> */
		public array $rows = [];
		public function find( int $id ): ?Session { return $this->rows[ $id ] ?? null; }
	}

	final class CheckoutHandoffTest extends TestCase {
		private const MARKUP = '<form method="post" action="https://buyer.example.com/return" id="pow-handoff-form"><input type="hidden" name="cxml-base64" value="PD94"></form>';
		private array $saved = [];
		private array $server = [];
		private CheckoutHandoffStore $store;

		protected function setUp(): void {
			foreach ( [ 'pow_test_transients', 'pow_test_login_token', 'pow_test_cookies_cleared', 'pow_test_environment_type', 'pow_test_transient_delete_lost', 'pow_test_cookies_set', 'pow_test_orders' ] as $key ) {
				$this->saved[ $key ] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null ];
				unset( $GLOBALS[ $key ] );
			}
			$this->server = [ $_SERVER['REQUEST_METHOD'] ?? null, $_GET ];
			$_SERVER['REQUEST_METHOD'] = 'GET';
			$_GET = [];
			$this->store = new CheckoutHandoffStore();
		}

		protected function tearDown(): void {
			foreach ( $this->saved as $key => [ $existed, $value ] ) {
				if ( $existed ) { $GLOBALS[ $key ] = $value; } else { unset( $GLOBALS[ $key ] ); }
			}
			[ $method, $get ] = $this->server;
			if ( null === $method ) { unset( $_SERVER['REQUEST_METHOD'] ); } else { $_SERVER['REQUEST_METHOD'] = $method; }
			$_GET = $get;
		}

		private function visit( string $status = 'returned', string $token = 'login-a', int $order_id = 501 ): Session {
			return Session::from_row( [ 'id' => 42, 'partner_id' => 5, 'user_id' => 20, 'status' => $status, 'wp_session_token' => $token, 'order_id' => $order_id ] );
		}

		/** What the handoff route answers for this browser (login token) and query. */
		private function serve( string $token, array $query ): string {
			$GLOBALS['pow_test_login_token'] = $token;
			$_GET = $query;
			ob_start();
			( new Handoff( $this->store ) )->handle();
			return (string) ob_get_clean();
		}

		public function test_the_kept_page_is_found_only_for_the_same_login_and_order_and_only_once(): void {
			self::assertTrue( Handoff::remember( 'login-a', 'wc_order_k1', 42, 501, self::MARKUP ) );
			self::assertNull( Handoff::take( 'login-b', 'wc_order_k1' ), 'Another browser of the same account gets nothing' );
			self::assertNull( Handoff::take( 'login-a', 'wc_order_k2' ), 'Another order gets nothing' );
			self::assertSame( [ 'session_id' => 42, 'order_id' => 501, 'markup' => self::MARKUP ], Handoff::take( 'login-a', 'wc_order_k1' ) );
			self::assertNull( Handoff::take( 'login-a', 'wc_order_k1' ), 'Served once' );
		}

		public function test_nothing_is_kept_without_a_login_an_order_key_or_a_page(): void {
			self::assertFalse( Handoff::remember( '', 'wc_order_k1', 42, 501, self::MARKUP ) );
			self::assertFalse( Handoff::remember( 'login-a', '', 42, 501, self::MARKUP ) );
			self::assertFalse( Handoff::remember( 'login-a', 'wc_order_k1', 42, 501, '  ' ) );
			self::assertSame( [], $GLOBALS['pow_test_transients'] ?? [] );
		}

		public function test_only_a_hash_of_the_login_and_the_order_key_names_the_kept_page(): void {
			Handoff::remember( 'login-a', 'wc_order_k1', 42, 501, self::MARKUP );
			$names = array_keys( $GLOBALS['pow_test_transients'] );
			self::assertCount( 1, $names );
			self::assertStringNotContainsString( 'login-a', $names[0] );
			self::assertStringNotContainsString( 'wc_order_k1', $names[0] );
		}

		public function test_the_address_names_the_order_and_its_key_under_the_punchout_path(): void {
			self::assertSame( 'https://shop.example.test/punchout/handoff?order=501&key=wc_order_k1', Handoff::url( 501, 'wc_order_k1' ) );
		}

		public function test_the_winning_browser_gets_the_page_once_and_its_login_cookie_is_cleared(): void {
			$this->store->rows[42] = $this->visit();
			Handoff::remember( 'login-a', 'wc_order_k1', 42, 501, self::MARKUP );
			self::assertSame( self::MARKUP, $this->serve( 'login-a', [ 'order' => '501', 'key' => 'wc_order_k1' ] ) );
			self::assertSame( [ true ], $GLOBALS['pow_test_cookies_cleared'] ?? [], 'Serving the handoff ends the login in this browser' );
			$again = $this->serve( 'login-a', [ 'order' => '501', 'key' => 'wc_order_k1' ] );
			self::assertStringNotContainsString( 'pow-handoff-form', $again, 'A second fetch never posts the cart again' );
			self::assertStringNotContainsString( 'cxml', $again );
		}

		public function test_a_visit_that_has_not_returned_or_names_another_login_or_order_gets_no_page(): void {
			foreach ( [ $this->visit( 'active' ), $this->visit( 'returned', 'login-z' ), $this->visit( 'returned', 'login-a', 777 ) ] as $visit ) {
				$this->store->rows[42] = $visit;
				Handoff::remember( 'login-a', 'wc_order_k1', 42, 501, self::MARKUP );
				self::assertStringNotContainsString( 'pow-handoff-form', $this->serve( 'login-a', [ 'order' => '501', 'key' => 'wc_order_k1' ] ) );
			}
			$this->store->rows[42] = $this->visit();
			Handoff::remember( 'login-a', 'wc_order_k1', 42, 501, self::MARKUP );
			self::assertStringNotContainsString( 'pow-handoff-form', $this->serve( 'login-a', [ 'order' => '999', 'key' => 'wc_order_k1' ] ), 'The order in the address must be the kept one' );
		}

		public function test_a_page_another_request_took_first_is_not_served_again(): void {
			Handoff::remember( 'login-a', 'wc_order_k1', 42, 501, self::MARKUP );
			// Two GETs read the kept page at once; the other one's delete removed it first.
			$GLOBALS['pow_test_transient_delete_lost'] = true;
			self::assertNull( Handoff::take( 'login-a', 'wc_order_k1' ), 'Only the request whose delete removed the page may serve it' );
		}

		public function test_whether_a_page_is_still_kept_can_be_asked_without_taking_it(): void {
			self::assertFalse( Handoff::kept( 'login-a', 'wc_order_k1' ) );
			Handoff::remember( 'login-a', 'wc_order_k1', 42, 501, self::MARKUP );
			self::assertTrue( Handoff::kept( 'login-a', 'wc_order_k1' ) );
			self::assertFalse( Handoff::kept( 'login-b', 'wc_order_k1' ), 'Only for the browser it was kept for' );
			self::assertNotNull( Handoff::take( 'login-a', 'wc_order_k1' ), 'Asking did not take it' );
			self::assertFalse( Handoff::kept( 'login-a', 'wc_order_k1' ) );
		}

		public function test_after_a_return_a_browser_goes_to_its_handoff_while_kept_and_else_to_the_sent_state(): void {
			$GLOBALS['pow_test_orders'][501] = new CheckoutHandoffOrder( 501, 'wc_order_k1' );
			$returned = $this->visit();
			Handoff::remember( 'login-a', 'wc_order_k1', 42, 501, self::MARKUP );
			self::assertSame( Handoff::url( 501, 'wc_order_k1' ), Handoff::after_return( $returned, 'login-a' ), 'Never served yet: this is not a second post' );
			self::assertSame( Handoff::sent_url(), Handoff::after_return( $returned, 'login-z' ), 'Another login never reaches the page' );
			Handoff::take( 'login-a', 'wc_order_k1' );
			self::assertSame( Handoff::sent_url(), Handoff::after_return( $returned, 'login-a' ), 'Served once already' );
			self::assertSame( Handoff::sent_url(), Handoff::after_return( $this->visit( 'returned', 'login-a', 0 ), 'login-a' ), 'A return with no order' );
		}

		public function test_the_sent_state_is_a_theme_free_page_with_the_state_classes_and_no_form(): void {
			self::assertSame( 'https://shop.example.test/punchout/sent', Handoff::sent_url() );
			ob_start();
			( new Handoff( $this->store ) )->sent();
			$page = (string) ob_get_clean();
			self::assertStringContainsString( 'class="pow-state pow-state--sent"', $page );
			self::assertStringContainsString( 'class="pow-state__title"', $page );
			self::assertStringContainsString( 'class="pow-state__text"', $page );
			self::assertStringContainsString( 'noindex', $page );
			self::assertStringNotContainsString( '<form', $page );
			self::assertStringNotContainsString( 'approv', strtolower( $page ) );
		}

		public function test_serving_the_handoff_leaves_a_sent_marker_that_names_only_that_visit(): void {
			$this->store->rows[42] = $this->visit();
			Handoff::remember( 'login-a', 'wc_order_k1', 42, 501, self::MARKUP );
			$this->serve( 'login-a', [ 'order' => '501', 'key' => 'wc_order_k1' ] );
			$cookies = array_values( array_filter( $GLOBALS['pow_test_cookies_set'] ?? [], static fn( array $c ): bool => Handoff::SENT_MARKER === $c['name'] ) );
			self::assertCount( 1, $cookies );
			self::assertTrue( $cookies[0]['httponly'] );
			self::assertSame( 42, Handoff::marked_visit( $cookies[0]['value'] ) );
			self::assertSame( 0, Handoff::marked_visit( '43' . substr( $cookies[0]['value'], 2 ) ), 'Another visit id fails the key' );
			self::assertSame( 0, Handoff::marked_visit( '42.forged' ) );
			self::assertSame( 0, Handoff::marked_visit( '' ) );
		}

		public function test_no_marker_without_a_served_page(): void {
			$this->store->rows[42] = $this->visit( 'active' );
			Handoff::remember( 'login-a', 'wc_order_k1', 42, 501, self::MARKUP );
			$this->serve( 'login-a', [ 'order' => '501', 'key' => 'wc_order_k1' ] );
			self::assertSame( [], $GLOBALS['pow_test_cookies_set'] ?? [] );
		}

		public function test_only_a_get_is_answered_and_a_post_leaves_the_page_kept(): void {
			$this->store->rows[42] = $this->visit();
			Handoff::remember( 'login-a', 'wc_order_k1', 42, 501, self::MARKUP );
			$_SERVER['REQUEST_METHOD'] = 'POST';
			self::assertStringNotContainsString( 'pow-handoff-form', $this->serve( 'login-a', [ 'order' => '501', 'key' => 'wc_order_k1' ] ) );
			$_SERVER['REQUEST_METHOD'] = 'GET';
			self::assertSame( self::MARKUP, $this->serve( 'login-a', [ 'order' => '501', 'key' => 'wc_order_k1' ] ) );
		}
	}
}
