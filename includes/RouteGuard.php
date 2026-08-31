<?php
/**
 * Punchout session route scoping.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW;

use POW\Partners\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Scoping guard, not a checkout block (scope §5.5): active ONLY inside a
 * punchout session, it 302s the buyer away from surfaces a punchout
 * login must not reach (account pages, other users' orders) and — for
 * requisition_only partners only — re-arms the checkout block as
 * belt-and-braces (template_redirect 302 + woocommerce_checkout_process
 * hard fail).
 *
 * Ordinary shoppers never enter this code path; presentation conditions
 * (theme render logics) remain display-only — this guard is the access
 * control behind them.
 */
final class RouteGuard {

	public function __construct(
		private Plugin $plugin,
		private Registry $registry,
		private Settings $settings,
	) {}

	public function register(): void {
		add_action( 'template_redirect', [ $this, 'guard' ], 1 );
		add_action( 'woocommerce_checkout_process', [ $this, 'block_checkout_process' ] );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'block_store_api_checkout' ] );
		add_action( 'login_init', [ $this, 'guard_login_screen' ] );
	}

	public function guard(): void {
		$session = $this->plugin->current_session();

		if ( null === $session ) {
			return;
		}

		// Account surfaces (order history, addresses, downloads, password
		// changes) are outside a punchout session's remit.
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			$this->redirect_to_landing();
			return;
		}

		// order-pay / order-received only for the buyer's OWN orders — the
		// order-key check is Woo's, this is the customer-id belt (§5.5).
		$endpoint_order_id = $this->endpoint_order_id();

		if ( $endpoint_order_id > 0 && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $endpoint_order_id );

			if ( $order && (int) $order->get_customer_id() !== get_current_user_id() ) {
				$this->redirect_to_landing();
				return;
			}
		}

		// requisition_only partners: checkout is 302-blocked (order-pay /
		// order-received stay reachable — they only exist for dual_exit
		// partners' orders anyway).
		if ( $this->requisition_only() && function_exists( 'is_checkout' ) && is_checkout() && 0 === $endpoint_order_id ) {
			$target = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : $this->settings->landing_url();
			wp_safe_redirect( $target, 302 );
			exit;
		}

		/**
		 * Fires after the built-in route checks pass, so sites can extend
		 * the blocked surface (e.g. plugin-specific endpoints) without
		 * forking the guard. Callbacks redirect+exit themselves.
		 *
		 * @param \POW\Sessions\Session $session Active punchout session.
		 */
		do_action( 'pow_route_guard', $session );
	}

	/**
	 * Second layer of the requisition_only block: even a crafted POST to
	 * the checkout processor hard-fails (scope §5.4).
	 */
	/**
	 * The blocks checkout never runs woocommerce_checkout_process — it
	 * posts to the Store API. Same policy, that route's own veto point.
	 *
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When a requisition_only session tries to check out.
	 */
	public function block_store_api_checkout(): void {
		if ( ! $this->requisition_only() ) {
			return;
		}

		$message = __( 'Checkout is not available in this catalog session. Please use "Send to your purchasing system for approval".', 'punchout-woocommerce' );

		if ( class_exists( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'pow_requisition_only', esc_html( $message ), 403 );
		}

		wp_die( esc_html( $message ), 403 ); // Unreachable on any Woo new enough to serve the Store API.
	}

	public function block_checkout_process(): void {
		if ( $this->requisition_only() && function_exists( 'wc_add_notice' ) ) {
			wc_add_notice(
				__( 'Checkout is not available in this catalog session. Please use "Send to your purchasing system for approval".', 'punchout-woocommerce' ),
				'error'
			);
		}
	}

	/**
	 * wp-login.php inside a punchout session: logout is allowed, anything
	 * else goes back to the landing page (scope §5.5).
	 */
	public function guard_login_screen(): void {
		if ( null === $this->plugin->current_session() ) {
			return;
		}

		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : 'login'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing decision.

		if ( 'logout' !== $action ) {
			wp_safe_redirect( $this->settings->landing_url(), 302 );
			exit;
		}
	}

	private function requisition_only(): bool {
		$session = $this->plugin->current_session();

		if ( null === $session ) {
			return false;
		}

		$partner = $this->registry->find( $session->partner_id );

		return null !== $partner && $partner->is_requisition_only();
	}

	private function endpoint_order_id(): int {
		if ( ! function_exists( 'is_wc_endpoint_url' ) ) {
			return 0;
		}

		global $wp;

		if ( is_wc_endpoint_url( 'order-pay' ) ) {
			return absint( $wp->query_vars['order-pay'] ?? 0 );
		}

		if ( is_wc_endpoint_url( 'order-received' ) ) {
			return absint( $wp->query_vars['order-received'] ?? 0 );
		}

		return 0;
	}

	private function redirect_to_landing(): void {
		wp_safe_redirect( $this->settings->landing_url(), 302 );
		exit;
	}
}
