<?php
/** Coordinate native Woo persistence with the existing Punchout winner mutex. @package POW @license AGPL-3.0-or-later */
declare( strict_types = 1 );
namespace POW\Cart;
use POW\Partners\Registry;
use POW\Sessions\Store;
use POW\Sessions\Session;
use POW\Installer;
use POW\Logger;
use Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils;
defined( 'ABSPATH' ) || exit;

final class NativeSessionGuard {
	private static ?self $registered = null;
	public function __construct( private Registry $registry, private Store $sessions, private ?Logger $logger = null ) {}
	public static function registered(): self {
		if ( ! self::$registered ) { throw new \RuntimeException( 'Native cart guard unavailable.' ); }
		return self::$registered;
	}
	public function register(): void {
		self::$registered = $this;
		add_filter( 'woocommerce_session_handler', [ $this, 'select_handler' ], PHP_INT_MAX );
		add_filter( 'rest_authentication_errors', [ $this, 'token_authentication' ], PHP_INT_MAX );
		add_filter( 'rest_request_after_callbacks', [ $this, 'save_rest_cart' ], PHP_INT_MAX, 3 );
		add_action( 'woocommerce_cart_emptied', [ $this, 'cleanup_terminal_cart' ], PHP_INT_MAX, 0 );
	}
	/** The native token utility authenticates the key; posted/decoded-only identities never decide protection. */
	private function protected_token(): bool {
		$token = $_SERVER['HTTP_CART_TOKEN'] ?? '';
		$utils = CartTokenUtils::class;
		if ( ! is_string( $token ) || '' === $token || ! class_exists( $utils ) || ! $utils::validate_cart_token( $token ) ) { return false; }
		$payload = $utils::get_cart_token_payload( $token );
		return $this->protected_key( (string) ( $payload['user_id'] ?? '' ) );
	}
	public function token_authentication( mixed $result ): mixed {
		if ( $result instanceof \WP_Error ) { return $result; }
		try { return $this->protected_token() ? self::error( 'pow_cart_token_unsupported' ) : $result; }
		catch ( \Throwable $error ) { return self::error(); }
	}
	public function select_handler( mixed $handler ): mixed {
		// Refuse before constructing the final token handler, including its route-level reselection.
		if ( $this->protected_token() ) { $this->deny(); }
		if ( in_array( $handler, [ 'WC_Session_Handler', '\\WC_Session_Handler', NativeSessionHandler::class ], true ) ) { return NativeSessionHandler::class; }
		if ( $this->protected_key( (string) get_current_user_id() ) ) { $this->deny(); }
		return $handler;
	}
	public function protected_key( string $key ): bool {
		if ( '' === $key || ! ctype_digit( $key ) || (int) $key <= 0 ) { return false; }
		$user = get_userdata( (int) $key );
		return ( $user && in_array( Installer::ROLE, (array) $user->roles, true ) ) || (int) get_user_meta( (int) $key, '_pow_partner_id', true ) > 0;
	}
	/** Called before native data is hydrated, not at confirmation entry. */
	public function login( string $key ): Session {
		if ( (string) get_current_user_id() !== $key ) { throw new \RuntimeException( 'Native cart identity unavailable.' ); }
		$login = $this->sessions->find_for_login( (int) $key, wp_get_session_token(), [ Session::ACTIVE, Session::ORDERED ] );
		if ( ! $login || ! $this->sessions->login_valid_checked( $login ) || ! $login->expires || $login->expires <= gmdate( 'Y-m-d H:i:s' ) ) { throw new \RuntimeException( 'Native cart login unavailable.' ); }
		return $login;
	}
	/** Staging/authentication can invoke native code and therefore happens outside the mutex. */
	public function stage_identity( Session $bound ): array {
		$auth = $this->raw_auth( $bound->user_id );
		$fresh = $this->login( (string) $bound->user_id );
		$partner = $this->registry->find( $bound->partner_id );
		if ( $fresh->id !== $bound->id || $fresh->partner_id !== $bound->partner_id || $fresh->wp_session_token !== $bound->wp_session_token || ! $partner || ! $partner->is_active() || get_current_user_id() !== $bound->user_id || wp_get_session_token() !== $bound->wp_session_token || $auth !== $this->raw_auth( $fresh->user_id ) ) { throw new \RuntimeException( 'Native cart login changed.' ); }
		return [ 'session' => $fresh->id, 'user' => $fresh->user_id, 'token' => $fresh->wp_session_token, 'partner' => $fresh->partner_id, 'owner' => $partner->owner_user_id, 'auth' => $auth ];
	}
	/** Fresh protected reads only; no token-manager/currency/native session callbacks. */
	public function identity_locked( array $identity ): ?Session {
		$fresh = $this->sessions->find( $identity['session'] );
		$partner = $this->registry->find( $identity['partner'] );
		if ( ! $fresh || ! $partner || ! $partner->is_active() || $partner->owner_user_id !== $identity['owner'] || $fresh->partner_id !== $identity['partner'] || $fresh->user_id !== $identity['user'] || $fresh->wp_session_token !== $identity['token'] || ! in_array( $fresh->status, [ Session::ACTIVE, Session::ORDERED ], true ) || ! $fresh->expires || $fresh->expires <= gmdate( 'Y-m-d H:i:s' ) || $identity['auth'] !== $this->raw_auth( $identity['user'] ) ) { return null; }
		return $fresh;
	}
	private function raw_auth( int $user ): array {
		global $wpdb;
		// All native user metadata is conservative and includes token/role/association changes. Never unserialize in the critical section.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT umeta_id, meta_key, meta_value FROM ' . $wpdb->usermeta . ' WHERE user_id = %d ORDER BY umeta_id', $user ), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== ( $wpdb->last_error ?? '' ) ) { throw new \RuntimeException( 'Native login storage unavailable.' ); }
		return $rows;
	}
	public function save( NativeSessionHandler $handler, array $prepared ): bool {
		try { return $this->registry->with_partner_lock( $prepared['identity']['partner'], fn() => $handler->commit_locked( $prepared ) ); }
		catch ( \Throwable $error ) { $handler->refuse(); return false; }
	}
	/** Operational visibility for a refused protected save. The key is hashed; no session data, secrets or raw identifiers are logged. Logging failures never alter the refusal. */
	public function log_refusal( string $key, string $reason ): void {
		try { $this->logger?->warning( 'Native cart commit refused', [ 'session_key_hash' => substr( hash( 'sha256', $key ), 0, 16 ), 'reason' => $reason ] ); }
		catch ( \Throwable $error ) { /* A logging failure must not change the refusal outcome. */ }
	}
	public function invalidate_locked( Session $session ): bool {
		return Session::ORDERED === $session->status || $this->sessions->invalidate_delivery( $session->id, $session->user_id, $session->wp_session_token );
	}
	/** Removal does not require the native login to remain valid; return has just revoked it. Exact stored login and newer-open-session checks still protect replacements. */
	public function cleanup( NativeSessionHandler $handler, Session $bound, bool $terminal_only ): bool {
		try {
			return $this->registry->with_partner_lock( $bound->partner_id, function () use ( $handler, $bound, $terminal_only ) {
				$fresh = $this->sessions->find( $bound->id );
				if ( $fresh && Session::ORDERED === $fresh->status && Session::ORDERED !== $bound->status ) { return false; }
				if ( ! $fresh || $fresh->user_id !== $bound->user_id || $fresh->partner_id !== $bound->partner_id || $fresh->wp_session_token !== $bound->wp_session_token || ( $terminal_only && ! in_array( $fresh->status, [ Session::RETURNED, Session::CLOSED, Session::EXPIRED ], true ) ) ) { return false; }
				foreach ( $this->sessions->open_for_user( $bound->user_id ) as $open ) { if ( $open->id !== $bound->id || $open->wp_session_token !== $bound->wp_session_token ) { return false; } }
				return $handler->delete_locked();
			} );
		} catch ( \Throwable $error ) { $handler->refuse(); return false; }
	}
	public function cleanup_terminal_cart(): void {
		$handler = WC()->session ?? null;
		if ( $handler instanceof NativeSessionHandler ) { $handler->cleanup_terminal(); }
	}
	public function mark_changed(): void {
		$handler = WC()->session ?? null;
		if ( $handler instanceof NativeSessionHandler ) { $handler->mark_changed(); }
	}
	public function prepare( Session $session ): array {
		$handler = WC()->session ?? null;
		if ( ! $handler instanceof NativeSessionHandler || (string) $session->user_id !== (string) $handler->get_customer_id() ) { throw new \RuntimeException( 'Native cart handler unsupported.' ); }
		$prepared = $handler->stage();
		if ( $prepared['identity']['session'] !== $session->id ) { throw new \RuntimeException( 'Native cart login changed.' ); }
		return $prepared;
	}
	/** Explicit caller-held helper: used at consent save and immediately before the existing winner. */
	public function check_locked( Session $session, array $prepared ): bool {
		$handler = WC()->session ?? null;
		return $handler instanceof NativeSessionHandler && ( $prepared['identity']['session'] ?? null ) === $session->id && $handler->check_locked( $prepared );
	}
	public function commit_locked( Session $session, array $prepared ): bool {
		$handler = WC()->session ?? null;
		return $handler instanceof NativeSessionHandler && ( $prepared['identity']['session'] ?? null ) === $session->id && $handler->commit_locked( $prepared );
	}
	public function flush_cache(): void { $handler = WC()->session ?? null; if ( $handler instanceof NativeSessionHandler ) { $handler->flush_cache(); } }
	/** Cookie+Nonce mutations must finish persistence before their successful REST response is delivered. */
	public function save_rest_cart( mixed $response, mixed $route_handler, \WP_REST_Request $request ): mixed {
		if ( ! preg_match( '#^/wc/store/v[1-9][0-9]*/(?:cart|checkout)(?:/|$)#', $request->get_route() ) ) { return $response; }
		$handler = WC()->session ?? null;
		if ( $handler instanceof NativeSessionHandler && $handler->is_protected() && ! $handler->save_checked() ) { return self::error(); }
		return $response;
	}
	private function deny(): never { wp_die( esc_html__( 'This cart session is not supported for Punchout. Open the catalog again through your purchasing system.', 'punchout-woocommerce' ), '', [ 'response' => 403 ] ); exit; }
	private static function error( string $code = 'pow_cart_changed' ): \WP_Error { return new \WP_Error( $code, __( 'The cart changed or its session could not be protected. Reload the cart and review delivery again.', 'punchout-woocommerce' ), [ 'status' => 409 ] ); }
}
