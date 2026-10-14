<?php
/**
 * A browser whose visit has ended is sent to the state the visit ended in (0.5.0).
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Http;

use POW\Checkout\Handoff;
use POW\Plugin;
use POW\Sessions\Session;
use POW\Sessions\Store;

defined( 'ABSPATH' ) || exit;

/**
 * A visit can end while its browser is still on the shop: its time runs out, an administrator ends it, or the store
 * is switched off. Its login cookie stays in the browser but no longer signs anyone in, so the next page used to
 * open silently as a guest, with nothing to say the visit was over.
 *
 * With a state page chosen (Http\StatePage), a front-end page request (GET or HEAD, not AJAX, not signed in, not
 * inside a live visit, not the state page itself) whose login cookie names a visit that is over is sent to the state
 * the visit ended in: Session ended, Not sent after Cancel and return, Sent after a return. The dead cookie is cleared
 * with that redirect, so it happens once and the browser is an ordinary guest afterwards. A return whose handoff
 * page was never served is sent to that page first, and keeps the cookie, because the page is served only to it.
 *
 * Since 0.5.3 the browser a cart was sent from (the handoff page cleared its login cookie and left the keyed sent
 * marker) is sent to the Sent state when it asks for the cart or checkout with an empty cart, with or without a state
 * page: Back after Submit skips the self-posting handoff page and would otherwise land on an empty guest cart.
 *
 * The cookie is read, never trusted: it only names a login token, and only a visit row recording that exact token
 * (Store::find_login_any_status) counts. A cookie that names no visit (the account holder's own password login, for
 * example) changes nothing, and nothing changes while no state page is chosen. Checkout\LateSubmit applies the same
 * rule to a Submit.
 */
final class EndedVisit {

	public function __construct( private Plugin $plugin, private Store $sessions ) {}

	public function register(): void {
		add_action( 'template_redirect', [ $this, 'maybe_redirect' ], 0 );
	}

	/** `template_redirect` 0: send the browser on, before any guard or the page itself runs. */
	public function maybe_redirect(): void {
		$target = $this->target();
		if ( null === $target ) { return; }
		[ $url, $clear ] = $target;
		if ( $clear ) { wp_clear_auth_cookie(); }
		wp_safe_redirect( $url, 303 );
		exit;
	}

	/**
	 * Where this request goes, and whether the dead login cookie is cleared on the way; null to carry on.
	 *
	 * @return array{0: string, 1: bool}|null
	 */
	public function target(): ?array {
		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) );
		if ( ! in_array( $method, [ 'GET', 'HEAD' ], true ) || wp_doing_ajax() || is_user_logged_in() ) { return null; }
		try {
			$page = StatePage::for_site();
			if ( $page->is_state_request() || null !== $this->plugin->current_session() ) { return null; }
			$sent = $this->sent_browser_target();
			if ( null !== $sent ) { return $sent; }
			if ( 0 === $page->page_id() ) { return null; }
			$found = self::from_cookie( $this->sessions );
			if ( null === $found || ! self::over( $found[0] ) ) { return null; }
			[ $visit, $token ] = $found;
			if ( Session::RETURNED === $visit->status ) {
				$kept = Handoff::kept_url( $visit, $token );
				if ( null !== $kept ) { return [ $kept, false ]; }
			}
			$url = $page->url( self::state( $visit ) );
			return null !== $url ? [ $url, true ] : null;
		} catch ( \Throwable $error ) {
			// Nothing proven: the request carries on as it would have.
			return null;
		}
	}

	/**
	 * 0.5.3: the cart or checkout, asked for by the browser a cart was sent from, while its cart is empty: the Sent
	 * state (the state page, else /punchout/sent), cookies untouched. Serving the handoff page clears the login cookie
	 * and leaves the sent marker (Checkout\Handoff::SENT_MARKER); that page posts itself, so the browser's Back skips
	 * it and lands on checkout, which with the emptied cart would send the buyer on to an empty guest cart, as if the
	 * visit could be entered again. The marker is keyed, and only a visit row that returned counts; a later visit or a
	 * Cancel and return in the same browser drops it (Handoff::forget_marker()). A cart filled since (the browser
	 * shopping on as a guest), WooCommerce's order-received and order-pay pages and every other page open as usual.
	 *
	 * @return array{0: string, 1: bool}|null
	 */
	private function sent_browser_target(): ?array {
		$on_cart = ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() );
		// The plain cart and checkout only: order-received and order-pay are the browser's own later guest orders.
		if ( ! $on_cart || ( function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'order-pay' ) ) ) ) { return null; }
		$marker = $_COOKIE[ Handoff::SENT_MARKER ] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- parsed and keyed by marked_visit().
		$id     = is_string( $marker ) ? Handoff::marked_visit( $marker ) : 0;
		$visit  = $id > 0 ? $this->sessions->find( $id ) : null;
		if ( null === $visit || Session::RETURNED !== $visit->status ) { return null; }
		$cart = function_exists( 'WC' ) ? WC()->cart : null;
		if ( is_object( $cart ) && method_exists( $cart, 'is_empty' ) && ! $cart->is_empty() ) { return null; }
		return [ Handoff::sent_url(), false ];
	}

	/**
	 * The visit this browser's login cookie names, with the cookie's token; null when it names none. The cookie is
	 * only parsed: whether the login is valid is WordPress's question, and whether the visit is over is over()'s.
	 *
	 * @return array{0: Session, 1: string}|null
	 */
	public static function from_cookie( Store $sessions ): ?array {
		$cookie = function_exists( 'wp_parse_auth_cookie' ) ? wp_parse_auth_cookie( '', 'logged_in' ) : false;
		if ( ! is_array( $cookie ) ) { return null; }
		$token    = (string) ( $cookie['token'] ?? '' );
		$username = (string) ( $cookie['username'] ?? '' );
		if ( '' === $token || '' === $username ) { return null; }
		$user  = get_user_by( 'login', $username );
		$visit = $user ? $sessions->find_login_any_status( (int) $user->ID, $token ) : null;
		return $visit instanceof Session ? [ $visit, $token ] : null;
	}

	/** Whether a visit is over: it has left active (returned, closed, expired) or its time has run out. */
	public static function over( Session $visit ): bool {
		return ! in_array( $visit->status, [ Session::ACTIVE, Session::ORDERED ], true ) || ! $visit->expires || $visit->expires <= gmdate( 'Y-m-d H:i:s' );
	}

	/** The state a visit that is over ended in: Sent after a return, Not sent after Cancel and return, else Session ended. */
	public static function state( Session $visit ): string {
		return match ( $visit->status ) {
			Session::RETURNED => StatePage::SENT,
			Session::CLOSED   => StatePage::NOT_SENT,
			default           => StatePage::ENDED,
		};
	}
}
