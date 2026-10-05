<?php
/**
 * The delegated-login gate.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Sessions;

use POW\Audit\Log;
use POW\Partners\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Decides, on every request, whether a WordPress login the plugin minted for
 * a visit may still act as the connection's account.
 *
 * A visit's login is an ordinary WordPress session token of the bound
 * customer account, recorded on the visit row. Whatever confines a visit
 * (the route guard, the cart guard, the checkout block) asks "which visit
 * is this request inside?" and gets an answer only for ACTIVE/ORDERED rows.
 * Two things could therefore leave a delegated login authenticated with no
 * confinement at all:
 *
 * - its visit ended (expired, returned, closed) but the native token
 *   survived a cleanup that could not be confirmed (finding PO-02);
 * - its account gained an authoring or administrative capability after the
 *   visit started (finding PO-01).
 *
 * This runs on WordPress's own `determine_current_user` filter, last, so it
 * judges every request — front end, wp-admin, admin-ajax, REST — before any
 * code acts as the account. In both cases the request continues as a guest,
 * which is stricter than any visit confinement, and the login is revoked
 * under the connection lock (its visit ended first for a promoted account).
 * A revocation that cannot be confirmed is retried on the next request that
 * presents the login and by the hourly job; until one succeeds the login
 * keeps failing here. Every other login — the account holder's own password
 * login, an administrator, a customer — is untouched: only tokens a visit
 * row records are ever judged or revoked.
 *
 * A lookup that cannot run leaves authentication to WordPress. It proves
 * nothing about the login, and the request guards that resolve visits
 * already refuse a request whose visit cannot be established.
 */
final class DelegatedLogin {

	/** True while a decision is being made, so a capability filter asking for the current user cannot recurse. */
	private bool $deciding = false;

	/** @var array<string, bool> Per request: user|token => may authenticate. */
	private array $decided = [];

	public function __construct(
		private Store $sessions,
		private Registry $registry,
		private ?Log $audit = null,
	) {}

	public function register(): void {
		add_filter( 'determine_current_user', [ $this, 'authenticate' ], PHP_INT_MAX );
	}

	/**
	 * Re-judge a user WordPress resolved before this gate was registered.
	 *
	 * Code running before the plugin boots can ask for the current user, and
	 * WordPress does not run determine_current_user twice in one request.
	 */
	public function recheck_current_user(): void {
		$current = $GLOBALS['current_user'] ?? null;
		if ( ! is_object( $current ) || (int) ( $current->ID ?? 0 ) <= 0 ) { return; }
		if ( 0 === $this->authenticate( (int) $current->ID ) ) { wp_set_current_user( 0 ); }
	}

	/**
	 * `determine_current_user`: keep the resolved user, or answer 0 (guest)
	 * for a delegated login that must not act any more.
	 *
	 * @param mixed $user_id What earlier callbacks resolved.
	 * @return mixed The same value, or 0.
	 */
	public function authenticate( mixed $user_id ): mixed {
		if ( $this->deciding || ! is_numeric( $user_id ) || (int) $user_id <= 0 ) {
			return $user_id;
		}

		$id = (int) $user_id;
		foreach ( self::request_tokens() as $token ) {
			$memo = $id . '|' . $token;
			if ( ! isset( $this->decided[ $memo ] ) ) {
				$this->deciding = true;
				try {
					$this->decided[ $memo ] = $this->may_authenticate( $id, $token );
				} finally {
					$this->deciding = false;
				}
			}
			if ( ! $this->decided[ $memo ] ) {
				self::forget_login();
				return 0;
			}
		}

		return $user_id;
	}

	private function may_authenticate( int $user_id, string $token ): bool {
		try {
			$visit = $this->sessions->find_login_any_status( $user_id, $token );
		} catch ( \Throwable $e ) {
			return true;
		}

		if ( null === $visit ) {
			return true;
		}

		if ( in_array( $visit->status, [ Session::ACTIVE, Session::ORDERED ], true ) ) {
			if ( Registry::eligible_login( get_userdata( $user_id ) ) ) {
				return true;
			}
			// PO-01: the account can now do more than shop. End the visit.
			$ended = false;
			try { $ended = $this->sessions->expire_and_destroy( $visit, $this->registry ); }
			catch ( \Throwable $e ) { $ended = false; }
			$this->record( 'visit_login_ineligible', $visit, $ended ? 'revoked' : 'revoke_failed' );
			return false;
		}

		// PO-02: the visit is over but its login is still valid (WordPress
		// validated it to get here). Revoke it again; refuse it either way.
		$revoked = false;
		try {
			$revoked = (bool) $this->registry->with_partner_lock( $visit->partner_id, fn() => $this->sessions->destroy_login_checked( $visit ) );
		} catch ( \Throwable $e ) { $revoked = false; }
		$this->record( 'visit_login_revoked', $visit, $revoked ? 'revoked' : 'revoke_failed' );
		return false;
	}

	private function record( string $event, Session $visit, string $result ): void {
		try {
			$this->audit?->write_checked( $event, [ 'partner_id' => $visit->partner_id, 'session_id' => $visit->id, 'user_id' => $visit->user_id, 'result' => $result ] );
		} catch ( \Throwable $e ) { /* Diagnostics never decide authentication. */ }
	}

	/**
	 * The session tokens this request presents: the logged-in cookie that
	 * every visit resolver reads, and the wp-admin auth cookies, which carry
	 * the same token for a browser but can be sent on their own.
	 *
	 * @return list<string>
	 */
	private static function request_tokens(): array {
		$tokens = [ (string) wp_get_session_token() ];
		if ( function_exists( 'wp_parse_auth_cookie' ) ) {
			foreach ( [ 'secure_auth', 'auth' ] as $scheme ) {
				$cookie = wp_parse_auth_cookie( '', $scheme );
				if ( is_array( $cookie ) && ! empty( $cookie['token'] ) ) { $tokens[] = (string) $cookie['token']; }
			}
		}
		return array_values( array_unique( array_filter( $tokens, static fn( string $token ): bool => '' !== $token ) ) );
	}

	/** Make the rest of this request, and the browser, forget the refused login. */
	private static function forget_login(): void {
		foreach ( [ 'LOGGED_IN_COOKIE', 'AUTH_COOKIE', 'SECURE_AUTH_COOKIE' ] as $name ) {
			if ( defined( $name ) ) { unset( $_COOKIE[ constant( $name ) ] ); }
		}
		if ( function_exists( 'wp_clear_auth_cookie' ) && ! headers_sent() ) { wp_clear_auth_cookie(); }
	}
}
