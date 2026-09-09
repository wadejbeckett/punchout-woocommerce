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
 * Two controls, one endpoint: the cart return (mode=cart) and the
 * mid-session abandon (mode=empty, the cXML cancel semantic — "return
 * without a cart"). Both are presentation only; ReturnEndpoint owns
 * every authorisation check.
 *
 * Four placement paths for the return button, most flexible first:
 * - [punchout_return_button] shortcode (builders, widgets);
 * - pow_return_button() PHP helper (theme code);
 * - automatic injection on the classic cart page (woocommerce_proceed_to_checkout);
 * - automatic injection on the blocks cart (render_block on the
 *   proceed-to-checkout block).
 *
 * The abandon control has one placement path — [punchout_abandon_button]
 * — because there is no core hook that means "the session chrome".
 */
final class Surface {

	public function __construct(
		private Plugin $plugin,
		private Registry $registry,
	) {}

	public function register(): void {
		add_shortcode( 'punchout_return_button', [ $this, 'shortcode' ] );
		add_shortcode( 'punchout_abandon_button', [ $this, 'abandon_shortcode' ] );
		add_action( 'woocommerce_proceed_to_checkout', [ $this, 'render_cart_button' ], 30 );
		add_filter( 'render_block_woocommerce/proceed-to-checkout-block', [ $this, 'filter_blocks_proceed' ] );
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
		 * Applied to the operator's saved label (or the default when that
		 * is blank), so a filter always has the last word.
		 *
		 * @param string $label Button text.
		 */
		$label = (string) apply_filters(
			'pow_return_button_label',
			$this->plugin->settings()->button_label(
				'return_button_label',
				__( 'Punchout', 'punchout-woocommerce' )
			)
		);

		return Templates::render(
			'return-button',
			[
				'action_url' => home_url( '/punchout/return' ),
				'nonce'      => wp_create_nonce( 'pow_return' ),
				'label'      => $label,
				'classes'    => $this->button_classes( 'pow-return-button' ),
			]
		);
	}

	/**
	 * The abandon control's markup, or '' outside an active punchout session.
	 *
	 * Posts mode=empty to the same endpoint the cart return uses: an empty
	 * PunchOutOrderMessage, which is the cXML way of saying "the buyer left
	 * with nothing". It is a real POST carrying the pow_return nonce, so the
	 * control is a submit button and never a link.
	 */
	public function abandon_markup(): string {
		$session = $this->plugin->current_session();

		if ( null === $session ) {
			return '';
		}

		/**
		 * Filter the abandon control's label.
		 *
		 * Applied to the operator's saved label (or the default when that
		 * is blank), so a filter always has the last word.
		 *
		 * @param string $label Button text.
		 */
		$label = (string) apply_filters(
			'pow_abandon_button_label',
			$this->plugin->settings()->button_label(
				'abandon_button_label',
				__( 'Return without a cart', 'punchout-woocommerce' )
			)
		);

		return Templates::render(
			'abandon-button',
			[
				'action_url' => home_url( '/punchout/return' ),
				'nonce'      => wp_create_nonce( 'pow_return' ),
				'label'      => $label,
				'classes'    => $this->button_classes( 'pow-abandon-button', false ),
			]
		);
	}

	/**
	 * The class attribute for one of the plugin's submit controls.
	 *
	 * Theme compatibility, not theme knowledge: a punchout session's exit
	 * controls are usually placed in a builder's global header/footer, i.e.
	 * OUTSIDE the `.woocommerce` wrapper that most themes hang their button
	 * styling off, so `button alt` alone leaves them looking like an
	 * unstyled `<button>` on exactly the pages that matter. Where a theme
	 * publishes a generic default-button class, add it so the control
	 * inherits that theme's own button options instead of shipping a
	 * bespoke stylesheet. Anything else belongs on the filter.
	 *
	 * The abandon control passes $themed = false: it is a secondary "leave
	 * with nothing" action that reads as a text link beside the primary
	 * exit, so it carries only its own class and is styled by the site.
	 *
	 * @param string $base   The control's own identifying class.
	 * @param bool   $themed Whether to add the generic button classes.
	 */
	private function button_classes( string $base, bool $themed = true ): string {
		$classes = $themed ? [ 'button', 'alt', 'wp-element-button', $base ] : [ $base ];

		if ( $themed && defined( 'AVADA_VERSION' ) ) {
			$classes[] = 'button-default';
		}

		/**
		 * Filter the class list of a punchout submit control.
		 *
		 * @param string[] $classes Class names.
		 * @param string   $base    The control's identifying class.
		 * @param bool     $themed  Whether the generic button classes were added.
		 */
		$classes = (array) apply_filters( 'pow_button_classes', $classes, $base, $themed );

		return implode( ' ', array_filter( array_map( 'sanitize_html_class', $classes ) ) );
	}

	public function shortcode(): string {
		return $this->markup();
	}

	public function abandon_shortcode(): string {
		return $this->abandon_markup();
	}

	public function render_cart_button(): void {
		echo $this->markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template output, escaped within.
	}

	/**
	 * Blocks cart (Woo 8.3+ default): the proceed-to-checkout block is a
	 * server-rendered wrapper that React hydrates internally, so appending
	 * a SIBLING here survives hydration. Dual exit appends beside the stock
	 * button; requisition_only replaces the wrapper outright so the
	 * checkout button never mounts — presentation only, RouteGuard owns
	 * the hard block either way.
	 */
	public function filter_blocks_proceed( string $block_content ): string {
		$session = $this->plugin->current_session();

		if ( null === $session ) {
			return $block_content;
		}

		$partner = $this->registry->find( $session->partner_id );

		if ( null === $partner || \POW\Checkout\ExitPolicy::CHECKOUT !== ( new \POW\Checkout\ExitPolicy( $this->plugin->settings(), $this->registry ) )->effective( $partner, get_current_user_id() ) ) {
			return $this->markup();
		}

		return $block_content . $this->markup();
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

		if ( null === $partner || \POW\Checkout\ExitPolicy::CHECKOUT !== ( new \POW\Checkout\ExitPolicy( $this->plugin->settings(), $this->registry ) )->effective( $partner, get_current_user_id() ) ) {
			remove_action( 'woocommerce_proceed_to_checkout', 'woocommerce_button_proceed_to_checkout', 20 );
		}
	}
}
