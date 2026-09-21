<?php
/** Coordinate native Woo persistence with the existing Punchout winner mutex. @package POW @license AGPL-3.0-or-later */
declare( strict_types = 1 );
namespace POW\Cart;
use POW\Cart\SessionKey;
use POW\Partners\Registry;
use POW\Sessions\Store;
use POW\Sessions\Session;
use POW\Logger;
use Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils;
defined( 'ABSPATH' ) || exit;

final class NativeSessionGuard {
	private static ?self $registered = null;
	/** Re-entrancy bookkeeping for the per-visit cart lock, keyed by lock name. */
	private static array $visit_locks = [];
	/**
	 * User metadata that can change what this login is ALLOWED to do.
	 *
	 * The commit fingerprint is this list and nothing else. It used to be every
	 * usermeta row of the account, which is unusable once one account is shared:
	 * a colleague redeeming a Start token rewrites `session_tokens`, and
	 * WooCommerce itself rewrites `wc_last_active` on the `wp` action every five
	 * minutes of ordinary browsing — after staging and before the shutdown
	 * commit — so a single buyer shopping alone lost cart lines to a row that
	 * authorises nothing. The connection binding is not user metadata any more
	 * either: it is `partners.owner_user_id`, which identity_locked() re-proves
	 * from the registry under the same lock.
	 *
	 * The trade-off is deliberate: a login revoked between staging and the
	 * commit is no longer caught by the fingerprint. Revocation is proven
	 * outside the mutex by Store::login_valid_checked() (native authentication
	 * may not run under the lock), and a revoked visit's basket row is removed
	 * by the return path or by cron, so the worst case is one last write to a
	 * row that is about to be deleted.
	 */
	private const AUTH_META = [ 'capabilities', 'user_level' ];
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
		add_filter( 'rest_post_dispatch', [ $this, 'strip_cart_token' ], PHP_INT_MAX, 3 );
		add_action( 'woocommerce_cart_emptied', [ $this, 'cleanup_terminal_cart' ], PHP_INT_MAX, 0 );
	}
	/**
	 * Whether an inbound Cart-Token names a basket this plugin protects.
	 *
	 * The native token utility authenticates the token first; a posted or merely
	 * decoded identity never decides protection. Two payloads are refused: a
	 * visit's own key, and the customer account a connection is bound to —
	 * because a signed token naming that account is a cookie-less, nonce-less
	 * bearer credential for the shared login's shopping, and core's Store API
	 * handler would answer it with an ON DUPLICATE KEY UPDATE write that no
	 * compare-and-set guards. Shape alone cannot tell the second case from an
	 * ordinary shopper, so the connection registry is asked.
	 *
	 * Read from $_SERVER on purpose. The agentic checkout routes take the token
	 * from a BODY parameter and only then write it into the request headers, so
	 * the rest_authentication_errors gate sees nothing for them and
	 * select_handler() is the only door left — which is why the refusal lives in
	 * both.
	 */
	private function protected_token(): bool {
		$token = $_SERVER['HTTP_CART_TOKEN'] ?? '';
		$utils = CartTokenUtils::class;
		if ( ! is_string( $token ) || '' === $token || ! class_exists( $utils ) || ! $utils::validate_cart_token( $token ) ) { return false; }
		$payload = $utils::get_cart_token_payload( $token );
		$customer = (string) ( $payload['user_id'] ?? '' );
		if ( $this->protected_key( $customer ) ) { return true; }
		$account = ctype_digit( $customer ) ? (int) $customer : 0;
		return $account > 0 && null !== $this->registry->find_by_owner( $account );
	}
	public function token_authentication( mixed $result ): mixed {
		if ( $result instanceof \WP_Error ) { return $result; }
		try { return $this->protected_token() ? self::error( 'pow_cart_token_unsupported' ) : $result; }
		catch ( \Throwable $error ) { return self::error(); }
	}
	/**
	 * WC::initialize_session() re-evaluates this filter on every call — wc_load_cart
	 * at init 0, the Store API's CartController, DraftOrderTrait — and re-constructs
	 * the handler only when the live object is not an instance of what the filter
	 * answered. Answering a class name that differs from the live object's would
	 * silently build a SECOND handler and init() it mid-request while the plugin
	 * holds a staged snapshot, so this must keep answering the same class for the
	 * whole request. NativeSessionHandler::class is in the allowlist for exactly
	 * that reason, and the handler's own `( WC()->session ?? null ) !== $this`
	 * tests are the backstop.
	 */
	public function select_handler( mixed $handler ): mixed {
		// Refuse before constructing the final token handler, including its route-level reselection.
		$token = false;
		try { $token = $this->protected_token(); } catch ( \Throwable $error ) { $this->deny(); }
		if ( $token ) { $this->deny(); }
		// Core's init_session_from_request() validates ?session=, then generates a
		// customer id, re-cookies it and clones that basket's data onto this row.
		// The per-visit bypass never reaches it; a visit refuses it outright, and
		// the parameter is absent from every ordinary request, so the visit lookup
		// only runs when one is actually present.
		if ( isset( $_GET['session'] ) && $this->inside_visit() ) { $this->deny(); } // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence of core's clone parameter only; the value is never read.
		if ( in_array( $handler, [ 'WC_Session_Handler', '\\WC_Session_Handler', NativeSessionHandler::class ], true ) ) { return NativeSessionHandler::class; }
		// A third-party handler cannot carry a visit's basket, and the question is
		// "is this request inside a visit" — asking what the USER is would refuse the
		// bound account's own ordinary shopping. An unanswerable lookup fails closed.
		if ( $this->inside_visit() ) { $this->deny(); }
		return $handler;
	}
	/** Request-scoped "is this a visit", failing closed: an unanswerable lookup refuses rather than passing for an ordinary shopper. */
	private function inside_visit(): bool {
		try { return null !== $this->visit(); } catch ( \Throwable $error ) { $this->deny(); }
	}
	/**
	 * Whether a WooCommerce session key is a punchout visit's.
	 *
	 * Shape alone decides. The login is the connection's own customer account,
	 * so no question about the USER can tell a visit from the account holder's
	 * ordinary shopping, and a numeric or `t_` key must answer false so an
	 * ordinary shopper keeps core's behaviour untouched.
	 */
	public function protected_key( string $key ): bool {
		return SessionKey::is_visit_key( $key );
	}
	/**
	 * The visit this request is inside, still valid, or null.
	 *
	 * Proven by the row matching (current user, current WP session token): the
	 * token is minted per visit at auto-login, so two employees signed in as
	 * one account resolve to two different visits.
	 *
	 * @throws \RuntimeException When the session row cannot be looked up.
	 */
	public function visit(): ?Session {
		$user = (int) get_current_user_id();
		if ( $user <= 0 ) { return null; }
		$login = $this->sessions->find_for_login( $user, wp_get_session_token(), [ Session::ACTIVE, Session::ORDERED ] );
		if ( ! $login || ! $this->sessions->login_valid_checked( $login ) || ! $login->expires || $login->expires <= gmdate( 'Y-m-d H:i:s' ) ) { return null; }
		return $login;
	}
	/**
	 * Whether a WooCommerce session key belongs to THIS request's visit.
	 *
	 * Shape is not ownership: every concurrent buyer of one connection holds a
	 * correctly shaped key, and any of them can name another's. This starts from
	 * the key, reads the row that owns it — the column is UNIQUE, so it names
	 * exactly one visit where the shared user id names none — and requires this
	 * request to hold that visit's own WP session token.
	 *
	 * @throws \RuntimeException When the session row cannot be looked up.
	 */
	public function owned_key( string $key ): bool {
		return null !== $this->owned_visit( $key );
	}
	/** @throws \RuntimeException When the session row cannot be looked up. */
	private function owned_visit( string $key ): ?Session {
		$token = (string) wp_get_session_token();
		if ( ! SessionKey::is_visit_key( $key ) || '' === $token ) { return null; }
		$login = $this->sessions->find_by_wc_session_key( $key, [ Session::ACTIVE, Session::ORDERED ] );
		if ( ! $login || ! hash_equals( (string) $login->wp_session_token, $token ) ) { return null; }
		if ( ! $this->sessions->login_valid_checked( $login ) || ! $login->expires || $login->expires <= gmdate( 'Y-m-d H:i:s' ) ) { return null; }
		return $login;
	}
	/** Called before native data is hydrated, not at confirmation entry. The key, not the user id, is what binds a basket to a visit. */
	public function login( string $key ): Session {
		$login = $this->owned_visit( $key );
		if ( ! $login ) { throw new \RuntimeException( 'Native cart login unavailable.' ); }
		return $login;
	}
	/** Staging/authentication can invoke native code and therefore happens outside the mutex. */
	public function stage_identity( Session $bound ): array {
		$auth = $this->raw_auth( $bound->user_id );
		$key = SessionKey::for_session( $bound );
		$fresh = $this->login( $key );
		$partner = $this->registry->find( $bound->partner_id );
		if ( $fresh->id !== $bound->id || $fresh->partner_id !== $bound->partner_id || $fresh->wp_session_token !== $bound->wp_session_token || ! hash_equals( (string) $fresh->wc_session_key, $key ) || ! $partner || ! $partner->is_active() || get_current_user_id() !== $bound->user_id || wp_get_session_token() !== $bound->wp_session_token || $auth !== $this->raw_auth( $fresh->user_id ) ) { throw new \RuntimeException( 'Native cart login changed.' ); }
		return [ 'session' => $fresh->id, 'key' => $key, 'user' => $fresh->user_id, 'token' => $fresh->wp_session_token, 'partner' => $fresh->partner_id, 'owner' => $partner->owner_user_id, 'auth' => $auth ];
	}
	/**
	 * Fresh protected reads only; no token-manager/currency/native session callbacks.
	 *
	 * Two of these predicates are now the same fact: with one bound customer
	 * account per connection, `$fresh->user_id` and `$partner->owner_user_id`
	 * both say "the login is still this connection's account" and neither can
	 * tell one visit from another. The per-visit discriminators are the WP
	 * session token and the visit's own WooCommerce session key, both compared
	 * byte-exactly. This is the locked re-verification that makes the
	 * compare-and-set safe and none of it may be weakened.
	 */
	public function identity_locked( array $identity ): ?Session {
		$fresh = $this->sessions->find( $identity['session'] );
		$partner = $this->registry->find( $identity['partner'] );
		$key = (string) ( $identity['key'] ?? '' );
		if ( ! $fresh || ! $partner || ! $partner->is_active() || $partner->owner_user_id !== $identity['owner'] || $fresh->partner_id !== $identity['partner'] || $fresh->user_id !== $identity['user'] || ! hash_equals( (string) $fresh->wp_session_token, (string) $identity['token'] ) || ! SessionKey::is_visit_key( $key ) || ! hash_equals( (string) $fresh->wc_session_key, $key ) || ! in_array( $fresh->status, [ Session::ACTIVE, Session::ORDERED ], true ) || ! $fresh->expires || $fresh->expires <= gmdate( 'Y-m-d H:i:s' ) || $identity['auth'] !== $this->raw_auth( $identity['user'] ) ) { return null; }
		return $fresh;
	}
	/** The authorising rows of the bound account, named in SQL. Never unserialized: the values are compared as stored. */
	private function raw_auth( int $user ): array {
		global $wpdb;
		$keys = self::auth_meta_keys();
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT meta_key, meta_value FROM ' . $wpdb->usermeta . " WHERE user_id = %d AND meta_key IN ({$placeholders}) ORDER BY meta_key, meta_value", $user, ...$keys ), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== ( $wpdb->last_error ?? '' ) ) { throw new \RuntimeException( 'Native login storage unavailable.' ); }
		return $rows;
	}
	/** @return list<string> */
	private static function auth_meta_keys(): array {
		global $wpdb;
		$prefix = method_exists( $wpdb, 'get_blog_prefix' ) ? (string) $wpdb->get_blog_prefix() : (string) ( $wpdb->prefix ?? '' );
		return array_map( static fn( string $key ): string => $prefix . $key, self::AUTH_META );
	}
	public function save( NativeSessionHandler $handler, array $prepared ): bool {
		try { return $this->with_visit_lock( (int) $prepared['identity']['session'], fn() => $handler->commit_locked( $prepared ) ); }
		catch ( \Throwable $error ) { $handler->refuse_for_error( $error ); return false; }
	}
	/**
	 * Serialize one VISIT's basket writes, not one connection's.
	 *
	 * The cart mutex cannot be the per-connection lock: every employee of one
	 * customer shops as the same bound account, so every add-to-cart and every
	 * shipping recalculation of every concurrent buyer would queue behind one
	 * five-second GET_LOCK wait, and a wait that runs out refuses the write. The
	 * visit is the unit of contention, so the lock names the visit's row.
	 *
	 * The connection lock keeps everything it already guarded — redeem,
	 * teardown, registry writes, the delivery-consent transitions — and those
	 * callers enter the basket commit through check_locked()/commit_locked()
	 * while holding it, so both locks protect the same row and are always taken
	 * connection-first. Nothing inside this lock ever asks for the connection
	 * lock, so the order cannot invert.
	 */
	private function with_visit_lock( int $session_id, callable $operation ): mixed {
		global $wpdb;
		if ( $session_id <= 0 ) { throw new \RuntimeException( 'Visit cart lock unavailable.' ); }
		$key = self::visit_lock_key( $session_id );
		if ( isset( self::$visit_locks[ $key ] ) ) {
			++self::$visit_locks[ $key ];
			try { return $operation(); } finally { --self::$visit_locks[ $key ]; }
		}
		$previous = $wpdb->suppress_errors( true );
		$acquired = false;
		try {
			$acquired = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $key, 5 ) );
			if ( ! $acquired ) { throw new \RuntimeException( 'Visit cart lock unavailable.' ); }
			self::$visit_locks[ $key ] = 1;
			return $operation();
		} finally {
			unset( self::$visit_locks[ $key ] );
			try {
				if ( $acquired && '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) ) ) {
					throw new \RuntimeException( 'Visit cart lock release unconfirmed.' );
				}
			} finally { $wpdb->suppress_errors( $previous ); }
		}
	}
	/** Hashed, so no visit key and no identifier appears in a lock name a whole database can read. */
	private static function visit_lock_key( int $session_id ): string {
		global $wpdb;
		return 'pow_cart_' . substr( hash( 'sha256', ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|' . (string) ( $wpdb->prefix ?? '' ) . 'pow_sessions|' . $session_id ), 0, 52 );
	}
	/** Operational visibility for a refused protected save. The key is hashed; no session data, secrets or raw identifiers are logged. An 'exception' refusal names the throwable class and message. Logging failures never alter the refusal. */
	public function log_refusal( string $key, string $reason, ?string $detail = null ): void {
		$context = [ 'session_key_hash' => substr( hash( 'sha256', $key ), 0, 16 ), 'reason' => $reason ];
		if ( null !== $detail ) { $context['detail'] = $detail; }
		try { $this->logger?->warning( 'Native cart commit refused', $context ); }
		catch ( \Throwable $error ) { /* A logging failure must not change the refusal outcome. */ }
	}
	/**
	 * Consent belongs to an ACTIVE visit: Store::invalidate_delivery() answers
	 * false for every other status, and the guard exempts none of them. The
	 * ORDERED short-circuit that used to sit here ("a paid visit needs no
	 * consent invalidation") is gone with the paid exit — nothing writes ORDERED
	 * now, so it could only survive as a predicate that reads like live
	 * behaviour and can never be true.
	 */
	public function invalidate_locked( Session $session ): bool {
		return $this->sessions->invalidate_delivery( $session->id, $session->user_id, $session->wp_session_token );
	}
	/**
	 * Removal does not require the native login to remain valid; return has just revoked it.
	 *
	 * The fence is this visit's own row — its id, its login token and its own
	 * WooCommerce session key, all still as bound. It used to also refuse when
	 * the LOGIN had any other open session, which was right when one account
	 * meant one buyer and is unusable now: a colleague's open visit is the
	 * normal state, so no visit would ever delete its own basket and every row
	 * would leak until WooCommerce's expiry sweep found it.
	 */
	public function cleanup( NativeSessionHandler $handler, Session $bound, bool $terminal_only ): bool {
		try {
			return $this->with_visit_lock( $bound->id, function () use ( $handler, $bound, $terminal_only ) {
				$fresh = $this->sessions->find( $bound->id );
				if ( ! $fresh || $fresh->user_id !== $bound->user_id || $fresh->partner_id !== $bound->partner_id || ! hash_equals( (string) $fresh->wp_session_token, (string) $bound->wp_session_token ) || ! SessionKey::is_visit_key( (string) $fresh->wc_session_key ) || ! hash_equals( (string) $fresh->wc_session_key, (string) $bound->wc_session_key ) || ( $terminal_only && ! in_array( $fresh->status, [ Session::RETURNED, Session::CLOSED, Session::EXPIRED ], true ) ) ) { return false; }
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
		if ( ! $handler instanceof NativeSessionHandler || ! hash_equals( SessionKey::for_session( $session ), (string) $handler->get_customer_id() ) ) { throw new \RuntimeException( 'Native cart handler unsupported.' ); }
		$prepared = $handler->stage();
		if ( $prepared['identity']['session'] !== $session->id ) { throw new \RuntimeException( 'Native cart login changed.' ); }
		return $prepared;
	}
	/**
	 * Explicit caller-held helper: used at consent save and immediately before the existing winner.
	 *
	 * The caller holds the connection lock; the basket's own mutex is taken here
	 * so that every writer of one visit's row — this path and the shutdown
	 * commit alike — is serialized by the same lock.
	 */
	public function check_locked( Session $session, array $prepared ): bool {
		$handler = WC()->session ?? null;
		if ( ! $handler instanceof NativeSessionHandler || ( $prepared['identity']['session'] ?? null ) !== $session->id ) { return false; }
		return $this->with_visit_lock( $session->id, fn(): bool => $handler->check_locked( $prepared ) );
	}
	public function commit_locked( Session $session, array $prepared ): bool {
		$handler = WC()->session ?? null;
		if ( ! $handler instanceof NativeSessionHandler || ( $prepared['identity']['session'] ?? null ) !== $session->id ) { return false; }
		return $this->with_visit_lock( $session->id, fn(): bool => $handler->commit_locked( $prepared ) );
	}
	public function flush_cache(): void { $handler = WC()->session ?? null; if ( $handler instanceof NativeSessionHandler ) { $handler->flush_cache(); } }
	/** Cookie+Nonce mutations must finish persistence before their successful REST response is delivered. */
	public function save_rest_cart( mixed $response, mixed $route_handler, \WP_REST_Request $request ): mixed {
		if ( ! preg_match( '#^/wc/store/v[1-9][0-9]*/(?:cart|checkout)(?:/|$)#', $request->get_route() ) ) { return $response; }
		$handler = WC()->session ?? null;
		if ( $handler instanceof NativeSessionHandler && $handler->is_protected() && ! $handler->save_checked() ) { return self::error(); }
		return $response;
	}
	/**
	 * No Cart-Token may leave a visit.
	 *
	 * AbstractCartRoute::add_response_headers() answers every Store API cart and
	 * checkout response with a signed Cart-Token carrying
	 * WC()->session->get_customer_id() — inside a visit, the visit's own key.
	 * That token is a bearer credential for the basket: it authenticates with no
	 * cookie and no nonce, and core's Store API handler writes with an ON
	 * DUPLICATE KEY UPDATE that no compare-and-set guards. WooCommerce offers no
	 * filter on the header, so it is removed from the response before the server
	 * sends it; core's response object has no remove_header(), hence the
	 * get/set pair. An unanswerable visit lookup removes the header too —
	 * losing it costs an ordinary shopper nothing.
	 */
	public function strip_cart_token( mixed $response, mixed $server = null, mixed $request = null ): mixed {
		if ( ! is_object( $response ) || ! method_exists( $response, 'get_headers' ) || ! method_exists( $response, 'set_headers' ) ) { return $response; }
		$handler = WC()->session ?? null;
		if ( $handler instanceof NativeSessionHandler ) { $inside = $handler->in_visit(); }
		else { try { $inside = null !== $this->visit(); } catch ( \Throwable $error ) { $inside = true; } }
		if ( ! $inside ) { return $response; }
		$headers = (array) $response->get_headers();
		foreach ( array_keys( $headers ) as $name ) {
			if ( 0 === strcasecmp( (string) $name, 'Cart-Token' ) ) { unset( $headers[ $name ] ); }
		}
		$response->set_headers( $headers );
		return $response;
	}
	private function deny(): never { wp_die( esc_html__( 'This cart session is not supported for Punchout. Open the catalog again through your purchasing system.', 'punchout-woocommerce' ), '', [ 'response' => 403 ] ); exit; }
	private static function error( string $code = 'pow_cart_changed' ): \WP_Error { return new \WP_Error( $code, __( 'The cart changed or its session could not be protected. Reload the cart and review delivery again.', 'punchout-woocommerce' ), [ 'status' => 409 ] ); }
}
