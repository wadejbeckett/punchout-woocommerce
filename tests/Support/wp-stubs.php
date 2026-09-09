<?php
/**
 * The handful of WordPress functions the pure layers call.
 *
 * Every stub is function_exists-guarded, so nothing here is defined when
 * WordPress is loaded. It lives in the bootstrap rather than in one test
 * file so that any suite — the standalone runner or a single PHPUnit
 * class run on its own — has it, whatever order the files load in.
 *
 * The escaping stubs are the real WordPress implementations in miniature
 * (esc_html is htmlspecialchars with ENT_QUOTES), because a test that
 * proves output is escaped is worthless against a stub that escapes
 * nothing.
 *
 * nocache_headers() records its call count in pow_test_nocache_headers,
 * because a CLI run cannot see headers but can see that the page asked.
 *
 * The option, transient and mail stubs are recorders on the same
 * pattern: get_option reads pow_test_options, the transient pair shares
 * pow_test_transients, and wp_mail appends to pow_test_mail instead of
 * sending — which is how a throttle that must mail once, not once per
 * failure, can be proved.
 *
 * Four stubs read a $GLOBALS key so a test can steer them: __() consults
 * pow_test_translations (proving a string really goes through
 * translation, which an identity stub cannot), apply_filters runs one
 * callback per hook out of pow_test_filters, wp_verify_nonce accepts
 * only pow_test_valid_nonce, and get_current_user_id() returns
 * pow_test_current_user_id. All four behave as the plain stub would when
 * their key is unset.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'POW_PLUGIN_DIR' ) ) {
	define( 'POW_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/' );
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Translation stub — never defined when WordPress is loaded.
	 */
	function __( string $text, string $domain = '' ): string { // phpcs:ignore
		return $GLOBALS['pow_test_translations'][ $text ] ?? $text;
	}
}

if ( ! function_exists( '_x' ) ) {
	/**
	 * Context-qualified translation stub. The context disambiguates for
	 * translators only; it never changes the returned string, so this
	 * defers to __() and stays steerable by pow_test_translations.
	 */
	function _x( string $text, string $context, string $domain = '' ): string { // phpcs:ignore
		return __( $text, $domain );
	}
}

if ( ! function_exists( '_n_noop' ) ) {
	/**
	 * Registers a plural string for later translation without translating
	 * it now. Returns the same shape as WordPress: the numeric pair kept
	 * for legacy callers alongside the named keys.
	 *
	 * @return array<array-key, string|null>
	 */
	function _n_noop( string $singular, string $plural, ?string $domain = null ): array { // phpcs:ignore
		return [
			0          => $singular,
			1          => $plural,
			'singular' => $singular,
			'plural'   => $plural,
			'context'  => null,
			'domain'   => $domain,
		];
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string { // phpcs:ignore
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $text ): string { // phpcs:ignore
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_textarea' ) ) {
	function esc_textarea( string $text ): string { // phpcs:ignore
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( string $url ): string { // phpcs:ignore
		return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = '' ): string { // phpcs:ignore
		return esc_html( __( $text, $domain ) );
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( string $text, string $domain = '' ): void { // phpcs:ignore
		echo esc_html__( $text, $domain ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Stub of the WordPress hook runner: one callback per hook, taken from
	 * $GLOBALS['pow_test_filters'], which is enough to prove ordering.
	 * Unfiltered by default. Never defined when WordPress is loaded.
	 *
	 * @param mixed $value   Value to filter.
	 * @param mixed ...$args Further arguments; not passed on.
	 * @return mixed
	 */
	function apply_filters( string $hook, $value, ...$args ) { // phpcs:ignore
		$callback = $GLOBALS['pow_test_filters'][ $hook ] ?? null;

		return null === $callback ? $value : $callback( $value );
	}
}

if ( ! function_exists( 'locate_template' ) ) {
	/**
	 * No theme in the unit suite, so the plugin's own copy always wins.
	 *
	 * @param array<int, string> $names Candidate template files.
	 */
	function locate_template( array $names ): string { // phpcs:ignore
		return '';
	}
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( string $action = '-1', string $name = '_wpnonce', bool $referer = true, bool $display = true ): string { // phpcs:ignore
		$field = '<input type="hidden" name="' . esc_attr( $name ) . '" value="pow-test-nonce" />';

		if ( $display ) {
			echo $field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		return $field;
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	/**
	 * Accepts exactly the value a test declared valid; everything else is
	 * a forgery, which is what the production function means.
	 *
	 * @return int|false
	 */
	function wp_verify_nonce( string $nonce, string $action = '-1' ) { // phpcs:ignore
		return isset( $GLOBALS['pow_test_valid_nonce'] ) && $GLOBALS['pow_test_valid_nonce'] === $nonce ? 1 : false;
	}
}

if ( ! function_exists( 'nocache_headers' ) ) {
	/**
	 * Records that the page asked not to be cached. The real function
	 * sends headers, which a CLI test run cannot observe; the count is
	 * what the rule is about — it was called, or it was not.
	 */
	function nocache_headers(): void { // phpcs:ignore
		$GLOBALS['pow_test_nocache_headers'] = ( $GLOBALS['pow_test_nocache_headers'] ?? 0 ) + 1;
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * @param mixed $value Value to unslash.
	 * @return mixed
	 */
	function wp_unslash( $value ) { // phpcs:ignore
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $text ): string { // phpcs:ignore
		return trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', wp_strip_all_tags( $text ) ) );
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	/**
	 * The part of sanitize_title() an action key has to survive: anything
	 * outside [A-Za-z0-9_-] becomes a dash and the result is lowercased.
	 * The real function does more (accent folding, entity stripping); a
	 * key that this rewrites is rewritten by that one too, which is what
	 * the hook-name test is asking.
	 */
	function sanitize_title( string $text ): string { // phpcs:ignore
		return strtolower( (string) preg_replace( '/[^A-Za-z0-9_-]/', '-', $text ) );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * The acting administrator, from pow_test_current_user_id; 0 — nobody
	 * logged in — when a test has not set one, as on a cron run.
	 */
	function get_current_user_id(): int { // phpcs:ignore
		return (int) ( $GLOBALS['pow_test_current_user_id'] ?? 0 );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $text ): string { // phpcs:ignore
		return strip_tags( $text );
	}
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Options a test declared in pow_test_options; everything else is
	 * unset, which is what a fresh install looks like.
	 *
	 * @param mixed $default Fallback when unset.
	 * @return mixed
	 */
	function get_option( string $name, $default = false ) { // phpcs:ignore
		return $GLOBALS['pow_test_options'][ $name ] ?? $default;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * Transients live in pow_test_transients for the run. Expiry is not
	 * simulated: a test that needs one to lapse unsets the key.
	 *
	 * @return mixed false when unset, as WordPress returns.
	 */
	function get_transient( string $key ) { // phpcs:ignore
		return $GLOBALS['pow_test_transients'][ $key ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	/**
	 * @param mixed $value      Value to store.
	 * @param int   $expiration Recorded in pow_test_transient_expirations; expiry is not simulated.
	 */
	function set_transient( string $key, $value, int $expiration = 0 ): bool { // phpcs:ignore
		$GLOBALS['pow_test_transients'][ $key ] = $value;
		$GLOBALS['pow_test_transient_expirations'][ $key ][] = $expiration;

		return true;
	}
}

if ( ! function_exists( 'wp_mail' ) ) {
	/**
	 * Records the message rather than sending it, so a test can count
	 * what a throttle let through.
	 */
	function wp_mail( string $to, string $subject, string $message ): bool { // phpcs:ignore
		if ( isset( $GLOBALS['pow_test_mail_error'] ) ) { throw $GLOBALS['pow_test_mail_error']; }
		$GLOBALS['pow_test_mail'][] = [
			'to'      => $to,
			'subject' => $subject,
			'message' => $message,
		];

		return $GLOBALS['pow_test_mail_result'] ?? true;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/** Just enough of the WordPress error object to carry a message. */
	class WP_Error { // phpcs:ignore

		public function __construct( private string $code = '', private string $message = '' ) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * @param mixed $thing Value to test.
	 */
	function is_wp_error( $thing ): bool { // phpcs:ignore
		return $thing instanceof WP_Error;
	}
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! function_exists( 'home_url' ) ) {
	/** Only the absolute site URL used by the endpoint is modelled. */
	function home_url( string $path = '' ): string { // phpcs:ignore
		return 'https://shop.example.test' . $path;
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/** Absolute URLs only in this suite; protocol-relative WP handling is not modelled. */
	function wp_parse_url( string $url, int $component = -1 ): mixed { // phpcs:ignore
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'status_header' ) ) {
	function status_header( int $code ): void { // phpcs:ignore
		$GLOBALS['pow_test_status_headers'][] = $code;
	}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	/** Only array merging is used by Settings; string/object argument parsing is not modelled. */
	function wp_parse_args( array $args, array $defaults = [] ): array { // phpcs:ignore
		return array_merge( $defaults, $args );
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool { return get_current_user_id() > 0; }
}
if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user(): object { return (object) [ 'ID' => get_current_user_id(), 'roles' => $GLOBALS['pow_test_roles'] ?? [] ]; }
}
if ( ! function_exists( 'wp_get_session_token' ) ) {
	function wp_get_session_token(): string { return $GLOBALS['pow_test_login_token'] ?? ''; }
}
if ( ! function_exists( 'wp_clear_auth_cookie' ) ) {
	function wp_clear_auth_cookie(): void { $GLOBALS['pow_test_cookies_cleared'][] = true; }
}
if ( ! class_exists( 'WP_Session_Tokens' ) ) {
	class WP_Session_Tokens {
		public function __construct( private int $user_id = 0 ) {}
		public static function get_instance( int $user_id ): self { return new self( $user_id ); }
		public function verify( string $token ): bool { return ! empty( $GLOBALS['pow_test_session_tokens'][ $this->user_id ][ $token ] ); }
		public function update( string $token, array $info ): void { $GLOBALS['pow_test_session_tokens'][ $this->user_id ][ $token ] = $info; }
		public function destroy( string $token ): void { $GLOBALS['pow_test_destroyed_tokens'][] = $token; unset( $GLOBALS['pow_test_session_tokens'][ $this->user_id ][ $token ] ); }
	}
}
if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( mixed $key, string $group = '' ): bool { return true; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $value ): string|false { return json_encode( $value ); }
}

if ( ! function_exists( 'get_userdata' ) ) {
	/** Explicit ordinary-account fixtures; missing users must fail authorization. */
	function get_userdata( int $user_id ): object|false {
		return $GLOBALS['pow_test_users'][ $user_id ] ?? false;
	}
}
if ( ! function_exists( 'user_can' ) ) {
	function user_can( mixed $user, string $capability, mixed ...$args ): bool {
		$user = is_object( $user ) ? $user : get_userdata( (int) $user );
		return $user && ! empty( $user->allcaps[ $capability ] );
	}
}
if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( int $user_id, string $key = '', bool $single = false ): mixed {
		$value = $GLOBALS['pow_test_user_meta'][ $user_id ][ $key ] ?? null;
		return $single ? ( $value ?? '' ) : ( null === $value ? [] : [ $value ] );
	}
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'https://shop.example.test/wp-admin/' . $path;
	}
}
