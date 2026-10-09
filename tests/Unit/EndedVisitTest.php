<?php
/**
 * A visit that has ended, seen from its browser (0.5.0, ticket 77). When a visit ends without the browser leaving
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
		private const GLOBALS = [ 'pow_test_options', 'pow_test_posts', 'pow_test_query', 'pow_test_current_user_id', 'pow_test_doing_ajax', 'pow_test_transients', 'pow_test_orders', 'pow_test_login_token' ];
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
