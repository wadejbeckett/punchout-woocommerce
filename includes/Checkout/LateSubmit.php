<?php
/**
 * A Submit that arrives after the visit has returned (0.5.0).
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Checkout;

use POW\Plugin;
use POW\Sessions\Session;
use POW\Sessions\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Once a visit has returned its cart, its login has ended. A second click on Submit, or Submit in a second tab, then
 * reaches WooCommerce as a guest, and WooCommerce refuses it at its nonce check ("your session has expired"), before
 * any checkout hook. This catches that request first: on `wc_ajax_checkout` at priority 1 (WooCommerce answers at 10)
 * and on the form post without the checkout script at `wp_loaded` 19 (WooCommerce handles it at 20).
 *
 * It acts only on a request that posts the PunchOut method, is not inside a visit, and proves which visit it ran:
 * - the login cookie the checkout answer left in the browser, whose ended login still names its visit row; or
 * - the marker the handoff leaves when it clears that cookie (Handoff::SENT_MARKER), keyed so it cannot be forged.
 * When that visit has returned, the browser is sent to the visit's handoff while its page has never been served (for
 * this login), and otherwise to the Sent state, with no auto-posting form: the cart is never posted twice. Anything
 * else carries on to WooCommerce untouched.
 */
final class LateSubmit {

	public function __construct( private Plugin $plugin, private Store $sessions ) {}

	public function register(): void {
		add_action( 'wc_ajax_checkout', [ $this, 'catch_ajax' ], 1 );
		add_action( 'wp_loaded', [ $this, 'catch_form' ], 19 );
	}

	/** `wc_ajax_checkout`: WooCommerce's checkout script follows a "success" answer's redirect. */
	public function catch_ajax(): void {
		$target = $this->target();
		if ( null === $target ) { return; }
		wp_send_json( [ 'result' => 'success', 'redirect' => $target ] );
	}

	/** `wp_loaded`: the checkout form posted without the script. */
	public function catch_form(): void {
		if ( ! isset( $_POST['woocommerce_checkout_place_order'] ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the visit row is the proof; nothing is changed.
		$target = $this->target();
		if ( null === $target ) { return; }
		wp_safe_redirect( $target, 303 );
		exit;
	}

	/** Where this request goes when it is a Submit after its visit's return, else null. */
	public function target(): ?string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- read-only routing; WooCommerce's nonce is for a login that has ended.
		$posted = $_POST['payment_method'] ?? '';
		// phpcs:enable
		if ( ! is_string( $posted ) || PaymentMethod::ID !== $posted ) { return null; }
		try {
			if ( null !== $this->plugin->current_session() ) { return null; }

			$cookie = function_exists( 'wp_parse_auth_cookie' ) ? wp_parse_auth_cookie( '', 'logged_in' ) : false;
			if ( is_array( $cookie ) && '' !== (string) ( $cookie['token'] ?? '' ) && '' !== (string) ( $cookie['username'] ?? '' ) ) {
				$user  = get_user_by( 'login', (string) $cookie['username'] );
				$visit = $user ? $this->sessions->find_login_any_status( (int) $user->ID, (string) $cookie['token'] ) : null;
				if ( $visit instanceof Session && Session::RETURNED === $visit->status ) {
					return Handoff::after_return( $visit, (string) $cookie['token'] );
				}
			}

			$marker = $_COOKIE[ Handoff::SENT_MARKER ] ?? '';
			$marked = is_string( $marker ) ? Handoff::marked_visit( $marker ) : 0;
			$visit  = $marked > 0 ? $this->sessions->find( $marked ) : null;
			if ( $visit instanceof Session && Session::RETURNED === $visit->status ) {
				return Handoff::sent_url();
			}
		} catch ( \Throwable $error ) {
			// Nothing proven: WooCommerce answers the request itself.
		}
		return null;
	}
}
