<?php
/**
 * The PunchOut payment method's registration and its visit-only rules (0.5.0).
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Checkout;

use POW\Partners\Registry;
use POW\Plugin;
use POW\Sessions\Session;
use POW\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the gateway with WooCommerce and decides where it shows.
 *
 * Kept apart from the gateway itself, whose WooCommerce base class exists only once WooCommerce has loaded: this
 * class is built at boot, the gateway when WooCommerce first asks for its payment methods.
 *
 * - Outside a visit the method is never offered, and the shop's own methods are left as they are.
 * - While a live visit owns the request (its row active, its connection active) it is the only method offered.
 *   That rule runs last (PHP_INT_MAX), so a pricing or visibility plugin's gateway rules can neither remove it nor
 *   add another; it puts the method back even when such a rule dropped it.
 * - Inside a visit that is not live, or whose lookup cannot answer, nothing is offered at all.
 * - Its place-order button reads the method's own setting (default "Place order"), and checkout's button carries
 *   that text from the first render, before any method is chosen.
 */
final class PaymentMethod {

	/** The gateway id, and the value checkout posts as payment_method. */
	public const ID = 'punchout';

	/** Filter over the place-order label, applied to the saved setting (or the default). */
	public const PLACE_ORDER_FILTER = 'punchout_place_order_label';

	private ?Gateway $gateway = null;

	public function __construct(
		private Plugin $plugin,
		private Registry $registry,
		private ?Submission $submission = null,
	) {}

	public function register(): void {
		add_filter( 'woocommerce_payment_gateways', [ $this, 'add_gateway' ] );
		add_filter( 'woocommerce_available_payment_gateways', [ $this, 'available_gateways' ], PHP_INT_MAX );
		add_filter( 'woocommerce_order_button_text', [ $this, 'order_button_text' ], PHP_INT_MAX );
	}

	/** The one gateway instance for this request. */
	public function gateway(): Gateway {
		return $this->gateway ??= new Gateway( $this, $this->submission );
	}

	/** `woocommerce_payment_gateways`: add the PunchOut method to the shop's list. */
	public function add_gateway( mixed $gateways ): mixed {
		if ( ! is_array( $gateways ) ) { return $gateways; }
		$gateways[] = $this->gateway();
		return $gateways;
	}

	/** `woocommerce_available_payment_gateways`, last: only PunchOut inside a visit, never PunchOut outside one. */
	public function available_gateways( mixed $gateways ): mixed {
		if ( ! is_array( $gateways ) ) { return $gateways; }
		if ( $this->inside_visit() ) {
			$gateway = $this->gateway();
			return $gateway->is_available() ? [ self::ID => $gateway ] : [];
		}
		unset( $gateways[ self::ID ] );
		return $gateways;
	}

	/** `woocommerce_order_button_text`: during a live visit checkout's button reads the place-order label from the start. */
	public function order_button_text( mixed $text ): mixed {
		return $this->live_visit() ? $this->gateway()->order_button_text : $text;
	}

	/**
	 * Whether a live visit owns this request: its row is active and its connection is active. Anything that cannot
	 * be established answers false.
	 */
	public function live_visit(): bool {
		try {
			$visit = $this->plugin->current_session();
			if ( null === $visit || Session::ACTIVE !== $visit->status ) { return false; }
			$partner = $this->registry->find( $visit->partner_id );
			return null !== $partner && $partner->is_active();
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/** The live visit's connection, or null when no live visit owns this request (or that cannot be established). */
	public function partner(): ?\POW\Partners\Partner {
		try {
			$visit = $this->plugin->current_session();
			if ( null === $visit || Session::ACTIVE !== $visit->status ) { return null; }
			$partner = $this->registry->find( $visit->partner_id );
			return null !== $partner && $partner->is_active() ? $partner : null;
		} catch ( \Throwable $error ) {
			return null;
		}
	}

	/** Whether the request is inside any visit, and also when that cannot be established (fail closed). */
	private function inside_visit(): bool {
		try {
			return null !== $this->plugin->current_session();
		} catch ( \Throwable $error ) {
			return true;
		}
	}

	/** The place-order label: a non-blank saved value, else "Place order"; the filter runs last and a blank result keeps the default. */
	public static function place_order_label( mixed $saved ): string {
		$default = __( 'Place order', 'punchout-woocommerce' );
		$label   = trim( (string) apply_filters( self::PLACE_ORDER_FILTER, Settings::resolve_label( $saved, $default ) ) );
		return '' !== $label ? $label : $default;
	}
}
