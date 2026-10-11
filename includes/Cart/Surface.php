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
use POW\Cart\LeaveConfirmation;
use POW\Plugin;
use POW\Sessions\Session;
use POW\Support\Templates;

defined( 'ABSPATH' ) || exit;

/**
 * The cart's controls inside a visit. Since 0.5.0 the cart's proceed
 * button and the Cart widget's checkout button lead to WooCommerce's
 * classic checkout, where PunchOut is the only payment method; they carry
 * the "Checkout button label" setting (maybe_unhook_checkout_button()).
 *
 * Outside a visit nothing here applies. Ordinary shoppers — including the
 * connection's own bound account on a password login — keep WooCommerce's
 * cart exactly as WooCommerce renders it.
 *
 * The two cart templates need opposite treatment:
 *
 * - Classic cart: Woo's proceed-to-checkout hook is cleared for the visit
 *   and only the labelled proceed button is put back.
 * - Block cart: the native wrapper is left untouched, because Cart's React
 *   render recreates its button whatever PHP does to the markup. Woo's
 *   public label filter (assets/js/cart-blocks.js, configured by
 *   enqueue_cart_blocks_filters()) relabels that one native button.
 *
 * The mid-session abandon control ("Cancel and return": an empty
 * PunchOutOrderMessage, the cXML cancel semantic) posts to ReturnEndpoint,
 * which owns every authorisation check; this class is presentation only.
 * It has one placement path — [punchout_abandon_button] — because there is
 * no core hook that means "the session chrome".
 *
 * The old return button, which opened the retired review page, renders
 * nothing: its shortcode and helper stay registered for one release so a
 * layout that still carries them shows nothing rather than a tag.
 */
final class Surface {

	/** The visit controls that carry the operator's extra button classes. */
	private const EXTRA_CLASS_CONTROLS = [ 'pow-abandon-button' ];

	/** Leave-confirmation dialogs drawn in this request, so each control names its own (a header can draw two). */
	private static int $dialogs = 0;

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
		// Retired in 0.5.0 and kept for one release: it renders nothing (markup()).
		add_shortcode( 'punchout_return_button', [ $this, 'shortcode' ] );
		add_shortcode( 'punchout_abandon_button', [ $this, 'abandon_shortcode' ] );
		add_action( 'woocommerce_blocks_cart_enqueue_data', [ $this, 'enqueue_cart_blocks_filters' ] );
		add_action( 'wp', [ $this, 'maybe_unhook_checkout_button' ] );
		add_filter( 'body_class', [ $this, 'visit_body_class' ] );
	}

	/**
	 * The theme-facing visit signal: `pow-visit` on <body> while a live visit
	 * is being served. Themes and page builders gate presentation on it (or
	 * on POW\pow_is_punchout()); it is never access control, RouteGuard is.
	 *
	 * @param string[] $classes
	 * @return string[]
	 */
	public function visit_body_class( array $classes ): array {
		$session = $this->plugin->current_session();
		if ( null !== $session && $this->visit_is_live( $session ) && ! in_array( 'pow-visit', $classes, true ) ) {
			$classes[] = 'pow-visit';
		}
		return $classes;
	}

	/**
	 * The old return button's markup: always '' since 0.5.0.
	 *
	 * The button opened the retired review page. A visit now leaves through WooCommerce's checkout (the cart's
	 * buttons carry the Checkout button label), so the button renders nothing wherever a layout still places it:
	 * the [punchout_return_button] shortcode and the pow_return_button() helper. Both stay for one release, so a
	 * layout that carries them shows nothing rather than a tag; the next release removes them.
	 */
	public function markup(): string {
		return '';
	}

	/**
	 * The abandon control's markup, or '' outside an active punchout session.
	 *
	 * Posts mode=empty to the same endpoint the cart return uses: an empty
	 * PunchOutOrderMessage, which is the cXML way of saying "the buyer left
	 * with nothing". It is a real POST carrying the pow_return nonce, so the
	 * control is a submit button and never a link.
	 *
	 * With "Ask before leaving" on and a line in the cart (0.5.0), it also
	 * draws a dialog asking first (LeaveConfirmation): the question is the
	 * shortcode's `question` attribute, its two buttons are settings, and the
	 * plugin's own script opens it. Without the script the server asks
	 * instead, by sending the browser back with the dialog drawn open.
	 *
	 * @param array<string, mixed> $atts Shortcode attributes: `question`.
	 */
	public function abandon_markup( array $atts = [] ): string {
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
				'leave'      => $this->leave_dialog( $atts ),
			]
		);
	}

	/**
	 * The leave-confirmation dialog for one cancel control, or null when it leaves at once (the setting is off or
	 * the cart is empty). Enqueues the plugin's script that opens it.
	 *
	 * @param array<string, mixed> $atts Shortcode attributes: `question`.
	 * @return array{id: string, question: string, confirm: string, stay: string, open: bool}|null
	 */
	private function leave_dialog( array $atts ): ?array {
		$settings = $this->plugin->settings();
		if ( ! $settings->leave_confirmation() || LeaveConfirmation::cart_items() <= 0 ) {
			return null;
		}
		$atts = shortcode_atts( [ 'question' => __( 'Return without sending your cart?', 'punchout-woocommerce' ) ], $atts, 'punchout_abandon_button' );
		$file = POW_PLUGIN_DIR . 'assets/js/leave-confirmation.js';
		wp_enqueue_script( LeaveConfirmation::SCRIPT, POW_PLUGIN_URL . 'assets/js/leave-confirmation.js', [], is_readable( $file ) ? (string) filemtime( $file ) : false, true );

		return [
			'id'       => 'pow-leave-' . ( ++self::$dialogs ),
			'question' => trim( (string) $atts['question'] ),
			'confirm'  => $settings->leave_confirm_label(),
			'stay'     => $settings->leave_stay_label(),
			'open'     => LeaveConfirmation::asked(),
		];
	}

	/**
	 * The class attribute for one of the plugin's submit controls.
	 *
	 * Generic WooCommerce and WordPress button classes give a site stable
	 * styling hooks without detecting a theme or assuming its callbacks.
	 * The visit's abandon control also carries the operator's "Extra button
	 * classes" setting, appended before the filter so that code keeps the
	 * last word; the checkout links do not. Site-specific classes can also
	 * be added through the filter.
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
		if ( in_array( $base, self::EXTRA_CLASS_CONTROLS, true ) ) {
			$classes = array_merge( $classes, array_values( array_diff( $this->plugin->settings()->extra_button_classes(), $classes ) ) );
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

	/** The [punchout_return_button] shortcode: nothing, since 0.5.0 (markup()). */
	public function shortcode(): string {
		return $this->markup();
	}

	/**
	 * A complete classic-cart exit control independent of theme callbacks.
	 *
	 * Sites using this shortcode can hide their theme's native cart button.
	 * The plugin then owns the exit without identifying or removing callbacks
	 * installed by WooCommerce or a theme.
	 *
	 * It is WooCommerce's normal checkout link for every shopper: a
	 * connection's bound account shopping on its own password login is an
	 * ordinary customer here. Inside a live visit (0.5.0) the same link
	 * carries the Checkout button label, since checkout is the visit's way
	 * out; a visit that is not live renders nothing.
	 */
	public function cart_exits_shortcode(): string {
		$session = $this->plugin->current_session();

		if ( null === $session ) {
			// No RouteGuard consultation: outside a visit the guard refuses
			// checkout for nothing a cart page can carry, so asking it here
			// would only add a second answer that could disagree.
			return '<div class="pow-cart-exits">' . $this->checkout_button_markup() . '</div>';
		}

		$link = $this->checkout_link( $this->button_classes( 'checkout-button' ) . ' wc-forward' );

		return '' === $link ? '' : '<div class="pow-cart-exits">' . $link . '</div>';
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

	/** @param mixed $atts Shortcode attributes ('' when there are none). */
	public function abandon_shortcode( mixed $atts = [] ): string {
		return $this->abandon_markup( is_array( $atts ) ? $atts : [] );
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

		// 0.5.0: the block's own button keeps its native link to checkout and carries the Checkout button label.
		$config = wp_json_encode(
			[
				'restricted' => true,
				'label'      => $this->plugin->settings()->checkout_button_label(),
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
	 * Inside a visit the cart page and the mini-cart lead to WooCommerce's checkout (0.5.0), where PunchOut is the
	 * only payment method, through buttons labelled by the "Checkout button label" setting.
	 *
	 * Whatever the theme or another plugin hung on these two hooks is presentation for a shopper who pays: its own
	 * checkout button, express-pay buttons. Naming Woo's own callback and priority would only ever remove Woo's
	 * button, and the plugin knows nothing about who else replaced it or at what priority, so the hooks are cleared
	 * whole and only what a visit may show is put back: the labelled proceed button on the cart, and "View cart" and
	 * the labelled checkout button in the mini-cart. Presentation only; RouteGuard and the payment method decide.
	 * A theme whose cart draws its own buttons without these hooks places the visit's button itself.
	 */
	public function maybe_unhook_checkout_button(): void {
		if ( null === $this->plugin->current_session() ) {
			return;
		}

		remove_all_actions( 'woocommerce_proceed_to_checkout' );
		add_action( 'woocommerce_proceed_to_checkout', [ $this, 'render_checkout_button' ], 20 );

		// WooCommerce's express-pay buttons on the checkout page, by name: other checkout callbacks there stay.
		remove_action( 'woocommerce_checkout_before_customer_details', 'wc_get_pay_buttons', 30 );

		remove_all_actions( 'woocommerce_widget_shopping_cart_buttons' );
		if ( function_exists( 'woocommerce_widget_shopping_cart_button_view_cart' ) ) {
			add_action( 'woocommerce_widget_shopping_cart_buttons', 'woocommerce_widget_shopping_cart_button_view_cart', 10 );
		}
		add_action( 'woocommerce_widget_shopping_cart_buttons', [ $this, 'render_widget_checkout_button' ], 20 );
	}

	/** The cart page's proceed button inside a live visit. */
	public function render_checkout_button(): void {
		echo $this->checkout_link( $this->button_classes( 'checkout-button' ) . ' wc-forward' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in checkout_link().
	}

	/** The Cart widget's checkout button inside a live visit. */
	public function render_widget_checkout_button(): void {
		echo $this->checkout_link( 'button checkout wc-forward wp-element-button' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in checkout_link().
	}

	/** A link to WooCommerce's checkout carrying the Checkout button label; '' unless a live visit is being served. */
	private function checkout_link( string $classes ): string {
		$session = $this->plugin->current_session();
		if ( null === $session || ! $this->visit_is_live( $session ) ) {
			return '';
		}

		return sprintf(
			'<a href="%s" class="%s">%s</a>',
			esc_url( wc_get_checkout_url() ),
			esc_attr( $classes ),
			esc_html( $this->plugin->settings()->checkout_button_label() )
		);
	}
}
