<?php
/**
 * The cart-exit surface.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Cart;

use POW\Support\Transport;

use POW\Partners\Registry;
use POW\Plugin;
use POW\Sessions\Session;
use POW\Support\Templates;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the "send to your purchasing system" button — the one exit a
 * punchout visit has. Checkout is blocked inside every visit (RouteGuard
 * owns the block and the confirmation endpoint owns the return), so inside
 * a visit this surface shows the return control and nothing else.
 *
 * Outside a visit nothing here applies. Ordinary shoppers — including the
 * connection's own bound account on a password login — keep WooCommerce's
 * cart exactly as WooCommerce renders it.
 *
 * The two cart templates need opposite treatment:
 *
 * - Classic cart: Woo's proceed-to-checkout button is unhooked for the
 *   visit and the return control renders in its place.
 * - Block cart: the native wrapper is left untouched, because Cart's React
 *   render recreates its button whatever PHP does to the markup. Woo's
 *   public label/link filters (assets/js/cart-blocks.js, configured by
 *   enqueue_cart_blocks_filters()) turn that one native button into the
 *   return control instead.
 *
 * Two controls, one endpoint: the cart return (mode=cart) and the
 * mid-session abandon (mode=empty, the cXML cancel semantic — "return
 * without a cart"). Both are presentation only; ReturnEndpoint owns
 * every authorisation check.
 *
 * Four placement paths for the return button, most flexible first:
 * - [punchout_cart_exits] complete cart control;
 * - [punchout_return_button] shortcode (builders, widgets);
 * - pow_return_button() PHP helper (theme code);
 * - automatic injection on the classic cart page (woocommerce_after_cart_totals,
 *   deliberately outside the proceed-to-checkout container, which themes and
 *   page-builder cart elements hide or replace wholesale).
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
		$this->register_cart_exits_shortcode();
		$this->register_runtime();
	}

	/** Register the complete cart region even while new PunchOut sessions are disabled. */
	public function register_cart_exits_shortcode(): void {
		add_shortcode( 'punchout_cart_exits', [ $this, 'cart_exits_shortcode' ] );
	}

	/** Register controls and filters that are meaningful only while PunchOut is enabled. */
	public function register_runtime(): void {
		add_shortcode( 'punchout_return_button', [ $this, 'shortcode' ] );
		add_shortcode( 'punchout_abandon_button', [ $this, 'abandon_shortcode' ] );
		add_action( 'woocommerce_after_cart_totals', [ $this, 'render_cart_button' ], 5 );
		add_action( 'woocommerce_blocks_cart_enqueue_data', [ $this, 'enqueue_cart_blocks_filters' ] );
		add_action( 'wp', [ $this, 'maybe_unhook_checkout_button' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_visit_styles' ] );
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
	 * The plugin then owns the authorized exit without identifying or
	 * removing callbacks installed by WooCommerce or a theme.
	 *
	 * Inside a visit that is the return control alone. Outside one it is the
	 * native checkout link, for every shopper: a connection's bound account
	 * shopping on its own password login is an ordinary customer here.
	 */
	public function cart_exits_shortcode(): string {
		$session = $this->plugin->current_session();

		if ( null === $session ) {
			// No RouteGuard consultation: outside a visit the guard refuses
			// checkout for nothing a cart page can carry, so asking it here
			// would only add a second answer that could disagree.
			return '<div class="pow-cart-exits">' . $this->checkout_button_markup() . '</div>';
		}

		if ( ! $this->visit_is_live( $session ) ) {
			return '';
		}

		$return = $this->markup();

		return '' === $return ? '' : '<div class="pow-cart-exits">' . $return . '</div>';
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
	 * Configure the Cart block's own button for every visit.
	 *
	 * Cart registers its frontend assets after its inner blocks have
	 * rendered, so this is where the restricted configuration can still be
	 * added. Every visit is restricted — there is no second exit to choose
	 * between — so the only question is whether a visit is present.
	 */
	public function enqueue_cart_blocks_filters(): void {
		if ( null !== $this->plugin->current_session() ) {
			$this->enqueue_blocks_filters();
		}
	}

	/**
	 * Whether the resolved visit may still render an exit.
	 *
	 * Which visit this is, is not in question: Sessions\Current resolved the
	 * row from this request's own login token, so it is this visit's row and
	 * no colleague's — the account id would answer nothing, since every
	 * buyer of the connection shares it. What is in question is whether the
	 * visit is still live at render time: a row that has left `active`, a
	 * connection that has been switched off or a registry read that cannot
	 * be trusted renders no exit at all, not a checkout link.
	 */
	private function visit_is_live( Session $session ): bool {
		if ( Session::ACTIVE !== $session->status ) {
			return false;
		}

		try {
			$partner = $this->registry->find( $session->partner_id );

			return null !== $partner && $partner->is_active();
		} catch ( \Throwable $error ) {
			return false;
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
	 * Inside a visit the cart page and the mini-cart show no checkout button
	 * at all: PunchOut is the visit's only exit and the return control takes
	 * the button's place.
	 *
	 * Whatever the theme or another plugin hung on these two hooks is
	 * presentation for a shopper who can check out. Naming Woo's own
	 * callback and priority would only ever remove Woo's button, and the
	 * plugin knows nothing about who else replaced it or at what priority,
	 * so the hooks are cleared whole and only what a visit may show is put
	 * back: "View cart" in the mini-cart. The return control is not put
	 * back here: it renders at woocommerce_after_cart_totals, outside the
	 * proceed-to-checkout container, because a theme or page-builder cart
	 * element that hides that container (to draw its own button) would
	 * otherwise hide the visit's only exit with it. Presentation only —
	 * RouteGuard owns the actual block.
	 */
	public function maybe_unhook_checkout_button(): void {
		if ( null === $this->plugin->current_session() ) {
			return;
		}

		remove_all_actions( 'woocommerce_proceed_to_checkout' );

		remove_all_actions( 'woocommerce_widget_shopping_cart_buttons' );
		if ( function_exists( 'woocommerce_widget_shopping_cart_button_view_cart' ) ) {
			add_action( 'woocommerce_widget_shopping_cart_buttons', 'woocommerce_widget_shopping_cart_button_view_cart', 10 );
		}
	}

	/**
	 * Hide every link to the checkout page inside a visit, wherever it came
	 * from: a page-builder button in the site's own cart layout, a theme
	 * mini-cart, a widget. None of those pass through a hook this plugin
	 * can clear, and the plugin is not allowed to know which theme or
	 * builder drew them, so the one thing they all share — the checkout
	 * URL — is what the stylesheet keys on. Presentation only; RouteGuard
	 * still refuses the page itself.
	 */
	public function enqueue_visit_styles(): void {
		$session = $this->plugin->current_session();
		if ( null === $session || ! $this->visit_is_live( $session ) ) {
			return;
		}

		$checkout = rtrim( (string) wc_get_checkout_url(), '/' );
		$checkout = str_replace( [ '\\', '"' ], [ '\\\\', '\\"' ], $checkout );
		// Never the wc-proceed-to-checkout wrapper itself: with its hooks
		// cleared it holds exactly one thing, this plugin's return control.
		$selectors = [
			'a[href^="' . $checkout . '"]',
			'.checkout-button',
			'.widget_shopping_cart .buttons .checkout',
			'.wc-block-mini-cart__footer-checkout',
		];

		wp_register_style( 'pow-visit', false, [], defined( 'POW\\VERSION' ) ? \POW\VERSION : false );
		wp_enqueue_style( 'pow-visit' );
		wp_add_inline_style( 'pow-visit', implode( ',', $selectors ) . '{display:none !important;}' );
	}
}
