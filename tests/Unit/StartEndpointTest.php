<?php
/** The StartPage redeem: the bound login re-proved, and one visit's own login token, cookie and cart key.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Http {
	/*
	 * Namespace-local seams for the four functions the redeem calls that the
	 * shared stubs deliberately do not define: wp_generate_password is on the
	 * user-mutating deny list, the global hook surface belongs to the boot
	 * fixture, and a redirect must be observable rather than sent. Only
	 * StartEndpoint calls any of them inside POW\Http, so these bind to it
	 * alone.
	 */
	function wp_generate_password( int $length = 12, bool $special = true, bool $extra = false ): string {
		$GLOBALS['pow_start_passwords'][] = [ $length, $special, $extra ];

		return str_pad( 'visit' . count( $GLOBALS['pow_start_passwords'] ), $length, 'x' );
	}

	function add_filter( string $hook, callable $callback, int $priority = 10, int $args = 1 ): void {
		$GLOBALS['pow_start_hooks'][] = [ 'add', $hook, $priority ];
	}

	function remove_filter( string $hook, callable $callback, int $priority = 10 ): void {
		$GLOBALS['pow_start_hooks'][] = [ 'remove', $hook, $priority ];
	}

	/** The one seam that can fail after the cookie TTL filter is installed. */
	function wp_set_auth_cookie( int $user_id, bool $remember = false, mixed $secure = '', string $token = '' ): void {
		if ( isset( $GLOBALS['pow_start_cookie_error'] ) ) { throw $GLOBALS['pow_start_cookie_error']; }

		\wp_set_auth_cookie( $user_id, $remember, $secure, $token );
	}

	function wp_safe_redirect( string $url, int $status = 302 ): void {
		$GLOBALS['pow_start_redirects'][] = [ $url, $status ];
	}
}

namespace {
require_once dirname( __DIR__ ) . '/Support/visit-key-database.php';

use PHPUnit\Framework\TestCase;
use POW\Audit\Log;
use POW\Cart\SessionKey;
use POW\Http\StartEndpoint;
use POW\Partners\Registry;
use POW\Partners\Secrets;
use POW\Sessions\Session;
use POW\Sessions\Store;
use POW\Sessions\Tokens;

/**
 * One connection, one bound customer account, many visits.
 *
 * The account is an ordinary customer: it holds no punchout role and no
 * plugin user meta, so the only thing that can authorise a redeem is the
 * connection row, and the only thing that can tell two of its visits apart is
 * what the redeem mints — this visit's WP session token and this visit's
 * WooCommerce session key.
 */
final class StartEndpointTest extends TestCase {

	private const ACCOUNT = 20;
	private const PARTNER = 7;

	private VisitKeyDatabase $db;
	private StartEndpoint $endpoint;
	/** @var array<string, array{bool, mixed}> */
	private array $saved = [];
	private array $server;

	protected function setUp(): void {
		foreach ( array_keys( $GLOBALS ) as $key ) {
			if ( 'wpdb' === $key || str_starts_with( $key, 'pow_test_' ) || str_starts_with( $key, 'pow_start_' ) ) {
				$this->saved[ $key ] = [ true, $GLOBALS[ $key ] ];
				unset( $GLOBALS[ $key ] );
			}
		}
		$this->server = $_SERVER;
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$_SERVER['HTTP_USER_AGENT'] = 'Buyer browser';

		$GLOBALS['wpdb'] = $this->db = new VisitKeyDatabase();
		$GLOBALS['pow_test_setup_io'] = [ 'headers' => [] ];
		$GLOBALS['pow_test_users'] = [ self::ACCOUNT => $this->customer() ];
		$this->db->partners[ self::PARTNER ] = [
			'id'            => self::PARTNER,
			'name'          => 'Example buyer',
			'status'        => 'active',
			'owner_user_id' => self::ACCOUNT,
			'session_ttl'   => 7200,
			'token_ttl'     => 300,
		];

		$this->endpoint = new StartEndpoint( new Store(), new Registry( new Secrets( str_repeat( 'x', 32 ) ) ), new QuoteOrderTestSettings(), new Log( new QuoteOrderTestLogger() ) );
	}

	protected function tearDown(): void {
		foreach ( array_keys( $GLOBALS ) as $key ) {
			if ( 'wpdb' === $key || str_starts_with( $key, 'pow_test_' ) || str_starts_with( $key, 'pow_start_' ) ) { unset( $GLOBALS[ $key ] ); }
		}
		foreach ( $this->saved as $key => [ $existed, $value ] ) {
			if ( $existed ) { $GLOBALS[ $key ] = $value; }
		}
		$_SERVER = $this->server;
	}

	/** The bound account as the shop holds it: a customer, nothing more. */
	private function customer( array $allcaps = [ 'read' => true ] ): object {
		return (object) [ 'ID' => self::ACCOUNT, 'roles' => [ 'customer' ], 'allcaps' => $allcaps ];
	}

	/**
	 * A pending claim of one visit, with its StartPage token.
	 *
	 * @param array<string, mixed> $overrides Columns that differ.
	 * @return string The one-time token the browser presents.
	 */
	private function claim( int $id, array $overrides = [] ): string {
		$token = str_pad( 'start-token-' . $id, 43, 'z' );
		$this->db->sessions[ $id ] = array_replace(
			[
				'id'                  => $id,
				'partner_id'          => self::PARTNER,
				'user_id'             => self::ACCOUNT,
				'wp_session_token'    => '',
				'wc_session_key'      => null,
				'one_time_token_hash' => Tokens::hash( $token ),
				'status'              => Session::PENDING,
				'expires'             => gmdate( 'Y-m-d H:i:s', time() + 300 ),
				'buyer_cookie'        => 'basket-reference-' . $id,
				'browser_form_post_url' => 'https://buyer.example.test/return',
			],
			$overrides
		);

		return $token;
	}

	/** The buyer's POST to the start link (0.4.23: the only method that redeems). */
	private function redeem( string $token ): string {
		return $this->visit( $token, 'POST' );
	}

	/** One request to /punchout/start/{token} with the given method, and what it printed. */
	private function visit( string $token, string $method ): string {
		$_SERVER['REQUEST_METHOD'] = $method;
		$level = ob_get_level();
		ob_start();
		try { $this->endpoint->handle( $token ); return (string) ob_get_contents(); }
		finally { while ( ob_get_level() > $level ) { ob_end_clean(); } }
	}

	/** @return list<string> */
	private function headers_sent(): array {
		return $GLOBALS['pow_test_setup_io']['headers'] ?? [];
	}

	/** A settings reader with only the start-link click setting saved. */
	private function click_settings( string $value ): \POW\Settings {
		return new class( $value ) extends \POW\Settings {
			public function __construct( private string $click ) {}
			public function get( string $key, mixed $default = null ): mixed {
				return 'start_link_click' === $key ? $this->click : $default;
			}
		};
	}

	/** @return list<string> */
	private function session_updates(): array {
		return array_values( array_filter( $this->db->queries, static fn( string $sql ): bool => str_starts_with( $sql, 'UPDATE' ) && str_contains( $sql, 'wc_session_key = ' ) ) );
	}

	private function audited( string $event ): array {
		foreach ( $this->db->audits as $row ) {
			if ( $event === $row['event'] ) { return $row; }
		}
		return [];
	}

	// ------------------------------------------------------------- the redeem

	public function test_redeem_logs_the_browser_in_as_the_bound_account_with_its_own_token_and_key(): void {
		$token = $this->claim( 42 );

		self::assertSame( '', $this->redeem( $token ) );

		$row = $this->db->sessions[42];
		self::assertSame( Session::ACTIVE, $row['status'] );
		self::assertSame( 43, strlen( (string) $row['wp_session_token'] ) );
		// The key is the visit's basket, and 32 characters is the column it fits.
		self::assertSame( SessionKey::LENGTH, strlen( (string) $row['wc_session_key'] ) );
		self::assertTrue( SessionKey::is_visit_key( (string) $row['wc_session_key'] ) );
		self::assertSame( gmdate( 'Y-m-d H:i:s', time() + 7200 ), $row['expires'] );

		// One login, on the connection's own account, verifiable by its token.
		self::assertTrue( ( new WP_Session_Tokens( self::ACCOUNT ) )->verify( (string) $row['wp_session_token'] ) );
		self::assertSame( [ [ 'user_id' => self::ACCOUNT, 'remember' => false, 'secure' => '', 'token' => $row['wp_session_token'] ] ], $GLOBALS['pow_test_auth_cookies'] );
		self::assertSame( [ self::ACCOUNT ], $GLOBALS['pow_test_current_user_switches'] );
		self::assertSame( [ [ 'https://shop.example.test/', 302 ] ], $GLOBALS['pow_start_redirects'] );

		$audit = $this->audited( 'token_redeem' );
		self::assertSame( [ 'ok', self::PARTNER, 42, self::ACCOUNT ], [ $audit['result'], $audit['partner_id'], $audit['session_id'], $audit['user_id'] ] );
		// Nothing about the visit is written onto the shared account: the
		// redeem would have fataled on an undefined update_user_meta().
		self::assertFalse( function_exists( 'update_user_meta' ), 'A redeem that wrote user meta would need this stub.' );
	}

	public function test_bind_receives_a_thirty_two_character_visit_key_in_the_same_update_as_the_token(): void {
		$token = $this->claim( 42 );
		$this->redeem( $token );

		$updates = $this->session_updates();
		self::assertCount( 1, $updates, 'The login and the basket key commit together, once.' );
		self::assertStringContainsString( "wp_session_token = '" . $this->db->sessions[42]['wp_session_token'] . "'", $updates[0] );
		self::assertStringContainsString( "wc_session_key = '" . $this->db->sessions[42]['wc_session_key'] . "'", $updates[0] );
		self::assertSame( 1, preg_match( "/wc_session_key = 'pow_[0-9a-f]{28}'/", $updates[0] ) );
	}

	/**
	 * A binding that does not round-trip — a UNIQUE collision on the visit
	 * key reads as an ordinary false — is retried once, with a fresh key.
	 */
	public function test_a_binding_that_does_not_round_trip_is_retried_once_with_a_fresh_key(): void {
		$token = $this->claim( 42 );
		// A racing writer rolls the claim's binding back between the write and
		// its readback, so the first attempt reports failure. The callback
		// re-arms itself past the earlier status flip, because only the write
		// that names the key is the one being contested.
		$rollback = function () use ( &$rollback ): void {
			if ( '' === (string) $this->db->sessions[42]['wp_session_token'] ) { $this->db->after_write = $rollback; return; }
			$this->db->sessions[42] = array_replace( $this->db->sessions[42], [ 'wp_session_token' => '', 'wc_session_key' => null ] );
		};
		$this->db->after_write = $rollback;

		$this->redeem( $token );

		$updates = $this->session_updates();
		self::assertCount( 2, $updates );
		self::assertSame( 1, preg_match( "/wc_session_key = '(pow_[0-9a-f]{28})'/", $updates[0], $first ) );
		self::assertSame( 1, preg_match( "/wc_session_key = '(pow_[0-9a-f]{28})'/", $updates[1], $second ) );
		self::assertNotSame( $first[1], $second[1], 'A retry is a fresh draw, not the same key again.' );
		self::assertSame( $second[1], $this->db->sessions[42]['wc_session_key'] );
		self::assertSame( Session::ACTIVE, $this->db->sessions[42]['status'] );
		self::assertSame( 'ok', $this->audited( 'token_redeem' )['result'] );
	}

	// ------------------------------------- link scanners (0.4.23): only POST redeems

	/**
	 * A mail scanner fetches every link in a message. A GET of the start link
	 * shows a small page that posts itself back to the same address, and
	 * touches nothing: no lookup, no write, no audit row.
	 */
	public function test_a_get_shows_a_self_submitting_page_and_leaves_the_link_unused(): void {
		$token = $this->claim( 42 );

		$page = $this->visit( $token, 'GET' );

		$url = 'https://shop.example.test/punchout/start/' . $token;
		self::assertStringContainsString( '<form method="post" action="' . $url . '" id="pow-start-form">', $page );
		self::assertSame( 1, preg_match( '#<button type="submit"[^>]*>[^<]+</button>#', $page ), 'A visible button for a browser without scripting.' );
		self::assertStringContainsString( "<script>document.getElementById( 'pow-start-form' ).submit();</script>", $page );
		self::assertStringContainsString( '<meta name="robots" content="noindex,nofollow">', $page );
		self::assertStringContainsString( '<meta name="referrer" content="no-referrer">', $page );
		self::assertSame( [ 200 ], $GLOBALS['pow_test_status_headers'] ?? [] );
		foreach ( [ 'Content-Type: text/html; charset=utf-8', 'Cache-Control: private, no-store', 'X-Robots-Tag: noindex, nofollow', 'X-Content-Type-Options: nosniff', 'Referrer-Policy: no-referrer' ] as $header ) {
			self::assertContains( $header, $this->headers_sent(), $header );
		}

		self::assertSame( Session::PENDING, $this->db->sessions[42]['status'] );
		self::assertSame( [], $this->db->queries, 'No lookup and no write.' );
		self::assertSame( [], $this->db->audits, 'No audit row.' );
		self::assertSame( [], $GLOBALS['pow_test_auth_cookies'] ?? [] );
		self::assertSame( [], $GLOBALS['pow_start_redirects'] ?? [] );
	}

	public function test_a_head_sends_the_same_headers_and_no_body(): void {
		$token = $this->claim( 42 );

		self::assertSame( '', $this->visit( $token, 'HEAD' ) );

		self::assertSame( [ 200 ], $GLOBALS['pow_test_status_headers'] ?? [] );
		foreach ( [ 'Cache-Control: private, no-store', 'X-Robots-Tag: noindex, nofollow', 'X-Content-Type-Options: nosniff', 'Referrer-Policy: no-referrer' ] as $header ) {
			self::assertContains( $header, $this->headers_sent(), $header );
		}
		self::assertSame( Session::PENDING, $this->db->sessions[42]['status'] );
		self::assertSame( [], $this->db->queries );
		self::assertSame( [], $this->db->audits );
	}

	/** What the scanner saw is still a link the buyer can use: the page's own POST redeems it, once. */
	public function test_after_a_scan_the_post_redeems_and_a_second_post_is_refused(): void {
		$token = $this->claim( 42 );
		$this->visit( $token, 'GET' );
		$this->visit( $token, 'HEAD' );

		self::assertSame( '', $this->redeem( $token ) );
		self::assertSame( Session::ACTIVE, $this->db->sessions[42]['status'] );
		self::assertSame( [ [ 'https://shop.example.test/', 302 ] ], $GLOBALS['pow_start_redirects'] );
		$login = $this->db->sessions[42]['wp_session_token'];
		$GLOBALS['pow_test_status_headers'] = [];

		$again = $this->redeem( $token );

		self::assertStringContainsString( 'This catalog link has expired', $again );
		self::assertSame( [ 403 ], $GLOBALS['pow_test_status_headers'] );
		self::assertSame( $login, $this->db->sessions[42]['wp_session_token'], 'The first visit keeps its login.' );
		self::assertCount( 1, $GLOBALS['pow_test_auth_cookies'], 'One login cookie, from the first POST.' );
		self::assertCount( 1, $GLOBALS['pow_start_redirects'] );
		self::assertSame( '403', $this->audited( 'token_reject' )['result'] );
	}

	public function test_a_malformed_token_is_refused_for_every_method_before_any_lookup(): void {
		foreach ( [ 'GET', 'HEAD', 'POST', 'PUT', 'OPTIONS' ] as $method ) {
			foreach ( [ 'short', str_repeat( 'a', 44 ), str_pad( 'bad token', 43, 'x' ), str_pad( 'quote"', 43, 'x' ) ] as $token ) {
				$this->tearDown();
				$this->setUp();

				$response = $this->visit( $token, $method );

				self::assertSame( [ 403 ], $GLOBALS['pow_test_status_headers'] ?? [], $method . ' ' . $token );
				self::assertStringContainsString( 'This catalog link has expired', $response, $method );
				self::assertStringNotContainsString( '<form', $response, $method );
				self::assertSame( [], $this->db->queries, $method );
				self::assertSame( [], $GLOBALS['pow_test_auth_cookies'] ?? [], $method );
			}
		}
	}

	public function test_a_method_other_than_get_head_or_post_neither_redeems_nor_looks_up(): void {
		foreach ( [ 'PUT', 'DELETE', 'OPTIONS', 'PATCH' ] as $method ) {
			$this->tearDown();
			$this->setUp();
			$token = $this->claim( 42 );

			$this->visit( $token, $method );

			self::assertSame( [ 405 ], $GLOBALS['pow_test_status_headers'] ?? [], $method );
			self::assertContains( 'Allow: GET, HEAD, POST', $this->headers_sent(), $method );
			self::assertSame( Session::PENDING, $this->db->sessions[42]['status'], $method );
			self::assertSame( [], $this->db->queries, $method );
			self::assertSame( [], $this->db->audits, $method );
		}
	}

	/** Off by default; on, the page waits for one real click, for scanners that also run scripts. */
	public function test_the_one_click_setting_drops_the_automatic_submit(): void {
		$token = $this->claim( 42 );
		$this->endpoint = new StartEndpoint( new Store(), new Registry( new Secrets( str_repeat( 'x', 32 ) ) ), $this->click_settings( 'yes' ), new Log( new QuoteOrderTestLogger() ) );

		$page = $this->visit( $token, 'GET' );

		self::assertStringContainsString( '<form method="post" action="https://shop.example.test/punchout/start/' . $token . '" id="pow-start-form">', $page );
		self::assertSame( 1, preg_match( '#<button type="submit"[^>]*>[^<]+</button>#', $page ) );
		self::assertStringNotContainsString( '<script', $page );
		self::assertSame( Session::PENDING, $this->db->sessions[42]['status'] );

		// Any other saved value is the default: the page submits itself.
		$this->endpoint = new StartEndpoint( new Store(), new Registry( new Secrets( str_repeat( 'x', 32 ) ) ), $this->click_settings( 'no' ), new Log( new QuoteOrderTestLogger() ) );
		self::assertStringContainsString( '.submit();</script>', $this->visit( $token, 'GET' ) );
	}

	public function test_the_click_setting_is_off_by_default_and_saved_as_yes_or_no(): void {
		self::assertFalse( ( new \POW\Settings() )->start_link_click() );
		$admin = ( new ReflectionClass( POW\Admin\Page::class ) )->newInstanceWithoutConstructor();
		( new ReflectionProperty( $admin, 'settings' ) )->setValue( $admin, new \POW\Settings() );
		self::assertSame( 'no', $admin->sanitize_settings( [] )['start_link_click'] );
		self::assertSame( 'yes', $admin->sanitize_settings( [ 'start_link_click' => 'yes' ] )['start_link_click'] );
		self::assertSame( 'no', $admin->sanitize_settings( [ 'start_link_click' => '1' ] )['start_link_click'] );
	}

	// ------------------------------------------------ the bound-login re-proof

	/**
	 * @return array<string, callable(): void>
	 */
	private function refusals(): array {
		return [
			'no bound account'      => function (): void { $this->db->partners[ self::PARTNER ]['owner_user_id'] = 0; },
			'another account'       => function (): void { $this->db->partners[ self::PARTNER ]['owner_user_id'] = 21; },
			'missing account'       => function (): void { $GLOBALS['pow_test_users'] = []; },
			'cannot read'           => function (): void { $GLOBALS['pow_test_users'][ self::ACCOUNT ] = $this->customer( [] ); },
			'shop manager'          => function (): void { $GLOBALS['pow_test_users'][ self::ACCOUNT ] = $this->customer( [ 'read' => true, 'manage_woocommerce' => true ] ); },
			'administrator'         => function (): void { $GLOBALS['pow_test_users'][ self::ACCOUNT ] = $this->customer( [ 'read' => true, 'manage_options' => true ] ); },
			'user editor'           => function (): void { $GLOBALS['pow_test_users'][ self::ACCOUNT ] = $this->customer( [ 'read' => true, 'edit_users' => true ] ); },
			'carries a connection'  => function (): void { $GLOBALS['pow_test_user_meta'][ self::ACCOUNT ]['_pow_partner_id'] = self::PARTNER; },
			'disabled connection'   => function (): void { $this->db->partners[ self::PARTNER ]['status'] = 'disabled'; },
		];
	}

	public function test_redeem_refuses_every_login_that_is_not_the_connections_bound_customer(): void {
		foreach ( $this->refusals() as $label => $break ) {
			$this->tearDown();
			$this->setUp();
			$token = $this->claim( 42 );
			$break();

			$response = $this->redeem( $token );

			self::assertStringContainsString( 'This catalog link has expired', $response, $label );
			self::assertSame( [ 403 ], $GLOBALS['pow_test_status_headers'], $label );
			self::assertSame( Session::PENDING, $this->db->sessions[42]['status'], $label );
			self::assertSame( '', $this->db->sessions[42]['wp_session_token'], $label );
			self::assertNull( $this->db->sessions[42]['wc_session_key'], $label );
			self::assertSame( [], $GLOBALS['pow_test_auth_cookies'] ?? [], $label );
			self::assertSame( [], $GLOBALS['pow_start_redirects'] ?? [], $label );
			self::assertSame( '403', $this->audited( 'token_reject' )['result'], $label );
			self::assertSame( [], $this->session_updates(), $label );
		}
	}

	// ------------------------------------------------------ two visits at once

	public function test_two_visits_of_one_account_get_their_own_login_and_neither_resolves_to_the_other(): void {
		$alice = $this->claim( 42 );
		$bob   = $this->claim( 43 );

		$this->redeem( $alice );
		$this->redeem( $bob );

		[ $one, $two ] = [ $this->db->sessions[42], $this->db->sessions[43] ];
		self::assertNotSame( $one['wp_session_token'], $two['wp_session_token'] );
		self::assertNotSame( $one['wc_session_key'], $two['wc_session_key'], 'Two baskets, never one.' );
		self::assertTrue( SessionKey::is_visit_key( (string) $one['wc_session_key'] ), 'The first visit owns a per-visit basket key.' );
		self::assertTrue( SessionKey::is_visit_key( (string) $two['wc_session_key'] ), 'And so does the second.' );
		self::assertSame( self::ACCOUNT, (int) $two['user_id'], 'Two employees of one customer are one account.' );

		// Both logins live at once on that one account.
		$tokens = new WP_Session_Tokens( self::ACCOUNT );
		self::assertTrue( $tokens->verify( (string) $one['wp_session_token'] ) );
		self::assertTrue( $tokens->verify( (string) $two['wp_session_token'] ) );
		// Which token each browser was handed is the whole of "two auth cookies
		// on one account": a cookie carrying the colleague's token — or none —
		// resolves the colleague's visit and basket while every row on the
		// account still reads correctly. A count cannot see that.
		self::assertSame(
			[ $one['wp_session_token'], $two['wp_session_token'] ],
			array_column( $GLOBALS['pow_test_auth_cookies'], 'token' ),
			'Each visit sets its own login cookie, in order.'
		);
		self::assertSame( [ self::ACCOUNT, self::ACCOUNT ], array_column( $GLOBALS['pow_test_auth_cookies'], 'user_id' ) );

		// And each token owns exactly its own visit.
		$store = new Store();
		self::assertSame( 42, $store->find_for_login( self::ACCOUNT, (string) $one['wp_session_token'] )?->id );
		self::assertSame( 43, $store->find_for_login( self::ACCOUNT, (string) $two['wp_session_token'] )?->id );
		self::assertSame( $one['wc_session_key'], $store->find_by_wc_session_key( (string) $one['wc_session_key'] )?->wc_session_key );
		self::assertSame( 43, $store->find_by_wc_session_key( (string) $two['wc_session_key'] )?->id );
	}

	// -------------------------------------------------------- the cookie TTL

	public function test_refused_tokens_are_logged_within_the_per_ip_budget_and_a_login_always_is(): void {
		$counts = [];
		$budget = new \POW\Http\AnonymousAudit( new \POW\Http\RateLimiter( 2, static function ( string $key ) use ( &$counts ): int { return $counts[ $key ] ?? 0; }, static function ( string $key, int $count ) use ( &$counts ): void { $counts[ $key ] = $count; }, 3600 ) );
		$this->endpoint = new StartEndpoint( new Store(), new Registry( new Secrets( str_repeat( 'x', 32 ) ) ), new QuoteOrderTestSettings(), new Log( new QuoteOrderTestLogger() ), $budget );
		foreach ( range( 1, 5 ) as $guess ) {
			self::assertStringContainsString( 'This catalog link has expired', $this->redeem( str_pad( 'guess-' . $guess, 43, 'q' ) ) );
		}
		self::assertCount( 2, array_filter( $this->db->audits, static fn( array $row ): bool => 'token_reject' === $row['event'] ), 'Two refusals logged, the rest answered without a row' );
		$this->redeem( $this->claim( 61 ) );
		self::assertSame( 'ok', $this->audited( 'token_redeem' )['result'] ?? null, 'A redeemed login is recorded whatever the budget' );
	}

	public function test_the_cookie_ttl_filter_is_installed_and_removed_on_every_path(): void {
		$token = $this->claim( 42 );
		$this->redeem( $token );

		self::assertSame( [ [ 'add', 'auth_cookie_expiration', 999 ], [ 'remove', 'auth_cookie_expiration', 999 ] ], $GLOBALS['pow_start_hooks'] );
	}

	public function test_a_login_that_fails_after_the_cookie_filter_removes_it_and_tears_the_visit_down(): void {
		$token = $this->claim( 42 );
		$GLOBALS['pow_start_cookie_error'] = new RuntimeException( 'cookie failed' );

		$response = $this->redeem( $token );

		self::assertSame( [ [ 'add', 'auth_cookie_expiration', 999 ], [ 'remove', 'auth_cookie_expiration', 999 ] ], $GLOBALS['pow_start_hooks'] );
		// The visit is gone: expired, its login destroyed, its cookies cleared
		// and the request no longer acting as the customer.
		self::assertSame( Session::EXPIRED, $this->db->sessions[42]['status'] );
		self::assertCount( 1, $GLOBALS['pow_test_destroyed_tokens'] );
		self::assertSame( [], $GLOBALS['pow_test_session_tokens'][ self::ACCOUNT ] );
		self::assertSame( [ true ], $GLOBALS['pow_test_cookies_cleared'] );
		self::assertSame( [ self::ACCOUNT, 0 ], $GLOBALS['pow_test_current_user_switches'] );
		self::assertStringContainsString( 'This catalog link has expired', $response );
		self::assertSame( '403', $this->audited( 'token_reject' )['result'] );
		self::assertSame( 'active', $this->db->partners[ self::PARTNER ]['status'], 'A confirmed teardown never fences the connection.' );
	}

	public function test_a_refused_binding_never_installs_the_cookie_filter(): void {
		$token = $this->claim( 42 );
		// A claim whose key was already taken can never bind: the UPDATE
		// requires a NULL key, and no retry can make one appear.
		$this->db->after_write = null;
		$this->db->write_fails = true;

		$response = $this->redeem( $token );

		self::assertSame( [], $GLOBALS['pow_start_hooks'] ?? [] );
		self::assertSame( [], $GLOBALS['pow_test_auth_cookies'] ?? [] );
		self::assertStringContainsString( 'This catalog link has expired', $response );
	}
}
}
