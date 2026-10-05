<?php
/**
 * Opt-in native regression for the 0.4.9 session-safety fixes (PO-01, PO-02,
 * PO-03 and the REST route spelling finding), against real WordPress
 * authentication, real session tokens, real roles and the real REST server.
 *
 * POW_NATIVE_TESTS=disposable wp --user=<fixture-admin> eval-file tests/Integration/SessionSafetyNative.php
 *
 * Creates neutral customer/author accounts and one connection, and leaves them
 * for inspection. Every visit it opens is ended before it finishes.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'disposable' !== getenv( 'POW_NATIVE_TESTS' ) || 'local' !== wp_get_environment_type() || ! current_user_can( 'manage_woocommerce' ) || ! function_exists( 'WC' ) ) {
	throw new RuntimeException( 'Requires opted-in disposable local WordPress/WooCommerce CLI and a capable fixture administrator.' );
}

require_once dirname( __DIR__ ) . '/Support/native-visits.php';

final class SessionSafetyNative {
	private POW\Partners\Registry $registry;
	private POW\Sessions\Store $sessions;
	private int $admin;
	private int $passed = 0;
	private int $failed = 0;

	public function __construct() {
		$plugin         = POW\Plugin::instance();
		$this->registry = $plugin->registry() ?? throw new RuntimeException( 'Plugin services unavailable.' );
		$this->sessions = $plugin->sessions() ?? throw new RuntimeException( 'Plugin services unavailable.' );
		$this->admin    = get_current_user_id();
	}

	private function check( bool $ok, string $label ): void {
		if ( $ok ) { ++$this->passed; echo 'PASS ' . $label . "\n"; return; }
		++$this->failed; echo 'FAIL ' . $label . "\n";
	}

	/** What WordPress itself resolves for a request carrying this login's logged_in cookie. */
	private function authenticate_as( int $user_id, string $token ): int {
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		wp_set_current_user( 0 );
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user_id, time() + 3600, 'logged_in', $token );
		wp_cache_delete( $user_id, 'user_meta' );
		pow_native_forget_visit_memo();
		// A fresh gate per simulated request: the real one memoises per request.
		$gate = new POW\Sessions\DelegatedLogin( $this->sessions, $this->registry, POW\Plugin::instance()->audit() );
		$id   = (int) $gate->authenticate( apply_filters( 'determine_current_user', false ) );
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		return $id;
	}

	private function token_live( int $user_id, string $token ): bool {
		wp_cache_delete( $user_id, 'user_meta' );
		return WP_Session_Tokens::get_instance( $user_id )->verify( $token );
	}

	private function connection( int $owner, string $suffix ): int {
		$id = $this->registry->insert( [ 'name' => 'Safety ' . $suffix, 'status' => POW\Partners\Partner::STATUS_ACTIVE, 'owner_user_id' => $owner, 'from_domain' => 'NetworkID', 'from_identity' => 'safety-' . $suffix, 'sender_domain' => 'NetworkID', 'sender_identity' => 'safety-' . $suffix, 'to_domain' => 'NetworkID', 'to_identity' => 'supplier-' . $suffix, 'cxml_version' => '1.2.008', 'deployment_mode' => 'test', 'return_encoding' => 'base64', 'visit_endpoints' => 'dashboard' ], wp_generate_password( 40, false, false ) );
		if ( $id <= 0 ) { throw new RuntimeException( 'Connection creation failed.' ); }
		return $id;
	}

	public function run(): int {
		$suffix  = bin2hex( random_bytes( 4 ) );
		$account = pow_native_bound_account( 'safety-customer' );
		$author  = pow_native_bound_account( 'safety-author' );
		( new WP_User( $author ) )->set_role( 'author' );
		$contrib = pow_native_bound_account( 'safety-contributor' );
		( new WP_User( $contrib ) )->set_role( 'contributor' );

		// PO-01 at binding.
		$spare = $this->registry->insert( [ 'name' => 'Safety spare ' . $suffix, 'status' => POW\Partners\Partner::STATUS_DISABLED, 'from_domain' => 'NetworkID', 'from_identity' => 'spare-' . $suffix, 'sender_domain' => 'NetworkID', 'sender_identity' => 'spare-' . $suffix, 'to_domain' => 'NetworkID', 'to_identity' => 'supplier-' . $suffix, 'cxml_version' => '1.2.008', 'deployment_mode' => 'test', 'return_encoding' => 'base64' ] );
		$this->check( ! $this->registry->associate_owner( $spare, $author ), 'binding refuses a native Author' );
		$this->check( ! $this->registry->associate_owner( $spare, $contrib ), 'binding refuses a native Contributor' );
		$this->check( POW\Partners\Registry::eligible_login( get_userdata( $account ) ), 'a native Customer is eligible' );

		$partner = $this->connection( $account, $suffix );

		// Ordinary password login of the bound account, and an administrator login: never touched.
		$own   = WP_Session_Tokens::get_instance( $account )->create( time() + 3600 );
		$admin = WP_Session_Tokens::get_instance( $this->admin )->create( time() + 3600 );
		$this->check( $account === $this->authenticate_as( $account, $own ), 'the account holder\'s own login authenticates' );

		// A live visit authenticates.
		$live = pow_native_open_visit( $partner, $account );
		$this->check( $account === $this->authenticate_as( $account, $live->wp_session_token ), 'a live visit of a customer authenticates' );

		// REST route spellings inside the live visit.
		pow_native_enter_visit( $live );
		foreach ( [ '/wp/v2/users/me', '/wp/v2/Users/me', '/WP/V2/USERS', '/wp/v2/users/' . $account . '/Application-Passwords' ] as $route ) {
			$response = rest_do_request( new WP_REST_Request( 'GET', $route ) );
			$this->check( 403 === $response->get_status() && 'pow_visit_locked' === ( $response->get_data()['code'] ?? '' ), 'REST ' . $route . ' refused inside a visit (' . $response->get_status() . ')' );
		}
		$batch = new WP_REST_Request( 'POST', '/batch/v1' );
		$batch->set_body_params( [ 'requests' => [ [ 'method' => 'POST', 'path' => '/wp/v2/Users/me', 'body' => [ 'description' => 'x' ] ] ] ] );
		$batched = rest_do_request( $batch );
		$inner   = $batched->get_data()['responses'][0] ?? [];
		$this->check( 403 === (int) ( $inner['status'] ?? 0 ) || 400 <= $batched->get_status(), 'REST batch sub-request to /wp/v2/Users/me refused (' . (int) ( $inner['status'] ?? $batched->get_status() ) . ')' );
		// Another plugin's REST callbacks that drop an earlier short-circuit
		// (Secure Custom Fields' rest_pre_dispatch returns nothing when its
		// REST API setting is off): the refusal must survive them.
		$clobber = static function () { return null; };
		add_filter( 'rest_pre_dispatch', $clobber, 10, 0 );
		add_filter( 'rest_request_before_callbacks', $clobber, 10, 0 );
		foreach ( [ '/wp/v2/users/me', '/wp/v2/Users/me' ] as $route ) {
			$response = rest_do_request( new WP_REST_Request( 'GET', $route ) );
			$this->check( 403 === $response->get_status() && 'pow_visit_locked' === ( $response->get_data()['code'] ?? '' ), 'REST ' . $route . ' refused despite a callback that drops earlier results (' . $response->get_status() . ')' );
		}
		remove_filter( 'rest_pre_dispatch', $clobber, 10 );
		remove_filter( 'rest_request_before_callbacks', $clobber, 10 );
		$posts = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts' ) );
		$this->check( 200 === $posts->get_status(), 'REST /wp/v2/posts still answers inside a visit' );
		pow_native_leave_visit( $this->admin );

		// PO-02: the visit ended but its login survived a failed cleanup.
		$ended = pow_native_open_visit( $partner, $account );
		global $wpdb;
		$wpdb->update( POW\Installer::sessions_table(), [ 'status' => POW\Sessions\Session::EXPIRED ], [ 'id' => $ended->id ] );
		$this->check( $this->token_live( $account, $ended->wp_session_token ), 'fixture: ended visit still holds a live native token' );
		$this->check( 0 === $this->authenticate_as( $account, $ended->wp_session_token ), 'an ended visit\'s surviving login does not authenticate' );
		$this->check( ! $this->token_live( $account, $ended->wp_session_token ), 'that surviving login is revoked by the gate' );

		// PO-02 retry path: Cron's store method.
		$late = pow_native_open_visit( $partner, $account );
		$wpdb->update( POW\Installer::sessions_table(), [ 'status' => POW\Sessions\Session::RETURNED ], [ 'id' => $late->id ] );
		$found = array_filter( $this->sessions->ended_with_login( 200, $late->id - 1 ), static fn( $s ): bool => $s->id === $late->id );
		$this->check( 1 === count( $found ), 'hourly retry finds the ended visit with a login' );
		$this->check( true === $this->sessions->revoke_ended_login( $this->sessions->find( $late->id ), $this->registry ) && ! $this->token_live( $account, $late->wp_session_token ), 'hourly retry revokes it' );

		// PO-01 during a visit: the account is promoted.
		$promoted = pow_native_open_visit( $partner, $account );
		$user     = new WP_User( $account );
		$user->add_role( 'author' );
		clean_user_cache( $account );
		$this->check( 0 === $this->authenticate_as( $account, $promoted->wp_session_token ), 'a promoted account\'s visit login does not authenticate' );
		$row = pow_native_visit_row( $promoted->id );
		$this->check( POW\Sessions\Session::EXPIRED === ( $row['status'] ?? '' ) && ! $this->token_live( $account, $promoted->wp_session_token ), 'the promoted visit is ended and its login revoked' );
		$user->remove_role( 'author' );
		clean_user_cache( $account );

		// PO-03: removal revokes delegated logins only.
		$a = pow_native_open_visit( $partner, $account );
		$b = pow_native_open_visit( $partner, $account );
		$stale = pow_native_open_visit( $partner, $account );
		$wpdb->update( POW\Installer::sessions_table(), [ 'status' => POW\Sessions\Session::CLOSED ], [ 'id' => $stale->id ] );
		$this->check( POW\Installer::revoke_delegated_logins(), 'removal sweep reports every delegated login gone' );
		$this->check( ! $this->token_live( $account, $a->wp_session_token ) && ! $this->token_live( $account, $b->wp_session_token ) && ! $this->token_live( $account, $stale->wp_session_token ), 'open and stale delegated logins revoked' );
		$this->check( $this->token_live( $account, $own ), 'the account holder\'s own login survives' );
		$this->check( $this->token_live( $this->admin, $admin ), 'the administrator login survives' );
		$this->check( [] === $this->sessions->all_open( 1 ), 'no visit is left open' );

		// Real deactivation, then reactivation of the fixture plugin.
		$c = pow_native_open_visit( $partner, $account );
		deactivate_plugins( 'punchout-woocommerce/punchout-woocommerce.php' );
		$this->check( ! is_plugin_active( 'punchout-woocommerce/punchout-woocommerce.php' ), 'native deactivation completes' );
		$this->check( ! $this->token_live( $account, $c->wp_session_token ) && $this->token_live( $account, $own ), 'deactivation revoked the visit login and kept the account holder\'s' );
		$activated = activate_plugin( 'punchout-woocommerce/punchout-woocommerce.php' );
		$this->check( null === $activated && is_plugin_active( 'punchout-woocommerce/punchout-woocommerce.php' ), 'plugin reactivated' );

		WP_Session_Tokens::get_instance( $account )->destroy( $own );
		WP_Session_Tokens::get_instance( $this->admin )->destroy( $admin );
		wp_set_current_user( $this->admin );

		echo "Passed: {$this->passed} Failed: {$this->failed}\n";
		return $this->failed;
	}
}

$failed = ( new SessionSafetyNative() )->run();
if ( $failed > 0 ) { throw new RuntimeException( 'SessionSafetyNative failed.' ); }
