<?php
/**
 * What WooCommerce asks about a PunchOut order's payment and its clean-up (0.5.0).
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Checkout;

use POW\Orders\QuoteOrder;

defined( 'ABSPATH' ) || exit;

/**
 * - Zero-total carts. WooCommerce sends a cart that needs no payment to its order-received page and marks the order
 *   paid, without any payment method. During a live visit the cart, and a pending order placed with the PunchOut
 *   method, are said to need payment, so a cart that totals zero (price-on-request lines, a full discount) still shows
 *   the PunchOut method and reaches Submit. Both rules run last; outside a live visit WooCommerce's answer stands.
 * - Unpaid-order clean-up. WooCommerce cancels pending checkout orders after the stock-hold time, which e-mails the
 *   store. A PunchOut order (placed with the method, or carrying PunchOut provenance) is never cancelled that way: a
 *   pending one is a Submit that could not be prepared, which WooCommerce resumes on the next Submit. That rule is
 *   registered whether or not PunchOut is switched on, because the clean-up runs from cron.
 */
final class Lifecycle {

	public function __construct( private PaymentMethod $method ) {}

	public function register(): void {
		add_filter( 'woocommerce_cart_needs_payment', [ $this, 'cart_needs_payment' ], PHP_INT_MAX, 2 );
		add_filter( 'woocommerce_order_needs_payment', [ $this, 'order_needs_payment' ], PHP_INT_MAX, 3 );
	}

	public static function register_unpaid_rule(): void {
		add_filter( 'woocommerce_cancel_unpaid_order', [ self::class, 'keep_unpaid' ], PHP_INT_MAX, 2 );
	}

	/** `woocommerce_cart_needs_payment`: true during a live visit. */
	public function cart_needs_payment( mixed $needs, mixed $cart = null ): mixed {
		return true === $needs || ! $this->method->live_visit() ? $needs : true;
	}

	/** `woocommerce_order_needs_payment`: true for a pending PunchOut order during a live visit. */
	public function order_needs_payment( mixed $needs, mixed $order = null, mixed $statuses = [] ): mixed {
		if ( true === $needs || ! $order instanceof \WC_Order ) { return $needs; }
		try {
			return 'pending' === $order->get_status() && PaymentMethod::ID === $order->get_payment_method() && $this->method->live_visit() ? true : $needs;
		} catch ( \Throwable $error ) {
			return $needs;
		}
	}

	/** `woocommerce_cancel_unpaid_order`: never for a PunchOut order. */
	public static function keep_unpaid( mixed $cancel, mixed $order = null ): mixed {
		return $order instanceof \WC_Order && self::punchout_order( $order ) ? false : $cancel;
	}

	/** Placed with the PunchOut method, or carrying PunchOut provenance (which any read failure is taken to mean). */
	public static function punchout_order( \WC_Order $order ): bool {
		try {
			return PaymentMethod::ID === $order->get_payment_method()
				|| '' !== trim( (string) $order->get_meta( QuoteOrder::META_SESSION_ID ) . (string) $order->get_meta( QuoteOrder::META_PARTNER_ID ) );
		} catch ( \Throwable $error ) {
			return true;
		}
	}
}
