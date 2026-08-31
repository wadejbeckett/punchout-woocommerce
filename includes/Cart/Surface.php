<?php
/**
 * The additive cart-exit surface.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Cart;

use POW\Partners\Registry;
use POW\Plugin;
use POW\Support\Templates;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the "send to your purchasing system" button — an ADDITIVE
 * element beside WooCommerce's own checkout button, shown only to active
 * punchout sessions. The stock checkout flow is never overridden,
 * replaced or filtered for dual_exit partners.
 *
 * For requisition_only partners the checkout button is unhooked for that
 * partner's punchout sessions only (their sanctioned single exit); the
 * hard block behind it lives in RouteGuard.
 *
 * Three placement paths, most flexible first:
 * - [punchout_return_button] shortcode (builders, widgets);
 * - pow_return_button() PHP helper (theme code);
 * - automatic injection on the cart page (woocommerce_proceed_to_checkout).
 */
final class Surface {

	public function __construct(
		private Plugin $plugin,
		private Registry $registry,
	) {}

	public function register(): void {
		add_shortcode( 'punchout_return_button', [ $this, 'shortcode' ] );
		add_action( 'woocommerce_proceed_to_checkout', [ $this, 'render_cart_button' ], 30 );
		add_action( 'wp', [ $this, 'maybe_unhook_checkout_button' ] );
	}

	/**
	 * The button markup, or '' outside an active punchout session.
	 */
	public function markup(): string {
		$session = $this->plugin->current_session();

		if ( null === $session ) {
			return '';
		}

		/**
		 * Filter the RFQ exit button label.
		 *
		 * @param string $label Button text.
		 */
		$label = (string) apply_filters(
			'pow_return_button_label',
			__( 'Send to your purchasing system for approval', 'punchout-woocommerce' )
		);

		return Templates::render(
			'return-button',
			[
				'action_url' => home_url( '/punchout/return' ),
				'nonce'      => wp_create_nonce( 'pow_return' ),
				'label'      => $label,
			]
		);
	}

	public function shortcode(): string {
		return $this->markup();
	}

	public function render_cart_button(): void {
		echo $this->markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template output, escaped within.
	}

	/**
	 * requisition_only partners: hide Woo's proceed-to-checkout button for
	 * that partner's punchout sessions. Presentation only — RouteGuard
	 * owns the actual block.
	 */
	public function maybe_unhook_checkout_button(): void {
		$session = $this->plugin->current_session();

		if ( null === $session ) {
			return;
		}

		$partner = $this->registry->find( $session->partner_id );

		if ( null !== $partner && $partner->is_requisition_only() ) {
			remove_action( 'woocommerce_proceed_to_checkout', 'woocommerce_button_proceed_to_checkout', 20 );
		}
	}
}
