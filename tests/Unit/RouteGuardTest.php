<?php
/** Visit lockdown: route scoping, the cross-visit order fence and the wp-admin/REST/application-password doors. @package POW */
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
	if ( ! function_exists( 'wp_doing_ajax' ) ) { function wp_doing_ajax(): bool { return (bool) ( $GLOBALS['pow_route_guard_test']['ajax'] ?? false ); } }

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
		public function find_for_login( int $user_id, string $wp_session_token, array $statuses = [ POW\Sessions\Session::ACTIVE ] ): ?POW\Sessions\Session {
			++$this->lookups;
			if ( $this->unreachable ) { throw new RuntimeException( 'Session lookup failed.' ); }
			$row = $this->rows[ $wp_session_token ] ?? null;
			return $row && $user_id === $row->user_id && in_array( $row->status, $statuses, true ) ? $row : null;
		}
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

	protected function setUp(): void {
		foreach ( [ 'wp', 'wpdb', 'pow_route_guard_test', 'pow_test_current_user_id', 'pow_test_options', 'pow_test_users', 'pow_test_roles', 'pow_test_login_token', 'pow_test_login_tokens', 'pow_test_login_token_index', 'pow_test_orders', 'pow_test_filters' ] as $key ) {
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
	private function guard( ?POW\Sessions\Session $session, ?RouteGuardStore $store = null ): POW\RouteGuard {
		$plugin   = ( new ReflectionClass( POW\Plugin::class ) )->newInstanceWithoutConstructor();
		$registry = new POW\Partners\Registry( new POW\Partners\Secrets( str_repeat( 'r', 32 ) ) );
		$settings = new POW\Settings();

		$values = [
			'registry'        => $registry,
			'sessions'        => $store ?? new RouteGuardStore(),
			'settings'        => $settings,
			'current_session' => $store ? null : $session,
			'session_resolved' => null === $store,
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

	public function test_wp_admin_is_refused_inside_a_visit_without_breaking_ajax(): void {
		$guard = $this->guard( $this->visit() );

		try { $guard->guard_admin(); self::fail( 'wp-admin must be refused inside a visit' ); }
		catch ( RouteGuardRedirect $redirect ) { self::assertSame( $this->landing(), $redirect->url ); }

		$GLOBALS['pow_route_guard_test']['ajax'] = true;
		$guard->guard_admin();

		$GLOBALS['pow_route_guard_test']['ajax'] = false;
		$this->guard( null )->guard_admin();
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
