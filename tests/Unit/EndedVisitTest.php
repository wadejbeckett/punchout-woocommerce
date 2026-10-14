<?php
/**
 * A visit that has ended, seen from its browser (0.5.0). When a visit ends without the browser leaving
 * (its time ran out, an administrator ended it, the store was switched off), its login cookie stays in the browser
 * but no longer signs anyone in, so the next page used to open silently as a guest. With a state page chosen, a page
 * request from that browser is sent to the state the visit ended in instead: Session ended (or Not sent after Cancel
 * and return, Sent after a return), and the dead cookie is cleared, so it happens once. A return whose page was never
 * served still gets that page first (the cookie stays: the page needs it). Nothing else is touched: a signed-in
 * request, a request inside a live visit, AJAX, a POST, the state page itself, a cookie that names no visit, and
 * every request while no state page is chosen.
 *
 * @package POW
 */

declare( strict_types = 1 );

namespace {
	use PHPUnit\Framework\TestCase;
	use POW\Checkout\Handoff;
	use POW\Http\EndedVisit;
	use POW\Sessions\Session;
	use POW\Settings;

	// The WordPress login cookie, read as WordPress reads it (pluggable.php), and the account its user name names.
	if ( ! defined( 'LOGGED_IN_COOKIE' ) ) { define( 'LOGGED_IN_COOKIE', 'wordpress_logged_in_pow_test' ); }
	if ( ! function_exists( 'wp_parse_auth_cookie' ) ) {
		function wp_parse_auth_cookie( $cookie = '', $scheme = '' ) {
			if ( empty( $cookie ) ) {
				if ( 'logged_in' !== $scheme || empty( $_COOKIE[ LOGGED_IN_COOKIE ] ) ) { return false; }
				$cookie = $_COOKIE[ LOGGED_IN_COOKIE ];
			}
			$elements = explode( '|', $cookie );
			if ( 4 !== count( $elements ) ) { return false; }
			[ $username, $expiration, $token, $hmac ] = $elements;
			return compact( 'username', 'expiration', 'token', 'hmac', 'scheme' );
		}
	}
	// WooCommerce's checkout answer, read from the same flag the route guard suite sets (whichever file defines it first).
	if ( ! function_exists( 'is_checkout' ) ) { function is_checkout(): bool { return (bool) ( $GLOBALS['pow_route_guard_test']['checkout'] ?? false ); } }
	if ( ! function_exists( 'is_wc_endpoint_url' ) ) { function is_wc_endpoint_url( string $endpoint = '' ): bool { return '' !== $endpoint && $endpoint === ( $GLOBALS['pow_route_guard_test']['endpoint'] ?? '' ); } }
	if ( ! function_exists( 'get_user_by' ) ) {
		function get_user_by( string $field, $value ) { return 'login' === $field && 'buyer-account' === $value ? (object) [ 'ID' => 20 ] : false; }
	}

	/** Visit rows by login. */
	final class EndedVisitStore extends POW\Sessions\Store {
		/** @var array<int, array<string, mixed>> */
		public array $rows = [];
		public bool $broken = false;
		public function __construct() {}
		public function find( int $id ): ?Session { return isset( $this->rows[ $id ] ) ? Session::from_row( $this->rows[ $id ] ) : null; }
		public function find_login_any_status( int $user_id, string $wp_session_token ): ?Session {
			if ( $this->broken ) { throw new RuntimeException( 'Session lookup failed.' ); }
			foreach ( $this->rows as $row ) {
				if ( $row['user_id'] === $user_id && $row['wp_session_token'] === $wp_session_token ) { return Session::from_row( $row ); }
			}
			return null;
		}
	}

	/** The visit's order, as the handoff reads its key. */
	final class EndedVisitOrder extends WC_Order {
		public function get_order_key(): string { return 'wc_order_k7'; }
	}

	final class EndedVisitTest extends TestCase {
		private const PAGE = 314;
		private const GLOBALS = [ 'pow_test_options', 'pow_test_posts', 'pow_test_query', 'pow_test_current_user_id', 'pow_test_doing_ajax', 'pow_test_transients', 'pow_test_orders', 'pow_test_login_token', 'pow_test_wc', 'pow_route_guard_test' ];
		private array $saved = [];
		private array $request = [];
		private EndedVisitStore $store;

		protected function setUp(): void {
			foreach ( self::GLOBALS as $key ) {
				$this->saved[ $key ] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null ];
				unset( $GLOBALS[ $key ] );
			}
			$this->request = [ $_COOKIE, $_SERVER['REQUEST_METHOD'] ?? null ];
			$_COOKIE = [ LOGGED_IN_COOKIE => 'buyer-account|1999999999|login-a|hmac' ];
			$_SERVER['REQUEST_METHOD'] = 'GET';
			$GLOBALS['pow_test_posts'][ self::PAGE ] = [ 'status' => 'publish', 'permalink' => 'https://shop.example.test/visit/' ];
			$GLOBALS['pow_test_options'][ Settings::OPTION_KEY ] = [ 'state_page_id' => self::PAGE ];
			$GLOBALS['pow_test_query'] = [ 'page' => 7 ];
			$this->store = new EndedVisitStore();
			$this->store->rows[42] = [ 'id' => 42, 'partner_id' => 5, 'user_id' => 20, 'status' => 'expired', 'wp_session_token' => 'login-a', 'order_id' => 0, 'expires' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ];
		}

		protected function tearDown(): void {
			[ $_COOKIE, $method ] = $this->request;
			if ( null === $method ) { unset( $_SERVER['REQUEST_METHOD'] ); } else { $_SERVER['REQUEST_METHOD'] = $method; }
			foreach ( $this->saved as $key => [ $existed, $value ] ) {
				if ( $existed ) { $GLOBALS[ $key ] = $value; } else { unset( $GLOBALS[ $key ] ); }
			}
		}

		/** The guard as this request sees it, inside the visit $visit (null: none). */
		private function guard( ?Session $visit = null ): EndedVisit {
			$plugin = ( new ReflectionClass( POW\Plugin::class ) )->newInstanceWithoutConstructor();
			( new ReflectionProperty( $plugin, 'settings' ) )->setValue( $plugin, new Settings() );
			( new ReflectionProperty( $plugin, 'session_resolved' ) )->setValue( $plugin, true );
			( new ReflectionProperty( $plugin, 'current_session' ) )->setValue( $plugin, $visit );
			return new EndedVisit( $plugin, $this->store );
		}

		public function test_a_page_from_the_browser_of_an_ended_visit_goes_to_session_ended_once(): void {
			self::assertSame( [ 'https://shop.example.test/visit/?pow_state=ended', true ], $this->guard()->target(), 'The dead cookie is cleared with the redirect' );
		}

		public function test_the_state_follows_how_the_visit_ended(): void {
			$this->store->rows[42]['status'] = 'closed';
			self::assertSame( [ 'https://shop.example.test/visit/?pow_state=not-sent', true ], $this->guard()->target(), 'Cancel and return' );
			$this->store->rows[42] = [ 'status' => 'returned', 'order_id' => 501 ] + $this->store->rows[42];
			self::assertSame( [ 'https://shop.example.test/visit/?pow_state=sent', true ], $this->guard()->target(), 'A return whose page was served' );
			$this->store->rows[42] = [ 'status' => 'active', 'expires' => gmdate( 'Y-m-d H:i:s', time() - 5 ) ] + $this->store->rows[42];
			self::assertSame( [ 'https://shop.example.test/visit/?pow_state=ended', true ], $this->guard()->target(), 'Its time ran out before the clean-up marked it' );
		}

		public function test_a_return_whose_page_was_never_served_gets_that_page_first_and_keeps_its_cookie(): void {
			$this->store->rows[42] = [ 'status' => 'returned', 'order_id' => 501 ] + $this->store->rows[42];
			$GLOBALS['pow_test_orders'][501] = new EndedVisitOrder( 501 );
			Handoff::remember( 'login-a', 'wc_order_k7', 42, 501, '<form id="pow-handoff-form"></form>' );
			self::assertSame( [ Handoff::url( 501, 'wc_order_k7' ), false ], $this->guard()->target() );
		}

		public function test_everything_else_is_left_alone(): void {
			$this->store->rows[42]['expires'] = gmdate( 'Y-m-d H:i:s', time() + 600 );
			$this->store->rows[42]['status']  = 'active';
			self::assertNull( $this->guard()->target(), 'A visit that is still live' );
			$this->store->rows[42]['status'] = 'expired';

			self::assertNull( $this->guard( Session::from_row( $this->store->rows[42] ) )->target(), 'A request inside a visit' );
			$GLOBALS['pow_test_current_user_id'] = 20;
			self::assertNull( $this->guard()->target(), 'A signed-in request' );
			unset( $GLOBALS['pow_test_current_user_id'] );
			$GLOBALS['pow_test_doing_ajax'] = true;
			self::assertNull( $this->guard()->target(), 'AJAX' );
			unset( $GLOBALS['pow_test_doing_ajax'] );
			$_SERVER['REQUEST_METHOD'] = 'POST';
			self::assertNull( $this->guard()->target(), 'A POST' );
			$_SERVER['REQUEST_METHOD'] = 'HEAD';
			self::assertNotNull( $this->guard()->target(), 'A HEAD is a page request' );
			$_SERVER['REQUEST_METHOD'] = 'GET';
			$GLOBALS['pow_test_query'] = [ 'page' => self::PAGE ];
			self::assertNull( $this->guard()->target(), 'The state page itself' );
			$GLOBALS['pow_test_query'] = [ 'page' => 7 ];
			$_COOKIE = [ LOGGED_IN_COOKIE => 'buyer-account|1999999999|login-z|hmac' ];
			self::assertNull( $this->guard()->target(), 'A login no visit recorded (the account holder\'s own)' );
			$_COOKIE = [];
			self::assertNull( $this->guard()->target(), 'No cookie' );
			$_COOKIE = [ LOGGED_IN_COOKIE => 'buyer-account|1999999999|login-a|hmac' ];
			$this->store->broken = true;
			self::assertNull( $this->guard()->target(), 'A lookup that cannot answer proves nothing' );
			$this->store->broken = false;
			$GLOBALS['pow_test_options'][ Settings::OPTION_KEY ] = [];
			self::assertNull( $this->guard()->target(), 'No state page: nothing changes' );
		}

		/** The sent marker a served handoff page leaves in its browser, for visit $id. */
		private static function marker( int $id ): string {
			$method = new ReflectionMethod( Handoff::class, 'marker' );
			$method->setAccessible( true );
			return (string) $method->invoke( null, $id );
		}

		/**
		 * 0.5.3: after Submit the handoff page clears the login cookie and posts the cart, and the browser's Back skips
		 * that self-posting page, landing on checkout. With the cart already emptied WooCommerce sent the browser on to
		 * an empty guest cart. The cart and checkout, asked for by the browser the cart was sent from (the sent marker
		 * names a returned visit) while its cart is empty, now go to the Sent state, so the post-Submit state cannot be
		 * entered again; with or without a state page.
		 */
		public function test_back_after_submit_lands_on_sent_not_on_the_cart(): void {
			$this->store->rows[43] = [ 'id' => 43, 'partner_id' => 5, 'user_id' => 20, 'status' => 'returned', 'wp_session_token' => 'login-b', 'order_id' => 502, 'expires' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ];
			$_COOKIE = [ Handoff::SENT_MARKER => self::marker( 43 ) ];
			WC()->cart = new class() { public bool $empty = true; public function is_empty(): bool { return $this->empty; } };
			$GLOBALS['pow_route_guard_test']['checkout'] = true;
			$GLOBALS['pow_test_query'] = [ 'page' => 9 ];
			self::assertSame( [ 'https://shop.example.test/visit/?pow_state=sent', false ], $this->guard()->target(), 'Back to checkout: the Sent state page' );
			$GLOBALS['pow_route_guard_test']['checkout'] = false;
			$GLOBALS['pow_test_query'] = [ 'page' => 8, 'cart' => true ];
			self::assertSame( [ 'https://shop.example.test/visit/?pow_state=sent', false ], $this->guard()->target(), 'and the cart' );
			$GLOBALS['pow_test_options'][ Settings::OPTION_KEY ] = [];
			self::assertSame( [ 'https://shop.example.test/punchout/sent', false ], $this->guard()->target(), 'Without a state page, the plain Sent page' );
			$GLOBALS['pow_test_options'][ Settings::OPTION_KEY ] = [ 'state_page_id' => self::PAGE ];

			WC()->cart->empty = false;
			self::assertNull( $this->guard()->target(), 'A cart filled since (the browser shops on as a guest) opens' );
			WC()->cart->empty = true;
			$GLOBALS['pow_test_query'] = [ 'page' => 7 ];
			self::assertNull( $this->guard()->target(), 'Any other page opens as before' );
			$GLOBALS['pow_test_query'] = [ 'page' => 8, 'cart' => true ];
			$_COOKIE = [ Handoff::SENT_MARKER => '43.' . str_repeat( '0', 32 ) ];
			self::assertNull( $this->guard()->target(), 'A forged marker proves nothing' );
			$this->store->rows[43]['status'] = 'closed';
			$_COOKIE = [ Handoff::SENT_MARKER => self::marker( 43 ) ];
			self::assertNull( $this->guard()->target(), 'A visit that did not return' );
			$this->store->rows[43]['status'] = 'returned';
			$GLOBALS['pow_test_current_user_id'] = 20;
			self::assertNull( $this->guard()->target(), 'A signed-in request (the account holder\'s own login)' );
			unset( $GLOBALS['pow_test_current_user_id'] );
			$_SERVER['REQUEST_METHOD'] = 'POST';
			self::assertNull( $this->guard()->target(), 'A POST (a late Submit is Checkout\\LateSubmit\'s)' );
			$_SERVER['REQUEST_METHOD'] = 'GET';
			self::assertSame( [ 'https://shop.example.test/visit/?pow_state=sent', false ], $this->guard()->target(), 'The marker is kept: Back again lands there again' );

			// The browser's own later guest order: WooCommerce's order-received and order-pay pages are checkout pages too.
			$GLOBALS['pow_test_query'] = [ 'page' => 9 ];
			$GLOBALS['pow_route_guard_test']['checkout'] = true;
			foreach ( [ 'order-received', 'order-pay' ] as $endpoint ) {
				$GLOBALS['pow_route_guard_test']['endpoint'] = $endpoint;
				self::assertNull( $this->guard()->target(), $endpoint . ' is never replaced by the Sent state' );
			}
			$GLOBALS['pow_route_guard_test']['endpoint'] = '';
		}

		/** A later visit, or a Cancel and return, in the same browser is not the visit that sent: the marker goes. */
		public function test_a_new_visit_or_cancel_and_return_drops_the_sent_marker(): void {
			$GLOBALS['pow_test_cookies_set'] = [];
			$_COOKIE = [ Handoff::SENT_MARKER => self::marker( 43 ) ];
			Handoff::forget_marker();
			self::assertArrayNotHasKey( Handoff::SENT_MARKER, $_COOKIE, 'Gone for the rest of this request' );
			$set = $GLOBALS['pow_test_cookies_set'][0] ?? [];
			self::assertSame( [ Handoff::SENT_MARKER, '' ], [ $set['name'] ?? null, $set['value'] ?? null ], 'and expired in the browser' );
			self::assertTrue( ( $set['expire'] ?? PHP_INT_MAX ) < time() );
			$GLOBALS['pow_test_cookies_set'] = [];
			Handoff::forget_marker();
			self::assertSame( [], $GLOBALS['pow_test_cookies_set'], 'Nothing to drop: no cookie is sent' );
			$root = dirname( __DIR__, 2 );
			self::assertStringContainsString( 'Handoff::forget_marker()', (string) file_get_contents( $root . '/includes/Http/StartEndpoint.php' ), 'A start link that signs a new visit in drops it' );
			self::assertGreaterThan( 1, substr_count( (string) file_get_contents( $root . '/includes/Http/ReturnEndpoint.php' ), 'Handoff::forget_marker()' ), 'Cancel and return (and its replay) drop it' );
			unset( $GLOBALS['pow_test_cookies_set'] );
		}

		public function test_whether_a_visit_is_over_and_in_which_state(): void {
			$row = fn( array $over ): Session => Session::from_row( $over + $this->store->rows[42] );
			$future = gmdate( 'Y-m-d H:i:s', time() + 600 );
			self::assertFalse( EndedVisit::over( $row( [ 'status' => 'active', 'expires' => $future ] ) ) );
			self::assertTrue( EndedVisit::over( $row( [ 'status' => 'active', 'expires' => null ] ) ), 'No expiry proves nothing live' );
			foreach ( [ 'returned' => 'sent', 'closed' => 'not-sent', 'expired' => 'ended', 'pending' => 'ended' ] as $status => $state ) {
				self::assertTrue( EndedVisit::over( $row( [ 'status' => $status, 'expires' => $future ] ) ), $status );
				self::assertSame( $state, EndedVisit::state( $row( [ 'status' => $status ] ) ), $status );
			}
		}
	}
}
