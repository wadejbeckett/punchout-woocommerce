<?php
/** Visit lockdown: route scoping, the My Account endpoint list, the cross-visit order fence, the wp-admin/REST/application-password doors and the user-creation veto. @package POW */
declare( strict_types = 1 );

namespace POW {
	// RouteGuard redirects and dies by terminating the request. Throwing
	// from the seam records the decision and skips the exit that follows.
	function wp_safe_redirect( string $url, int $status = 302 ): void { throw new \RouteGuardRedirect( $url, $status ); }
	function wp_die( mixed $message = '', mixed $title = '', array $args = [] ): void { throw new \RouteGuardHalt( (string) $message, (int) ( $args['response'] ?? 0 ) ); }
	function sanitize_key( string $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ); }
	function absint( mixed $value ): int { return abs( (int) $value ); }
}

namespace {
	// The WooCommerce page-context surface RouteGuard asks for by
	// function_exists(), which resolves global names only. Inert until a
	// test fills pow_route_guard_test.
	if ( ! function_exists( 'is_account_page' ) ) { function is_account_page(): bool { return (bool) ( $GLOBALS['pow_route_guard_test']['account'] ?? false ); } }
	if ( ! function_exists( 'is_checkout' ) ) { function is_checkout(): bool { return (bool) ( $GLOBALS['pow_route_guard_test']['checkout'] ?? false ); } }
	if ( ! function_exists( 'is_wc_endpoint_url' ) ) { function is_wc_endpoint_url( string $endpoint = '' ): bool { return '' !== $endpoint && $endpoint === ( $GLOBALS['pow_route_guard_test']['endpoint'] ?? '' ); } }
	if ( ! function_exists( 'wc_get_cart_url' ) ) { function wc_get_cart_url(): string { return 'https://shop.example.test/cart/'; } }

	// WooCommerce's answer to "is this AJAX", which it derives from the
	// ?wc-ajax= query parameter on every request. RouteGuard deliberately
	// does not ask it any more; it stays here so a test can set it true and
	// prove the guard is no longer switched off by it.
	if ( ! function_exists( 'wp_doing_ajax' ) ) { function wp_doing_ajax(): bool { return (bool) ( $GLOBALS['pow_route_guard_test']['ajax'] ?? false ); } }

	// Whether an account-endpoint action would render a query var: the
	// fixture names the query vars that have one.
	if ( ! function_exists( 'has_action' ) ) {
		function has_action( string $hook, mixed $callback = false ): bool {
			foreach ( $GLOBALS['pow_route_guard_test']['account_actions'] ?? [] as $endpoint ) {
				if ( 'woocommerce_account_' . $endpoint . '_endpoint' === $hook ) { return true; }
			}
			return false;
		}
	}

	// The cookie a WordPress request carries its session token in, and the
	// only place wp_get_session_token() looks. A visit's teardown forgets it.
	if ( ! defined( 'LOGGED_IN_COOKIE' ) ) { define( 'LOGGED_IN_COOKIE', 'wordpress_logged_in_pow_test' ); }

	/** The redirect RouteGuard would have sent. */
	final class RouteGuardRedirect extends RuntimeException {
		public function __construct( public string $url, public int $status ) { parent::__construct( 'redirect ' . $url ); }
	}

	/** The wp_die() RouteGuard would have emitted. */
	final class RouteGuardHalt extends RuntimeException {
		public function __construct( string $message, public int $status ) { parent::__construct( $message ); }
	}

	/** In-memory session rows keyed by their own WP login token, one per visit. */
	final class RouteGuardStore extends POW\Sessions\Store {
		/** @var array<string, POW\Sessions\Session> */
		public array $rows = [];
		public int $lookups = 0;
		public bool $unreachable = false;
		/** @var list<int> Visits a teardown was asked for, in order. */
		public array $expired = [];
		/** @var list<int> Those of them asked for outside the connection lock, which must stay empty. */
		public array $unlocked = [];
		public bool $refuse_teardown = false;
		public function find_for_login( int $user_id, string $wp_session_token, array $statuses = [ POW\Sessions\Session::ACTIVE ] ): ?POW\Sessions\Session {
			++$this->lookups;
			if ( $this->unreachable ) { throw new RuntimeException( 'Session lookup failed.' ); }
			$row = $this->rows[ $wp_session_token ] ?? null;
			return $row && $user_id === $row->user_id && in_array( $row->status, $statuses, true ) ? $row : null;
		}
		/**
		 * The one write that touches the account's shared session_tokens row.
		 *
		 * The real body is proved elsewhere; what matters here is that the
		 * caller reached it through Store::expire_and_destroy(), i.e. with the
		 * connection lock held, and that it ended this visit and no other.
		 */
		public function expire_locked( POW\Sessions\Session $session ): bool {
			$this->expired[] = $session->id;
			$database = $GLOBALS['wpdb'] ?? null;
			if ( ! $database instanceof RouteGuardLockDatabase || ! $database->held ) { $this->unlocked[] = $session->id; }
			if ( $this->refuse_teardown ) { return false; }
			unset( $this->rows[ $session->wp_session_token ] );
			return true;
		}
	}

	/**
	 * Enough of $wpdb for Registry::with_partner_lock(), and a record of
	 * whether the lock is held at the moment a write happens.
	 */
	final class RouteGuardLockDatabase {
		public string $prefix = 'fixture_';
		public string $last_error = '';
		public bool $held = false;
		/** @var list<string> */
		public array $events = [];
		private array $prepared = [];
		public function prepare( string $sql, mixed ...$args ): string {
			$key = $sql . ' /* ' . count( $this->prepared ) . ' */';
			$this->prepared[ $key ] = $sql;
			return $key;
		}
		public function suppress_errors( bool $suppress = true ): bool { return false; }
		public function get_var( string $key ): mixed {
			$sql = $this->prepared[ $key ] ?? $key;
			if ( str_contains( $sql, 'GET_LOCK' ) ) { $this->events[] = 'lock'; $this->held = true; return '1'; }
			if ( str_contains( $sql, 'RELEASE_LOCK' ) ) { $this->events[] = 'release'; $this->held = false; return '1'; }
			throw new RuntimeException( 'Unexpected SQL' );
		}
	}

	/**
	 * WC_Query as far as the guard reads it: the endpoint map (name => slug)
	 * and WooCommerce's own "current endpoint", which is the first name of
	 * that map the request's query vars set.
	 */
	final class RouteGuardWcQuery {
		/** @param array<string, string> $vars */
		public function __construct( private array $vars ) {}
		/** @return array<string, string> */
		public function get_query_vars(): array { return $this->vars; }
		public function get_current_endpoint(): string {
			foreach ( $this->vars as $key => $slug ) {
				if ( isset( $GLOBALS['wp']->query_vars[ $key ] ) ) { return $key; }
			}
			return '';
		}
	}

	/** Enough of $wpdb for Registry::find(): partner rows by id, or a failed lookup. */
	final class RouteGuardPartnerDatabase {
		public string $prefix = 'fixture_';
		public string $last_error = '';
		public bool $unreachable = false;
		public int $lookups = 0;
		/** @var array<int, array<string, mixed>> */
		public array $rows = [];
		private array $prepared = [];
		public function prepare( string $sql, mixed ...$args ): string {
			$key = 'q' . count( $this->prepared );
			$this->prepared[ $key ] = [ $sql, $args ];
			return $key;
		}
		public function suppress_errors( bool $suppress = true ): bool { return false; }
		public function get_row( string $key, mixed $format = null ): ?array {
			[ $sql, $args ] = $this->prepared[ $key ];
			if ( ! str_contains( $sql, 'fixture_pow_partners WHERE id =' ) ) { throw new RuntimeException( 'Unexpected SQL' ); }
			++$this->lookups;
			$this->last_error = $this->unreachable ? 'Lookup failed.' : '';
			return $this->unreachable ? null : ( $this->rows[ (int) $args[0] ] ?? null );
		}
	}

	/** The audit trail as the guard writes to it. */
	final class RouteGuardAudit extends POW\Audit\Log {
		/** @var list<array{0: string, 1: array<string, mixed>}> */
		public array $events = [];
		public function __construct() {}
		public function write_checked( string $event, array $context = [] ): bool { $this->events[] = [ $event, $context ]; return true; }
	}

	/** A REST request as rest_pre_dispatch hands it over: only its route matters here. */
	final class RouteGuardRestRequest {
		public function __construct( private string $route ) {}
		public function get_route(): string { return $this->route; }
	}

final class RouteGuardTest extends PHPUnit\Framework\TestCase {

	private const ACCOUNT = 20;
	private const VISIT   = 44;

	private array $saved = [];
	private array $saved_request = [];

	protected function setUp(): void {
		// The request itself is fixture too: the wp-admin door reads the
		// running script, the logout door reads the action, the nonce and the
		// session-token cookie.
		$this->saved_request = [ 'request' => $_REQUEST, 'get' => $_GET, 'cookie' => $_COOKIE, 'script' => $_SERVER['SCRIPT_FILENAME'] ?? null, 'uri' => $_SERVER['REQUEST_URI'] ?? null ];
		$_REQUEST = [];
		$_GET     = [];
		$_COOKIE  = [];
		$_SERVER['SCRIPT_FILENAME'] = '/srv/www/wp-admin/index.php';

		foreach ( [ 'wp', 'wpdb', 'pow_route_guard_test', 'pow_test_current_user_id', 'pow_test_options', 'pow_test_users', 'pow_test_roles', 'pow_test_login_token', 'pow_test_login_tokens', 'pow_test_login_token_index', 'pow_test_orders', 'pow_test_filters', 'pow_test_valid_nonce', 'pow_test_wc' ] as $key ) {
			$this->saved[ $key ] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null ];
			unset( $GLOBALS[ $key ] );
		}

		$GLOBALS['pow_route_guard_test']    = [ 'account' => false, 'checkout' => false, 'endpoint' => '', 'ajax' => false ];
		$GLOBALS['pow_test_current_user_id'] = self::ACCOUNT;
		$GLOBALS['pow_test_users']          = [ self::ACCOUNT => (object) [ 'ID' => self::ACCOUNT, 'roles' => [ 'customer' ], 'allcaps' => [ 'read' => true ] ] ];
		$GLOBALS['pow_test_options']        = [ POW\Settings::OPTION_KEY => [ 'enabled' => 'yes' ] ];
		$GLOBALS['pow_test_orders']         = [];
		$GLOBALS['wp']                      = (object) [ 'query_vars' => [] ];
	}

	protected function tearDown(): void {
		foreach ( $this->saved as $key => [ $exists, $value ] ) {
			if ( $exists ) { $GLOBALS[ $key ] = $value; } else { unset( $GLOBALS[ $key ] ); }
		}

		$_REQUEST = $this->saved_request['request'];
		$_GET     = $this->saved_request['get'];
		$_COOKIE  = $this->saved_request['cookie'];
		if ( null === $this->saved_request['script'] ) { unset( $_SERVER['SCRIPT_FILENAME'] ); } else { $_SERVER['SCRIPT_FILENAME'] = $this->saved_request['script']; }
		if ( null === $this->saved_request['uri'] ) { unset( $_SERVER['REQUEST_URI'] ); } else { $_SERVER['REQUEST_URI'] = $this->saved_request['uri']; }
	}

	/** A visit row for the shared customer account. */
	private function visit( int $id = self::VISIT, string $status = 'active', int $partner_id = 7, string $token = 'visit-a' ): POW\Sessions\Session {
		return POW\Sessions\Session::from_row( [
			'id'              => $id,
			'partner_id'      => $partner_id,
			'user_id'         => self::ACCOUNT,
			'status'          => $status,
			'wp_session_token' => $token,
			'wc_session_key'  => 'pow_' . substr( str_repeat( dechex( $id ), 28 ), 0, 28 ),
			'expires'         => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
		] );
	}

	/** RouteGuard on a container whose resolved visit is $session. */
	private function guard( ?POW\Sessions\Session $session, ?RouteGuardStore $store = null, ?RouteGuardAudit $audit = null ): POW\RouteGuard {
		$plugin   = ( new ReflectionClass( POW\Plugin::class ) )->newInstanceWithoutConstructor();
		$registry = new POW\Partners\Registry( new POW\Partners\Secrets( str_repeat( 'r', 32 ) ) );
		$settings = new POW\Settings();

		$values = [
			'registry'        => $registry,
			'sessions'        => $store ?? new RouteGuardStore(),
			'settings'        => $settings,
			'current_session' => $store ? null : $session,
			'session_resolved' => null === $store,
			'audit'           => $audit,
		];

		foreach ( $values as $key => $value ) {
			( new ReflectionProperty( $plugin, $key ) )->setValue( $plugin, $value );
		}

		return new POW\RouteGuard( $plugin, $registry, $settings );
	}

	/** A quote order as QuoteOrder stamps it, registered for wc_get_order(). */
	private function quote_order( int $id, ?int $session_id, int $partner_id = 7 ): WC_Order {
		$order = new WC_Order( $id );
		if ( null !== $session_id ) {
			$order->update_meta_data( POW\Orders\QuoteOrder::META_SESSION_ID, (string) $session_id );
			$order->update_meta_data( POW\Orders\QuoteOrder::META_PARTNER_ID, (string) $partner_id );
		}
		$GLOBALS['pow_test_orders'][ $id ] = $order;
		return $order;
	}

	/** The redirect guard() produced, or null when it let the request through. */
	private function guarded( POW\RouteGuard $guard ): ?RouteGuardRedirect {
		try { $guard->guard(); } catch ( RouteGuardRedirect $redirect ) { return $redirect; }
		return null;
	}

	private function landing(): string { return ( new POW\Settings() )->landing_url(); }

	public function test_a_visit_never_checks_out_and_an_ordinary_shopper_always_does(): void {
		self::assertFalse( $this->guard( null )->checkout_blocked_for_visit(), 'An ordinary shopper keeps native checkout' );

		// The bound customer account signed in with its own password is an
		// ordinary shopper: only a live visit blocks checkout.
		$GLOBALS['pow_test_current_user_id'] = self::ACCOUNT;
		self::assertFalse( $this->guard( null )->checkout_blocked_for_visit(), 'The bound account outside a visit checks out normally' );

		foreach ( [ 'active', 'ordered' ] as $status ) {
			self::assertTrue( $this->guard( $this->visit( self::VISIT, $status ) )->checkout_blocked_for_visit(), "A {$status} visit cannot check out" );
		}
	}

	public function test_classic_checkout_redirects_to_the_cart_only_inside_a_visit(): void {
		$GLOBALS['pow_route_guard_test']['checkout'] = true;

		self::assertNull( $this->guarded( $this->guard( null ) ), 'An ordinary shopper reaches /checkout' );

		$redirect = $this->guarded( $this->guard( $this->visit() ) );
		self::assertNotNull( $redirect, 'A visit is turned away from /checkout' );
		self::assertSame( 'https://shop.example.test/cart/', $redirect->url );
	}

	public function test_order_and_payment_boundaries_refuse_inside_a_visit(): void {
		$guard = $this->guard( $this->visit() );
		$order = $this->quote_order( 501, self::VISIT );

		try { $guard->enforce_order( $order ); self::fail( 'enforce_order must refuse inside a visit' ); }
		catch ( Exception $error ) { self::assertStringContainsString( 'Punchout', $error->getMessage() ); }

		try { $guard->block_store_api_checkout( $order ); self::fail( 'The Store API route must refuse inside a visit' ); }
		catch ( RouteGuardHalt $halt ) { self::assertStringContainsString( 'catalog session', $halt->getMessage() ); self::assertSame( 403, $halt->status ); }

		try { $guard->enforce_pay_action( $order ); self::fail( 'order-pay must refuse inside a visit' ); }
		catch ( RouteGuardHalt $halt ) { self::assertSame( 403, $halt->status ); }
	}

	public function test_a_quote_order_is_never_payable_outside_its_visit(): void {
		$guard = $this->guard( null );
		$quote = $this->quote_order( 502, 99 );

		self::assertTrue( $guard->checkout_blocked_for_visit( $quote ), 'A punchout-tagged order is not payable outside its visit' );
		self::assertFalse( $guard->checkout_blocked_for_visit( new WC_Order( 503 ) ), 'An ordinary order stays payable' );

		try { $guard->enforce_order( $quote ); self::fail( 'A tagged order must be refused' ); }
		catch ( Exception $error ) {
			self::assertStringContainsString( 'PunchOut', $error->getMessage() );
			self::assertStringNotContainsString( 'Please use', $error->getMessage(), 'Outside a visit there is no return button to offer' );
		}
	}

	public function test_a_visit_cannot_open_another_visits_quote_order(): void {
		$mine  = $this->quote_order( 601, self::VISIT );
		$other = $this->quote_order( 602, 45 );
		$plain = new WC_Order( 603 );
		$GLOBALS['pow_test_orders'][603] = $plain;

		foreach ( [ 'order-received', 'order-pay', 'view-order' ] as $endpoint ) {
			$GLOBALS['pow_route_guard_test']['endpoint'] = $endpoint;

			$GLOBALS['wp']->query_vars = [ $endpoint => $other->get_id() ];
			$redirect = $this->guarded( $this->guard( $this->visit() ) );
			self::assertNotNull( $redirect, "{$endpoint} must refuse another visit's order" );
			self::assertSame( $this->landing(), $redirect->url );

			$GLOBALS['wp']->query_vars = [ $endpoint => $plain->get_id() ];
			self::assertNotNull( $this->guarded( $this->guard( $this->visit() ) ), "{$endpoint} must refuse an order carrying no punchout meta" );

			$GLOBALS['wp']->query_vars = [ $endpoint => 999 ];
			self::assertNotNull( $this->guarded( $this->guard( $this->visit() ) ), "{$endpoint} must refuse an order that does not exist" );

			$GLOBALS['wp']->query_vars = [ $endpoint => $mine->get_id() ];
			$own = $this->guarded( $this->guard( $this->visit() ) );
			if ( 'order-pay' === $endpoint ) {
				self::assertNotNull( $own, 'A visit may read its own order but never pay it' );
			} else {
				self::assertNull( $own, "A visit reads its own order at {$endpoint}" );
			}

			// Outside a visit the fence is WooCommerce's own order-key check.
			$GLOBALS['wp']->query_vars = [ $endpoint => $other->get_id() ];
			$shopper = $this->guarded( $this->guard( null ) );
			if ( 'order-pay' === $endpoint ) {
				self::assertNotNull( $shopper, 'A punchout quote order is never payable' );
			} else {
				self::assertNull( $shopper, 'Ordinary shoppers keep native order pages' );
			}
		}
	}

	public function test_my_account_including_account_details_and_password_is_refused_inside_a_visit(): void {
		$GLOBALS['pow_route_guard_test']['account'] = true;

		foreach ( [ 'edit-account', 'lost-password', 'punchout-integration', 'customer-logout', '' ] as $endpoint ) {
			$GLOBALS['pow_route_guard_test']['endpoint'] = $endpoint;
			$redirect = $this->guarded( $this->guard( $this->visit() ) );
			self::assertNotNull( $redirect, "My Account ({$endpoint}) must be unreachable inside a visit" );
			self::assertSame( $this->landing(), $redirect->url );
			self::assertNull( $this->guarded( $this->guard( null ) ), 'An ordinary shopper keeps My Account' );
		}
	}

	/** WooCommerce's endpoint map on this fixture: its own account endpoints, the plugin's tab and three third-party ones. */
	private const WC_ENDPOINTS = [
		'order-pay', 'order-received', 'orders', 'view-order', 'downloads', 'edit-account', 'edit-address', 'payment-methods', 'lost-password',
		'customer-logout', 'add-payment-method', 'delete-payment-method', 'set-default-payment-method', 'punchout-integration',
		'bulkorder', 'purchase-lists', 'subaccounts',
	];

	/** Connection 7, active, listing $endpoints exactly as the row would hold them. */
	private function connection( string $endpoints, string $status = 'active' ): RouteGuardPartnerDatabase {
		$GLOBALS['wpdb'] = $database = new RouteGuardPartnerDatabase();
		$database->rows[7] = [ 'id' => 7, 'name' => 'Example', 'status' => $status, 'owner_user_id' => self::ACCOUNT, 'visit_endpoints' => $endpoints ];
		WC()->query = new RouteGuardWcQuery( array_combine( self::WC_ENDPOINTS, self::WC_ENDPOINTS ) );
		$GLOBALS['pow_route_guard_test']['account'] = true;
		return $database;
	}

	/** An account page request carrying these query vars, as WordPress and WooCommerce leave them. */
	private function account_request( array $endpoints ): void {
		$GLOBALS['wp']->query_vars = [ 'pagename' => 'my-account' ] + array_fill_keys( $endpoints, '' );
	}

	/** Whether guard() let an account page request naming $endpoints through. */
	private function opens( array $endpoints, ?POW\Sessions\Session $visit ): bool {
		$this->account_request( $endpoints );
		$redirect = $this->guarded( $this->guard( $visit ) );
		if ( null !== $redirect ) { self::assertSame( $this->landing(), $redirect->url ); }
		return null === $redirect;
	}

	public function test_a_listed_endpoint_opens_inside_a_visit_and_nothing_else_does(): void {
		$this->connection( 'bulkorder,purchase-lists' );

		self::assertTrue( $this->opens( [ 'bulkorder' ], $this->visit() ), 'A listed third-party endpoint opens inside a visit' );
		self::assertTrue( $this->opens( [ 'purchase-lists' ], $this->visit() ), 'Every listed endpoint opens' );
		self::assertFalse( $this->opens( [ 'subaccounts' ], $this->visit() ), 'An endpoint the connection does not list stays closed' );
		self::assertFalse( $this->opens( [], $this->visit() ), 'The dashboard stays closed' );
		self::assertFalse( $this->opens( [ 'bulkorder', 'orders' ], $this->visit() ), 'A second endpoint carried alongside a listed one closes the page' );
		self::assertFalse( $this->opens( [ 'purchase-lists', 'subaccounts' ], $this->visit() ), 'Every endpoint the request names must be listed' );

		foreach ( [ [ 'bulkorder' ], [ 'subaccounts' ], [ 'orders' ], [] ] as $endpoints ) {
			self::assertTrue( $this->opens( $endpoints, null ), 'Outside a visit My Account is untouched: ' . implode( ',', $endpoints ) );
		}
	}

	/**
	 * The hard-deny list is the guard's, not the row's: a row edited by hand
	 * to list the shared login's own surfaces still opens none of them.
	 */
	public function test_hard_denied_endpoints_stay_closed_even_when_a_row_lists_them(): void {
		$denied = array_values( array_diff( POW\Account\VisitEndpoints::HARD_DENY, [ '', 'dashboard' ] ) );
		$this->connection( 'bulkorder,dashboard,' . implode( ',', $denied ) );

		self::assertSame( [ 'bulkorder' ], POW\Partners\Partner::from_row( $GLOBALS['wpdb']->rows[7] )->visit_endpoint_list(), 'The row reads back without the hard-denied entries' );
		self::assertContains( POW\Account\IntegrationTab::ENDPOINT, $denied, "The plugin's own tab is on the list by its constant" );

		foreach ( $denied as $endpoint ) {
			self::assertFalse( $this->opens( [ $endpoint ], $this->visit() ), "{$endpoint} never opens inside a visit" );
		}
		self::assertFalse( $this->opens( [], $this->visit() ), 'Nor does the dashboard' );
		self::assertTrue( $this->opens( [ 'bulkorder' ], $this->visit() ), 'The listed endpoint beside them still opens' );
	}

	public function test_an_empty_disabled_missing_or_unreadable_connection_keeps_my_account_closed(): void {
		$this->connection( '' );
		self::assertFalse( $this->opens( [ 'bulkorder' ], $this->visit() ), 'The default empty list opens nothing' );

		$this->connection( 'bulkorder', 'disabled' );
		self::assertFalse( $this->opens( [ 'bulkorder' ], $this->visit() ), 'A connection that is not active opens nothing' );

		$this->connection( 'bulkorder' );
		self::assertFalse( $this->opens( [ 'bulkorder' ], $this->visit( self::VISIT, 'active', 8 ) ), "A visit whose connection row is gone opens nothing, and never another connection's list" );

		$database = $this->connection( 'bulkorder' );
		$database->unreachable = true;
		self::assertFalse( $this->opens( [ 'bulkorder' ], $this->visit() ), 'A connection that cannot be read opens nothing' );

		unset( $GLOBALS['wpdb'] );
		self::assertFalse( $this->opens( [ 'bulkorder' ], $this->visit() ), 'No database at all opens nothing' );
	}

	/** WooCommerce renders the first query var with an account-endpoint action, whether or not its own map knows the name. */
	public function test_a_query_var_an_account_action_would_render_must_be_listed_too(): void {
		$this->connection( 'bulkorder' );
		$GLOBALS['pow_route_guard_test']['account_actions'] = [ 'bulkorder', 'unmapped' ];

		self::assertTrue( $this->opens( [ 'bulkorder' ], $this->visit() ) );
		self::assertFalse( $this->opens( [ 'unmapped', 'bulkorder' ], $this->visit() ), 'An endpoint WooCommerce would render but does not map is still judged' );

		$this->connection( 'unmapped' );
		self::assertTrue( $this->opens( [ 'unmapped' ], $this->visit() ), 'Listed, it opens like any other' );
	}

	/** Before WordPress has parsed the request, its path and query string name the endpoint. */
	public function test_before_the_request_is_parsed_the_path_names_the_endpoint(): void {
		$this->connection( 'bulkorder' );
		$request = function ( string $uri, array $get = [] ): bool {
			$GLOBALS['wp']->query_vars = [];
			$_SERVER['REQUEST_URI']    = $uri;
			$_GET                      = $get;
			return null === $this->guarded( $this->guard( $this->visit() ) );
		};

		self::assertTrue( $request( '/my-account/bulkorder/' ), 'The listed endpoint opens from its path' );
		self::assertFalse( $request( '/my-account/' ), 'The dashboard stays closed' );
		self::assertFalse( $request( '/my-account/orders/' ), 'A hard-denied endpoint stays closed' );
		self::assertFalse( $request( '/my-account/bulkorder/', [ 'orders' => '1' ] ), 'A query-string endpoint beside it closes the page' );
		self::assertFalse( $request( '/?page_id=9', [ 'edit-account' => '' ] ), 'Plain permalinks are read the same way' );

		WC()->query = null;
		self::assertFalse( $request( '/my-account/bulkorder/' ), 'Without WooCommerce\'s endpoint map nothing can be proved, so nothing opens' );
	}

	public function test_the_account_menu_inside_a_visit_offers_only_listed_endpoints(): void {
		$items = [
			'dashboard' => 'Dashboard', 'orders' => 'Orders', 'bulkorder' => 'Quick order', 'purchase-lists' => 'Lists', 'subaccounts' => 'Subaccounts',
			'edit-address' => 'Addresses', 'edit-account' => 'Account details', 'punchout-integration' => 'Punchout integration', 'customer-logout' => 'Log out',
		];

		$this->connection( 'bulkorder,purchase-lists' );
		self::assertSame( [ 'bulkorder' => 'Quick order', 'purchase-lists' => 'Lists' ], $this->guard( $this->visit() )->visit_menu_items( $items ), 'Only listed endpoints stay in a visit\'s menu' );
		self::assertSame( $items, $this->guard( null )->visit_menu_items( $items ), 'Outside a visit the menu is untouched' );
		self::assertSame( 'not-a-menu', $this->guard( $this->visit() )->visit_menu_items( 'not-a-menu' ), 'Something that is not a menu is passed on' );

		$this->connection( 'bulkorder,dashboard,orders,customer-logout' );
		self::assertSame( [ 'bulkorder' => 'Quick order' ], $this->guard( $this->visit() )->visit_menu_items( $items ), 'A hard-denied item never survives, whoever listed it' );

		$this->connection( '' );
		self::assertSame( [], $this->guard( $this->visit() )->visit_menu_items( $items ), 'An empty list leaves an empty menu' );

		$store = new RouteGuardStore();
		$store->unreachable = true;
		$GLOBALS['pow_test_login_token'] = 'visit-a';
		self::assertSame( [], $this->guard( null, $store )->visit_menu_items( $items ), 'A request whose visit cannot be proved gets an empty menu' );
	}

	/**
	 * P2: a visit creates no user. admin-ajax.php stays reachable inside a
	 * visit so the basket works, which also makes every plugin action behind
	 * it reachable; a sub-account form there would otherwise hand a buyer a
	 * password login of its own.
	 */
	public function test_no_user_is_created_inside_a_visit_and_the_refusal_is_audited(): void {
		$audit = new RouteGuardAudit();
		$data  = [ 'user_login' => 'new-colleague', 'user_email' => 'new-colleague@example.invalid' ];
		$_SERVER['SCRIPT_FILENAME'] = '/srv/www/wp-admin/admin-ajax.php';
		$_REQUEST['action']         = 'example_create_subaccount';

		self::assertSame( [], $this->guard( $this->visit(), null, $audit )->refuse_user_creation( $data, false, null, $data ), 'Creation inside a visit gets an empty row, which core refuses with a WP_Error' );
		self::assertCount( 1, $audit->events );
		[ $event, $context ] = $audit->events[0];
		self::assertSame( 'visit_user_create_refused', $event );
		self::assertSame( [ 7, self::VISIT, self::ACCOUNT, 'refused' ], [ $context['partner_id'], $context['session_id'], $context['user_id'], $context['result'] ] );
		self::assertSame( [ 'script' => 'admin-ajax.php', 'action' => 'example_create_subaccount' ], $context['detail'] );
		self::assertStringNotContainsString( 'new-colleague', (string) json_encode( $audit->events ), 'Nothing about the refused account is recorded' );

		$audit->events = [];
		self::assertSame( $data, $this->guard( $this->visit(), null, $audit )->refuse_user_creation( $data, true, self::ACCOUNT, $data ), 'Updating an existing user is not creation' );
		self::assertSame( $data, $this->guard( null, null, $audit )->refuse_user_creation( $data, false, null, $data ), 'Outside a visit users are created normally' );
		self::assertSame( [], $audit->events, 'Nothing refused, nothing recorded' );

		$store = new RouteGuardStore();
		$store->unreachable = true;
		$GLOBALS['pow_test_login_token'] = 'visit-a';
		self::assertSame( [], $this->guard( null, $store, $audit )->refuse_user_creation( $data, false, null, $data ), 'A request whose visit cannot be proved creates no user' );
		self::assertSame( [ 0, 0 ], [ $audit->events[0][1]['partner_id'], $audit->events[0][1]['session_id'] ], 'An unproven visit is recorded without one' );
	}

	/** Both filters run last, so nothing registered after them can undo the decision. */
	public function test_the_menu_filter_and_the_user_veto_are_registered_last(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/RouteGuard.php' );
		self::assertStringContainsString( "add_filter( 'woocommerce_account_menu_items', [ \$this, 'visit_menu_items' ], PHP_INT_MAX );", $source );
		self::assertStringContainsString( "add_filter( 'wp_pre_insert_user_data', [ \$this, 'refuse_user_creation' ], PHP_INT_MAX, 4 );", $source );
	}

	public function test_wp_admin_is_refused_inside_a_visit_without_breaking_ajax(): void {
		$guard = $this->guard( $this->visit() );

		$_SERVER['SCRIPT_FILENAME'] = '/srv/www/wp-admin/profile.php';
		try { $guard->guard_admin(); self::fail( 'wp-admin must be refused inside a visit' ); }
		catch ( RouteGuardRedirect $redirect ) { self::assertSame( $this->landing(), $redirect->url ); }

		// The exemption is the one script a front-end basket calls.
		$_SERVER['SCRIPT_FILENAME'] = '/srv/www/wp-admin/admin-ajax.php';
		$guard->guard_admin();

		$_SERVER['SCRIPT_FILENAME'] = '/srv/www/wp-admin/profile.php';
		$this->guard( null )->guard_admin();
	}

	/**
	 * The wp-admin refusal may not depend on anything the buyer can send.
	 *
	 * WooCommerce defines DOING_AJAX from its own ?wc-ajax= query parameter at
	 * `init` priority 0 on every request, wp-admin page loads included, so
	 * wp_doing_ajax() answers whatever the request asks it to — and the screen
	 * behind it, profile.php, changes the password and e-mail address of the
	 * account every colleague of the connection shares.
	 */
	public function test_a_query_parameter_cannot_switch_off_the_wp_admin_refusal(): void {
		$GLOBALS['pow_route_guard_test']['ajax'] = true;
		$_GET['wc-ajax'] = '1';
		$_REQUEST['wc-ajax'] = '1';

		foreach ( [ 'profile.php', 'user-edit.php', 'admin-post.php', 'admin.php', 'options-general.php' ] as $script ) {
			$_SERVER['SCRIPT_FILENAME'] = '/srv/www/wp-admin/' . $script;
			try { $this->guard( $this->visit() )->guard_admin(); self::fail( $script . ' must stay refused inside a visit' ); }
			catch ( RouteGuardRedirect $redirect ) { self::assertSame( $this->landing(), $redirect->url ); }
		}

		// The genuine AJAX entry point stays open, query parameter or not.
		$_SERVER['SCRIPT_FILENAME'] = '/srv/www/wp-admin/admin-ajax.php';
		$this->guard( $this->visit() )->guard_admin();

		// A request whose script cannot be read is not provably that one.
		unset( $_SERVER['SCRIPT_FILENAME'] );
		try { $this->guard( $this->visit() )->guard_admin(); self::fail( 'An unreadable script name keeps the refusal' ); }
		catch ( RouteGuardRedirect $redirect ) { self::assertSame( $this->landing(), $redirect->url ); }
	}

	public function test_users_and_application_password_rest_routes_are_refused_inside_a_visit(): void {
		$visit   = $this->guard( $this->visit() );
		$shopper = $this->guard( null );

		foreach ( [ '/wp/v2/users', '/wp/v2/users/20', '/wp/v2/users/me', '/wp/v2/users/20/application-passwords', '/wp/v2/application-passwords/introspect' ] as $route ) {
			$refusal = $visit->guard_rest( null, null, new RouteGuardRestRequest( $route ) );
			self::assertTrue( is_wp_error( $refusal ), "{$route} must be refused inside a visit" );
			self::assertSame( 'pow_visit_locked', $refusal->get_error_code() );
			self::assertNull( $shopper->guard_rest( null, null, new RouteGuardRestRequest( $route ) ), 'Outside a visit REST is untouched' );
		}

		foreach ( [ '/wc/store/v1/cart', '/wp/v2/posts', '/', '/wp/v2/user-invented' ] as $route ) {
			self::assertNull( $visit->guard_rest( null, null, new RouteGuardRestRequest( $route ) ), "{$route} stays available inside a visit" );
		}
	}

	public function test_application_passwords_are_unavailable_inside_a_visit(): void {
		self::assertFalse( $this->guard( $this->visit() )->deny_application_passwords( true, null ), 'A visit cannot mint an application password' );
		self::assertTrue( $this->guard( null )->deny_application_passwords( true, null ), 'Outside a visit the native answer stands' );
		self::assertFalse( $this->guard( null )->deny_application_passwords( false, null ), 'A native refusal is never widened' );
	}

	public function test_the_login_screen_allows_only_logout_and_ends_one_visit(): void {
		$store = new RouteGuardStore();
		$store->rows = [ 'visit-a' => $this->visit( self::VISIT, 'active', 7, 'visit-a' ), 'visit-b' => $this->visit( 45, 'active', 7, 'visit-b' ) ];
		$GLOBALS['pow_test_login_tokens']      = [ 'visit-a', 'visit-b' ];
		$GLOBALS['pow_test_login_token_index'] = 0;

		$saved_request = $_REQUEST;

		try {
			$_REQUEST['action'] = 'login';
			try { $this->guard( null, $store )->guard_login_screen(); self::fail( 'wp-login.php is refused inside a visit' ); }
			catch ( RouteGuardRedirect $redirect ) { self::assertSame( $this->landing(), $redirect->url ); }

			// logout passes through: WordPress destroys this request's token
			// only, so the colleague on the same account keeps her visit.
			$_REQUEST['action'] = 'logout';
			$this->guard( null, $store )->guard_login_screen();

			unset( $store->rows['visit-a'] );
			$GLOBALS['pow_test_login_token_index'] = 1;
			$sibling = $this->guard( null, $store );
			$GLOBALS['pow_route_guard_test']['checkout'] = true;
			self::assertTrue( $sibling->checkout_blocked_for_visit(), 'The second visit still resolves after the first logs out' );

			// A non-string action is not the one action a visit may take.
			$GLOBALS['pow_test_login_token_index'] = 0;
			$store->rows['visit-a'] = $this->visit( self::VISIT, 'active', 7, 'visit-a' );
			$_REQUEST['action'] = [ 'logout' ];
			try { $this->guard( null, $store )->guard_login_screen(); self::fail( 'An array action is not a logout' ); }
			catch ( RouteGuardRedirect $redirect ) { self::assertSame( $this->landing(), $redirect->url ); }

			unset( $_REQUEST['action'] );
			$this->guard( null, new RouteGuardStore() )->guard_login_screen();
		} finally {
			$_REQUEST = $saved_request;
		}
	}

	/**
	 * A logout ends its own visit under the connection lock.
	 *
	 * session_tokens is ONE user-meta row holding every concurrent visit's
	 * login token for the shared account, so a writer that is not serialized
	 * on the connection lock can drop a colleague's token — the redeem path
	 * states that invariant and obeys it, and core's own
	 * wp_destroy_current_session() does not. The visit must therefore be torn
	 * down here, before core is reached, and core's read-modify-write left
	 * with no token to look for.
	 */
	public function test_a_logout_ends_only_its_own_visit_and_writes_under_the_connection_lock(): void {
		$store = new RouteGuardStore();
		$store->rows = [ 'visit-a' => $this->visit( self::VISIT, 'active', 7, 'visit-a' ), 'visit-b' => $this->visit( 45, 'active', 7, 'visit-b' ) ];
		$GLOBALS['pow_test_login_tokens'] = [ 'visit-a' ];
		$GLOBALS['wpdb']                  = $database = new RouteGuardLockDatabase();
		$GLOBALS['pow_test_valid_nonce']  = 'the-log-out-nonce';
		$_REQUEST['action']               = 'logout';
		$_REQUEST['_wpnonce']             = 'the-log-out-nonce';
		$_COOKIE[ LOGGED_IN_COOKIE ]      = 'cookie-carrying-visit-a';

		// No redirect: logout is the one action a visit may take here.
		$this->guard( null, $store )->guard_login_screen();

		self::assertSame( [ self::VISIT ], $store->expired, 'The logout ended its own visit, and only its own' );
		self::assertSame( [], $store->unlocked, "The account's shared session_tokens row was never written outside the connection lock" );
		self::assertSame( [ 'lock', 'release' ], $database->events, 'The connection lock was taken and released exactly once' );
		self::assertArrayHasKey( 'visit-b', $store->rows, "The colleague's visit on the same account survives" );
		self::assertFalse( array_key_exists( LOGGED_IN_COOKIE, $_COOKIE ), "core's unlocked teardown is left no token to read" );
	}

	public function test_a_logout_that_was_never_authorized_or_never_confirmed_tears_nothing_down(): void {
		$GLOBALS['pow_test_login_tokens'] = [ 'visit-a' ];
		$GLOBALS['pow_test_valid_nonce']  = 'the-log-out-nonce';
		$_REQUEST['action']               = 'logout';

		// wp-login.php checks its own log-out nonce after this hook and only
		// offers a confirmation screen without one, so an unauthorized request
		// — a link from anywhere, a prefetch — must not end a live basket.
		foreach ( [ 'a-stale-nonce', '' ] as $nonce ) {
			$store                       = new RouteGuardStore();
			$store->rows                 = [ 'visit-a' => $this->visit( self::VISIT, 'active', 7, 'visit-a' ) ];
			$GLOBALS['wpdb']             = $database = new RouteGuardLockDatabase();
			$_REQUEST['_wpnonce']        = $nonce;
			$_COOKIE[ LOGGED_IN_COOKIE ] = 'cookie-carrying-visit-a';

			$this->guard( null, $store )->guard_login_screen();

			self::assertSame( [], $store->expired, 'A logout core will not perform ends no visit' );
			self::assertSame( [], $database->events, 'An unauthorized logout takes no connection lock' );
			self::assertArrayHasKey( LOGGED_IN_COOKIE, $_COOKIE, 'The request keeps the login it still has' );
		}

		// A teardown that could not be confirmed leaves the token for core's
		// best effort rather than suppressing the only remaining destroy.
		$store                            = new RouteGuardStore();
		$store->rows                      = [ 'visit-a' => $this->visit( self::VISIT, 'active', 7, 'visit-a' ) ];
		$store->refuse_teardown           = true;
		$GLOBALS['wpdb']                  = new RouteGuardLockDatabase();
		$_REQUEST['_wpnonce']             = 'the-log-out-nonce';
		$_COOKIE[ LOGGED_IN_COOKIE ]      = 'cookie-carrying-visit-a';

		$this->guard( null, $store )->guard_login_screen();

		self::assertSame( [ self::VISIT ], $store->expired, 'The teardown was attempted' );
		self::assertSame( [], $store->unlocked, 'A failed teardown was still attempted under the lock' );
		self::assertArrayHasKey( LOGGED_IN_COOKIE, $_COOKIE, 'An unconfirmed teardown keeps the token reachable' );
	}

	/** Every other way out of a visit still ends the row and its basket, under the same lock. */
	public function test_the_logout_action_ends_a_resolved_visit_under_the_connection_lock(): void {
		$store = new RouteGuardStore();
		$store->rows = [ 'visit-a' => $this->visit( self::VISIT, 'active', 7, 'visit-a' ), 'visit-b' => $this->visit( 45, 'active', 7, 'visit-b' ) ];
		$GLOBALS['pow_test_login_tokens'] = [ 'visit-a' ];
		$GLOBALS['wpdb']                  = $database = new RouteGuardLockDatabase();

		$this->guard( null, $store )->end_visit_on_logout();

		self::assertSame( [ self::VISIT ], $store->expired, 'The resolved visit is expired and its basket deleted' );
		self::assertSame( [], $store->unlocked, 'Even this late the shared row is written under the lock' );
		self::assertSame( [ 'lock', 'release' ], $database->events );
		self::assertArrayHasKey( 'visit-b', $store->rows, "A colleague's visit is never ended by another's logout" );

		// Outside a visit — an ordinary shopper of the bound account logging
		// out — nothing is torn down and no lock is taken.
		$shopper = new RouteGuardStore();
		$GLOBALS['wpdb'] = $ordinary = new RouteGuardLockDatabase();
		$this->guard( null, $shopper )->end_visit_on_logout();
		self::assertSame( [], $shopper->expired );
		self::assertSame( [], $ordinary->events );
	}

	public function test_an_unreachable_session_store_fails_closed_on_every_door(): void {
		$store = new RouteGuardStore();
		$store->unreachable = true;
		$GLOBALS['pow_test_login_token'] = 'visit-a';

		$guard = $this->guard( null, $store );

		self::assertTrue( $guard->checkout_blocked_for_visit(), 'An unproven request cannot check out' );
		self::assertFalse( $guard->deny_application_passwords( true, null ), 'An unproven request cannot mint an application password' );
		self::assertTrue( is_wp_error( $guard->guard_rest( null, null, new RouteGuardRestRequest( '/wp/v2/users/me' ) ) ), 'An unproven request cannot read users' );

		try { $guard->guard_admin(); self::fail( 'An unproven request cannot enter wp-admin' ); }
		catch ( RouteGuardRedirect $redirect ) { self::assertSame( $this->landing(), $redirect->url ); }

		self::assertGreaterThan( 0, $store->lookups );
	}

	/**
	 * Decision 12 of the confirmed model, over the whole shipped tree.
	 *
	 * Every visit of a connection is signed in as the same WordPress user, so
	 * `$thing->get_customer_id() === $visit->user_id` is true for every
	 * colleague: an ownership check written that way silently degrades to "is
	 * this the bound account" and still looks right in every single-buyer test.
	 * Nothing about the result gives it away, so the proximity of the two is
	 * what is refused, everywhere under includes/. The exemption list holds the
	 * per-visit key's own class and nothing else — a line that compares the
	 * key, which is the only ownership proof the model has, has no reason to
	 * carry a user id within three lines of it.
	 */
	public function test_no_shipped_source_proves_ownership_with_a_user_id(): void {
		$root    = dirname( __DIR__, 2 );
		$allowed = [ 'includes/Cart/SessionKey.php' ];
		$files   = $this->php_files( $root . '/includes' );

		self::assertGreaterThan( 40, count( $files ), 'The source scan found too few files to be trusted' );

		$offences = [];

		foreach ( $files as $file ) {
			$relative = str_replace( $root . '/', '', $file );

			if ( in_array( $relative, $allowed, true ) ) {
				continue;
			}

			foreach ( $this->ownership_tautologies( $this->code_lines( (string) file_get_contents( $file ) ) ) as $line ) {
				$offences[] = $relative . ':' . $line;
			}
		}

		self::assertSame( [], $offences, 'get_customer_id() within three lines of a user id: ownership is proved by the per-visit key, never by the shared account' );
	}

	/** The scan has teeth: the shape it exists for, and the shapes it must leave alone. */
	public function test_the_ownership_scan_catches_the_shape_it_exists_for(): void {
		$defect = "<?php\n\$visit = \$this->visit();\nif ( \$order->get_customer_id() === \$visit->user_id ) { return true; }\n";
		self::assertSame( [ 3 ], $this->ownership_tautologies( $this->code_lines( $defect ) ), 'An ownership check written on the account id is caught' );

		$current = "<?php\nif ( get_current_user_id() === (int) \$handler->get_customer_id() ) { return true; }\n";
		self::assertSame( [ 2 ], $this->ownership_tautologies( $this->code_lines( $current ) ), 'The current user is the same defect' );

		$apart = "<?php\n\$user_id = 1;\n\$one = 1;\n\$two = 2;\n\$three = 3;\n\$key = \$session->get_customer_id();\n";
		self::assertSame( [], $this->ownership_tautologies( $this->code_lines( $apart ) ), 'Four lines apart is outside the rule' );

		$comment = "<?php\n/* core compares get_current_user_id() with the customer id */\n\$key = \$session->get_customer_id();\n";
		self::assertSame( [], $this->ownership_tautologies( $this->code_lines( $comment ) ), 'A comment compares nothing' );

		$key = "<?php\n\$expected = SessionKey::for_session( \$visit );\nif ( ! hash_equals( \$expected, (string) \$session->get_customer_id() ) ) { return false; }\n";
		self::assertSame( [], $this->ownership_tautologies( $this->code_lines( $key ) ), 'A per-visit key comparison is the shape the model wants' );
	}

	/**
	 * Line numbers where get_customer_id() sits within three lines of a user
	 * id. `get_current_user_id` carries `user_id` in its own name, so one
	 * needle covers both halves of the rule.
	 *
	 * @param list<string> $lines Code lines, comments already removed.
	 * @return list<int>
	 */
	private function ownership_tautologies( array $lines ): array {
		$found = [];

		foreach ( $lines as $number => $line ) {
			if ( ! str_contains( $line, 'get_customer_id' ) ) {
				continue;
			}

			for ( $near = $number - 3; $near <= $number + 3; ++$near ) {
				if ( str_contains( (string) ( $lines[ $near ] ?? '' ), 'user_id' ) ) {
					$found[] = $number + 1;
					break;
				}
			}
		}

		return $found;
	}

	/**
	 * The file's code with its comments blanked out and every line still where
	 * it was, so a reported line number names the real one.
	 *
	 * @return list<string>
	 */
	private function code_lines( string $source ): array {
		$code = '';

		foreach ( token_get_all( $source ) as $token ) {
			$text = is_array( $token ) ? $token[1] : $token;

			if ( is_array( $token ) && in_array( $token[0], [ T_COMMENT, T_DOC_COMMENT ], true ) ) {
				$text = str_repeat( "\n", substr_count( $text, "\n" ) );
			}

			$code .= $text;
		}

		return explode( "\n", $code );
	}

	/** @return list<string> Every PHP file under a directory, sorted. */
	private function php_files( string $directory ): array {
		$files = [];

		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory ) ) as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}

		sort( $files );

		return $files;
	}

	public function test_no_site_glue_hook_and_no_exit_policy_remain_in_the_guard(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/RouteGuard.php' );
		self::assertStringNotContainsString( 'pow_route_guard', $source, 'The site-glue extension hook is gone' );
		self::assertStringNotContainsString( 'ExitPolicy', $source, 'No exit policy remains in the route guard' );
		self::assertStringNotContainsString( 'get_customer_id', $source, 'Ownership is proved by the visit row, never by the user id' );
		self::assertStringNotContainsString( '_pow_session\'', $source, "PayExit's meta keys are gone" );
		self::assertStringContainsString( 'META_SESSION_ID', $source, 'The order fence keys on the quote order meta' );
	}
}
}
