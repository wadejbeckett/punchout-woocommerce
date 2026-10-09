<?php
/**
 * Checkout validation in a visit: what cannot be sent is refused before an order exists (0.5.0).
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Checkout;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce's last validation step (`woocommerce_after_checkout_validation`) runs before it creates the order, and
 * an error there stops checkout with a notice. During a live visit it refuses, with a plain message:
 *
 * - a cart line whose product has no SKU, naming the item: the buyer's purchasing system matches lines by the code,
 *   and a line it cannot carry would otherwise vanish from the cart it receives;
 * - a cart priced in a currency other than the store's own (WooCommerce > Settings > General), which is the
 *   currency a connection's catalogue is set up in on the buyer's side: a currency switcher must not send prices in
 *   another;
 * - a note that cannot be made valid for cXML (OrderMessage::clean_note()). A note is otherwise cleaned of control
 *   characters and capped at 2,000 characters on its way into the message, not refused.
 *
 * Because no order is created, nothing is held, mailed or left pending, and the visit stays open.
 */
final class Validation {

	public function __construct( private PaymentMethod $method ) {}

	public function register(): void {
		add_action( 'woocommerce_after_checkout_validation', [ $this, 'validate' ], 10, 2 );
	}

	/**
	 * @param mixed $data   The posted checkout data.
	 * @param mixed $errors WooCommerce's WP_Error for this checkout.
	 */
	public function validate( mixed $data, mixed $errors ): void {
		if ( ! $errors instanceof \WP_Error || ! $this->method->live_visit() ) { return; }
		try {
			$note = is_array( $data ) && is_string( $data['order_comments'] ?? null ) ? $data['order_comments'] : '';
			foreach ( $this->problems( $note ) as $message ) {
				$errors->add( 'pow_checkout', $message );
			}
		} catch ( \Throwable $error ) {
			$errors->add( 'pow_checkout', __( 'Your cart could not be checked before sending it to your purchasing system. Please try again.', 'punchout-woocommerce' ) );
		}
	}

	/** @return list<string> */
	private function problems( string $note ): array {
		$problems = [];
		$cart     = function_exists( 'WC' ) ? WC()->cart : null;
		foreach ( null !== $cart ? (array) $cart->get_cart() : [] as $line ) {
			$product = is_array( $line ) ? ( $line['data'] ?? null ) : null;
			if ( is_object( $product ) && method_exists( $product, 'get_sku' ) && '' === trim( (string) $product->get_sku() ) ) {
				/* translators: %s: product name */
				$problems[] = sprintf( __( '"%s" has no product code, so it cannot be sent to your purchasing system. Remove it from your cart to continue.', 'punchout-woocommerce' ), esc_html( (string) $product->get_name() ) );
			}
		}

		$currency = (string) get_woocommerce_currency();
		$store    = (string) get_option( 'woocommerce_currency', '' );
		if ( '' === $store || $currency !== $store ) {
			/* translators: 1: the cart's currency code, 2: the store's own currency code */
			$problems[] = sprintf( __( 'This cart is priced in %1$s and can be sent to your purchasing system only in %2$s.', 'punchout-woocommerce' ), esc_html( $currency ), esc_html( $store ) );
		}

		if ( null === OrderMessage::clean_note( $note ) ) {
			$problems[] = __( 'Your note contains characters that cannot be sent to your purchasing system. Change the note and submit again.', 'punchout-woocommerce' );
		}

		return $problems;
	}
}
