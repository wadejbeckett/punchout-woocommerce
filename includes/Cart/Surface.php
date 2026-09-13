<?php
/**
 * The additive cart-exit surface.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Cart;

use POW\Support\Transport;

use POW\Checkout\ExitPolicy;
use POW\Partners\Registry;
use POW\Plugin;
use POW\RouteGuard;
use POW\Sessions\Session;
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
 * Five placement paths for the return button, most flexible first:
 * - [punchout_cart_exits] complete policy-aware cart control;
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
		add_shortcode( 'punchout_cart_exits', [ $this, 'cart_exits_shortcode' ] );
		add_shortcode( 'punchout_return_button', [ $this, 'shortcode' ] );
		add_shortcode( 'punchout_abandon_button', [ $this, 'abandon_shortcode' ] );
		add_action( 'woocommerce_proceed_to_checkout', [ $this, 'render_cart_button' ], 30 );
		add_filter( 'render_block_woocommerce/proceed-to-checkout-block', [ $this, 'filter_blocks_proceed' ] );
		add_action( 'woocommerce_blocks_cart_enqueue_data', [ $this, 'enqueue_cart_blocks_filters' ] );
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

		return Templates::render(
			'return-button',
			[
				'action_url' => Transport::supplier_url( home_url( '/punchout/confirm' ) ),
				'nonce'      => wp_create_nonce( 'pow_confirm_delivery' ),
				'label'      => $this->return_button_label(),
				'classes'    => $this->button_classes( 'pow-return-button' ),
			]
		);
	}

	/** The native Cart block and the classic/shortcode controls share one label policy. */
	private function return_button_label(): string {
		/**
		 * Filter the RFQ exit button label.
		 *
		 * Applied to the operator's saved label (or the default when that
		 * is blank), so a filter always has the last word.
		 *
		 * @param string $label Button text.
		 */
		return (string) apply_filters(
			'pow_return_button_label',
			$this->plugin->settings()->button_label(
				'return_button_label',
				__( 'Punchout', 'punchout-woocommerce' )
			)
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
				'action_url' => Transport::supplier_url( home_url( '/punchout/return' ) ),
				'nonce'      => wp_create_nonce( 'pow_return' ),
				'label'      => $label,
				'classes'    => $this->button_classes( 'pow-abandon-button', false ),
			]
		);
	}

	/**
	 * The class attribute for one of the plugin's submit controls.
	 *
	 * Generic WooCommerce and WordPress button classes give a site stable
	 * styling hooks without detecting a theme or assuming its callbacks.
	 * Site-specific classes can be added through the existing filter.
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

	/**
	 * A complete classic-cart exit control independent of theme callbacks.
	 *
	 * Sites using this shortcode can hide their theme's native cart button.
	 * The plugin then owns both authorized choices without identifying or
	 * removing callbacks installed by WooCommerce, a theme, or another plugin.
	 */
	public function cart_exits_shortcode(): string {
		if ( null === $this->plugin->current_session() ) {
			$ordinary_checkout = ( new RouteGuard( $this->plugin, $this->registry, $this->plugin->settings() ) )->checkout_allowed();
			return $ordinary_checkout ? '<div class="pow-cart-exits">' . $this->checkout_button_markup() . '</div>' : '';
		}

		$policy = $this->current_cart_policy();

		if ( null === $policy ) {
			return '';
		}

		$return = $this->markup();
		if ( '' === $return ) {
			return '';
		}

		$checkout = ExitPolicy::CHECKOUT === $policy ? $this->checkout_button_markup() : '';

		return '<div class="pow-cart-exits">' . $checkout . $return . '</div>';
	}

	/** Native WooCommerce checkout link used by the complete cart control. */
	private function checkout_button_markup(): string {
		return sprintf(
			'<a href="%s" class="%s">%s</a>',
			esc_url( wc_get_checkout_url() ),
			esc_attr( $this->button_classes( 'checkout-button' ) . ' wc-forward' ),
			esc_html( __( 'Proceed to checkout', 'woocommerce' ) )
		);
	}

	public function abandon_shortcode(): string {
		return $this->abandon_markup();
	}

	public function render_cart_button(): void {
		echo $this->markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template output, escaped within.
	}

	/**
	 * Preserve the native wrapper: Cart's React render recreates its button
	 * even if PHP replaces the wrapper. Restricted sessions use Woo's public
	 * button label/link filters; dual exit retains the additive control.
	 * RouteGuard and the confirmation endpoint remain the authorities.
	 */
	public function filter_blocks_proceed( string $block_content ): string {
		$session = $this->plugin->current_session();

		if ( null === $session ) {
			return $block_content;
		}

		if ( ! $this->checkout_allowed( $session->partner_id ) ) {
			return $block_content;
		}

		return $block_content . $this->markup();
	}

	/** Cart registers its frontend assets after its inner blocks have rendered. */
	public function enqueue_cart_blocks_filters(): void {
		$session = $this->plugin->current_session();
		if ( null !== $session && ! $this->checkout_allowed( $session->partner_id ) ) {
			$this->enqueue_blocks_filters();
		}
	}

	/** Resolve current policy; an unavailable initial Registry read is also restricted. */
	private function checkout_allowed( int $partner_id ): bool {
		try {
			$partner = $this->registry->find( $partner_id );
			return null !== $partner && ExitPolicy::CHECKOUT === ( new ExitPolicy( $this->plugin->settings(), $this->registry ) )->effective( $partner, get_current_user_id() );
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/** Return the current valid cart session's effective policy, or null. */
	private function current_cart_policy(): ?string {
		$session = $this->plugin->current_session();

		if ( null === $session || Session::ACTIVE !== $session->status || $session->user_id !== get_current_user_id() ) {
			return null;
		}

		try {
			$policy  = new ExitPolicy( $this->plugin->settings(), $this->registry );
			$partner = $this->registry->find( $session->partner_id );
			if ( null === $partner || ! $partner->is_active() || ! $policy->member( $partner->id, $session->user_id ) ) {
				return null;
			}

			return $policy->effective( $partner, $session->user_id );
		} catch ( \Throwable $error ) {
			return null;
		}
	}

	/**
	 * Cart's enqueue-data hook runs after its frontend handle is registered,
	 * before Cart enqueues that handle and before footer scripts print.
	 * Depending only on the API would leave our script and Cart as unordered
	 * siblings. Make Cart depend on our filter too: API -> filters -> Cart.
	 */
	private function enqueue_blocks_filters(): void {
		$cart_handle = 'wc-cart-block-frontend';
		if ( ! wp_script_is( 'wc-blocks-checkout', 'registered' ) || ! wp_script_is( $cart_handle, 'registered' ) || wp_script_is( $cart_handle, 'done' ) ) {
			// Unsupported/already-printed assets: preserve native markup; RouteGuard still refuses checkout.
			return;
		}

		$config = wp_json_encode(
			[
				'restricted' => true,
				'label'      => $this->return_button_label(),
				'confirmUrl' => Transport::supplier_url( home_url( '/punchout/confirm' ) ),
			],
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);
		if ( false === $config ) { return; }

		wp_enqueue_script( 'pow-cart-blocks', POW_PLUGIN_URL . 'assets/js/cart-blocks.js', [ 'wc-blocks-checkout' ], (string) filemtime( POW_PLUGIN_DIR . 'assets/js/cart-blocks.js' ), true );
		wp_add_inline_script( 'pow-cart-blocks', 'window.powCartBlocks = ' . $config . ';', 'before' );
		$scripts = wp_scripts();
		if ( ! in_array( 'pow-cart-blocks', $scripts->registered[$cart_handle]->deps, true ) ) {
			$scripts->registered[$cart_handle]->deps[] = 'pow-cart-blocks';
		}
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

		if ( ! $this->checkout_allowed( $session->partner_id ) ) {
			remove_action( 'woocommerce_proceed_to_checkout', 'woocommerce_button_proceed_to_checkout', 20 );
		}
	}
}
