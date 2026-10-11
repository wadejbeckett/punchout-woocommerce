<?php
/**
 * Cancel and return (0.5.0): the real return endpoint, builder, template and session Store against recorded
 * WP/Woo/DB boundaries. Since 0.5.0 a cart leaves through WooCommerce's checkout (CheckoutSubmissionTest), so
 * every POST to /punchout/return is the empty close-out: the cXML cancel semantic, the visit closed, this
 * login only torn down.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Http {
	// Where the endpoint sends a browser instead of drawing a page, and the page the request came from (0.5.0).
	// Shared with StartEndpointTest, which reads pow_start_redirects: whichever file loads first defines it.
	if ( ! function_exists( __NAMESPACE__ . '\\wp_safe_redirect' ) ) {
		function wp_safe_redirect( string $url, int $status = 302 ): void { $GLOBALS['pow_test_redirects'][] = [ $url, $status ]; $GLOBALS['pow_start_redirects'][] = [ $url, $status ]; }
	}
	if ( ! function_exists( __NAMESPACE__ . '\\wp_get_referer' ) ) {
		function wp_get_referer(): string|false { return $GLOBALS['pow_test_referer'] ?? false; }
	}
}

namespace {
use PHPUnit\Framework\TestCase;
use POW\Audit\Log;
use POW\Cxml\Builder;
use POW\Http\ReturnEndpoint;
use POW\Partners\Registry;
use POW\Partners\Secrets;
use POW\Sessions\HandoffReplay;
use POW\Sessions\Store;
require_once dirname( __DIR__ ) . '/Support/return-database.php';
// The POW\Checkout seams (header()) before Checkout\Handoff::send_page_headers() first runs from the Not sent page.
require_once dirname( __DIR__ ) . '/Support/checkout-hooks.php';

final class CancelAndReturnTest extends TestCase {
	private ReturnDatabase $db;
	private ReturnEndpoint $endpoint;
	private array $saved = [];
	private array $post;
	private array $server;

	protected function setUp(): void {
		if ( ! extension_loaded( 'dom' ) ) { self::markTestSkipped( 'ext-dom required for the empty message' ); }
		foreach ( array_keys( $GLOBALS ) as $key ) {
			if ( str_starts_with( $key, 'pow_test_' ) || 'wpdb' === $key ) { $this->saved[ $key ] = $GLOBALS[ $key ]; unset( $GLOBALS[ $key ] ); }
		}
		$this->post = $_POST; $this->server = $_SERVER;
		$GLOBALS['wpdb'] = $this->db = new ReturnDatabase();
		$this->db->session['expires'] = gmdate( 'Y-m-d H:i:s', time() + 3600 );
		$GLOBALS['pow_test_session_tokens'][99]['test-login'] = [ 'expiration' => time() + 3600 ];
		$GLOBALS['pow_test_current_user_id'] = 99;
		// The bound account as the shop holds it: an ordinary customer. The endpoint consults no role; the visit is
		// proved by the WP session token matching its row.
		$GLOBALS['pow_test_roles'] = [ 'customer' ];
		$GLOBALS['pow_test_valid_nonce'] = 'valid';
		$GLOBALS['pow_test_login_token'] = 'test-login';
		$GLOBALS['pow_test_orders'] = [];
		$GLOBALS['pow_test_mail'] = [];
		$GLOBALS['pow_test_setup_io'] = [ 'headers' => [] ];
		$GLOBALS['pow_test_wc'] = new POW_Test_WC();
		$GLOBALS['pow_test_wc']->cart = new class {
			public function get_cart(): array { return [ [ 'data' => new WC_Product( 412, 'Example item', 'SKU-1001' ), 'product_id' => 412, 'variation_id' => 0, 'quantity' => 3, 'line_total' => 375.33 ] ]; }
			public function empty_cart( bool $persistent ): void { $GLOBALS['pow_test_cart_emptied'][] = true; }
		};
		if ( ! defined( 'POW\VERSION' ) ) { define( 'POW\VERSION', 'test' ); }
		$logger = new QuoteOrderTestLogger();
		$this->endpoint = new ReturnEndpoint( new Store(), new Registry( new Secrets( str_repeat( 'x', 32 ) ) ), new Builder(), new Log( $logger ) );
		$_POST = [ 'pow_nonce' => 'valid', 'pow_mode' => 'empty' ]; $_SERVER['REQUEST_METHOD'] = 'POST';
	}

	protected function tearDown(): void {
		foreach ( array_keys( $GLOBALS ) as $key ) { if ( str_starts_with( $key, 'pow_test_' ) || 'wpdb' === $key ) { unset( $GLOBALS[ $key ] ); } }
		foreach ( $this->saved as $key => $value ) { $GLOBALS[ $key ] = $value; }
		$_POST = $this->post; $_SERVER = $this->server;
	}

	private function response(): string {
		$level = ob_get_level(); ob_start();
		try { $this->endpoint->handle(); return (string) ob_get_contents(); }
		finally { while ( ob_get_level() > $level ) { ob_end_clean(); } }
	}

	private function kept(): mixed { return $GLOBALS['pow_test_transients'][ HandoffReplay::key( 'test-login' ) ] ?? null; }

	/** The cXML the page posts, decoded from its hidden field. */
	private static function message( string $page ): string {
		self::assertSame( 1, preg_match( '/name="cxml-(base64|urlencoded)" value="([^"]*)"/', $page, $m ), 'The page carries the message' );
		return 'base64' === $m[1] ? (string) base64_decode( $m[2], true ) : rawurldecode( html_entity_decode( $m[2], ENT_QUOTES ) );
	}

	/** A second live visit of the same bound account: its own login token and its own basket key. */
	private function second_visit( int $id = 43, string $token = 'other-visit' ): array {
		$row = $this->db->add_visit( $id, [ 'wp_session_token' => $token, 'wc_session_key' => 'pow_' . str_repeat( 'b', 28 ), 'buyer_cookie' => 'basket-reference-' . $id, 'buyer_identity' => 'second@example.test', 'buyer_name' => 'Second Buyer', 'expires' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ] );
		$GLOBALS['pow_test_session_tokens'][99][ $token ] = [ 'expiration' => time() + 3600 ];
		return $row;
	}

	public function test_cancel_and_return_closes_the_visit_with_the_empty_message_and_the_not_sent_state(): void {
		$response = $this->response();
		self::assertStringContainsString( 'pow-handoff-form', $response );
		self::assertStringContainsString( 'class="pow-state pow-state--not-sent"', $response );
		self::assertStringNotContainsString( 'pow-state--sending', $response );
		self::assertStringContainsString( 'action="https://buyer.example.test/return"', $response, 'Posted to the visit\'s own BrowserFormPost' );
		self::assertSame( 'closed', $this->db->session['status'] );
		self::assertSame( [], $GLOBALS['pow_test_orders'], 'No order' );
		self::assertSame( [ 'test-login' ], $GLOBALS['pow_test_destroyed_tokens'], 'This login only' );
		self::assertSame( [ true ], $GLOBALS['pow_test_cart_emptied'] ?? [], 'This basket only' );
		self::assertSame( 200, end( $GLOBALS['pow_test_status_headers'] ) );
		self::assertContains( 'X-Content-Type-Options: nosniff', $GLOBALS['pow_test_setup_io']['headers'] );
		// Security review, 11 October: the Not sent page cannot be framed, runs only its own script and posts only to the receiver's origin.
		self::assertContains( 'X-Frame-Options: DENY', $GLOBALS['pow_test_setup_io']['headers'] );
		self::assertContains( 'Content-Security-Policy: ' . POW\Checkout\Handoff::page_policy( $response, 'https://buyer.example.test/return' ), $GLOBALS['pow_test_setup_io']['headers'] );
		self::assertStringContainsString( 'form-action https://buyer.example.test;', implode( "\n", $GLOBALS['pow_test_setup_io']['headers'] ) );
		$xml = self::message( $response );
		self::assertStringContainsString( '<PunchOutOrderMessage>', $xml );
		self::assertStringNotContainsString( '<ItemIn', $xml, 'Empty: the cXML cancel semantic' );
		self::assertStringNotContainsString( 'SharedSecret', $xml );
		self::assertStringContainsString( '<BuyerCookie>basket-reference</BuyerCookie>', $xml );
		self::assertSame( [ 'return_empty_sent', 'session_closed' ], array_column( $this->db->audits, 'event' ) );
	}

	/** Whatever the old return form posted (mode=cart, no mode), every POST here is the cancel since 0.5.0. */
	public function test_every_post_is_the_cancel_whatever_mode_an_old_form_carries(): void {
		foreach ( [ 'cart', null ] as $mode ) {
			$this->tearDown(); $this->setUp();
			if ( null === $mode ) { unset( $_POST['pow_mode'] ); } else { $_POST['pow_mode'] = $mode; }
			$response = $this->response();
			self::assertStringContainsString( 'pow-state--not-sent', $response, var_export( $mode, true ) );
			self::assertStringNotContainsString( '<ItemIn', self::message( $response ) );
			self::assertSame( 'closed', $this->db->session['status'] );
			self::assertSame( [], $GLOBALS['pow_test_orders'] );
		}
	}

	/** The message never names an order of ours, whatever the row or the connection's flags hold, and adds no freight. */
	public function test_the_empty_message_carries_no_order_reference_no_shipping_and_no_freight(): void {
		$this->db->session['order_id'] = 314;
		$this->db->partner_fields = [ 'emit_delivery_line' => true, 'emit_ship_to' => true, 'emit_delivery_code' => true, 'delivery_notes_policy' => 'item_detail_extrinsic', 'cxml_version' => '1.2.071' ];
		$xml = self::message( $this->response() );
		self::assertStringNotContainsString( 'SupplierOrderInfo', $xml );
		self::assertStringNotContainsString( 'ShipTo', $xml );
		self::assertStringNotContainsString( '<ItemIn', $xml );
		self::assertStringContainsString( '<Total><Money currency="ZAR">0.00</Money></Total>', $xml );
		self::assertSame( 'closed', $this->db->session['status'] );
	}

	public function test_an_insecure_saved_receiver_refuses_before_any_transition(): void {
		$GLOBALS['pow_test_environment_type'] = 'production'; $_SERVER['HTTPS'] = 'on';
		$this->db->session['browser_form_post_url'] = 'http://buyer.example.test/return';
		$response = $this->response();
		self::assertStringContainsString( 'HTTPS', $response );
		self::assertStringNotContainsString( 'pow-handoff-form', $response );
		self::assertSame( 'active', $this->db->session['status'] );
		self::assertSame( [], $GLOBALS['pow_test_destroyed_tokens'] ?? [] );
	}

	public function test_a_repeated_cancel_after_the_close_out_gets_the_same_page_and_changes_nothing(): void {
		$first = $this->response();
		self::assertStringContainsString( 'pow-handoff-form', $first );
		self::assertSame( 'closed', $this->db->session['status'] );
		self::assertTrue( is_array( $this->kept() ) );
		self::assertSame( [ HandoffReplay::TTL ], $GLOBALS['pow_test_transient_expirations'][ HandoffReplay::key( 'test-login' ) ] );
		self::assertStringNotContainsString( 'test-login', (string) json_encode( array_keys( $GLOBALS['pow_test_transients'] ) ), 'Only a hash of the login token names the record' );

		// The second click, still carrying the login cookie the winner has destroyed: logged out now.
		$GLOBALS['pow_test_current_user_id'] = 0;
		$GLOBALS['pow_test_setup_io']['headers'] = [];
		$cleared = count( $GLOBALS['pow_test_cookies_cleared'] ?? [] );
		$second = $this->response();
		self::assertSame( $first, $second, 'The same page, byte for byte' );
		self::assertSame( 200, end( $GLOBALS['pow_test_status_headers'] ) );
		self::assertContains( 'X-Content-Type-Options: nosniff', $GLOBALS['pow_test_setup_io']['headers'] );
		self::assertGreaterThan( $cleared, count( $GLOBALS['pow_test_cookies_cleared'] ?? [] ), 'The repeat clears this browser\'s login cookie too' );

		// Or still signed in (the second request arrived before the token was destroyed, then waited).
		$GLOBALS['pow_test_current_user_id'] = 99;
		self::assertSame( $first, $this->response() );
		self::assertCount( 1, $GLOBALS['pow_test_destroyed_tokens'], 'No second teardown' );
		self::assertSame( 1, count( array_filter( array_column( $this->db->audits, 'event' ), static fn( string $event ): bool => 'return_empty_sent' === $event ) ), 'Nothing is sent twice' );
	}

	public function test_only_the_same_form_from_the_same_browser_gets_the_page_again(): void {
		self::assertStringContainsString( 'pow-handoff-form', $this->response() );
		$GLOBALS['pow_test_current_user_id'] = 0;

		$_POST['pow_nonce'] = 'another-form';
		self::assertStringContainsString( 'expired', $this->response(), 'Another return nonce' );
		$_POST['pow_nonce'] = 'valid';

		$GLOBALS['pow_test_login_token'] = 'another-browser';
		self::assertStringContainsString( 'expired', $this->response(), 'Another login' );
		$GLOBALS['pow_test_login_token'] = 'test-login';

		self::assertStringContainsString( 'pow-handoff-form', $this->response() );
	}

	public function test_a_kept_page_is_never_served_for_a_visit_that_has_not_closed(): void {
		// A record that names this login but whose visit is still active (a winner that then lost) is ignored,
		// and so is one kept by the checkout path under its own mode.
		foreach ( [ 'empty', 'cart' ] as $mode ) {
			$GLOBALS['pow_test_transients'] = [];
			HandoffReplay::remember( 'test-login', 'valid', 42, $mode, '<form class="pow-handoff-form"></form>' );
			$GLOBALS['pow_test_current_user_id'] = 0;
			self::assertStringContainsString( 'expired', $this->response(), $mode );
			self::assertSame( 'active', $this->db->session['status'] );
		}
	}

	public function test_a_request_that_loses_the_transition_drops_its_record(): void {
		$this->db->transition_fails = true;
		$response = $this->response();
		self::assertStringContainsString( 'expired', $response );
		self::assertStringNotContainsString( 'pow-handoff-form', $response );
		self::assertSame( 'active', $this->db->session['status'] );
		self::assertNull( $this->kept(), 'Nothing is left to repeat' );
		self::assertSame( [], $GLOBALS['pow_test_destroyed_tokens'] ?? [] );
	}

	/** 0.5.0: "Ask before leaving" on and the cart holds a line: a cancel without the answer goes back to its page to ask, and nothing leaves. */
	public function test_cancel_and_return_with_items_goes_back_to_ask_first(): void {
		$GLOBALS['pow_test_options'][ \POW\Settings::OPTION_KEY ] = [ 'leave_confirmation' => 'yes' ];
		$GLOBALS['pow_test_referer'] = 'https://shop.example.test/products/';
		self::assertSame( '', $this->response() );
		self::assertSame( [ [ 'https://shop.example.test/products/?pow_leave=ask', 303 ] ], $GLOBALS['pow_test_redirects'] );
		self::assertSame( 'active', $this->db->session['status'], 'The visit stays open' );
		self::assertSame( [], $GLOBALS['pow_test_destroyed_tokens'] ?? [] );

		$_POST['pow_leave'] = 'confirmed';
		$response = $this->response();
		self::assertStringContainsString( 'class="pow-state pow-state--not-sent"', $response, 'The answer leaves' );
		self::assertSame( 'closed', $this->db->session['status'] );
	}

	public function test_cancel_and_return_leaves_at_once_with_an_empty_cart_or_the_setting_off(): void {
		self::assertStringContainsString( 'pow-handoff-form', $this->response(), 'Off by default' );
		self::assertSame( [], $GLOBALS['pow_test_redirects'] ?? [] );

		$this->tearDown(); $this->setUp();
		$GLOBALS['pow_test_options'][ \POW\Settings::OPTION_KEY ] = [ 'leave_confirmation' => 'yes' ];
		$GLOBALS['pow_test_wc']->cart = new class { public function get_cart(): array { return []; } public function empty_cart( bool $persistent ): void {} };
		self::assertStringContainsString( 'pow-handoff-form', $this->response(), 'An empty cart' );
		self::assertSame( [], $GLOBALS['pow_test_redirects'] ?? [] );
		self::assertSame( 'closed', $this->db->session['status'] );
	}

	/** 0.5.0: with a state page chosen, a cancel whose visit has ended goes to the Session ended state; without one the plain page stays. */
	public function test_a_cancel_after_the_visit_has_ended_goes_to_the_session_ended_state(): void {
		$GLOBALS['pow_test_current_user_id'] = 0;
		self::assertStringContainsString( 'expired', $this->response(), 'No state page: the plain page' );
		$GLOBALS['pow_test_posts'][314] = [ 'status' => 'publish', 'permalink' => 'https://shop.example.test/visit/' ];
		$GLOBALS['pow_test_options'][ \POW\Settings::OPTION_KEY ] = [ 'state_page_id' => 314 ];
		$GLOBALS['pow_test_redirects'] = [];
		self::assertSame( '', $this->response() );
		self::assertSame( [ [ 'https://shop.example.test/visit/?pow_state=ended', 303 ] ], $GLOBALS['pow_test_redirects'] );
	}

	public function test_invalid_nonce_and_login_never_transition(): void {
		// 'visit': a valid login token of ANOTHER visit of the same bound account. It owns its own row and nothing of this one.
		$this->second_visit();
		$this->db->sessions[43]['status'] = 'returned';
		foreach ( [ 'nonce', 'token', 'visit' ] as $failure ) {
			$_POST['pow_nonce'] = 'nonce' === $failure ? 'bad' : 'valid';
			$GLOBALS['pow_test_login_token'] = match ( $failure ) { 'token' => 'wrong-login', 'visit' => 'other-visit', default => 'test-login' };
			self::assertStringNotContainsString( 'pow-handoff-form', $this->response(), $failure );
		}
		self::assertSame( [], $this->db->queries );
		self::assertSame( 'active', $this->db->session['status'] );
		self::assertSame( 'returned', $this->db->sessions[43]['status'] );
		self::assertSame( [], $GLOBALS['pow_test_destroyed_tokens'] ?? [] );
	}

	/** Two employees of one customer are one WordPress account and two visits: a cancel closes its own row only. */
	public function test_two_visits_of_one_account_close_independently(): void {
		$this->second_visit();
		$GLOBALS['pow_test_login_token'] = 'other-visit';
		self::assertStringContainsString( 'pow-handoff-form', $this->response() );
		self::assertSame( 'closed', $this->db->sessions[43]['status'] );
		self::assertSame( 'active', $this->db->session['status'], 'The colleague\'s visit stays open' );
		self::assertSame( [ 'other-visit' ], $GLOBALS['pow_test_destroyed_tokens'] );
		$GLOBALS['pow_test_login_token'] = 'test-login';
		self::assertStringContainsString( 'pow-handoff-form', $this->response() );
		self::assertSame( 'closed', $this->db->session['status'] );
		self::assertSame( [ 'other-visit', 'test-login' ], $GLOBALS['pow_test_destroyed_tokens'] );
	}

	public function test_a_template_failure_does_not_consume_the_session(): void {
		$GLOBALS['pow_test_filters']['pow_handoff_copy'] = static function () { throw new RuntimeException( 'template failed' ); };
		$response = $this->response();
		self::assertStringContainsString( 'could not be prepared', $response );
		self::assertSame( 500, end( $GLOBALS['pow_test_status_headers'] ) );
		self::assertSame( 'active', $this->db->session['status'] );
		self::assertSame( [], $GLOBALS['pow_test_destroyed_tokens'] ?? [] );
		self::assertNull( $this->kept() );
	}

	public function test_a_connection_disabled_while_the_page_is_prepared_cannot_close_out(): void {
		$GLOBALS['pow_test_filters']['pow_handoff_copy'] = function ( $copy ) { $this->db->partner_status = 'disabled'; return $copy; };
		$response = $this->response();
		self::assertStringContainsString( 'expired', $response );
		self::assertSame( 'active', $this->db->session['status'] );
		self::assertSame( [], $GLOBALS['pow_test_destroyed_tokens'] ?? [] );
	}

	public function test_a_get_is_refused(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		self::assertStringContainsString( 'Invalid request', $this->response() );
		self::assertSame( 'active', $this->db->session['status'] );
	}
}
}
