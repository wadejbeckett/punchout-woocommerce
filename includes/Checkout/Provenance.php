<?php
/**
 * PunchOut provenance on an order from the moment checkout creates it (0.5.0).
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Checkout;

use POW\Orders\QuoteOrder;
use POW\Partners\Partner;
use POW\Partners\Registry;
use POW\Plugin;
use POW\Sessions\Session;

defined( 'ABSPATH' ) || exit;

/**
 * Classic checkout creates the order, saves it and only then asks the payment method to process it. An order placed
 * with the PunchOut method in a live visit carries that visit's provenance (visit, connection, buyer, cookie) from the
 * moment WooCommerce creates it (`woocommerce_checkout_create_order`, before its save), the same keys Submit stamps
 * again with the cXML copy. A PunchOut order is therefore never payable by another route (order-pay, the Store API),
 * also while it is still pending because a Submit could not be prepared.
 *
 * In a visit that is no longer live, or whose connection cannot be read, checkout stops before the order is saved,
 * with the plain "session has ended" message: no unstamped PunchOut order is left behind. Another method in a visit,
 * and the PunchOut method outside one, are the route guard's to refuse.
 */
final class Provenance {

	public function __construct(
		private Plugin $plugin,
		private Registry $registry,
		private PaymentMethod $method,
	) {}

	public function register(): void {
		add_action( 'woocommerce_checkout_create_order', [ $this, 'stamp_new_order' ], 10 );
	}

	/**
	 * The provenance keys every PunchOut order carries (old Quotes carry the same), without the cXML copy.
	 *
	 * @return array<string, string>
	 */
	public static function keys( Session $visit, Partner $partner ): array {
		return [
			QuoteOrder::META_SESSION_ID     => (string) $visit->id,
			QuoteOrder::META_PARTNER_ID     => (string) $partner->id,
			QuoteOrder::META_PARTNER_NAME   => $partner->name,
			QuoteOrder::META_BUYER_IDENTITY => (string) $visit->buyer_identity,
			QuoteOrder::META_BUYER_NAME     => (string) $visit->buyer_name,
			QuoteOrder::META_BUYER_COOKIE   => $visit->buyer_cookie,
		];
	}

	/**
	 * `woocommerce_checkout_create_order`: stamp a PunchOut order placed in a live visit; refuse one in a visit that
	 * is not live.
	 *
	 * @throws \Exception When the visit is not live: WooCommerce stops checkout with the message, before the save.
	 */
	public function stamp_new_order( mixed $order ): void {
		if ( ! $order instanceof \WC_Order || PaymentMethod::ID !== $order->get_payment_method() ) { return; }
		try {
			$visit = $this->plugin->current_session();
		} catch ( \Throwable $error ) {
			return; // Unproven: the route guard refuses the order on this same hook.
		}
		if ( null === $visit ) { return; }

		$partner = null;
		try {
			$partner = $this->method->live_visit() ? $this->registry->find( $visit->partner_id ) : null;
		} catch ( \Throwable $error ) {
			$partner = null;
		}
		if ( null === $partner || ! $partner->is_active() ) {
			throw new \Exception( Submission::ended() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain translated text; WooCommerce escapes notices.
		}
		foreach ( self::keys( $visit, $partner ) as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
	}
}
