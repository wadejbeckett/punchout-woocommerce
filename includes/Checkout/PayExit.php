<?php
/**
 * Pay-exit lifecycle (additive only).
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Checkout;

use POW\Audit\Log;
use POW\Plugin;
use POW\Sessions\Session;
use POW\Sessions\Store;
use POW\Support\Templates;

defined( 'ABSPATH' ) || exit;

/**
 * The pay-now path IS stock WooCommerce — this class never overrides,
 * replaces or filters checkout or any payment gateway (client requirement
 * 2026-08-31). It only listens:
 *
 * - tags orders created inside a punchout session (HPOS-safe CRUD meta);
 * - flips the session active -> ordered at woocommerce_payment_complete —
 *   payment confirmation, not order creation, is the flip (gateways
 *   confirm asynchronously; every §9.7 state is handled);
 * - offers the close-out CTA on the order-received page (empty POOM +
 *   SupplierOrderInfo). If the buyer never clicks it, cron tears the
 *   session down at TTL — the documented fallback.
 */
final class PayExit {

	public function __construct(
		private Plugin $plugin,
		private Store $sessions,
		private Log $audit,
	) {}

	public function register(): void {
		add_action( 'woocommerce_checkout_create_order', [ $this, 'tag_order' ], 10, 1 );
		add_action( 'woocommerce_checkout_order_processed', [ $this, 'link_order' ], 10, 1 );
		add_action( 'woocommerce_payment_complete', [ $this, 'payment_complete' ] );
		add_action( 'woocommerce_order_status_failed', [ $this, 'payment_failed' ] );
		add_action( 'woocommerce_thankyou', [ $this, 'render_closeout' ], 20 );
	}

	/**
	 * Tag the order with the session linkage before it is saved.
	 *
	 * @param \WC_Order $order Order being created.
	 */
	public function tag_order( $order ): void {
		$session = $this->plugin->current_session();

		if ( null === $session || ! $order instanceof \WC_Order ) {
			return;
		}

		$order->update_meta_data( '_pow_session', (string) $session->id );
		$order->update_meta_data( '_pow_partner', (string) $session->partner_id );
		$order->update_meta_data( '_pow_buyer_cookie', $session->buyer_cookie );
		$order->update_meta_data( '_pow_outcome', 'direct_order' );
	}

	/**
	 * Record the order id on the session row once the order exists. The
	 * status stays `active` until payment confirms — a declined buyer must
	 * still be able to retry on order-pay inside the session (scope §9.7).
	 *
	 * @param int $order_id New order id.
	 */
	public function link_order( $order_id ): void {
		$session = $this->plugin->current_session();

		if ( null === $session ) {
			return;
		}

		$this->sessions->update( $session->id, [ 'order_id' => (int) $order_id ] );

		$this->audit->write(
			'checkout_order',
			[
				'partner_id' => $session->partner_id,
				'session_id' => $session->id,
				'user_id'    => $session->user_id,
				'order_id'   => (int) $order_id,
				'result'     => 'ok',
			]
		);
	}

	/**
	 * Payment confirmation flips the session to `ordered`. Confirmation
	 * can arrive after teardown (asynchronous gateway notification) — the
	 * order stands and the audit row records the late arrival; the row's
	 * terminal state is not disturbed (scope §9.7).
	 *
	 * @param int $order_id Paid order id.
	 */
	public function payment_complete( $order_id ): void {
		$session = $this->session_for_order( (int) $order_id );

		if ( null === $session ) {
			return;
		}

		$flipped = $this->sessions->transition( $session->id, Session::ACTIVE, Session::ORDERED, [ 'order_id' => (int) $order_id ] );

		$this->audit->write(
			'payment_complete',
			[
				'partner_id' => $session->partner_id,
				'session_id' => $session->id,
				'user_id'    => $session->user_id,
				'order_id'   => (int) $order_id,
				'result'     => $flipped ? 'ok' : 'late',
			]
		);
	}

	/**
	 * Audit trail for declined payments; the session stays `active` so the
	 * buyer can retry from order-pay or close out (scope §9.7).
	 *
	 * @param int $order_id Failed order id.
	 */
	public function payment_failed( $order_id ): void {
		$session = $this->session_for_order( (int) $order_id );

		if ( null === $session ) {
			return;
		}

		$this->audit->write(
			'payment_failed',
			[
				'partner_id' => $session->partner_id,
				'session_id' => $session->id,
				'user_id'    => $session->user_id,
				'order_id'   => (int) $order_id,
				'result'     => 'failed',
			]
		);
	}

	/**
	 * The "return to your purchasing system" CTA on the order-received
	 * page, for the buyer's own punchout order.
	 *
	 * @param int $order_id Received order id.
	 */
	public function render_closeout( $order_id ): void {
		$session = $this->plugin->current_session();

		if ( null === $session || $session->order_id !== (int) $order_id ) {
			return;
		}

		/**
		 * Filter the close-out CTA strings.
		 *
		 * @param array<string, string> $copy label/copy strings.
		 */
		$copy = apply_filters(
			'pow_closeout_copy',
			[
				'label' => __( 'Return to your purchasing system', 'punchout-woocommerce' ),
				'copy'  => __( 'Your order is placed with the store. Click below to close the catalog session in your purchasing system.', 'punchout-woocommerce' ),
			]
		);

		echo Templates::render( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template output, escaped within.
			'closeout-button',
			[
				'action_url' => home_url( '/punchout/return' ),
				'nonce'      => wp_create_nonce( 'pow_return' ),
				'label'      => (string) ( $copy['label'] ?? '' ),
				'copy'       => (string) ( $copy['copy'] ?? '' ),
			]
		);
	}

	/**
	 * Resolve the punchout session an order belongs to via its meta tag
	 * (works after teardown, when no login exists).
	 */
	private function session_for_order( int $order_id ): ?Session {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return null;
		}

		$session_id = (int) $order->get_meta( '_pow_session' );

		return $session_id > 0 ? $this->sessions->find( $session_id ) : null;
	}
}
