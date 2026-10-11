<?php
/**
 * A Submit that arrives after the visit has returned (0.5.0, ticket 74). The visit's login has ended, so the request
 * is a guest and WooCommerce would refuse it at its nonce check ("your session has expired"). PunchOut catches it
 * first, on wc_ajax_checkout and on the form post, and sends the browser to the Sent state with no auto-posting form,
 * or to the visit's own handoff while that page has never been served. The proof that this browser ran the visit is
 * its ended login cookie, or the marker the handoff left when it cleared that cookie.
 *
 * @package POW
 */

declare( strict_types = 1 );

namespace {
	// The answers that end a request (LateSubmitAnswer) come from the shared checkout seams.
	require_once dirname( __DIR__ ) . '/Support/checkout-hooks.php';

	use PHPUnit\Framework\TestCase;
	use POW\Checkout\Handoff;
	use POW\Checkout\LateSubmit;
	use POW\Checkout\LateSubmitAnswer;
	use POW\Sessions\Session;

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

	/** Visit rows by id and by login. */
	final class LateSubmitStore extends POW\Sessions\Store {
		/** @var array<int, array<string, mixed>> */
		public array $rows = [];
		public bool $broken = false;
		public function __construct() {}
		public function find( int $id ): ?Session {
			if ( $this->broken ) { throw new RuntimeException( 'Session lookup failed.' ); }
			return isset( $this->rows[ $id ] ) ? Session::from_row( $this->rows[ $id ] ) : null;
		}
		public function find_login_any_status( int $user_id, string $wp_session_token ): ?Session {
			if ( $this->broken ) { throw new RuntimeException( 'Session lookup failed.' ); }
			foreach ( $this->rows as $row ) {
				if ( $row['user_id'] === $user_id && $row['wp_session_token'] === $wp_session_token ) { return Session::from_row( $row ); }
			}
			return null;
		}
	}

	/** The visit's order, as the Sent decision reads its key. */
	final class LateSubmitOrder extends WC_Order {
		public function get_order_key(): string { return 'wc_order_k1'; }
	}

	final class CheckoutLateSubmitTest extends TestCase {
		private const GLOBALS = [ 'pow_test_transients', 'pow_test_orders', 'pow_payment_method_hooks', 'pow_test_cookies_set', 'pow_test_login_token', 'pow_test_current_user_id' ];
		private array $saved = [];
		private array $request = [];
		private LateSubmitStore $store;

		protected function setUp(): void {
			foreach ( self::GLOBALS as $key ) {
				$this->saved[ $key ] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null ];
				unset( $GLOBALS[ $key ] );
			}
			$this->request = [ $_POST, $_COOKIE ];
			$_POST   = [ 'payment_method' => 'punchout' ];
			$_COOKIE = [ LOGGED_IN_COOKIE => 'buyer-account|1999999999|login-a|hmac' ];
			$this->store = new LateSubmitStore();
			$this->store->rows[42] = [ 'id' => 42, 'partner_id' => 5, 'user_id' => 20, 'status' => 'returned', 'wp_session_token' => 'login-a', 'order_id' => 501 ];
			$GLOBALS['pow_test_orders'][501] = new LateSubmitOrder( 501 );
		}

		protected function tearDown(): void {
			[ $_POST, $_COOKIE ] = $this->request;
			foreach ( $this->saved as $key => [ $existed, $value ] ) {
				if ( $existed ) { $GLOBALS[ $key ] = $value; } else { unset( $GLOBALS[ $key ] ); }
			}
		}

		/** The catch as this request sees it, inside the visit $visit (null: none; false: the lookup cannot answer). */
		private function late( Session|false|null $visit = null ): LateSubmit {
			$plugin = ( new ReflectionClass( POW\Plugin::class ) )->newInstanceWithoutConstructor();
			( new ReflectionProperty( $plugin, 'settings' ) )->setValue( $plugin, new POW\Settings() );
			if ( false === $visit ) {
				$GLOBALS['pow_test_current_user_id'] = 20;
				$GLOBALS['pow_test_login_token']     = 'login-b';
				( new ReflectionProperty( $plugin, 'sessions' ) )->setValue( $plugin, new LateSubmitBrokenStore() );
			} else {
				( new ReflectionProperty( $plugin, 'session_resolved' ) )->setValue( $plugin, true );
				( new ReflectionProperty( $plugin, 'current_session' ) )->setValue( $plugin, $visit );
			}
			return new LateSubmit( $plugin, $this->store );
		}

		/** The marker the handoff leaves for visit 42, as the browser sends it back. */
		private function marker(): string {
			$this->store->rows[43] = [ 'id' => 43, 'partner_id' => 5, 'user_id' => 20, 'status' => 'returned', 'wp_session_token' => 'login-c', 'order_id' => 601 ];
			Handoff::remember( 'login-c', 'wc_order_k9', 43, 601, '<form id="pow-handoff-form"></form>' );
			$GLOBALS['pow_test_login_token'] = 'login-c';
			$_GET = [ 'order' => '601', 'key' => 'wc_order_k9' ];
			$server = $_SERVER['REQUEST_METHOD'] ?? null;
			$_SERVER['REQUEST_METHOD'] = 'GET';
			ob_start();
			( new Handoff( $this->store ) )->handle();
			ob_end_clean();
			if ( null === $server ) { unset( $_SERVER['REQUEST_METHOD'] ); } else { $_SERVER['REQUEST_METHOD'] = $server; }
			$_GET = [];
			return (string) ( $GLOBALS['pow_test_cookies_set'][0]['value'] ?? '' );
		}

		public function test_with_the_ended_login_cookie_a_submit_goes_to_the_sent_state(): void {
			self::assertSame( Handoff::sent_url(), $this->late()->target() );
		}

		public function test_while_the_visits_handoff_has_never_been_served_the_submit_goes_there(): void {
			Handoff::remember( 'login-a', 'wc_order_k1', 42, 501, '<form id="pow-handoff-form"></form>' );
			self::assertSame( Handoff::url( 501, 'wc_order_k1' ), $this->late()->target(), 'The first answer never reached a browser: the page is still served once' );
		}

		public function test_after_the_handoff_cleared_the_login_cookie_its_marker_is_the_proof(): void {
			$marker  = $this->marker();
			$_COOKIE = [ Handoff::SENT_MARKER => $marker ];
			self::assertSame( Handoff::sent_url(), $this->late()->target() );
			$_COOKIE = [ Handoff::SENT_MARKER => '43.' . str_repeat( 'a', 32 ) ];
			self::assertNull( $this->late()->target(), 'A forged marker proves nothing' );
		}

		public function test_anything_that_is_not_a_late_submit_is_left_to_woocommerce(): void {
			$this->store->rows[42]['status'] = 'expired';
			self::assertNull( $this->late()->target(), 'A visit that ended without a return was never sent' );
			$this->store->rows[42]['status'] = 'returned';

			self::assertNull( $this->late( Session::from_row( $this->store->rows[42] + [ 'expires' => gmdate( 'Y-m-d H:i:s', time() + 60 ) ] ) )->target(), 'A request still inside a visit is a Submit' );
			self::assertNull( $this->late( false )->target(), 'A visit lookup that cannot answer is not a proof' );

			$_POST = [ 'payment_method' => 'bacs' ];
			self::assertNull( $this->late()->target(), 'Only the PunchOut method' );
			$_POST = [ 'payment_method' => 'punchout' ];

			$_COOKIE = [ LOGGED_IN_COOKIE => 'buyer-account|1999999999|login-z|hmac' ];
			self::assertNull( $this->late()->target(), 'Another login proves nothing' );
			$_COOKIE = [];
			self::assertNull( $this->late()->target(), 'No cookie, no proof' );

			$_COOKIE = [ LOGGED_IN_COOKIE => 'buyer-account|1999999999|login-a|hmac' ];
			$this->store->broken = true;
			self::assertNull( $this->late()->target() );
		}

		/** 0.5.0 (ticket 77): with a state page chosen, a Submit from a visit that ended without sending goes there too. */
		public function test_with_a_state_page_a_submit_after_the_visit_ended_or_left_without_sending_goes_there(): void {
			$saved = [ $GLOBALS['pow_test_options'] ?? null, $GLOBALS['pow_test_posts'] ?? null ];
			$GLOBALS['pow_test_posts'][314] = [ 'status' => 'publish', 'permalink' => 'https://shop.example.test/visit/' ];
			$GLOBALS['pow_test_options'][ POW\Settings::OPTION_KEY ] = [ 'state_page_id' => 314 ];
			try {
				self::assertSame( 'https://shop.example.test/visit/?pow_state=sent', $this->late()->target(), 'Returned: Sent, on the page' );
				$this->store->rows[42]['status'] = 'expired';
				self::assertSame( 'https://shop.example.test/visit/?pow_state=ended', $this->late()->target() );
				$this->store->rows[42]['status'] = 'closed';
				self::assertSame( 'https://shop.example.test/visit/?pow_state=not-sent', $this->late()->target(), 'Cancel and return sent nothing' );
				$this->store->rows[42] = [ 'status' => 'active', 'expires' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ] + $this->store->rows[42];
				self::assertSame( 'https://shop.example.test/visit/?pow_state=ended', $this->late()->target(), 'Its time ran out before the clean-up marked it' );
				$this->store->rows[42]['expires'] = gmdate( 'Y-m-d H:i:s', time() + 600 );
				self::assertNull( $this->late()->target(), 'A visit that is still live is not this rule\'s' );
			} finally {
				[ $options, $posts ] = $saved;
				if ( null === $options ) { unset( $GLOBALS['pow_test_options'] ); } else { $GLOBALS['pow_test_options'] = $options; }
				if ( null === $posts ) { unset( $GLOBALS['pow_test_posts'] ); } else { $GLOBALS['pow_test_posts'] = $posts; }
			}
		}

		public function test_the_checkout_script_gets_success_with_that_address(): void {
			try {
				$this->late()->catch_ajax();
				self::fail( 'The request ends with an answer' );
			} catch ( LateSubmitAnswer $answer ) {
				self::assertSame( 'json', $answer->kind );
				self::assertSame( [ 'result' => 'success', 'redirect' => Handoff::sent_url() ], $answer->payload );
			}
			$_POST = [ 'payment_method' => 'bacs' ];
			$this->late()->catch_ajax();
			self::assertTrue( true, 'Anything else carries on to WooCommerce' );
		}

		public function test_a_form_post_without_the_script_is_redirected_only_when_it_is_a_place_order(): void {
			$this->late()->catch_form();
			$_POST['woocommerce_checkout_place_order'] = 'Submit';
			try {
				$this->late()->catch_form();
				self::fail( 'The request ends with a redirect' );
			} catch ( LateSubmitAnswer $answer ) {
				self::assertSame( [ 'redirect', [ Handoff::sent_url(), 303 ] ], [ $answer->kind, $answer->payload ] );
			}
		}

		public function test_it_runs_before_woocommerces_checkout_and_its_nonce_check(): void {
			$late = $this->late();
			$late->register();
			self::assertSame( [ [ 1, [ $late, 'catch_ajax' ], 1 ] ], $GLOBALS['pow_payment_method_hooks']['wc_ajax_checkout'], 'WooCommerce answers wc-ajax=checkout at 10' );
			self::assertSame( [ [ 19, [ $late, 'catch_form' ], 1 ] ], $GLOBALS['pow_payment_method_hooks']['wp_loaded'], 'WooCommerce handles the form post at wp_loaded 20' );
		}
	}

	/** A session store whose visit lookup cannot answer. */
	final class LateSubmitBrokenStore extends POW\Sessions\Store {
		public function __construct() {}
		public function find_for_login( int $user_id, string $wp_session_token, array $statuses = [ Session::ACTIVE ] ): ?Session { throw new RuntimeException( 'Session lookup failed.' ); }
	}
}
