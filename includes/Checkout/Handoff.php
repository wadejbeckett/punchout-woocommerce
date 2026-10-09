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
 * that order, and only when the visit row it names has returned with that login and that order. Serving it clears
 * the login cookie in that browser (the login itself was ended when the visit won). Anything else gets a short page
 * with no form, so a refresh, a back button or a second tab never posts the cart twice.
 */
final class Handoff {

	/** Seconds the prepared page waits for its browser. */
	public const TTL = 300;

	private const PREFIX = 'pow_checkout_handoff_';

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
			delete_transient( $key );
		} catch ( \Throwable $error ) {
			return null;
		}
		if ( ! is_array( $record ) || ! is_int( $record['session_id'] ?? null ) || ! is_int( $record['order_id'] ?? null ) || ! is_string( $record['markup'] ?? null ) || '' === $record['markup'] ) { return null; }
		return [ 'session_id' => $record['session_id'], 'order_id' => $record['order_id'], 'markup' => $record['markup'] ];
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
			$this->no_form_page( 200 );
			return;
		}

		wp_clear_auth_cookie();
		try {
			$this->audit?->write_checked( 'handoff_served', [ 'partner_id' => $visit->partner_id, 'session_id' => $visit->id, 'user_id' => $visit->user_id, 'order_id' => $order_id, 'direction' => 'out', 'result' => 'ok' ] );
		} catch ( \Throwable $error ) {
			// Reporting never stands in the way of the prepared handoff.
		}
		self::html_headers( 200 );
		echo $kept['markup']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the handoff template, escaped within.
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
