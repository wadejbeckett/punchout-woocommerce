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
	require_once dirname( __DIR__ ) . '/Support/checkout-hooks.php';

	use PHPUnit\Framework\TestCase;
	use POW\Checkout\Handoff;
	use POW\Checkout\LateSubmitAnswer;
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
			foreach ( [ 'pow_test_transients', 'pow_test_login_token', 'pow_test_cookies_cleared', 'pow_test_environment_type', 'pow_test_transient_delete_lost', 'pow_test_cookies_set', 'pow_test_orders', 'pow_test_setup_io', 'pow_test_options', 'pow_test_posts', 'pow_test_get_order_errors' ] as $key ) {
				$this->saved[ $key ] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null ];
				unset( $GLOBALS[ $key ] );
			}
			$this->server = [ $_SERVER['REQUEST_METHOD'] ?? null, $_GET, $_COOKIE ];
			$_SERVER['REQUEST_METHOD'] = 'GET';
			$_GET = [];
			$_COOKIE = [];
			$GLOBALS['pow_test_setup_io'] = [ 'headers' => [] ];
			$this->store = new CheckoutHandoffStore();
		}

		protected function tearDown(): void {
			foreach ( $this->saved as $key => [ $existed, $value ] ) {
				if ( $existed ) { $GLOBALS[ $key ] = $value; } else { unset( $GLOBALS[ $key ] ); }
			}
			[ $method, $get, $cookie ] = $this->server;
			if ( null === $method ) { unset( $_SERVER['REQUEST_METHOD'] ); } else { $_SERVER['REQUEST_METHOD'] = $method; }
			$_GET = $get;
			$_COOKIE = $cookie;
		}

		private function visit( string $status = 'returned', string $token = 'login-a', int $order_id = 501 ): Session {
			return Session::from_row( [ 'id' => 42, 'partner_id' => 5, 'user_id' => 20, 'status' => $status, 'wp_session_token' => $token, 'order_id' => $order_id, 'browser_form_post_url' => 'https://buyer.example.com:8443/punchout/return?cookie=1' ] );
		}

		/** What the handoff route answers for this browser (login token) and query; a redirect is thrown as LateSubmitAnswer. */
		private function serve( string $token, array $query ): string {
			$GLOBALS['pow_test_login_token'] = $token;
			$_GET = $query;
			$level = ob_get_level();
			ob_start();
			try {
				( new Handoff( $this->store ) )->handle();
				return (string) ob_get_contents();
			} finally {
				while ( ob_get_level() > $level ) { ob_end_clean(); }
			}
		}

		/** Where the route sent the browser instead of answering a page. */
		private function redirected( string $token, array $query ): string {
			try {
				$this->serve( $token, $query );
			} catch ( LateSubmitAnswer $answer ) {
				self::assertSame( 'redirect', $answer->kind );
				self::assertSame( 303, $answer->payload[1] );
				return $answer->payload[0];
			}
			self::fail( 'A page was answered where a redirect was due' );
		}

		private function choose_state_page(): void {
			$GLOBALS['pow_test_posts'][314] = [ 'status' => 'publish', 'permalink' => 'https://shop.example.test/visit/' ];
			$GLOBALS['pow_test_options'][ POW\Settings::OPTION_KEY ] = [ 'state_page_id' => 314 ];
		}

		/** The header the route sent with this name, or null. */
		private static function header( string $name ): ?string {
			foreach ( $GLOBALS['pow_test_setup_io']['headers'] ?? [] as $header ) {
				if ( str_starts_with( strtolower( $header ), strtolower( $name ) . ':' ) ) { return trim( substr( $header, strlen( $name ) + 1 ) ); }
			}
			return null;
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

		// ------------------------------------------------------------------- hardening (0.5.0)

		/**
		 * A GET with neither the login cookie nor the sent marker is nobody's: it is sent to Session ended (or the
		 * plain page) before any order is looked up, so the handoff route cannot be used to probe order ids and keys.
		 */
		public function test_a_browser_with_neither_login_cookie_nor_sent_marker_consults_no_order(): void {
			$this->store->rows[42] = $this->visit();
			Handoff::remember( 'login-a', 'wc_order_k1', 42, 501, self::MARKUP );
			$GLOBALS['pow_test_orders'][501] = new CheckoutHandoffOrder( 501, 'wc_order_k1' );
			$GLOBALS['pow_test_get_order_errors'] = [ 501 => new LogicException( 'The order was looked up.' ) ];
			$this->choose_state_page();
			self::assertSame( 'https://shop.example.test/visit/?pow_state=ended', $this->redirected( '', [ 'order' => '501', 'key' => 'wc_order_k1' ] ) );
			self::assertTrue( Handoff::kept( 'login-a', 'wc_order_k1' ), 'The page stays kept for the browser that won' );
			unset( $GLOBALS['pow_test_options'], $GLOBALS['pow_test_posts'] );
			$page = $this->serve( '', [ 'order' => '501', 'key' => 'wc_order_k1' ] );
			self::assertStringContainsString( 'already been used or has expired', $page, 'Without a state page, the plain no-form page' );
			self::assertStringNotContainsString( 'pow-handoff-form', $page );
		}

		/** With the sent marker (the handoff cleared the login cookie and left it) the state page is still worded. */
		public function test_the_sent_marker_still_words_the_state_page_after_the_login_cookie_is_gone(): void {
			$this->store->rows[42] = $this->visit();
			Handoff::remember( 'login-a', 'wc_order_k1', 42, 501, self::MARKUP );
			$this->serve( 'login-a', [ 'order' => '501', 'key' => 'wc_order_k1' ] );
			$marker = (string) ( $GLOBALS['pow_test_cookies_set'][0]['value'] ?? '' );
			self::assertSame( 42, Handoff::marked_visit( $marker ) );
			$this->choose_state_page();
			$_COOKIE[ Handoff::SENT_MARKER ] = $marker;
			self::assertSame( 'https://shop.example.test/visit/?pow_state=sent', $this->redirected( '', [ 'order' => '501', 'key' => 'wc_order_k1' ] ) );
			$_COOKIE[ Handoff::SENT_MARKER ] = '42.forged';
			self::assertSame( 'https://shop.example.test/visit/?pow_state=ended', $this->redirected( '', [ 'order' => '501', 'key' => 'wc_order_k1' ] ), 'A forged marker is no marker' );
		}

		/** The kept page is claimed (deleted) only once the order and the visit match, so a wrong GET cannot spend it. */
		public function test_a_get_naming_another_order_or_an_unreturned_visit_leaves_the_page_kept(): void {
			$this->store->rows[42] = $this->visit( 'active' );
			Handoff::remember( 'login-a', 'wc_order_k1', 42, 501, self::MARKUP );
			self::assertStringNotContainsString( 'pow-handoff-form', $this->serve( 'login-a', [ 'order' => '501', 'key' => 'wc_order_k1' ] ) );
			self::assertTrue( Handoff::kept( 'login-a', 'wc_order_k1' ), 'The visit has not returned: nothing is claimed' );
			$this->store->rows[42] = $this->visit();
			self::assertStringNotContainsString( 'pow-handoff-form', $this->serve( 'login-a', [ 'order' => '999', 'key' => 'wc_order_k1' ] ) );
			self::assertTrue( Handoff::kept( 'login-a', 'wc_order_k1' ), 'Another order in the address: nothing is claimed' );
			self::assertSame( self::MARKUP, $this->serve( 'login-a', [ 'order' => '501', 'key' => 'wc_order_k1' ] ) );
			self::assertFalse( Handoff::kept( 'login-a', 'wc_order_k1' ), 'The right request claimed it' );
		}

		/** Of two matching GETs at once, only the one whose delete removed the page serves it. */
		public function test_a_matching_get_whose_claim_was_lost_does_not_serve_the_page(): void {
			$this->store->rows[42] = $this->visit();
			Handoff::remember( 'login-a', 'wc_order_k1', 42, 501, self::MARKUP );
			$GLOBALS['pow_test_transient_delete_lost'] = true;
			self::assertStringNotContainsString( 'pow-handoff-form', $this->serve( 'login-a', [ 'order' => '501', 'key' => 'wc_order_k1' ] ) );
			self::assertSame( [], $GLOBALS['pow_test_cookies_cleared'] ?? [], 'The losing request ends nothing' );
		}

		/** The page's policy: its own inline script and styles by hash, a form that posts only to the receiver's origin, no framing. */
		public function test_the_page_policy_names_its_own_script_and_styles_and_the_receivers_origin(): void {
			$script = "document.getElementById( 'pow-handoff-form' ).submit();";
			$markup = '<html><head><title>x</title></head><body style="margin:4em auto"><main class="pow-state"><p style="color:#666">Ref</p><form method="post" action="https://buyer.example.com:8443/punchout/return?cookie=1" id="pow-handoff-form"></form></main><script>' . $script . '</script></body></html>';
			$hash   = static fn( string $source ): string => "'sha256-" . base64_encode( hash( 'sha256', $source, true ) ) . "'";
			$policy = Handoff::page_policy( $markup, 'https://buyer.example.com:8443/punchout/return?cookie=1' );
			self::assertSame(
				"default-src 'none'; script-src " . $hash( $script ) . "; style-src 'unsafe-hashes' " . $hash( 'margin:4em auto' ) . ' ' . $hash( 'color:#666' ) . "; form-action https://buyer.example.com:8443; frame-ancestors 'none'; base-uri 'none'",
				$policy
			);
			self::assertSame( [ 'X-Frame-Options: DENY', 'Content-Security-Policy: ' . $policy ], Handoff::page_headers( $markup, 'https://buyer.example.com:8443/punchout/return?cookie=1' ) );
			self::assertStringContainsString( "script-src 'none'; style-src 'none'; form-action 'none'", Handoff::page_policy( '<html><body></body></html>', 'not a url' ), 'Nothing to run, nowhere to post' );
			self::assertStringContainsString( "form-action https://buyer.example.com", Handoff::page_policy( '', 'https://buyer.example.com/' ) );
			$element = Handoff::page_policy( '<style>body{margin:0}</style><style>p{color:#666}</style>', 'https://buyer.example.com/' );
			self::assertStringContainsString( 'style-src ' . $hash( 'body{margin:0}' ) . ' ' . $hash( 'p{color:#666}' ) . ';', $element, 'Style elements need no unsafe-hashes' );
			self::assertStringContainsString( "style-src 'unsafe-hashes' " . $hash( 'a&b' ), Handoff::page_policy( '<p style="a&amp;b">x</p>', 'https://buyer.example.com/' ), 'An attribute is hashed as the browser reads it' );
		}

		public function test_the_served_handoff_cannot_be_framed_and_runs_only_its_own_script(): void {
			$this->store->rows[42] = $this->visit();
			$markup = self::MARKUP . '<script>document.getElementById( \'pow-handoff-form\' ).submit();</script>';
			Handoff::remember( 'login-a', 'wc_order_k1', 42, 501, $markup );
			self::assertSame( $markup, $this->serve( 'login-a', [ 'order' => '501', 'key' => 'wc_order_k1' ] ) );
			self::assertSame( 'DENY', self::header( 'X-Frame-Options' ) );
			self::assertSame( Handoff::page_policy( $markup, 'https://buyer.example.com:8443/punchout/return?cookie=1' ), self::header( 'Content-Security-Policy' ) );
			self::assertStringContainsString( 'form-action https://buyer.example.com:8443;', (string) self::header( 'Content-Security-Policy' ) );
		}
	}
}
