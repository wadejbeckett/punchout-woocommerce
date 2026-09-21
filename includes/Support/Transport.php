<?php
/** Shared transport policy for plugin HTTP surfaces and buyer handoff targets. @package POW @license AGPL-3.0-or-later */
declare( strict_types = 1 );
namespace POW\Support;

defined( 'ABSPATH' ) || exit;

final class Transport {
	/** Register during plugin boot, before init:0 initializes Woo and wp_loaded processes its cart forms. */
	public static function register(): void {
		add_action( 'init', [ self::class, 'guard_shopping' ], -100 );
		// REST authentication can resolve an actor later than init. Refuse before Store API callbacks as well.
		add_filter( 'rest_pre_dispatch', [ self::class, 'guard_rest' ], -100 );
	}

	/**
	 * Whether this request belongs to punchout rather than to an ordinary
	 * shopper. Two signals, and deliberately no role and no user meta:
	 * buyers are signed in as the customer's own plain WooCommerce account,
	 * so anything asked about the USER answers "ordinary shopper" and the
	 * policy would stop covering punchout traffic altogether.
	 *
	 * 1. A live visit — the session row matching this request's user and WP
	 *    session token. That is buyer catalog, cart and Store API traffic.
	 * 2. The account a connection is bound to, visit or no visit: it is the
	 *    login every buyer of that connection arrives on, and its cookie
	 *    must never cross the wire in cleartext.
	 *
	 * Unlike the version this replaces it does consult a session — it never
	 * mutates one. Safe at init:-100 and rest_pre_dispatch:-100: both
	 * signals need only the auth cookie and $wpdb, neither needs
	 * WooCommerce, and the visit lookup is the one Plugin memoises for the
	 * whole request. Reached only once the request is already cleartext, so
	 * an HTTPS request pays nothing for it.
	 *
	 * A lookup that cannot run answers "punchout": refusing cleartext is
	 * the conservative half of that mistake, and Sessions\Current's own
	 * contract is that a throw is not "no visit".
	 */
	private static function protected_actor(): bool {
		$id = get_current_user_id();
		if ( $id <= 0 ) { return false; }
		$plugin = \POW\Plugin::instance();
		try {
			if ( null !== $plugin->current_session() ) { return true; }
			$partners = $plugin->registry();
			return null !== $partners && null !== $partners->find_by_owner( $id );
		} catch ( \Throwable $e ) {
			return true;
		}
	}

	public static function guard_shopping(): void {
		if ( ! self::request_allowed() && self::protected_actor() ) { self::require_https(); }
	}

	public static function guard_rest( mixed $result ): mixed {
		if ( ! self::request_allowed() && self::protected_actor() ) {
			self::private_headers();
			return new \WP_Error( 'pow_https_required', self::message(), [ 'status' => 403 ] );
		}
		return $result;
	}

	/** Only an explicit native environment setting permits cleartext; deploymentMode and debug flags do not. */
	private static function allows_http(): bool {
		return in_array( wp_get_environment_type(), [ 'local', 'development' ], true );
	}

	public static function request_allowed(): bool {
		// Proxy trust belongs to the web server/WordPress deployment. Never interpret forwarded headers here.
		return is_ssl() || self::allows_http();
	}

	public static function receiver_allowed( string $url ): bool {
		if ( preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) { return false; }
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) { return false; }
		$scheme = strtolower( $parts['scheme'] ?? '' );
		return 'https' === $scheme || ( 'http' === $scheme && self::allows_http() );
	}

	/** Supplier-owned links only. Never pass a stored BrowserFormPost through this method. */
	public static function supplier_url( string $url ): string {
		return self::allows_http() ? $url : set_url_scheme( $url, 'https' );
	}

	public static function message(): string {
		return __( 'HTTPS is required for this PunchOut request.', 'punchout-woocommerce' );
	}

	public static function private_headers(): void {
		defined( 'DONOTCACHEPAGE' ) || define( 'DONOTCACHEPAGE', true );
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Cache-Control: no-store, private, max-age=0', true );
			header( 'X-Robots-Tag: noindex, nofollow', true );
			header( 'Referrer-Policy: no-referrer', true );
		}
	}

	/** Refuse in place: redirecting a credential-bearing POST would discard or resend its body. */
	public static function require_https(): void {
		if ( self::request_allowed() ) { return; }
		self::private_headers();
		wp_die( esc_html( self::message() ), '', [ 'response' => 403 ] );
	}

	/** Shortcodes must return their response instead of emitting a full WordPress error document. */
	public static function notice(): string {
		self::private_headers();
		status_header( 403 );
		return '<p class="woocommerce-error">' . esc_html( self::message() ) . '</p>';
	}
}
