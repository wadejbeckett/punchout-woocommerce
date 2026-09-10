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

	/** Same native role/association semantics as the existing cart guard, without consulting or mutating a session. */
	private static function protected_actor(): bool {
		$id = get_current_user_id();
		if ( $id <= 0 ) { return false; }
		$user = get_userdata( $id );
		return ( $user && in_array( \POW\Installer::ROLE, (array) $user->roles, true ) ) || (int) get_user_meta( $id, '_pow_partner_id', true ) > 0;
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
