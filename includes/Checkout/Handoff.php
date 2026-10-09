<?php
/**
 * GET /punchout/handoff: the prepared cart return, served once after checkout (0.5.0).
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Checkout;

use POW\Audit\Log;
use POW\Http\StatePage;
use POW\Orders\QuoteOrder;
use POW\Sessions\Session;
use POW\Sessions\Store;
use POW\Support\Transport;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce's checkout answers Submit with a redirect (JSON for its script, a 302 without it), so the page that
 * posts the cXML to the buyer's purchasing system cannot be that answer. Submission keeps the prepared page here,
 * under the visit's lock and before the visit wins its return, and the browser fetches it from this theme-free
 * route.
 *
 * The page is kept under a hash of the visit's login token and the order key, and only for TTL seconds. It is
 * served once, with no-store, noindex, nosniff and no-referrer, to a GET that presents that same login cookie and
 * that order, and only when the visit row it names has returned with that login and that order: the request whose
 * delete removes the kept page is the one that serves it, so two GETs at once cannot both post the cart. Serving it
 * clears the login cookie in that browser (the login itself was ended when the visit won) and leaves a small marker
 * cookie naming the visit, keyed so it cannot be forged, by which a later Submit from a stale tab is recognised.
 * Anything else gets a short page with no form, so a refresh, a back button or a second tab never posts the cart
 * twice.
 *
 * The Sent state is where a Submit that arrives after the visit has returned is sent (0.5.0, ticket 74): the site's
 * state page when one is chosen (Http\StatePage, ticket 77), else the theme-free GET /punchout/sent, with the
 * `pow-state` classes and no form. With a state page chosen, a handoff that cannot be served sends the browser there
 * too (spent_target()): Sent when that order's page was served, Not sent when the order's visit returned with it but
 * its page never reached a browser, else Session ended.
 */
final class Handoff {

	/** Seconds the prepared page waits for its browser. */
	public const TTL = 300;

	/** The cookie serving the page leaves behind: the visit id and its key. Carries no secret. */
	public const SENT_MARKER = 'pow_visit_sent';

	/** How long the marker lasts: as long as WooCommerce's checkout nonce a stale tab could still hold. */
	private const MARKER_TTL = 86400;

	private const PREFIX = 'pow_checkout_handoff_';

	/** Names the mark a served page leaves for its order (a hash of the order key), for as long as the marker cookie. */
	private const SERVED_PREFIX = 'pow_checkout_handoff_served_';

	public function __construct( private Store $sessions, private ?Log $audit = null ) {}

	/** The transient name for a login token and an order key, or '' when either is missing. Only a hash is stored. */
	private static function key( string $login_token, string $order_key ): string {
		return '' === $login_token || '' === $order_key ? '' : self::PREFIX . hash( 'sha256', 'pow-checkout-handoff|' . $login_token . '|' . $order_key );
	}

	/** Keep the prepared page for this login and order. False when there is nothing to keep or the write failed. */
	public static function remember( string $login_token, string $order_key, int $session_id, int $order_id, string $markup ): bool {
		$key = self::key( $login_token, $order_key );
		if ( '' === $key || $session_id <= 0 || $order_id <= 0 || '' === trim( $markup ) ) { return false; }
		try {
			return true === set_transient( $key, [ 'session_id' => $session_id, 'order_id' => $order_id, 'markup' => $markup ], self::TTL );
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/**
	 * The kept page for this login and order, removed as it is read (served once), or null.
	 *
	 * @return array{session_id: int, order_id: int, markup: string}|null
	 */
	public static function take( string $login_token, string $order_key ): ?array {
		$key = self::key( $login_token, $order_key );
		if ( '' === $key ) { return null; }
		try {
			$record = get_transient( $key );
			// The delete is the claim: of two requests that read the page at once, only the one whose delete removed
			// it may serve it.
			if ( false === $record || true !== delete_transient( $key ) ) { return null; }
		} catch ( \Throwable $error ) {
			return null;
		}
		if ( ! is_array( $record ) || ! is_int( $record['session_id'] ?? null ) || ! is_int( $record['order_id'] ?? null ) || ! is_string( $record['markup'] ?? null ) || '' === $record['markup'] ) { return null; }
		return [ 'session_id' => $record['session_id'], 'order_id' => $record['order_id'], 'markup' => $record['markup'] ];
	}

	/** Whether a page is still kept for this login and order (never served, not expired). Takes nothing. */
	public static function kept( string $login_token, string $order_key ): bool {
		$key = self::key( $login_token, $order_key );
		if ( '' === $key ) { return false; }
		try {
			return is_array( get_transient( $key ) );
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/**
	 * Where a browser goes once its visit has returned: the handoff of the order the visit sent, while its page is
	 * still kept for this browser's login (the answer that named it may never have reached a browser, and the page is
	 * served only once), else the Sent state.
	 */
	public static function after_return( Session $returned, string $login_token ): string {
		return self::kept_url( $returned, $login_token ) ?? self::sent_url();
	}

	/** The handoff of the order a returned visit sent, while its page is still kept for this login; else null. */
	public static function kept_url( Session $returned, string $login_token ): ?string {
		try {
			$order = $returned->order_id > 0 && '' !== $login_token && hash_equals( $returned->wp_session_token, $login_token ) ? wc_get_order( $returned->order_id ) : null;
			$key   = $order instanceof \WC_Order ? (string) $order->get_order_key() : '';
			if ( '' !== $key && self::kept( $login_token, $key ) ) { return self::url( $returned->order_id, $key ); }
		} catch ( \Throwable $error ) {
			// The Sent state carries nothing a failed lookup could expose.
		}
		return null;
	}

	/** The Sent state: the chosen state page, else the theme-free /punchout/sent. No form, nothing posted. */
	public static function sent_url(): string {
		return StatePage::link( StatePage::SENT ) ?? Transport::supplier_url( home_url( '/punchout/sent' ) );
	}

	/** The transient marking that this order's page has been served. Only a hash of the order key names it. */
	private static function served_key( string $order_key ): string {
		return self::SERVED_PREFIX . hash( 'sha256', 'pow-checkout-handoff-served|' . $order_key );
	}

	/**
	 * Where the state page sends a browser whose handoff cannot be served, or null without a state page (the plain
	 * no-form page answers): Sent when this order's page has been served (a refresh, Back, a second tab); Not sent
	 * when the order (named by its id and key) is the one its visit returned with, but its page never reached a
	 * browser; Session ended for anything else, including a lookup that fails.
	 */
	public function spent_target( int $order_id, string $order_key ): ?string {
		$page = StatePage::for_site();
		if ( 0 === $page->page_id() ) { return null; }
		$state = StatePage::ENDED;
		try {
			if ( '' !== $order_key && false !== get_transient( self::served_key( $order_key ) ) ) {
				$state = StatePage::SENT;
			} elseif ( $order_id > 0 && '' !== $order_key ) {
				$order = wc_get_order( $order_id );
				$visit = $order instanceof \WC_Order && hash_equals( (string) $order->get_order_key(), $order_key ) ? $this->sessions->find( (int) $order->get_meta( QuoteOrder::META_SESSION_ID ) ) : null;
				if ( null !== $visit && Session::RETURNED === $visit->status && $visit->order_id === $order_id ) { $state = StatePage::NOT_SENT; }
			}
		} catch ( \Throwable $error ) {
			$state = StatePage::ENDED;
		}
		return $page->url( $state );
	}

	/** The marker's value for a visit: its id and a key over it. */
	private static function marker( int $session_id ): string {
		return $session_id . '.' . substr( hash_hmac( 'sha256', 'pow-visit-sent|' . $session_id, wp_salt( 'auth' ) ), 0, 32 );
	}

	/** The visit a marker names when its key holds, else 0. */
	public static function marked_visit( string $value ): int {
		if ( 1 !== preg_match( '/\A([1-9][0-9]{0,18})\.[0-9a-f]{32}\z/', $value, $match ) ) { return 0; }
		return hash_equals( self::marker( (int) $match[1] ), $value ) ? (int) $match[1] : 0;
	}

	/** Drop a page kept for a return this request then lost. */
	public static function forget( string $login_token, string $order_key ): void {
		$key = self::key( $login_token, $order_key );
		if ( '' === $key ) { return; }
		try { delete_transient( $key ); } catch ( \Throwable $error ) { /* It expires on its own. */ }
	}

	/** Where Submit sends the browser: this route, naming the order and its key. */
	public static function url( int $order_id, string $order_key ): string {
		return Transport::supplier_url( home_url( '/punchout/handoff' ) ) . '?' . http_build_query( [ 'order' => $order_id, 'key' => $order_key ], '', '&' );
	}

	/** GET /punchout/handoff. */
	public function handle(): void {
		Transport::require_https();
		Transport::private_headers();
		if ( 'GET' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			if ( ! headers_sent() ) { header( 'Allow: GET' ); }
			$this->no_form_page( 405 );
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the login cookie and the kept record are the proof.
		$order_key = is_string( $_GET['key'] ?? null ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$order_id  = is_string( $_GET['order'] ?? null ) && ctype_digit( $_GET['order'] ) ? (int) $_GET['order'] : 0;
		// phpcs:enable
		$login_token = (string) wp_get_session_token();

		$kept  = self::take( $login_token, $order_key );
		$visit = null;
		try {
			$visit = null !== $kept && $kept['order_id'] === $order_id ? $this->sessions->find( $kept['session_id'] ) : null;
		} catch ( \Throwable $error ) {
			$visit = null;
		}
		if ( null === $kept || null === $visit || Session::RETURNED !== $visit->status || '' === $visit->wp_session_token || ! hash_equals( $visit->wp_session_token, $login_token ) || $visit->order_id !== $order_id ) {
			$target = $this->spent_target( $order_id, $order_key );
			if ( null !== $target ) {
				wp_safe_redirect( $target, 303 );
				return;
			}
			$this->no_form_page( 200 );
			return;
		}

		wp_clear_auth_cookie();
		try {
			set_transient( self::served_key( $order_key ), 1, self::MARKER_TTL );
		} catch ( \Throwable $error ) {
			// The mark only words a later state page; the cart is posted either way.
		}
		if ( function_exists( 'wc_setcookie' ) ) {
			wc_setcookie( self::SENT_MARKER, self::marker( $visit->id ), time() + self::MARKER_TTL, is_ssl(), true );
		}
		try {
			$this->audit?->write_checked( 'handoff_served', [ 'partner_id' => $visit->partner_id, 'session_id' => $visit->id, 'user_id' => $visit->user_id, 'order_id' => $order_id, 'direction' => 'out', 'result' => 'ok' ] );
		} catch ( \Throwable $error ) {
			// Reporting never stands in the way of the prepared handoff.
		}
		self::html_headers( 200 );
		echo $kept['markup']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the handoff template, escaped within.
	}

	/** GET /punchout/sent: the Sent state, theme-free and with no form. */
	public function sent(): void {
		Transport::private_headers();
		self::html_headers( 200 );
		echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer"><meta name="viewport" content="width=device-width, initial-scale=1"><title>';
		echo esc_html__( 'Sent', 'punchout-woocommerce' );
		echo '</title></head><body><main class="pow-state pow-state--sent" style="font-family:sans-serif;max-width:36em;margin:4em auto;padding:0 1em"><h1 class="pow-state__title">';
		echo esc_html__( 'Sent', 'punchout-woocommerce' );
		echo '</h1><p class="pow-state__text">';
		echo esc_html__( 'Your cart has been sent to your purchasing system. Return there to continue.', 'punchout-woocommerce' );
		echo '</p></main></body></html>';
	}

	private static function html_headers( int $status ): void {
		status_header( $status );
		if ( ! headers_sent() ) { header( 'Content-Type: text/html; charset=utf-8' ); }
	}

	/** A short theme-free page with no form: the cart is never posted twice. */
	private function no_form_page( int $status ): void {
		self::html_headers( $status );
		echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer"><title>';
		echo esc_html__( 'Punchout', 'punchout-woocommerce' );
		echo '</title></head><body style="font-family:sans-serif;max-width:36em;margin:4em auto;padding:0 1em"><p>';
		echo esc_html__( 'This page has already been used or has expired. Return to your purchasing system to continue.', 'punchout-woocommerce' );
		echo '</p></body></html>';
	}
}
