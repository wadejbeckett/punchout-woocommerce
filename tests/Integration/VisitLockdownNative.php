<?php
/**
 * Opt-in native shared-login lockdown: what a request inside a visit may not reach.
 *
 * Run only against an opted-in disposable local WordPress/WooCommerce database:
 * POW_NATIVE_TESTS=disposable wp --user=<fixture-admin> eval-file tests/Integration/VisitLockdownNative.php
 *
 * The buyer is signed in as the connection's own ordinary customer account, so
 * every surface that account holds is reachable unless something refuses it:
 * wp-admin, the users and application-password REST routes, the account details
 * and password screens, wp-login.php, checkout, a colleague's basket and a
 * colleague's quote order. None of those refusals can be a question about the
 * user — the answer would be the same for the account holder's own ordinary
 * shopping, which must keep working. They are questions about the visit, and
 * about the per-visit `wc_session_key`, so they can only be proved against real
 * rows, a real login token and a real WooCommerce session row.
 *
 * It creates one customer account, one connection, two visits and one quote
 * order, and leaves them for inspection. It proves the plugin's own doors, not
 * the web server's: an actual HTTP run is still the only thing that shows the
 * 302 reaching a browser.
 *
 * It also ticks the dashboard and one third-party My Account endpoint on
 * that connection (`bulkorder`, registered with WooCommerce here the way a
 * store's own plugin registers it, when nothing on the fixture already
 * does), renders the connection form from WooCommerce's live endpoint map,
 * opens a second, new connection that lists only its dashboard, and proves
 * that a visit creates no user.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'disposable' !== getenv( 'POW_NATIVE_TESTS' ) || 'local' !== wp_get_environment_type() || ! current_user_can( 'manage_woocommerce' ) || ! function_exists( 'WC' ) ) {
	throw new RuntimeException( 'Requires opted-in disposable local WordPress/WooCommerce CLI and a capable fixture administrator.' );
}

require_once dirname( __DIR__ ) . '/Support/native-visits.php';

/** A refusal that would have been a 302 in a browser. */
final class VisitLockdownRedirect extends RuntimeException {}

final class VisitLockdownNative {
	/** What the suite's own bulkorder content and dashboard hook print, so a render is judged by what it shows. */
	private const BULKORDER_MARK = 'pow-fixture-bulkorder-content';
	private const DASHBOARD_MARK = 'pow-fixture-account-dashboard';

	private POW\Partners\Registry $registry;
	private POW\Sessions\Store $sessions;
	private POW\RouteGuard $guard;
	private POW\Cart\NativeSessionGuard $carts;
	private int $admin;
	private int $account = 0;
	private POW\Partners\Partner $partner;
	/** @var list<POW\Sessions\Session> */
	private array $visits = [];
	private int $quote = 0;
	private int $ordinary_order = 0;
	private int $passed = 0;
	private int $failed = 0;

	public function __construct() {
		$plugin = POW\Plugin::instance();
		$this->registry = $plugin->registry() ?? throw new RuntimeException( 'Plugin services unavailable.' );
		$this->sessions = $plugin->sessions() ?? throw new RuntimeException( 'Plugin services unavailable.' );
		$this->guard = new POW\RouteGuard( $plugin, $this->registry, $plugin->settings() );
		$this->carts = POW\Cart\NativeSessionGuard::registered();
		$this->admin = get_current_user_id();
	}

	private function check( bool $ok, string $label ): void {
		if ( $ok ) { ++$this->passed; echo 'PASS ' . $label . "\n"; return; }
		++$this->failed; echo 'FAIL ' . $label . "\n";
	}

	public function catch_redirect( string $location ): string {
		throw new VisitLockdownRedirect( $location );
	}

	/** Whether the door answered with what would be a browser redirect. */
	private function redirected( callable $operation ): bool {
		add_filter( 'wp_redirect', [ $this, 'catch_redirect' ], PHP_INT_MAX );
		try { $operation(); return false; }
		catch ( VisitLockdownRedirect $redirect ) { return true; }
		finally { remove_filter( 'wp_redirect', [ $this, 'catch_redirect' ], PHP_INT_MAX ); }
	}

	private function threw( callable $operation ): bool {
		try { $operation(); return false; } catch ( Throwable $error ) { return true; }
	}

	/** Set WooCommerce endpoint query vars the way a real front-end request carries them. */
	private function endpoint( array $vars ): void {
		global $wp;
		foreach ( [ 'edit-account', 'orders', 'view-order', 'order-pay', 'order-received' ] as $endpoint ) { unset( $wp->query_vars[ $endpoint ] ); }
		foreach ( $vars as $endpoint => $value ) { $wp->query_vars[ $endpoint ] = $value; }
	}

	private function seed(): void {
		$suffix = bin2hex( random_bytes( 6 ) );
		$this->account = pow_native_bound_account( 'lockdown' );
		$id = $this->registry->insert(
			[
				'name' => 'Lockdown company ' . $suffix,
				'status' => POW\Partners\Partner::STATUS_ACTIVE,
				'owner_user_id' => $this->account,
				'from_domain' => 'NetworkID',
				'from_identity' => 'lockdown-' . $suffix,
				'sender_domain' => 'NetworkID',
				'sender_identity' => 'lockdown-' . $suffix,
				'to_domain' => 'NetworkID',
				'to_identity' => 'supplier-' . $suffix,
				'cxml_version' => '1.2.008',
				'deployment_mode' => 'test',
				'return_encoding' => 'base64',
			],
			wp_generate_password( 40, false, false )
		);
		$this->partner = $id > 0 ? ( $this->registry->find( $id ) ?? throw new RuntimeException( 'Lockdown connection missing.' ) ) : throw new RuntimeException( 'Lockdown connection could not be created.' );
		foreach ( [ 'first', 'second' ] as $label ) {
			$this->visits[] = pow_native_open_visit( $this->partner->id, $this->account, [ 'buyer_identity' => $label . '-' . $suffix . '@example.invalid', 'buyer_name' => 'Employee ' . $label ] );
		}
		// Each visit fills its own basket row, so "another visit's basket" is a
		// real row with real contents rather than an empty key.
		foreach ( $this->visits as $index => $visit ) {
			$handler = pow_native_visit_handler( $visit );
			WC()->session = $handler;
			$handler->set( 'cart', [ 'lockdown-' . $index => [ 'product_id' => 0, 'quantity' => $index + 1 ] ] );
			if ( ! $handler->save_checked() ) { throw new RuntimeException( 'Lockdown basket could not be persisted.' ); }
		}
		$this->quote = $this->order( $this->visits[0] );
		$this->ordinary_order = $this->order( null );
		pow_native_leave_visit( $this->admin );
	}

	/** A quote order stamped with its own visit, or an ordinary order of the same account. */
	private function order( ?POW\Sessions\Session $visit ): int {
		$order = wc_create_order( [ 'customer_id' => $this->account, 'status' => null === $visit ? 'pending' : POW\Orders\Status::SLUG ] );
		if ( ! $order instanceof WC_Order ) { throw new RuntimeException( 'Lockdown order fixture unavailable.' ); }
		if ( null !== $visit ) {
			$order->update_meta_data( POW\Orders\QuoteOrder::META_SESSION_ID, $visit->id );
			$order->update_meta_data( POW\Orders\QuoteOrder::META_PARTNER_ID, $this->partner->id );
		}
		$order->save();
		return $order->get_id();
	}

	/**
	 * wp-admin, and the one entry point under it a visit may still call.
	 *
	 * The exemption is the script, not wp_doing_ajax(): WooCommerce defines
	 * DOING_AJAX from its own ?wc-ajax= parameter at `init` priority 0 on every
	 * request, wp-admin page loads included, so the refusal is proved here
	 * against a wp-admin script that carries that parameter as well.
	 */
	private function admin_area(): void {
		[ $first ] = $this->visits;
		$script = $_SERVER['SCRIPT_FILENAME'] ?? null;
		$query  = $_GET['wc-ajax'] ?? null;
		pow_native_enter_visit( $first );
		try {
			$_SERVER['SCRIPT_FILENAME'] = ABSPATH . 'wp-admin/profile.php';
			$this->check( $this->redirected( fn() => $this->guard->guard_admin() ), 'wp-admin is refused inside a visit' );
			$_GET['wc-ajax'] = '1';
			$ajax = static fn(): bool => true;
			add_filter( 'wp_doing_ajax', $ajax );
			try { $this->check( $this->redirected( fn() => $this->guard->guard_admin() ), 'a ?wc-ajax= query parameter does not open wp-admin inside a visit' ); }
			finally { remove_filter( 'wp_doing_ajax', $ajax ); }
			unset( $_GET['wc-ajax'] );
			$_SERVER['SCRIPT_FILENAME'] = ABSPATH . 'wp-admin/admin-ajax.php';
			$this->check( ! $this->redirected( fn() => $this->guard->guard_admin() ), 'admin-ajax keeps working inside a visit, because the front-end basket calls it' );
			$_SERVER['SCRIPT_FILENAME'] = ABSPATH . 'wp-admin/profile.php';
			pow_native_leave_visit( $this->account );
			$this->check( ! $this->redirected( fn() => $this->guard->guard_admin() ), 'the bound account outside a visit keeps its own wp-admin answer' );
		} finally {
			if ( null === $script ) { unset( $_SERVER['SCRIPT_FILENAME'] ); } else { $_SERVER['SCRIPT_FILENAME'] = $script; }
			if ( null === $query ) { unset( $_GET['wc-ajax'] ); } else { $_GET['wc-ajax'] = $query; }
		}
		pow_native_leave_visit( $this->admin );
	}

	private function rest_routes(): void {
		[ $first ] = $this->visits;
		$locked = [ '/wp/v2/users', '/wp/v2/users/me', '/wp/v2/application-passwords', '/wp/v2/application-passwords/' . $this->account . '/introspect' ];
		$open = [ '/wc/store/v1/cart', '/wp/v2/posts', '/wp/v2/users-like-this' ];
		pow_native_enter_visit( $first );
		foreach ( $locked as $route ) {
			$result = $this->guard->guard_rest( null, null, new WP_REST_Request( 'GET', $route ) );
			$this->check(
				$result instanceof WP_Error && 'pow_visit_locked' === $result->get_error_code() && 403 === ( $result->get_error_data()['status'] ?? 0 ),
				'REST ' . $route . ' is refused inside a visit with pow_visit_locked'
			);
		}
		foreach ( $open as $route ) {
			$this->check( null === $this->guard->guard_rest( null, null, new WP_REST_Request( 'GET', $route ) ), 'REST ' . $route . ' is untouched inside a visit' );
		}
		$this->check( false === $this->guard->deny_application_passwords( true, get_userdata( $this->account ) ), 'application passwords are unavailable inside a visit' );
		pow_native_leave_visit( $this->account );
		foreach ( $locked as $route ) {
			$this->check( null === $this->guard->guard_rest( null, null, new WP_REST_Request( 'GET', $route ) ), 'REST ' . $route . ' is untouched outside a visit' );
		}
		$this->check( true === $this->guard->deny_application_passwords( true, get_userdata( $this->account ) ), 'the account keeps application passwords outside a visit' );
		pow_native_leave_visit( $this->admin );
	}

	private function account_surfaces(): void {
		[ $first ] = $this->visits;
		$is_account = static fn(): bool => true;
		add_filter( 'woocommerce_is_account_page', $is_account );
		try {
			foreach ( [ 'edit-account', 'orders' ] as $endpoint ) {
				$this->endpoint( [ $endpoint => '' ] );
				pow_native_enter_visit( $first );
				$this->check( $this->redirected( fn() => $this->guard->guard() ), 'the account ' . $endpoint . ' screen is refused inside a visit' );
				pow_native_leave_visit( $this->account );
				$this->check( ! $this->redirected( fn() => $this->guard->guard() ), 'the account holder keeps its own ' . $endpoint . ' screen outside a visit' );
			}
		} finally { remove_filter( 'woocommerce_is_account_page', $is_account ); $this->endpoint( [] ); pow_native_leave_visit( $this->admin ); }
		pow_native_enter_visit( $first );
		$saved = $_REQUEST['action'] ?? null;
		try {
			$_REQUEST['action'] = 'login';
			$this->check( $this->redirected( fn() => $this->guard->guard_login_screen() ), 'wp-login.php is refused inside a visit' );
			$_REQUEST['action'] = 'logout';
			$this->check( ! $this->redirected( fn() => $this->guard->guard_login_screen() ), 'logout stays available inside a visit' );
		} finally {
			if ( null === $saved ) { unset( $_REQUEST['action'] ); } else { $_REQUEST['action'] = $saved; }
			pow_native_leave_visit( $this->admin );
		}
	}

	/**
	 * A connection's My Account list against real WooCommerce endpoints,
	 * real request parsing, WooCommerce's own account-content renderer, the
	 * real account menu, the real connection form and a real second
	 * connection.
	 *
	 * Each request is a real front-end load of the account page: WordPress
	 * parses it (plain permalinks, WooCommerce mapping its endpoint slugs)
	 * and runs the main query, then the guard's two passes run and, when both
	 * let it through, woocommerce_account_content() renders it. Whether a
	 * page opened is judged on that HTML, not on the absence of a redirect:
	 * WooCommerce renders the dashboard for a request whose endpoint has no
	 * content action, and the dashboard may render only where it is ticked
	 * and was asked for.
	 */
	private function account_endpoints(): void {
		global $wp, $wpdb;
		[ $first, $second ] = $this->visits;
		$register   = static fn( array $vars ): array => $vars + [ 'bulkorder' => 'bulkorder' ];
		$registered = ! isset( WC()->query->get_query_vars()['bulkorder'] );
		$late_var   = static fn( array $vars ): array => array_merge( $vars, [ 'pow_fixture_late' ] );
		$render     = static function (): void { echo '<p>' . self::BULKORDER_MARK . '</p>'; };
		$late       = static function (): void { echo '<p>late content</p>'; };
		$dashboard  = static function (): void { echo '<p>' . self::DASHBOARD_MARK . '</p>'; };
		$menu_item  = static fn( array $items ): array => $items + [ 'bulkorder' => 'Quick order', 'subaccounts' => 'Subaccounts' ];
		$vars       = $wp->query_vars;
		$main       = [ $GLOBALS['wp_the_query'] ?? null, $GLOBALS['wp_query'] ?? null ];
		// Whatever else registers bulkorder content on this fixture is not
		// part of the proof; the suite's own renderer is the only one.
		remove_all_actions( 'woocommerce_account_bulkorder_endpoint' );
		if ( $registered ) { add_filter( 'woocommerce_get_query_vars', $register ); }
		add_filter( 'query_vars', $late_var );
		add_action( 'woocommerce_account_bulkorder_endpoint', $render );
		add_action( 'woocommerce_account_dashboard', $dashboard );
		add_filter( 'woocommerce_account_menu_items', $menu_item );
		$opened    = static fn( ?string $html ): bool => is_string( $html ) && str_contains( $html, self::BULKORDER_MARK ) && ! str_contains( $html, self::DASHBOARD_MARK );
		$dashboard_opened = static fn( ?string $html ): bool => is_string( $html ) && str_contains( $html, self::DASHBOARD_MARK ) && ! str_contains( $html, self::BULKORDER_MARK );
		$guard_for = fn(): POW\RouteGuard => new POW\RouteGuard( POW\Plugin::instance(), $this->registry, POW\Plugin::instance()->settings() );
		$set       = function ( string $list ): POW\RouteGuard {
			pow_native_leave_visit( $this->admin );
			$this->check( $this->registry->update( $this->partner->id, [ 'visit_endpoints' => $list ] ) && $list === ( $this->registry->find( $this->partner->id )?->visit_endpoints ?? null ), 'the connection saves "' . $list . '" under its lock, exactly as ticked' );
			return new POW\RouteGuard( POW\Plugin::instance(), $this->registry, POW\Plugin::instance()->settings() );
		};
		try {
			$this->check( 'dashboard' === $this->partner->visit_endpoints, 'a new connection lists the dashboard and nothing else' );

			// The form an administrator ticks, rendered from WooCommerce's live endpoint map.
			$form = $this->connection_form();
			$this->check( 1 === preg_match( '#<strong>WooCommerce</strong>.*value="dashboard" checked="checked".*value="orders" />.*<strong>This plugin</strong>.*value="punchout-integration" disabled="disabled" />.*<strong>Added by other plugins</strong>.*value="bulkorder" />#s', $form ), 'the connection form lists WooCommerce\'s pages first, the dashboard ticked, then this plugin\'s tab, then the registered third-party page, unticked' );
			$this->check( 9 === substr_count( $form, ' disabled="disabled"' ) && 5 === substr_count( $form, 'Stays closed in a visit.' ) && str_contains( $form, 'Orders <code>orders</code>' ) && ! str_contains( $form, 'value="view-order"' ), 'the four payment-method rows and the five pages that never open are disabled on a punchout-only connection, a single order has no row of its own, and WooCommerce\'s own titles label the rows' );

			$guard = $set( 'dashboard,bulkorder' );
			$form  = $this->connection_form();
			$this->check( 1 === preg_match( '#<strong>Added by other plugins</strong>.*value="bulkorder" checked="checked"#s', $form ), 'the form shows the ticked third-party page as ticked' );
			foreach ( [ $first, $second ] as $index => $visit ) {
				pow_native_enter_visit( $visit );
				$this->check( $opened( $this->account_page( $guard, [ 'bulkorder' => '' ] ) ), 'visit ' . ( $index + 1 ) . ' opens the listed bulkorder endpoint and WooCommerce renders its content, not the dashboard' );
				$this->check( $dashboard_opened( $this->account_page( $guard, [] ) ), 'visit ' . ( $index + 1 ) . ' opens the ticked dashboard and WooCommerce renders the dashboard' );
			}
			pow_native_enter_visit( $first );
			$closed = [
				'orders'                     => [ 'orders' => '' ],
				'edit-account'               => [ 'edit-account' => '' ],
				'bulkorder carrying ?orders' => [ 'bulkorder' => '', 'orders' => '' ],
				'the dashboard carrying ?orders' => [ 'orders' => '1' ],
				'order-pay'                  => [ 'order-pay' => '' ],
				'order-received'             => [ 'order-received' => '' ],
			];
			foreach ( $closed as $label => $query ) {
				$this->check( null === $this->account_page( $guard, $query ), $label . ' stays closed inside a visit of a connection listing the dashboard and bulkorder' );
			}
			// A name nothing registered is not an endpoint: WordPress drops it
			// and WooCommerce shows the account page, the ticked dashboard.
			$this->check( $dashboard_opened( $this->account_page( $guard, [ 'subaccounts' => '' ] ) ), 'an unregistered name shows the ticked dashboard and nothing else' );
			$menu = $this->menu();
			$this->check( [ 'bulkorder', 'dashboard' ] === $this->sorted_keys( $menu ), 'the real account menu inside a visit holds the dashboard and the listed endpoint only' );

			// The shared login's own pages open once ticked; a page WooCommerce
			// has no account content for stays closed even when ticked, and a
			// payment page never opens for a punchout-only connection.
			$edited = $set( 'dashboard,bulkorder,orders,order-pay,order-received,customer-logout,lost-password,punchout-integration' );
			pow_native_enter_visit( $first );
			$orders = $this->account_page( $edited, [ 'orders' => '' ] );
			$this->check( is_string( $orders ) && ! str_contains( $orders, self::DASHBOARD_MARK ), 'a ticked orders page opens with its own content' );
			foreach ( [ 'order-pay', 'order-received', 'customer-logout', 'lost-password', 'punchout-integration' ] as $endpoint ) {
				$this->check( null === $this->account_page( $edited, [ $endpoint => '' ] ), $endpoint . ' on the account page stays closed although the row names it' );
			}
			$this->check( [ 'bulkorder', 'dashboard', 'orders' ] === $this->sorted_keys( $this->menu() ), 'the menu shows the ticked pages that open, and no logout item' );
			$single = $set( 'dashboard,orders,view-order' );
			pow_native_enter_visit( $first );
			$this->check( null === $this->account_page( $single, [ 'view-order' => (string) $this->ordinary_order ] ), 'a single order of the shared account stays closed in a visit, even with orders and view-order in the row' );
			pow_native_leave_visit( $this->admin );
			$wpdb->update( POW\Installer::partners_table(), [ 'visit_endpoints' => 'dashboard,payment-methods,add-payment-method' ], [ 'id' => $this->partner->id ] );
			$this->check( [ 'dashboard' ] === ( $this->registry->find( $this->partner->id )?->visit_endpoint_list() ?? [] ), 'a hand-edited row naming payment pages reads back without them for a punchout-only connection' );
			$paying = $guard_for();
			pow_native_enter_visit( $first );
			foreach ( [ 'payment-methods', 'add-payment-method' ] as $endpoint ) {
				$this->check( null === $this->account_page( $paying, [ $endpoint => '' ] ), $endpoint . ' stays closed although the row names it' );
			}

			// A listed endpoint WooCommerce has no content for would render
			// the dashboard, so it stays closed and leaves the menu, even with
			// the dashboard ticked. This is the state of a third-party endpoint
			// whose plugin registers its query var for every user but its
			// content only for some.
			$edited = $set( 'dashboard,bulkorder' );
			pow_native_enter_visit( $first );
			remove_action( 'woocommerce_account_bulkorder_endpoint', $render );
			$this->check( null === $this->account_page( $edited, [ 'bulkorder' => '' ] ), 'a listed endpoint with no account content stays closed instead of rendering the dashboard' );
			$this->check( [ 'dashboard' ] === array_keys( $this->menu() ), 'and the account menu drops it' );
			add_action( 'woocommerce_account_bulkorder_endpoint', $render );

			// Content registered after the guard's first pass, for a query
			// var WooCommerce does not map, is judged by the second pass.
			$html = $this->account_page(
				$edited,
				[ 'bulkorder' => '', 'pow_fixture_late' => '1' ],
				static function () use ( $late ): void { add_action( 'woocommerce_account_pow_fixture_late_endpoint', $late ); }
			);
			remove_action( 'woocommerce_account_pow_fixture_late_endpoint', $late );
			$this->check( null === $html, 'content registered after the first pass for an unlisted query var closes the page before it renders' );

			// A 0.4.5 row, which never names the dashboard, keeps the dashboard closed.
			$older = $set( 'bulkorder' );
			pow_native_enter_visit( $first );
			$this->check( null === $this->account_page( $older, [] ), 'without dashboard in the row the account page stays closed' );
			$this->check( null === $this->account_page( $older, [ 'subaccounts' => '' ] ), 'and so does an unregistered name, which would show it' );
			$this->check( $opened( $this->account_page( $older, [ 'bulkorder' => '' ] ) ), 'and the listed endpoint still opens' );
			$this->check( [ 'bulkorder' ] === array_keys( $this->menu() ), 'and the menu holds only the listed endpoint' );

			pow_native_leave_visit( $this->account );
			$this->check( str_contains( (string) $this->account_page( $guard, [] ), self::DASHBOARD_MARK ), 'the account holder outside a visit keeps the dashboard' );
			$this->check( null !== $this->account_page( $guard, [ 'orders' => '' ] ), 'and its orders page' );
			$menu = $this->menu();
			$this->check( isset( $menu['dashboard'], $menu['orders'], $menu['bulkorder'], $menu['customer-logout'] ), 'the account holder outside a visit keeps the whole menu' );

			// Every customer difference is a column: a second connection, new,
			// opens its dashboard and nothing this connection lists.
			pow_native_leave_visit( $this->admin );
			$suffix = bin2hex( random_bytes( 6 ) );
			$other_account = pow_native_bound_account( 'lockdown-other' );
			$other_id = $this->registry->insert( [ 'name' => 'Lockdown other ' . $suffix, 'status' => POW\Partners\Partner::STATUS_ACTIVE, 'owner_user_id' => $other_account, 'from_domain' => 'NetworkID', 'from_identity' => 'other-' . $suffix, 'sender_domain' => 'NetworkID', 'sender_identity' => 'other-' . $suffix, 'to_domain' => 'NetworkID', 'to_identity' => 'supplier-' . $suffix, 'cxml_version' => '1.2.008', 'deployment_mode' => 'test', 'return_encoding' => 'base64' ], wp_generate_password( 40, false, false ) );
			$other_visit = pow_native_open_visit( $other_id, $other_account, [ 'buyer_identity' => 'other-' . $suffix . '@example.invalid' ] );
			pow_native_enter_visit( $other_visit );
			$this->check( null === $this->account_page( $guard_for(), [ 'bulkorder' => '' ] ), "another connection's visit does not inherit this connection's list" );
			$this->check( $dashboard_opened( $this->account_page( $guard_for(), [] ) ), 'a new connection\'s visit opens its dashboard' );
			$this->check( [ 'dashboard' ] === array_keys( $this->menu() ), "that visit's account menu holds the dashboard alone" );
		} finally {
			$wp->query_vars = $vars;
			[ $GLOBALS['wp_the_query'], $GLOBALS['wp_query'] ] = $main;
			remove_filter( 'woocommerce_account_menu_items', $menu_item );
			remove_action( 'woocommerce_account_dashboard', $dashboard );
			remove_action( 'woocommerce_account_bulkorder_endpoint', $render );
			remove_filter( 'query_vars', $late_var );
			if ( $registered ) { remove_filter( 'woocommerce_get_query_vars', $register ); }
			pow_native_leave_visit( $this->admin );
		}
	}

	/**
	 * WooCommerce's real account menu. The plugin's own guard reads each
	 * connection's list once per request, and this whole suite is one
	 * request, so its memo is cleared first: each menu is judged on the list
	 * as it is saved now.
	 *
	 * @return array<string, string>
	 */
	private function menu(): array {
		foreach ( $GLOBALS['wp_filter']['woocommerce_account_menu_items']->callbacks ?? [] as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'] ?? null;
				if ( is_array( $function ) && $function[0] instanceof POW\RouteGuard ) {
					( new ReflectionProperty( POW\RouteGuard::class, 'visit_endpoints' ) )->setValue( $function[0], [] );
				}
			}
		}
		return wc_get_account_menu_items();
	}

	/** @param array<string, string> $menu @return list<string> */
	private function sorted_keys( array $menu ): array {
		$keys = array_map( 'strval', array_keys( $menu ) );
		sort( $keys );
		return $keys;
	}

	/** The connection's edit form as the fixture administrator sees it, from WooCommerce's live endpoint map. */
	private function connection_form(): string {
		$saved = $_GET;
		$_GET  = [ 'page' => POW\Admin\Page::SLUG, 'tab' => 'partners', 'action' => 'edit', 'partner' => (string) $this->partner->id ];
		wp_set_current_user( $this->admin );
		ob_start();
		try {
			$plugin = POW\Plugin::instance();
			( new POW\Admin\Page( $plugin->settings(), $this->registry, $plugin->audit() ?? throw new RuntimeException( 'Audit log unavailable.' ) ) )->render();
			return (string) ob_get_contents();
		} finally {
			ob_end_clean();
			$_GET = $saved;
		}
	}

	/**
	 * One front-end load of the account page with plain permalinks: parse,
	 * main query, the guard's first pass (template_redirect priority 1),
	 * whatever else runs before the second pass ($between), the second pass
	 * (priority PHP_INT_MAX), then WooCommerce's own account content.
	 *
	 * @param array<string, string> $query Endpoint query string, beside page_id.
	 * @return string|null The rendered account content, or null when the guard refused the request.
	 */
	private function account_page( POW\RouteGuard $guard, array $query, ?callable $between = null ): ?string {
		global $wp;
		$saved = [ $_GET, $_SERVER['REQUEST_URI'] ?? null, $_SERVER['QUERY_STRING'] ?? null ];
		$_GET  = [ 'page_id' => (string) wc_get_page_id( 'myaccount' ) ] + $query;
		$_SERVER['QUERY_STRING'] = http_build_query( $_GET );
		$_SERVER['REQUEST_URI']  = '/?' . $_SERVER['QUERY_STRING'];
		$GLOBALS['wp_the_query'] = new WP_Query();
		$GLOBALS['wp_query']     = $GLOBALS['wp_the_query'];
		try {
			$wp->parse_request();
			$wp->query_posts();
			if ( ! is_account_page() ) { throw new RuntimeException( 'The fixture request did not reach the account page.' ); }
			if ( $this->redirected( fn() => $guard->guard() ) ) { return null; }
			if ( null !== $between ) { $between(); }
			if ( $this->redirected( fn() => $guard->recheck_account_page() ) ) { return null; }
			ob_start();
			woocommerce_account_content();
			return (string) ob_get_clean();
		} finally {
			[ $_GET, $uri, $query_string ] = $saved;
			if ( null === $uri ) { unset( $_SERVER['REQUEST_URI'] ); } else { $_SERVER['REQUEST_URI'] = $uri; }
			if ( null === $query_string ) { unset( $_SERVER['QUERY_STRING'] ); } else { $_SERVER['QUERY_STRING'] = $query_string; }
		}
	}

	/**
	 * The connection form as wp-admin renders it, on a store whose other
	 * plugin adds its account pages to WooCommerce's endpoint map only on the
	 * front end and only for flagged accounts, and registers one page only
	 * as a WordPress rewrite endpoint with account content. That plugin
	 * still registers every page's rewrite endpoint on every request, as it
	 * must for its addresses to survive a permalink save from wp-admin, so
	 * the form offers all of them there, ticked or not: a page unticked once
	 * can be ticked again. In a visit the rewrite-only page opens like any
	 * other ticked page.
	 */
	private function wp_admin_form(): void {
		global $wp, $wp_rewrite;
		[ $first ] = $this->visits;
		$pages     = [ 'pow-fixture-orderform', 'pow-fixture-lists', 'pow-fixture-new-list' ];
		$flag      = 'pow_fixture_flagged';
		$map       = static function ( array $vars ) use ( $flag ): array {
			if ( ! is_admin() && 'yes' === get_user_meta( get_current_user_id(), $flag, true ) ) {
				$vars += [ 'pow-fixture-orderform' => 'pow-fixture-orderform', 'pow-fixture-lists' => 'pow-fixture-lists' ];
			}
			return $vars;
		};
		$content   = static function (): void { echo '<p>pow-fixture-new-list-content</p>'; };
		$endpoints = $wp_rewrite->endpoints;
		$public    = $wp->public_query_vars;
		$vars      = $wp->query_vars;
		$main      = [ $GLOBALS['wp_the_query'] ?? null, $GLOBALS['wp_query'] ?? null ];
		$screen    = $GLOBALS['current_screen'] ?? null;
		add_filter( 'woocommerce_get_query_vars', $map );
		add_action( 'woocommerce_account_pow-fixture-new-list_endpoint', $content );
		foreach ( $pages as $page ) { add_rewrite_endpoint( $page, EP_ROOT | EP_PAGES | EP_PERMALINK ); }
		update_user_meta( $this->account, $flag, 'yes' );
		try {
			pow_native_leave_visit( $this->admin );
			$this->check( $this->registry->update( $this->partner->id, [ 'visit_endpoints' => 'dashboard,' . implode( ',', $pages ) ] ), 'the connection lists the three pages' );
			$GLOBALS['current_screen'] = new class() { public string $id = 'toplevel_page_pow_fixture'; public function in_admin( mixed $admin = null ): bool { return true; } };
			$this->check( is_admin() && ! isset( WC()->query->get_query_vars()['pow-fixture-orderform'] ), 'in wp-admin the pages are not in WooCommerce\'s endpoint map' );
			$form = $this->connection_form();
			$this->check( 1 === preg_match( '#<strong>Added by other plugins</strong>.*value="pow-fixture-lists" checked="checked".*value="pow-fixture-new-list" checked="checked".*value="pow-fixture-orderform" checked="checked"#s', $form ) && ! str_contains( $form, 'not found on this site' ), 'wp-admin offers all three under Added by other plugins, ticked, and calls none of them missing' );
			$this->check( 1 === preg_match( '#name="visit_endpoints\[\]" value="" class="regular-text"#', $form ), 'the form has a box to add a page by name' );
			$this->check( $this->registry->update( $this->partner->id, [ 'visit_endpoints' => 'dashboard,pow-fixture-orderform,pow-fixture-lists' ] ), 'the new-list page is unticked and saved' );
			$form = $this->connection_form();
			$this->check( 1 === preg_match( '#value="pow-fixture-new-list" />#', $form ), 'wp-admin still offers the unticked page, so it can be ticked again' );
			unset( $GLOBALS['current_screen'] );
			if ( null !== $screen ) { $GLOBALS['current_screen'] = $screen; }

			$this->check( $this->registry->update( $this->partner->id, [ 'visit_endpoints' => 'dashboard,pow-fixture-new-list' ] ), 'the new-list page is ticked again' );
			$guard = new POW\RouteGuard( POW\Plugin::instance(), $this->registry, POW\Plugin::instance()->settings() );
			pow_native_enter_visit( $first );
			$html = $this->account_page( $guard, [ 'pow-fixture-new-list' => '' ] );
			$this->check( is_string( $html ) && str_contains( $html, 'pow-fixture-new-list-content' ), 'in a visit the ticked page WooCommerce does not map opens with its own content' );
			$this->check( null === $this->account_page( $guard, [ 'pow-fixture-orderform' => '' ] ), 'and a page the connection no longer lists stays closed' );
		} finally {
			unset( $GLOBALS['current_screen'] );
			if ( null !== $screen ) { $GLOBALS['current_screen'] = $screen; }
			delete_user_meta( $this->account, $flag );
			remove_filter( 'woocommerce_get_query_vars', $map );
			remove_action( 'woocommerce_account_pow-fixture-new-list_endpoint', $content );
			$wp_rewrite->endpoints = $endpoints;
			$wp->public_query_vars = $public;
			$wp->query_vars        = $vars;
			[ $GLOBALS['wp_the_query'], $GLOBALS['wp_query'] ] = $main;
			pow_native_leave_visit( $this->admin );
		}
	}

	/**
	 * WooCommerce's temporary-password notice with the dashboard ticked. An
	 * account whose password WooCommerce generated carries
	 * default_password_nag, and WooCommerce's dashboard then offers a Resend
	 * link that mails the account a new password-reset link. The account
	 * holder outside a visit keeps the notice; inside a visit the flag reads
	 * false, the dashboard shows neither notice nor link, and a request
	 * carrying the Resend action with this visit's own valid nonce is
	 * refused before WooCommerce's handler runs.
	 */
	private function temporary_password(): void {
		global $wp;
		[ $first ] = $this->visits;
		$vars    = $wp->query_vars;
		$main    = [ $GLOBALS['wp_the_query'] ?? null, $GLOBALS['wp_query'] ?? null ];
		$session = WC()->session ?? null;
		$hidden  = null;
		foreach ( $GLOBALS['wp_filter']['get_user_option_default_password_nag']->callbacks ?? [] as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'] ?? null;
				if ( is_array( $function ) && $function[0] instanceof POW\RouteGuard && 'hide_temporary_password' === $function[1] ) { $hidden = $function; }
			}
		}
		try {
			$this->check( null !== $hidden, 'the plugin hides the temporary-password flag through WordPress\'s own user-option filter' );
			pow_native_leave_visit( $this->admin );
			$this->check( $this->registry->update( $this->partner->id, [ 'visit_endpoints' => 'dashboard' ] ), 'the connection ticks the dashboard' );
			update_user_option( $this->account, 'default_password_nag', true, true );
			delete_user_meta( $this->account, WC_Form_Handler::SET_PASSWORD_RESEND_META );
			pow_native_leave_visit( $this->account );
			$this->check( (bool) get_user_option( 'default_password_nag' ), 'the account holder outside a visit keeps the temporary-password flag' );

			$guard = new POW\RouteGuard( POW\Plugin::instance(), $this->registry, POW\Plugin::instance()->settings() );
			pow_native_enter_visit( $first );
			WC()->session = pow_native_visit_handler( $first );
			$this->check( false === get_user_option( 'default_password_nag' ), 'inside a visit the flag reads false' );
			$dashboard = $this->account_shortcode( $guard );
			$this->check( is_string( $dashboard ) && ! str_contains( $dashboard, 'wc-resend-set-password' ) && ! str_contains( $dashboard, 'temporary password' ), 'the ticked dashboard in a visit shows no temporary-password notice and no Resend link' );
			if ( null !== $hidden ) {
				remove_filter( 'get_user_option_default_password_nag', $hidden, PHP_INT_MAX );
				$control = $this->account_shortcode( $guard );
				add_filter( 'get_user_option_default_password_nag', $hidden, PHP_INT_MAX );
				$this->check( is_string( $control ) && str_contains( $control, 'wc-resend-set-password' ), 'control: without the filter the same visit would see WooCommerce\'s Resend link' );
			}
			$nonce = wp_create_nonce( 'wc-resend-set-password' );
			$this->check( null === $this->account_page( $guard, [ 'wc-resend-set-password' => '1', '_wpnonce' => $nonce ] ), 'a request carrying the Resend action with this visit\'s valid nonce is refused before WooCommerce handles it' );
			$this->check( '' === (string) get_user_meta( $this->account, WC_Form_Handler::SET_PASSWORD_RESEND_META, true ), 'the shared account was not asked for a new password link' );
		} finally {
			delete_user_option( $this->account, 'default_password_nag', true );
			delete_user_meta( $this->account, WC_Form_Handler::SET_PASSWORD_RESEND_META );
			$wp->query_vars = $vars;
			[ $GLOBALS['wp_the_query'], $GLOBALS['wp_query'] ] = $main;
			WC()->session = $session;
			pow_native_leave_visit( $this->admin );
		}
	}

	/**
	 * WooCommerce's dashboard greets the account with "not you? Log out",
	 * a wp-login.php logout link that would end the visit and delete its
	 * basket. With the dashboard ticked, the account holder outside a visit
	 * keeps that link and the menu's Log out item; inside a visit the whole
	 * account page, dashboard text and menu, has neither, the greeting still
	 * names the account, and content added to the dashboard still shows.
	 */
	private function dashboard_logout(): void {
		global $wp;
		[ $first ] = $this->visits;
		$vars    = $wp->query_vars;
		$main    = [ $GLOBALS['wp_the_query'] ?? null, $GLOBALS['wp_query'] ?? null ];
		$session = WC()->session ?? null;
		$mark    = static function (): void { echo '<p>' . self::DASHBOARD_MARK . '</p>'; };
		$swap    = null;
		foreach ( $GLOBALS['wp_filter']['wc_get_template']->callbacks ?? [] as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'] ?? null;
				if ( is_array( $function ) && $function[0] instanceof POW\Account\VisitDashboard ) { $swap = $function; }
			}
		}
		$logout = static fn( ?string $html ): bool => is_string( $html ) && str_contains( $html, 'wp-login.php?action=logout' );
		add_action( 'woocommerce_account_dashboard', $mark );
		try {
			$this->check( null !== $swap, 'the plugin swaps the dashboard template through WooCommerce\'s own template filter' );
			pow_native_leave_visit( $this->admin );
			$this->check( $this->registry->update( $this->partner->id, [ 'visit_endpoints' => 'dashboard' ] ), 'the connection ticks the dashboard' );
			$guard = new POW\RouteGuard( POW\Plugin::instance(), $this->registry, POW\Plugin::instance()->settings() );

			pow_native_leave_visit( $this->account );
			$outside = $this->account_shortcode( $guard );
			$this->check( $logout( $outside ), 'outside a visit the account holder\'s dashboard keeps WooCommerce\'s wp-login.php logout link' );
			$this->check( is_string( $outside ) && str_contains( $outside, 'customer-logout' ), 'and the account menu keeps its Log out item' );

			pow_native_enter_visit( $first );
			WC()->session = pow_native_visit_handler( $first );
			$inside = $this->account_shortcode( $guard );
			$name   = (string) ( get_userdata( $this->account )->display_name ?? '' );
			$this->check( is_string( $inside ) && str_contains( $inside, self::DASHBOARD_MARK ), 'inside a visit the ticked dashboard opens, and content added to it still shows' );
			$this->check( is_string( $inside ) && ! $logout( $inside ) && ! str_contains( $inside, 'action=logout' ), 'inside a visit the dashboard text has no logout link' );
			$this->check( is_string( $inside ) && ! str_contains( $inside, 'customer-logout' ), 'and the account menu has no Log out item' );
			$this->check( is_string( $inside ) && '' !== $name && str_contains( $inside, 'Hello <strong>' . esc_html( $name ) . '</strong>' ), 'the dashboard still greets the account by name' );
			if ( null !== $swap ) {
				remove_filter( 'wc_get_template', $swap, PHP_INT_MAX );
				$control = $this->account_shortcode( $guard );
				add_filter( 'wc_get_template', $swap, PHP_INT_MAX, 2 );
				$this->check( $logout( $control ), 'control: without the swap the same visit would see WooCommerce\'s logout link' );
			}
			$this->check( POW\Sessions\Session::ACTIVE === ( $this->sessions->find( $first->id )?->status ?? '' ), 'the visit is still open after its dashboard rendered' );
		} finally {
			remove_action( 'woocommerce_account_dashboard', $mark );
			$wp->query_vars = $vars;
			[ $GLOBALS['wp_the_query'], $GLOBALS['wp_query'] ] = $main;
			WC()->session = $session;
			pow_native_leave_visit( $this->admin );
		}
	}

	/**
	 * The whole account page as WooCommerce's shortcode renders it for a
	 * request with no endpoint, notices included; null when the guard
	 * refused it. The empty `page` query var is what a pretty-permalink
	 * request for /my-account/ carries, and it is how WooCommerce tells that
	 * the dashboard is the current page.
	 */
	private function account_shortcode( POW\RouteGuard $guard ): ?string {
		$page = $this->account_page( $guard, [ 'page' => '' ] );
		if ( null === $page ) { return null; }
		ob_start();
		echo WC_Shortcode_My_Account::output( [] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixture capture.
		wc_print_notices();
		return (string) ob_get_clean();
	}

	/**
	 * A visit creates no user, through the one routine every user-creating
	 * path ends in, and the refusal is in the audit trail. Updates of the
	 * shared account still work inside a visit, and users are created
	 * normally outside one.
	 */
	private function user_creation(): void {
		global $wpdb;
		[ $first ] = $this->visits;
		$login = 'visit-created-' . bin2hex( random_bytes( 6 ) );
		$saved = [ $_SERVER['SCRIPT_FILENAME'] ?? null, $_REQUEST['action'] ?? null, $_SERVER['REQUEST_URI'] ?? null ];
		pow_native_enter_visit( $first );
		try {
			$_SERVER['SCRIPT_FILENAME'] = ABSPATH . 'wp-admin/admin-ajax.php';
			$_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php';
			$_REQUEST['action'] = 'fixture_create_subaccount';
			$result = wp_insert_user( [ 'user_login' => $login, 'user_email' => $login . '@example.invalid', 'user_pass' => wp_generate_password( 32 ), 'role' => 'customer' ] );
			$this->check( $result instanceof WP_Error && 'empty_data' === $result->get_error_code(), 'creating a user inside a visit returns a WP_Error' );
			$this->check( false === username_exists( $login ) && false === email_exists( $login . '@example.invalid' ), 'no user row was written' );
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT partner_id, session_id, user_id, result, detail FROM ' . POW\Installer::log_table() . ' WHERE event = %s AND session_id = %d ORDER BY id DESC LIMIT 1', 'visit_user_create_refused', $first->id ), ARRAY_A );
			$detail = is_array( $row ) ? json_decode( (string) $row['detail'], true ) : null;
			$this->check(
				is_array( $row ) && (int) $row['partner_id'] === $this->partner->id && (int) $row['user_id'] === $this->account && 'refused' === $row['result']
					&& [ 'script' => 'admin-ajax.php', 'action' => 'fixture_create_subaccount', 'wc_ajax' => '', 'rest_route' => '', 'path' => '/wp-admin/admin-ajax.php' ] === $detail && ! str_contains( (string) $row['detail'], $login ),
				'the refusal is audited with its connection, visit, script, action and path, and nothing about the refused account'
			);
			$refused = static fn(): int => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . POW\Installer::log_table() . ' WHERE event = %s AND session_id = %d', 'visit_user_create_refused', $first->id ) );
			$before  = $refused();
			$again   = wp_insert_user( [ 'user_login' => $login . '-2', 'user_email' => $login . '-2@example.invalid', 'user_pass' => wp_generate_password( 32 ), 'role' => 'customer' ] );
			$this->check( $again instanceof WP_Error && false === username_exists( $login . '-2' ) && $refused() === $before, 'a second attempt in the same request is refused and not recorded again' );
			$updated = wp_update_user( [ 'ID' => $this->account, 'display_name' => (string) get_userdata( $this->account )->display_name ] );
			$this->check( $updated === $this->account, 'updating the shared account inside a visit is not creation and still works' );
		} finally {
			[ $script, $action, $uri ] = $saved;
			if ( null === $script ) { unset( $_SERVER['SCRIPT_FILENAME'] ); } else { $_SERVER['SCRIPT_FILENAME'] = $script; }
			if ( null === $action ) { unset( $_REQUEST['action'] ); } else { $_REQUEST['action'] = $action; }
			if ( null === $uri ) { unset( $_SERVER['REQUEST_URI'] ); } else { $_SERVER['REQUEST_URI'] = $uri; }
			pow_native_leave_visit( $this->admin );
		}
		$this->check( pow_native_bound_account( 'lockdown-outside' ) > 0, 'outside a visit users are created normally' );
	}

	private function foreign_basket(): void {
		[ $first, $second ] = $this->visits;
		$own = POW\Cart\SessionKey::for_session( $first );
		$foreign = POW\Cart\SessionKey::for_session( $second );
		$handler = pow_native_visit_handler( $first );
		WC()->session = $handler;
		$this->check( $this->carts->owned_key( $own ) && ! $this->carts->owned_key( $foreign ), 'only this visit own cart key is this request basket' );
		$this->check( $this->carts->protected_key( $foreign ) && ! $this->carts->protected_key( (string) $this->account ), 'a colleague visit key is protected and the shared account id is not a visit key' );
		$this->check( $this->threw( fn() => $this->carts->login( $foreign ) ), 'a colleague visit key resolves no login for this request' );
		$this->check( [ 'lockdown-1' ] === array_keys( (array) pow_native_cart_value( $foreign, 'cart' ) ), 'the colleague basket row still holds only its own lines' );
		$this->check( null === pow_native_cart_row( (string) $this->account ), 'no visit writes a basket row keyed on the shared account' );
		$utils = 'Automattic\\WooCommerce\\StoreApi\\Utilities\\CartTokenUtils';
		if ( class_exists( $utils ) && method_exists( $utils, 'get_cart_token' ) ) {
			$saved = $_SERVER['HTTP_CART_TOKEN'] ?? null;
			try {
				foreach ( [ 'a colleague visit key' => $foreign, 'the bound account' => (string) $this->account ] as $label => $payload ) {
					$_SERVER['HTTP_CART_TOKEN'] = $utils::get_cart_token( $payload );
					$refusal = $this->carts->token_authentication( null );
					$this->check( $refusal instanceof WP_Error && 'pow_cart_token_unsupported' === $refusal->get_error_code(), 'a Cart-Token naming ' . $label . ' is refused' );
				}
			} finally {
				if ( null === $saved ) { unset( $_SERVER['HTTP_CART_TOKEN'] ); } else { $_SERVER['HTTP_CART_TOKEN'] = $saved; }
			}
		}
		pow_native_leave_visit( $this->admin );
	}

	private function foreign_order(): void {
		[ $first, $second ] = $this->visits;
		$this->endpoint( [ 'view-order' => (string) $this->quote ] );
		pow_native_enter_visit( $second );
		$this->check( $this->redirected( fn() => $this->guard->guard() ), 'a colleague quote order is refused inside another visit' );
		pow_native_enter_visit( $first );
		$this->check( ! $this->redirected( fn() => $this->guard->guard() ), 'a visit may read the quote order it created' );
		$this->endpoint( [ 'view-order' => (string) $this->ordinary_order ] );
		$this->check( $this->redirected( fn() => $this->guard->guard() ), 'an order carrying no visit is not a visit order to read' );
		$this->endpoint( [ 'order-pay' => (string) $this->quote ] );
		$this->check( $this->redirected( fn() => $this->guard->guard() ), 'paying a quote order is refused inside its own visit' );
		pow_native_leave_visit( $this->account );
		$this->check( $this->redirected( fn() => $this->guard->guard() ), 'paying a quote order is refused outside every visit too' );
		$this->endpoint( [] );
		pow_native_leave_visit( $this->admin );
	}

	private function checkout(): void {
		[ $first ] = $this->visits;
		$quote = wc_get_order( $this->quote );
		$ordinary = wc_get_order( $this->ordinary_order );
		pow_native_enter_visit( $first );
		$this->check( $this->guard->checkout_blocked_for_visit(), 'checkout is blocked inside a visit' );
		$this->check( $this->threw( fn() => $this->guard->enforce_order( $ordinary ) ), 'creating a checkout order inside a visit is refused' );
		$this->check( $this->threw( fn() => $this->guard->block_store_api_checkout( $ordinary ) ), 'the Store API checkout route is refused inside a visit' );
		if ( function_exists( 'wc_clear_notices' ) && function_exists( 'wc_get_notices' ) ) {
			wc_clear_notices();
			$this->guard->block_checkout_process();
			$notices = wc_get_notices( 'error' );
			$this->check( [] !== $notices, 'classic checkout inside a visit refuses with a buyer-facing notice' );
			wc_clear_notices();
		}
		pow_native_leave_visit( $this->account );
		$this->check( ! $this->guard->checkout_blocked_for_visit(), 'the bound account checks out normally outside a visit' );
		$this->check( ! $this->threw( fn() => $this->guard->enforce_order( $ordinary ) ), 'an ordinary order of that account is created normally' );
		$this->check( $this->threw( fn() => $this->guard->enforce_order( $quote ) ), 'a punchout quote order is not payable anywhere' );
		pow_native_leave_visit( $this->admin );
	}

	/**
	 * Logging out of a visit ends that visit and nobody else's.
	 *
	 * The teardown has to happen here, under the connection lock, because
	 * core's own wp_destroy_current_session() is an unlocked read-modify-write
	 * of the single session_tokens row the whole account shares and runs before
	 * any hook a plugin can reach. The proof is therefore that by the time this
	 * door returns the row is expired, its login token no longer verifies and
	 * its basket row is gone, while every visit seeded earlier is untouched. It
	 * opens its own visit, so it can run last without disturbing them.
	 */
	private function logout(): void {
		$suffix  = bin2hex( random_bytes( 6 ) );
		$visit   = pow_native_open_visit( $this->partner->id, $this->account, [ 'buyer_identity' => 'logout-' . $suffix . '@example.invalid', 'buyer_name' => 'Employee logging out' ] );
		$key     = POW\Cart\SessionKey::for_session( $visit );
		$handler = pow_native_visit_handler( $visit );
		WC()->session = $handler;
		$handler->set( 'cart', [ 'logout-' . $suffix => [ 'product_id' => 0, 'quantity' => 1 ] ] );
		if ( ! $handler->save_checked() ) { throw new RuntimeException( 'Logout basket could not be persisted.' ); }
		$this->check( null !== pow_native_cart_row( $key ), 'the visit about to log out has a basket row of its own' );

		$saved = [ $_REQUEST['action'] ?? null, $_REQUEST['_wpnonce'] ?? null ];
		try {
			$_REQUEST['action'] = 'logout';

			// wp-login.php checks its own log-out nonce after this hook and
			// offers a confirmation screen without one, so an unauthorized
			// request must leave the visit exactly as it is.
			pow_native_enter_visit( $visit );
			pow_native_forget_visit_memo();
			$_REQUEST['_wpnonce'] = 'not-this-visits-nonce';
			$this->check( ! $this->redirected( fn() => $this->guard->guard_login_screen() ), 'an unauthorized logout is not redirected' );
			$this->check( POW\Sessions\Session::ACTIVE === ( $this->sessions->find( $visit->id )?->status ?? '' ), 'an unauthorized logout ends no visit' );
			$this->check( null !== pow_native_cart_row( $key ), 'an unauthorized logout deletes no basket row' );

			pow_native_enter_visit( $visit );
			pow_native_forget_visit_memo();
			$_REQUEST['_wpnonce'] = wp_create_nonce( 'log-out' );
			$this->check( ! $this->redirected( fn() => $this->guard->guard_login_screen() ), 'the authorized logout is allowed through' );
			$this->check( POW\Sessions\Session::EXPIRED === ( $this->sessions->find( $visit->id )?->status ?? '' ), 'the logout expired its own visit row' );
			$this->check( ! WP_Session_Tokens::get_instance( $this->account )->verify( $visit->wp_session_token ), 'the logout destroyed its own login token' );
			$this->check( null === pow_native_cart_row( $key ), "the logout deleted its own visit's basket row" );
			$this->check( ! isset( $_COOKIE[ LOGGED_IN_COOKIE ] ), "core's unlocked teardown is left no token to read" );

			foreach ( $this->visits as $colleague ) {
				$live = $this->sessions->find( $colleague->id );
				$this->check(
					$live && POW\Sessions\Session::ACTIVE === $live->status && WP_Session_Tokens::get_instance( $this->account )->verify( $colleague->wp_session_token ),
					'visit ' . $colleague->id . ' of the same account keeps its row and its login'
				);
			}
		} finally {
			[ $action, $nonce ] = $saved;
			if ( null === $action ) { unset( $_REQUEST['action'] ); } else { $_REQUEST['action'] = $action; }
			if ( null === $nonce ) { unset( $_REQUEST['_wpnonce'] ); } else { $_REQUEST['_wpnonce'] = $nonce; }
			pow_native_leave_visit( $this->admin );
		}
	}

	public function run(): void {
		$cookies = $_COOKIE;
		$session = WC()->session ?? null;
		ob_start();
		try {
			$this->seed();
			$this->admin_area();
			$this->rest_routes();
			$this->account_surfaces();
			$this->account_endpoints();
			$this->wp_admin_form();
			$this->temporary_password();
			$this->dashboard_logout();
			$this->foreign_basket();
			$this->foreign_order();
			$this->checkout();
			$this->user_creation();
			$this->logout();
		} finally {
			$this->endpoint( [] );
			pow_native_leave_visit( $this->admin );
			$_COOKIE = $cookies;
			WC()->session = $session;
			$output = ob_get_clean();
			if ( is_string( $output ) ) { echo $output; }
		}
		echo 'Passed: ' . $this->passed . ' Failed: ' . $this->failed . " Skipped: 0\n";
		echo 'Fixture: connection ' . $this->partner->id . ', account ' . $this->account . ', visits ' . implode( '/', array_map( static fn( POW\Sessions\Session $v ): int => $v->id, $this->visits ) ) . "\n";
		if ( $this->failed > 0 ) { WP_CLI::halt( 1 ); }
	}
}

( new VisitLockdownNative() )->run();
