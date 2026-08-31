<?php
/**
 * Cart-level guards for punchout sessions.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Cart;

use POW\Logger;
use POW\Plugin;
use POW\Sessions\Store;
use POW\Audit\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Three cart behaviours scoped strictly to active punchout sessions
 * (scope §5.3/§6.1); nothing here touches ordinary shoppers:
 *
 * - Persistent cart disabled: last week's basket must never resurrect
 *   into a fresh punchout session.
 * - The cart is emptied once, on the first authenticated request of a
 *   `create` session (deferred from the login request, where WC()->cart
 *   still belongs to the guest session).
 * - Add-to-cart range validation: hiding a product is display filtering,
 *   not access control — ?wc-ajax=add_to_cart accepts an arbitrary
 *   product id, so the server rejects products outside the buyer's
 *   visible range here (leak surface L7).
 */
final class Guard {

	public function __construct(
		private Plugin $plugin,
		private Store $sessions,
		private Log $audit,
		private Logger $logger,
	) {}

	public function register(): void {
		add_filter( 'woocommerce_persistent_cart_enabled', [ $this, 'disable_persistent_cart' ] );
		add_action( 'wp_loaded', [ $this, 'seed_cart' ], 30 );
		add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'validate_add_to_cart' ], 20, 2 );
	}

	/**
	 * @param mixed $enabled Incoming filter value.
	 * @return mixed
	 */
	public function disable_persistent_cart( $enabled ) {
		return null !== $this->plugin->current_session() ? false : $enabled;
	}

	/**
	 * Empty the cart exactly once per create-session (scope §6.1).
	 */
	public function seed_cart(): void {
		$session = $this->plugin->current_session();

		if ( null === $session || $session->cart_ready || 'create' !== $session->operation ) {
			return;
		}

		// Latch only after the empty actually ran: on requests where the
		// cart is not loaded (REST/admin), leaving cart_ready=0 keeps the
		// one-shot armed for the first real storefront request.
		if ( function_exists( 'WC' ) && null !== WC()->cart ) {
			WC()->cart->empty_cart( true );
			$this->sessions->update( $session->id, [ 'cart_ready' => 1 ] );
		}
	}

	/**
	 * @param bool $passed     Prior validation result.
	 * @param int  $product_id Product being added.
	 */
	public function validate_add_to_cart( $passed, $product_id ): bool {
		$session = $this->plugin->current_session();

		if ( null === $session || ! $passed ) {
			return (bool) $passed;
		}

		$product = wc_get_product( $product_id );
		$allowed = $product instanceof \WC_Product && $product->is_visible() && $product->is_purchasable();

		/**
		 * Filter whether a product is inside the punchout buyer's range.
		 *
		 * The default combines WC visibility (which catalogue-visibility
		 * plugins hook) with purchasability; sites with
		 * stricter contract-range rules tighten it here.
		 *
		 * @param bool                  $allowed    Default decision.
		 * @param int                   $product_id Product id.
		 * @param \POW\Sessions\Session $session    Active punchout session.
		 */
		$allowed = (bool) apply_filters( 'pow_product_in_range', $allowed, (int) $product_id, $session );

		if ( ! $allowed ) {
			$this->audit->write(
				'cart_add_reject',
				[
					'partner_id' => $session->partner_id,
					'session_id' => $session->id,
					'user_id'    => $session->user_id,
					'result'     => 'reject',
					'detail'     => [ 'product_id' => (int) $product_id ],
				]
			);

			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( __( 'That product is not available in this catalog.', 'punchout-woocommerce' ), 'error' );
			}

			return false;
		}

		return true;
	}
}
