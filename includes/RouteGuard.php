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
use POW\Checkout\ExitPolicy;
use POW\Sessions\Session;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps punchout logins on permitted surfaces and enforces fresh company entitlement at native order/payment boundaries. Unrelated shoppers retain native behavior; paid-order information remains separate from permission to make another payment.
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
		add_action( 'woocommerce_checkout_create_order', [ $this, 'enforce_order' ], PHP_INT_MAX );
		add_action( 'woocommerce_checkout_order_processed', [ $this, 'enforce_order' ], PHP_INT_MAX );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', [ $this, 'enforce_order' ], PHP_INT_MAX );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'enforce_order' ], PHP_INT_MAX );
		add_action( 'woocommerce_rest_checkout_process_payment_with_context', [ $this, 'enforce_payment_context' ], -9999 );
		add_action( 'woocommerce_before_pay_action', [ $this, 'enforce_pay_action' ], -9999 );
		add_action( 'login_init', [ $this, 'guard_login_screen' ] );
	}

	public function guard(): void {
		$session = $this->plugin->current_session();

		// Account surfaces (order history, addresses, downloads, password
		// changes) are outside a punchout session's remit. A direct company
		// owner has no PunchOut session and retains ordinary account access.
		if ( null !== $session && function_exists( 'is_account_page' ) && is_account_page() ) {
			$this->redirect_to_landing();
			return;
		}

		// order-pay / order-received only for the buyer's OWN orders — the
		// order-key check is Woo's, this is the customer-id belt (§5.5).
		$endpoint_order_id = $this->endpoint_order_id();

		if ( $endpoint_order_id > 0 && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $endpoint_order_id );

			if ( ! $order || (int) $order->get_customer_id() !== get_current_user_id() || ( is_wc_endpoint_url( 'order-pay' ) && ! $this->checkout_allowed( $order ) ) ) {
				$this->redirect_to_landing();
				return;
			}
		}

		// Ordinary checkout follows current entitlement; order-pay has its own payment check above, while owned order-received information remains reachable.
		if ( function_exists( 'is_checkout' ) && is_checkout() && 0 === $endpoint_order_id && $this->requisition_only() ) {
			$target = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : $this->settings->landing_url();
			wp_safe_redirect( $target, 302 );
			exit;
		}

		if ( null === $session ) {
			return;
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
	public function block_store_api_checkout( $order = null ): void {
		if ( $this->checkout_allowed( $order instanceof \WC_Order ? $order : null ) ) {
			return;
		}

		$message = $this->checkout_blocked_message();

		if ( class_exists( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'pow_requisition_only', esc_html( $message ), 403 );
		}

		wp_die( esc_html( $message ), 403 ); // Unreachable on any Woo new enough to serve the Store API.
	}

	public function block_checkout_process(): void {
		if ( $this->requisition_only() && function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $this->checkout_blocked_message(), 'error' );
		}
	}

	/**
	 * Direct owners retain My Account guidance. Session buyers receive the
	 * operator's configured and filtered PunchOut return-button label.
	 */
	private function checkout_blocked_message(): string {
		if ( null === $this->plugin->current_session() ) {
			$actor = get_current_user_id();
			$owner_policy = ( new ExitPolicy( $this->settings, $this->registry ) )->effective_for_owner( $actor );
			if ( ExitPolicy::ONLY === $owner_policy ) {
				return __( 'Checkout is not available for this PunchOut-only company. My Account remains available for company configuration.', 'punchout-woocommerce' );
			}
		}

		$label = (string) apply_filters(
			'pow_return_button_label',
			$this->settings->button_label(
				'return_button_label',
				__( 'Punchout', 'punchout-woocommerce' )
			)
		);

		return sprintf(
			/* translators: %s: label of the punchout exit button */
			__( 'Checkout is not available in this catalog session. Please use "%s".', 'punchout-woocommerce' ),
			$label
		);
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

	private function requisition_only(): bool { return ! $this->checkout_allowed(); }

	/** Fresh authorization at every order/payment boundary, including orphaned buyer logins. */
	public function checkout_allowed( ?\WC_Order $order = null ): bool {
		try {
			$actor = get_current_user_id();
			$user = $actor > 0 ? get_userdata( $actor ) : false;
			$session = $this->plugin->current_session();
			$buyer = $user && ( in_array( Installer::ROLE, (array) $user->roles, true ) || get_user_meta( $actor, '_pow_partner_id', true ) );
			$tagged = $order && ( $order->get_meta( '_pow_session' ) || $order->get_meta( '_pow_partner' ) );
			if ( ! $buyer && ! $session && ! $tagged ) {
				if ( ! $this->plugin->enabled() ) { return true; }
				$owner_policy = ( new ExitPolicy( $this->settings, $this->registry ) )->effective_for_owner( $actor );
				return null === $owner_policy || ExitPolicy::CHECKOUT === $owner_policy;
			}
			if ( ! $this->plugin->enabled() || ! $session || $session->user_id !== $actor ) { return false; }
			$fresh = $this->plugin->sessions()?->find_for_login( $actor, wp_get_session_token(), [ Session::ACTIVE ] );
			if ( ! $fresh || $fresh->id !== $session->id || $fresh->partner_id !== $session->partner_id || ! $fresh->expires || strtotime( $fresh->expires . ' UTC' ) <= time() ) { return false; }
			$partner = $this->registry->find( $fresh->partner_id );
			if ( ! $partner || ExitPolicy::CHECKOUT !== ( new ExitPolicy( $this->settings, $this->registry ) )->effective( $partner, $actor ) ) { return false; }
			if ( $order ) {
				if ( (int) $order->get_customer_id() !== $actor || $order->is_paid() ) { return false; }
				if ( $order->get_meta( '_pow_session' ) && (int) $order->get_meta( '_pow_session' ) !== $fresh->id ) { return false; }
				if ( $order->get_meta( '_pow_partner' ) && (int) $order->get_meta( '_pow_partner' ) !== $partner->id ) { return false; }
			}
			return true;
		} catch ( \Throwable $e ) { return false; }
	}

	/** Woo catches this exception in classic creation and both Store API submission paths, including zero totals. */
	public function enforce_order( $order ): void {
		if ( is_numeric( $order ) ) { $order = wc_get_order( (int) $order ); }
		if ( ! $this->checkout_allowed( $order instanceof \WC_Order ? $order : null ) ) {
			if ( class_exists( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'pow_requisition_only', $this->checkout_blocked_message(), 403 );
			}
			throw new \Exception( $this->checkout_blocked_message() );
		}
	}

	/** This native hook is outside Woo's try/catch: terminate before any customer or gateway mutation. */
	public function enforce_pay_action( $order ): void {
		if ( ! $this->checkout_allowed( $order instanceof \WC_Order ? $order : null ) ) {
			wp_die( esc_html( $this->checkout_blocked_message() ), '', [ 'response' => 403 ] );
		}
	}

	/** Store API payment integrations run after this early veto, without changing payment-complete callbacks. */
	public function enforce_payment_context( $context ): void { $this->enforce_order( $context->order ); }

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
