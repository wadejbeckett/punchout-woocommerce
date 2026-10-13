<?php
/**
 * Punchout Quote orders.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Orders;

use POW\Audit\Log;
use POW\Logger;
use POW\Sessions\Store;
use POW\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The WooCommerce order a punchout visit leaves behind: a **Punchout Quote**.
 *
 * Since 0.5.0 the order is WooCommerce's own, placed at the classic checkout with the PunchOut payment method
 * (Checkout\Submission stamps the provenance keys below and the cXML copy and moves it to this status last).
 * The Quote building of earlier releases (an order assembled from the returned cart on the review page) is
 * retired; what it wrote stays readable (Orders\QuoteCompatibility, Addresses\DeliveryData).
 *
 * What this class keeps, for history and for every Quote already on the shop: register_admin() offers
 * "Convert Punchout Quote to order" on the order screen and draws the "Bought by" line (the person behind the
 * shared account), and expire() — called from the hourly housekeeping job — cancels quotes nobody converted
 * within the retention window. Neither ever deletes an order. retention_cutoff() turns a day count into the
 * timestamp string the purge query compares against.
 */
final class QuoteOrder {

	/**
	 * Order meta keys. Each is underscore-prefixed: that is what makes
	 * WooCommerce treat the meta as protected, so none of it is editable
	 * or visible in the order screen's custom-fields box.
	 */
	public const META_POOM_XML      = '_pow_poom_xml';
	public const META_SESSION_ID    = '_pow_session_id';
	/**
	 * Careful: '_pow_partner_id' is two keys with one name. The buyer-to-
	 * connection USER meta of that name belongs to the removed per-employee
	 * accounts; this is ORDER meta, it is how QuoteCompatibility reads a
	 * historical quote, and a grep-and-delete on the string corrupts every
	 * quote order already on the shop.
	 */
	public const META_PARTNER_ID    = '_pow_partner_id';
	/**
	 * What the connection was called when the buyer bought. Stamped rather
	 * than looked up, because a connection that has since been renamed or
	 * deleted must not rewrite or erase the attribution on an order that has
	 * already shipped.
	 */
	public const META_PARTNER_NAME  = '_pow_partner_name';
	public const META_DELIVERY_CODE = '_pow_delivery_code';
	/**
	 * Who bought. Buyers share the connection's own customer account, so
	 * customer_id names the company and only these keys name the person.
	 * An empty string is the legitimate, audited anonymous case — the
	 * purchasing system sent no identity — not a missing value.
	 */
	public const META_BUYER_IDENTITY = '_pow_buyer_identity';
	public const META_BUYER_NAME    = '_pow_buyer_name';
	/** The buyer's own correlator for this basket, straight off the setup request. */
	public const META_BUYER_COOKIE  = '_pow_buyer_cookie';
	/** Written by the retired review path (up to 0.4.23) only; read by QuoteCompatibility. 0.5.0 orders never carry them. */
	public const META_DELIVERY      = '_pow_delivery';
	public const META_DELIVERY_NOTES = '_pow_delivery_notes';
	public const META_DELIVERY_CHOICE = '_pow_delivery_choice';
	public const META_DELIVERY_CONFIRMATION = '_pow_delivery_confirmation';
	/** The buyer's preferred delivery date (Y-m-d) on a Quote created before 0.4.23, copied then from a schema-2 confirmation. 0.4.23 writes none; the order screen, the store e-mail and the attach check still read it. Never sent in the PunchOutOrderMessage. */
	public const META_PREFERRED_DELIVERY_DATE = '_pow_preferred_delivery_date';

	/**
	 * The order-screen action key. It has to survive sanitize_title()
	 * unchanged, because the meta box fires
	 * woocommerce_order_action_ . sanitize_title( $action )
	 * (class-wc-meta-box-order-actions.php:184) — a key that gets
	 * rewritten registers a hook nothing ever fires.
	 */
	public const CONVERT_ACTION = 'pow_convert_quote';

	/**
	 * Statuses a conversion may target: the three the settings screen
	 * offers, and the ones a not-yet-paid order legitimately moves to.
	 */
	public const CONVERT_STATUSES = [ 'pending', 'processing', 'on-hold' ];

	public function __construct(
		private Store $sessions,
		private Log $audit,
		private Settings $settings,
		private Logger $logger,
	) {}

	/**
	 * The order-screen wiring: the "Convert Punchout Quote to order"
	 * action, and the handler that services it.
	 *
	 * Both hooks belong to WooCommerce's order-actions meta box, so this
	 * is called in admin context only. It is registered outside the
	 * master switch for the same reason the status is: quotes already
	 * taken must stay convertible after punchout is switched off.
	 */
	public function register_admin(): void {
		add_filter( 'woocommerce_order_actions', [ $this, 'add_order_action' ], 10, 2 );
		add_action( 'woocommerce_order_action_' . self::CONVERT_ACTION, [ $this, 'convert' ] );
		add_action( 'woocommerce_admin_order_data_after_billing_address', [ $this, 'render_bought_by' ] );
	}

	/**
	 * The "Bought by" line under the order screen's billing address.
	 *
	 * Buyers share the connection's customer account, so the order screen's
	 * own customer field names the company. This is the only place an admin
	 * can see which person bought, which is why it is registered outside
	 * the master switch alongside the conversion action: a shop that
	 * switches punchout off must not lose the attribution on the orders it
	 * already has.
	 *
	 * Anything that is not one of our quotes prints nothing at all.
	 *
	 * @param mixed $order Order being rendered (WooCommerce passes \WC_Order).
	 */
	public function render_bought_by( mixed $order ): void {
		if ( ! $order instanceof \WC_Order || '' === (string) $order->get_meta( self::META_SESSION_ID ) ) {
			return;
		}

		// Absent attribution and empty attribution are different facts. A
		// quote taken before the buyer became data carries neither key, and
		// its buyer was the order's own customer; saying the purchasing
		// system named nobody would contradict the evidence still on it. A
		// visit of this release writes both keys, empty when the request
		// named nobody, and that case is the worded one below.
		if ( ! $order->meta_exists( self::META_BUYER_IDENTITY ) && ! $order->meta_exists( self::META_BUYER_NAME ) ) {
			return;
		}

		$date = (string) $order->get_meta( self::META_PREFERRED_DELIVERY_DATE );
		/* translators: %s: the buyer's preferred delivery date, Y-m-d */
		echo '<p class="pow-bought-by"><strong>' . esc_html__( 'PunchOut', 'punchout-woocommerce' ) . '</strong><br />' . esc_html( $this->bought_by_for( $order ) ) . ( '' !== $date ? '<br />' . esc_html( sprintf( __( 'Preferred delivery date: %s', 'punchout-woocommerce' ), $date ) ) : '' ) . '</p>';
	}

	/** The attribution sentence for one order, unescaped: the rule, separate from its markup. */
	public function bought_by_for( \WC_Order $order ): string {
		return self::bought_by(
			(string) $order->get_meta( self::META_BUYER_NAME ),
			(string) $order->get_meta( self::META_BUYER_IDENTITY ),
			self::connection_label( (string) $order->get_meta( self::META_PARTNER_NAME ), (int) $order->get_meta( self::META_PARTNER_ID ) )
		);
	}

	/**
	 * The one "Bought by" sentence, used verbatim in the order note and on
	 * the admin order screen so the two can never drift apart.
	 *
	 * Pure, and it escapes nothing: each caller escapes for its own output.
	 * The anonymous case is worded rather than left blank, because
	 * "Bought by  ()" reads as a fault in the shop instead of as a
	 * purchasing system that sent no identity.
	 *
	 * The e-mail address is bracketed with parentheses, never with angle
	 * brackets: an order note is stored verbatim and rendered through kses,
	 * where `<jane@buyer.test>` parses as an element named `jane` and is
	 * deleted along with the address. That failure hits the ordinary
	 * fully-identified case and is silent, so the wording carries no
	 * tag-shaped span at all rather than relying on every caller to escape.
	 */
	public static function bought_by( string $name, string $identity, string $connection ): string {
		if ( '' === $name && '' === $identity ) {
			/* translators: %s: customer connection name */
			return sprintf( __( 'Bought by an unnamed buyer via PunchOut (%s); the purchasing system sent no name or e-mail', 'punchout-woocommerce' ), $connection );
		}

		if ( '' === $name || '' === $identity ) {
			/* translators: 1: buyer name or e-mail address, 2: customer connection name */
			return sprintf( __( 'Bought by %1$s via PunchOut (%2$s)', 'punchout-woocommerce' ), '' === $name ? $identity : $name, $connection );
		}

		/* translators: 1: buyer name, 2: buyer e-mail address, 3: customer connection name */
		return sprintf( __( 'Bought by %1$s (%2$s) via PunchOut (%3$s)', 'punchout-woocommerce' ), $name, $identity, $connection );
	}

	/** Name the connection by the name it carried at the time, or by its id when it carried none. */
	private static function connection_label( string $name, int $id ): string {
		/* translators: %d: customer connection id */
		return '' !== $name ? $name : sprintf( __( 'connection #%d', 'punchout-woocommerce' ), $id );
	}

	/**
	 * Offer the conversion on a quote and on nothing else.
	 *
	 * The order argument is optional because the filter has not always
	 * carried one: with no order there is nothing to judge, so the action
	 * is not offered rather than offered blindly.
	 *
	 * @param array<string, string> $actions Order actions.
	 * @return array<string, string>
	 */
	public function add_order_action( array $actions, ?\WC_Order $order = null ): array {
		if ( null === $order || Status::SLUG !== $order->get_status() ) {
			return $actions;
		}

		$actions[ self::CONVERT_ACTION ] = __( 'Convert Punchout Quote to order', 'punchout-woocommerce' );

		return $actions;
	}

	/**
	 * Move one quote to the configured status.
	 *
	 * The status guard is not redundant with add_order_action(): offering
	 * the action and servicing it are separate hooks, so the handler is
	 * reachable from a stale order screen or a hand-built request on an
	 * order that is no longer a quote — and converting a paid order back
	 * to "pending payment" would be a real loss.
	 *
	 * update_status() rather than set_status() + save(): this runs on the
	 * admin's own request, not inside a punchout return, so the write is
	 * the point and core's own transition hooks should fire.
	 */
	public function convert( \WC_Order $order ): void {
		$context = [];

		try {
			$context['order_id'] = (int) $order->get_id();
			$context['user_id'] = get_current_user_id();

			if ( Status::SLUG !== $order->get_status() ) {
				return;
			}

			$context['partner_id'] = (int) $order->get_meta( self::META_PARTNER_ID );
			$context['session_id'] = (int) $order->get_meta( self::META_SESSION_ID );
			$status = $this->convert_status();
			$context['detail'] = [ 'status' => $status ];

			// Native real-order effects, including stock reduction for processing/on-hold, are intentional.
			$updated = $order->update_status(
				$status,
				sprintf(
					/* translators: %s: order status slug the quote was converted to */
					__( 'Punchout Quote converted to a live order (status: %s). The cXML basket returned to the buyer is still stored on this order.', 'punchout-woocommerce' ),
					$status
				)
			);

			if ( ! $updated ) {
				$this->transition_failure( 'quote_order_convert_failed', $context, 'status_update_failed' );
				return;
			}

			$context['result'] = 'ok';
			$this->audit->write( 'quote_order_converted', $context );
		} catch ( \Throwable $e ) {
			$this->transition_failure( 'quote_order_convert_failed', $context, 'operation_failed' );
		}
	}

	/**
	 * The status a conversion targets, clamped to the settings screen's supported values. An invalid saved value falls back to pending. Conversion uses normal WooCommerce transitions: processing/on-hold may reduce stock, and native email and fulfilment hooks remain enabled.
	 */
	public function convert_status(): string {
		$status = (string) $this->settings->get( 'quote_convert_status', 'pending' );

		return in_array( $status, self::CONVERT_STATUSES, true ) ? $status : 'pending';
	}

	/**
	 * Retention (DESIGN §1): quote orders older than quote_retention_days
	 * are moved to cancelled — never deleted. The audit log stays the
	 * canonical record, and the order itself keeps its basket XML.
	 *
	 * Cancellation uses native stock restoration only if stock was previously reduced. A quote created by this plugin has not reduced stock; recheck each fetched status so converted orders are left alone.
	 */
	public function expire(): int {
		if ( ! function_exists( 'wc_get_orders' ) || ! function_exists( 'wc_get_order' ) ) {
			return 0;
		}

		try {
			$days = $this->settings->int( 'quote_retention_days' );
			$cutoff = self::retention_cutoff( $days, time() );

			if ( '' === $cutoff ) {
				return 0;
			}

			// wc_get_orders date strings compare whole days; Unix timestamps compare UTC seconds.
			$order_ids = wc_get_orders(
				[
					'status'       => Status::SLUG,
					'date_created' => '<' . strtotime( $cutoff . ' UTC' ),
					'limit'        => 100,
					'return'       => 'ids',
					'orderby'      => 'date',
					'order'        => 'ASC',
				]
			);
		} catch ( \Throwable $e ) {
			$this->transition_failure( 'quote_order_expire_failed', [], 'query_failed' );
			return 0;
		}

		$moved = 0;

		foreach ( (array) $order_ids as $order_id ) {
			$context = [ 'order_id' => (int) $order_id ];

			try {
				$order = wc_get_order( (int) $order_id );

				if ( ! $order instanceof \WC_Order || Status::SLUG !== $order->get_status() ) {
					continue;
				}

				$context['session_id'] = (int) $order->get_meta( self::META_SESSION_ID );
				$context['partner_id'] = (int) $order->get_meta( self::META_PARTNER_ID );
				$updated = $order->update_status(
					'cancelled',
					sprintf(
						/* translators: %d: retention days */
						__( 'Cancelled automatically: this Punchout Quote was not converted within %d days. The returned basket is still stored on this order.', 'punchout-woocommerce' ),
						$days
					)
				);

				if ( ! $updated ) {
					$this->transition_failure( 'quote_order_expire_failed', $context, 'status_update_failed' );
					continue;
				}

				$context['result'] = 'retention';
				$this->audit->write( 'quote_order_expired', $context );
				++$moved;
			} catch ( \Throwable $e ) {
				$this->transition_failure( 'quote_order_expire_failed', $context, 'operation_failed' );
			}
		}

		return $moved;
	}

	/**
	 * Housekeeping (0.5.0 hardening): a pending order carrying a visit's provenance whose visit is no
	 * longer active (returned, closed, expired, past its time, or gone) and older than $age seconds (the handoff TTL)
	 * is moved to the bin with no status change, so no e-mail is sent and nothing WooCommerce would resume lingers.
	 * A pending order of a live visit is WooCommerce's to resume on the next Submit; an order or visit that cannot
	 * be read is left alone. Returns how many were binned.
	 */
	public function trash_stale_pending( int $age ): int {
		if ( ! function_exists( 'wc_get_orders' ) || ! function_exists( 'wc_get_order' ) ) {
			return 0;
		}
		try {
			$order_ids = wc_get_orders(
				[
					'status'       => 'pending',
					'meta_key'     => self::META_SESSION_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the provenance key, indexed with the rest of the order meta.
					'meta_compare' => 'EXISTS',
					'date_created' => '<' . ( time() - max( 0, $age ) ),
					'limit'        => 100,
					'return'       => 'ids',
					'orderby'      => 'date',
					'order'        => 'ASC',
				]
			);
		} catch ( \Throwable $e ) {
			$this->transition_failure( 'pending_order_sweep_failed', [], 'query_failed' );
			return 0;
		}

		$binned = 0;
		foreach ( (array) $order_ids as $order_id ) {
			$context = [ 'order_id' => (int) $order_id ];
			try {
				$order = wc_get_order( (int) $order_id );
				if ( ! $order instanceof \WC_Order || 'pending' !== $order->get_status() ) { continue; }
				$session_id = (int) $order->get_meta( self::META_SESSION_ID );
				if ( $session_id <= 0 ) { continue; }
				$context['session_id'] = $session_id;
				$context['partner_id'] = (int) $order->get_meta( self::META_PARTNER_ID );
				$visit = $this->sessions->find( $session_id );
				if ( null !== $visit && ! \POW\Http\EndedVisit::over( $visit ) ) { continue; }
				// The order this visit's return sent: still pending only because the Quote status write failed.
				if ( null !== $visit && $visit->order_id === (int) $order_id ) { continue; }
				$order->delete( false );
				$context['result'] = 'stale';
				$this->audit->write( 'pending_order_trashed', $context );
				++$binned;
			} catch ( \Throwable $e ) {
				$this->transition_failure( 'pending_order_trash_failed', $context, 'operation_failed' );
			}
		}

		return $binned;
	}

	/** Record failure without re-reading a broken order or exposing third-party exception text. */
	private function transition_failure( string $event, array $context, string $reason ): void {
		$context['result'] = 'error';
		$context['detail']['error'] = $reason;

		try {
			$this->audit->write( $event, $context );
		} catch ( \Throwable $e ) {
			// A failed audit backend must not abort the remaining retention candidates.
			try {
				$this->logger->error( 'Quote order transition could not be audited', [ 'event' => $event, 'order' => $context['order_id'] ?? 0 ] );
			} catch ( \Throwable $logging_error ) {
				// Both reporting backends are unavailable; preserve the caller's failure containment.
			}
		}
	}

	/**
	 * The datetime string a retention purge compares against, in UTC.
	 * A non-positive day count means retention is off, and returns ''.
	 */
	public static function retention_cutoff( int $days, int $now ): string {
		return $days > 0 ? gmdate( 'Y-m-d H:i:s', $now - $days * 86400 ) : '';
	}

}
