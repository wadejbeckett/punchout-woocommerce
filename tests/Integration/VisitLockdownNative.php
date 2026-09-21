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
			$this->foreign_basket();
			$this->foreign_order();
			$this->checkout();
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
