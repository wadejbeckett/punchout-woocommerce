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
use POW\Partners\Partner;
use POW\Sessions\Session;
use POW\Sessions\Store;
use POW\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The WooCommerce order a punchout return leaves behind.
 *
 * create_for_session() is the WordPress half: it runs between the cart
 * mapping and the basket build (DESIGN §1), so the order carries exactly
 * the lines and prices the buyer's system was quoted. It can never block
 * that basket — a failure is logged, audited, notified and swallowed
 * (DESIGN §6) — and the document itself lands later, via attach_poom(),
 * because it does not exist until the build has run.
 *
 * The admin half is the other end of the same life: register_admin()
 * offers "Convert Punchout Quote to order" on the order screen, and
 * expire() — called from the hourly housekeeping job — cancels quotes
 * nobody converted within the retention window. Neither ever deletes an
 * order.
 *
 * The rules the order is built from are pure and unit-tested without
 * WordPress:
 *
 * - line_args() derives the order line from the POOM line, so the order
 *   and the document the buyer received cannot disagree about price. The
 *   cents are the ones PoomMapper emitted, after
 *   pow_poom_unit_price_cents, and they are ex-tax.
 * - resolve_shipping() fixes the delivery-address precedence in one place
 *   rather than in a chain of ifs at the call site.
 * - address_from_ship_to() reads the ShipTo fragment the session stored
 *   at setup time, under the same XXE rules Cxml\Parser applies.
 * - retention_cutoff() turns a day count into the timestamp string the
 *   purge query compares against.
 */
final class QuoteOrder {

	/**
	 * Order meta keys. Each is underscore-prefixed: that is what makes
	 * WooCommerce treat the meta as protected, so none of it is editable
	 * or visible in the order screen's custom-fields box.
	 */
	public const META_POOM_XML      = '_pow_poom_xml';
	public const META_SESSION_ID    = '_pow_session_id';
	public const META_PARTNER_ID    = '_pow_partner_id';
	public const META_DELIVERY_CODE = '_pow_delivery_code';

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
	private const CONVERT_STATUSES = [ 'pending', 'processing', 'on-hold' ];

	/** Transient that throttles the failure notice to one an hour. */
	private const FAIL_NOTICE_KEY = 'pow_quote_fail_notice';

	/**
	 * The shipping fields WooCommerce stores as order props. Anything a
	 * filter or an inbound ShipTo offers outside this list is dropped
	 * rather than written, which is what keeps HPOS free of orphan meta.
	 */
	private const SHIPPING_FIELDS = [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' ];

	public function __construct(
		private Store $sessions,
		private Log $audit,
		private Settings $settings,
		private Logger $logger,
	) {}

	/**
	 * Create the Punchout Quote order for a returning session.
	 *
	 * @param array<string, mixed> $poom_lines PoomMapper::from_cart() output.
	 * @return int Order id, or 0 when creation failed (logged and audited).
	 */
	public function create_for_session( Session $session, Partner $partner, array $poom_lines ): int {
		// Held outside the try so the catch can cancel a part-built order:
		// wc_create_order(), add_product() and calculate_totals() each
		// persist, so a throw halfway through would otherwise leave a
		// payable wc-pending order in the shop.
		$order = null;

		try {
			// A double-submitted return must not leave two quotes behind:
			// the session already names one of ours, so hand that back.
			// Inside the try because the lookup touches the database, and
			// nothing in here may throw into the waiting basket.
			$existing = $this->existing_quote( $session );

			if ( $existing > 0 ) {
				$this->audit->write(
					'quote_order_reused',
					[
						'partner_id' => $partner->id,
						'session_id' => $session->id,
						'user_id'    => $session->user_id,
						'order_id'   => $existing,
						'result'     => 'ok',
						'detail'     => [ 'reason' => 'duplicate_return' ],
					]
				);

				return $existing;
			}

			$order = wc_create_order( [ 'customer_id' => $session->user_id ] );

			if ( ! $order instanceof \WC_Order ) {
				// wc_create_order() returns a WP_Error; it does not throw.
				throw new \RuntimeException( is_wp_error( $order ) ? (string) $order->get_error_message() : 'wc_create_order returned no order' );
			}

			$order->set_currency( (string) ( $poom_lines['currency'] ?? get_woocommerce_currency() ) );

			foreach ( (array) ( $poom_lines['items'] ?? [] ) as $item ) {
				$this->add_line( $order, self::line_args( (array) $item ) );
			}

			$shipping = $this->shipping_for( $session, $partner );

			if ( [] !== $shipping['address'] ) {
				$this->set_shipping( $order, $shipping['address'] );
			}

			// Stored as strings: that is what WooCommerce writes back after
			// a round trip through the meta store, so a reader comparing
			// values sees the same type whether the order was just built or
			// re-fetched (PayExit does the same).
			$order->update_meta_data( self::META_SESSION_ID, (string) $session->id );
			$order->update_meta_data( self::META_PARTNER_ID, (string) $partner->id );
			$order->update_meta_data( self::META_DELIVERY_CODE, $shipping['code'] );

			// Totals without taxes: the basket quotes ex-tax unit prices
			// (PoomMapper), so a tax-inclusive order total would not be the
			// number the buyer's system received.
			$order->calculate_totals( false );

			// Status set last: wc_create_order() saves the order as pending,
			// so the only transition core sees is pending -> punchout-quote.
			// Neither end of that pair is on a hook that moves stock or
			// sends mail (wc-stock-functions.php:124-127 binds only
			// completed/processing/on-hold and payment_complete;
			// class-wc-emails.php:91 lists fixed X_to_Y pairs, none of
			// which names a custom status).
			$order->set_status( Status::SLUG );

			$order->add_order_note(
				sprintf(
					/* translators: 1: session id, 2: customer connection name, 3: address source */
					__( 'Punchout Quote created from punchout session #%1$d (%2$s). The cXML basket returned to the buyer is stored on this order. Delivery address source: %3$s.', 'punchout-woocommerce' ),
					$session->id,
					$partner->name,
					$shipping['source']
				)
			);

			$order_id = (int) $order->save();
		} catch ( \Throwable $e ) {
			// DESIGN §6: quote-order failure never blocks the basket —
			// log, notify the admin, continue. The buyer still gets their
			// cart back and the audit copy of the basket still exists.
			return $this->fail( $session, $partner, $order, $e->getMessage() );
		}

		// PayExit owns sessions.order_id once a buyer checks out (it links
		// the paid order and leaves the session active until payment
		// confirms), so a quote only ever fills an empty column.
		if ( $session->order_id <= 0 ) {
			$this->sessions->update( $session->id, [ 'order_id' => $order_id ] );
		}

		$this->audit->write(
			'quote_order_created',
			[
				'partner_id' => $partner->id,
				'session_id' => $session->id,
				'user_id'    => $session->user_id,
				'order_id'   => $order_id,
				'result'     => 'ok',
				'detail'     => [
					'lines'          => count( (array) ( $poom_lines['items'] ?? [] ) ),
					'total'          => (int) ( $poom_lines['total_cents'] ?? 0 ),
					'address_source' => $shipping['source'],
					'delivery_code'  => $shipping['code'],
				],
			]
		);

		return $order_id;
	}

	/**
	 * Store the basket document on the order once the build has produced
	 * it. Separate from creation because DESIGN §1 puts creation between
	 * the mapping and the build, so there is no XML to store yet.
	 *
	 * Swallows its own failures for the same reason creation does: this
	 * runs while the buyer's basket is on its way back, and the audit log
	 * already holds the document, so a save that fails costs the operator
	 * a convenience copy and nothing else.
	 */
	public function attach_poom( int $order_id, string $poom_xml ): void {
		try {
			$order = wc_get_order( $order_id );

			if ( ! $order instanceof \WC_Order ) {
				return;
			}

			$order->update_meta_data( self::META_POOM_XML, $poom_xml );
			$order->save();
		} catch ( \Throwable $e ) {
			$this->logger->error( 'Quote order basket could not be attached', [ 'order' => $order_id, 'error' => $e->getMessage() ] );
			$this->audit->write(
				'quote_poom_failed',
				[
					'order_id' => $order_id,
					'result'   => 'error',
					'detail'   => [ 'error' => $e->getMessage() ],
				]
			);
		}
	}

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
		if ( Status::SLUG !== $order->get_status() ) {
			return;
		}

		$status = $this->convert_status();

		$order->update_status(
			$status,
			sprintf(
				/* translators: %s: order status slug the quote was converted to */
				__( 'Punchout Quote converted to a live order (status: %s). The cXML basket returned to the buyer is still stored on this order.', 'punchout-woocommerce' ),
				$status
			)
		);

		$this->audit->write(
			'quote_order_converted',
			[
				'partner_id' => (int) $order->get_meta( self::META_PARTNER_ID ),
				'session_id' => (int) $order->get_meta( self::META_SESSION_ID ),
				'user_id'    => get_current_user_id(),
				'order_id'   => (int) $order->get_id(),
				'result'     => 'ok',
				'detail'     => [ 'status' => $status ],
			]
		);
	}

	/**
	 * The status a conversion targets, clamped to the three the settings
	 * screen offers. A saved value outside that set — an old option row,
	 * a filtered update, a hand-edited database — falls back to pending
	 * rather than moving the order somewhere that sends mail or moves
	 * stock unasked.
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
	 * Moving to cancelled restores no stock: wc_maybe_increase_stock_levels
	 * returns early unless the order's _reduced_stock flag is set, and a
	 * quote never reduced any (wc-stock-functions.php:136-155).
	 */
	public function expire(): int {
		$cutoff = self::retention_cutoff( $this->settings->int( 'quote_retention_days' ), time() );

		if ( '' === $cutoff || ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		$order_ids = wc_get_orders(
			[
				'status'       => Status::SLUG,
				'date_created' => '<' . $cutoff,
				'limit'        => 100,
				'return'       => 'ids',
				'orderby'      => 'date',
				'order'        => 'ASC',
			]
		);

		$moved = 0;

		foreach ( (array) $order_ids as $order_id ) {
			$order = wc_get_order( (int) $order_id );

			if ( ! $order ) {
				continue;
			}

			$order->update_status(
				'cancelled',
				sprintf(
					/* translators: %d: retention days */
					__( 'Cancelled automatically: this Punchout Quote was not converted within %d days. The returned basket is still stored on this order.', 'punchout-woocommerce' ),
					$this->settings->int( 'quote_retention_days' )
				)
			);

			$this->audit->write(
				'quote_order_expired',
				[
					'order_id'   => (int) $order_id,
					'session_id' => (int) $order->get_meta( self::META_SESSION_ID ),
					'partner_id' => (int) $order->get_meta( self::META_PARTNER_ID ),
					'result'     => 'retention',
				]
			);

			++$moved;
		}

		return $moved;
	}

	/**
	 * The quote this session already has, or 0.
	 *
	 * The meta check is what separates our own quote from the paid order
	 * PayExit links to the same column: that one carries _pow_session,
	 * never _pow_session_id.
	 */
	private function existing_quote( Session $session ): int {
		if ( $session->order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return 0;
		}

		$order = wc_get_order( $session->order_id );

		if ( ! $order instanceof \WC_Order || (int) $order->get_meta( self::META_SESSION_ID ) !== $session->id ) {
			return 0;
		}

		return (int) $order->get_id();
	}

	/**
	 * The failure path (DESIGN §6). Cancels whatever was already
	 * persisted, records the failure everywhere it belongs, and returns
	 * the 0 the caller treats as "no quote".
	 *
	 * @param \WC_Order|\WP_Error|null $order Whatever wc_create_order() left us with.
	 */
	private function fail( Session $session, Partner $partner, mixed $order, string $error ): int {
		$order_id = $order instanceof \WC_Order ? (int) $order->get_id() : 0;

		if ( $order_id > 0 ) {
			$this->cancel_part_built( $order, $error );
		}

		$this->logger->error( 'Quote order creation failed', [ 'session' => $session->id, 'order' => $order_id, 'error' => $error ] );

		$this->audit->write(
			'quote_order_failed',
			[
				'partner_id' => $partner->id,
				'session_id' => $session->id,
				'user_id'    => $session->user_id,
				'order_id'   => $order_id,
				'result'     => 'error',
				'detail'     => [
					'error'    => $error,
					'order_id' => $order_id,
				],
			]
		);

		$this->notify_failure( $session, $partner, $order_id, $error );

		return 0;
	}

	/**
	 * A part-built quote is cancelled, never deleted: cancelled keeps the
	 * evidence, keeps it out of the buyer's pay-for-order links, and
	 * restores no stock (a quote never reduced any).
	 */
	private function cancel_part_built( \WC_Order $order, string $error ): void {
		try {
			$order->add_order_note(
				sprintf(
					/* translators: %s: error message */
					__( 'Cancelled automatically: this Punchout Quote was left part-built by a failed punchout return (%s). Do not treat it as an order to fulfil — the buyer\'s basket went back normally and their purchasing system holds the real requisition.', 'punchout-woocommerce' ),
					$error
				)
			);
			$order->set_status( 'cancelled' );
			$order->save();
		} catch ( \Throwable $e ) {
			// Nothing left to try: say so loudly rather than throw into
			// the return the buyer is still waiting on.
			$this->logger->error( 'Part-built quote order could not be cancelled', [ 'order' => $order->get_id(), 'error' => $e->getMessage() ] );
		}
	}

	/**
	 * The WordPress side of the delivery-address rule: gather the three
	 * candidates, then let the pure resolve_shipping() pick.
	 *
	 * @return array{address: array<string, string>, code: string, source: string}
	 */
	private function shipping_for( Session $session, Partner $partner ): array {
		/**
		 * Filter the delivery address for a Punchout Quote order.
		 *
		 * The addresses feature hooks this and returns
		 * [ 'address' => WC-style array, 'code' => string ], or null to
		 * fall through to the inbound ShipTo and then the customer's own
		 * saved shipping address.
		 *
		 * @param array|null $address ['address' => array, 'code' => string] or null.
		 * @param Session    $session The returning session.
		 * @param Partner    $partner The customer connection.
		 */
		$filtered = apply_filters( 'pow_quote_shipping_address', null, $session, $partner );

		$inbound = null !== $session->ship_to ? self::address_from_ship_to( $session->ship_to ) : null;

		$customer = null;

		if ( function_exists( 'WC' ) && null !== WC()->customer ) {
			// A customer who has never saved a shipping address still has
			// the full set of empty fields; offering that as a candidate
			// would stamp a blank address on the order and record it as a
			// match, so only real values count.
			$saved = array_filter(
				(array) WC()->customer->get_shipping(),
				static fn ( $value ): bool => is_scalar( $value ) && '' !== trim( (string) $value )
			);

			if ( [] !== $saved ) {
				$customer = [
					'address' => $saved,
					'code'    => '',
				];
			}
		}

		return self::resolve_shipping( is_array( $filtered ) ? $filtered : null, $inbound, $customer );
	}

	/**
	 * Write the delivery address as order props rather than through the
	 * legacy set_address(): under HPOS the props are the columns, and an
	 * unknown key handed to set_address() becomes orphan post meta. Keys
	 * outside the shipping set are dropped here, deliberately and visibly.
	 *
	 * @param array<string, mixed> $address WC-style address fields.
	 */
	private function set_shipping( \WC_Order $order, array $address ): void {
		$props = [];

		foreach ( self::SHIPPING_FIELDS as $field ) {
			if ( isset( $address[ $field ] ) && is_scalar( $address[ $field ] ) && '' !== (string) $address[ $field ] ) {
				$props[ 'shipping_' . $field ] = (string) $address[ $field ];
			}
		}

		if ( [] !== $props ) {
			$order->set_props( $props );
		}
	}

	/**
	 * Add one POOM line to the order.
	 *
	 * The line is attached to the product it came from wherever that
	 * product still exists, so stock, reports and the order screen behave
	 * normally. When it does not — deleted between punchout and return, or
	 * a line that never carried an aux_id — the line is still added, bare
	 * but priced: a deleted product must not silently drop a line the
	 * buyer's system was quoted.
	 *
	 * @param \WC_Order            $order Order being built.
	 * @param array<string, mixed> $args  One line from line_args().
	 */
	private function add_line( \WC_Order $order, array $args ): void {
		$product_id = $args['variation_id'] > 0 ? $args['variation_id'] : $args['product_id'];
		$product    = ( $product_id > 0 && function_exists( 'wc_get_product' ) ) ? wc_get_product( $product_id ) : null;

		if ( $product ) {
			$order->add_product(
				$product,
				$args['quantity'],
				[
					'subtotal' => $args['subtotal'],
					'total'    => $args['total'],
				]
			);

			return;
		}

		$item = new \WC_Order_Item_Product();

		$item->set_name( '' !== $args['name'] ? $args['name'] : $args['sku'] );
		$item->set_quantity( $args['quantity'] );
		$item->set_subtotal( $args['subtotal'] );
		$item->set_total( $args['total'] );

		if ( '' !== $args['sku'] ) {
			// Visible line meta rather than protected: with no product
			// behind the line, this is the only place the part number the
			// buyer ordered against still shows on the order screen.
			$item->add_meta_data( 'SKU', $args['sku'], true );
		}

		$order->add_item( $item );
	}

	/**
	 * DESIGN §6: the operator hears about a failed quote order, but at most
	 * once an hour — a systematic failure would otherwise mail on every
	 * punchout return. The audit log carries every occurrence regardless.
	 */
	private function notify_failure( Session $session, Partner $partner, int $order_id, string $error ): void {
		$to = (string) get_option( 'admin_email' );

		if ( '' === $to || false !== get_transient( self::FAIL_NOTICE_KEY ) ) {
			return;
		}

		set_transient( self::FAIL_NOTICE_KEY, 1, HOUR_IN_SECONDS );

		$outcome = $order_id > 0
			? sprintf(
				/* translators: %d: order id */
				__( 'order #%d was left part-built and has been cancelled for you', 'punchout-woocommerce' ),
				$order_id
			)
			: __( 'no order was created', 'punchout-woocommerce' );

		wp_mail(
			$to,
			__( 'Punchout: a quote order could not be created', 'punchout-woocommerce' ),
			sprintf(
				/* translators: 1: connection name, 2: session id, 3: what happened to the order, 4: error message */
				__( 'A punchout return from %1$s (session #%2$d) went back to the buyer normally, but the Punchout Quote order could not be completed: %3$s. The error was: %4$s. Further failures in the next hour are logged but not emailed; see the punchout audit log.', 'punchout-woocommerce' ),
				$partner->name,
				$session->id,
				$outcome,
				$error
			)
		);
	}

	/**
	 * Order line arguments for one POOM line.
	 *
	 * aux_id is the correlation key PoomMapper writes —
	 * "product_id|variation_id" — and is what lets the line be attached to
	 * the right product without a second SKU lookup. It may legitimately
	 * be absent (a filtered line set, or an older session), in which case
	 * both ids are 0 and the caller falls back to a bare item.
	 *
	 * @param array<string, mixed> $poom_item One assembled POOM line.
	 * @return array{product_id: int, variation_id: int, quantity: float, subtotal: float, total: float, sku: string, name: string}
	 */
	public static function line_args( array $poom_item ): array {
		$parts      = explode( '|', (string) ( $poom_item['aux_id'] ?? '' ) );
		$quantity   = (float) ( $poom_item['quantity'] ?? 0 );
		$unit_cents = (int) ( $poom_item['unit_price_cents'] ?? 0 );

		// The line total is the quoted unit price times quantity, ex-tax:
		// the same arithmetic PoomMapper used for the document Total, so
		// the order and the POOM add up to the same number.
		$line_total = round( $unit_cents * $quantity / 100, 2 );

		return [
			'product_id'   => (int) $parts[0],
			'variation_id' => (int) ( $parts[1] ?? 0 ),
			'quantity'     => $quantity,
			'subtotal'     => $line_total,
			'total'        => $line_total,
			'sku'          => (string) ( $poom_item['supplier_part_id'] ?? '' ),
			'name'         => (string) ( $poom_item['description'] ?? '' ),
		];
	}

	/**
	 * Pick the delivery address, in the order fixed by the design: the
	 * pow_quote_shipping_address filter, then the ShipTo the buyer's
	 * system sent at setup, then the customer's own saved address.
	 *
	 * A candidate counts only when it carries an 'address' array. Anything
	 * else — a filter returning true, a string, a bare code — falls
	 * through to the next source rather than becoming a blank shipping
	 * address on a real order.
	 *
	 * @param array<string, mixed>|null $filtered Result of pow_quote_shipping_address.
	 * @param array<string, mixed>|null $inbound  Parsed inbound ShipTo.
	 * @param array<string, mixed>|null $customer WC()->customer shipping address.
	 * @return array{address: array<string, string>, code: string, source: string}
	 */
	public static function resolve_shipping( ?array $filtered, ?array $inbound, ?array $customer ): array {
		foreach ( [ 'filter' => $filtered, 'ship_to' => $inbound, 'customer' => $customer ] as $source => $candidate ) {
			if ( ! is_array( $candidate ) || ! isset( $candidate['address'] ) || ! is_array( $candidate['address'] ) ) {
				continue;
			}

			return [
				'address' => $candidate['address'],
				'code'    => (string) ( $candidate['code'] ?? '' ),
				'source'  => $source,
			];
		}

		return [
			'address' => [],
			'code'    => '',
			'source'  => 'none',
		];
	}

	/**
	 * Turn a stored cXML ShipTo fragment into a WooCommerce address.
	 *
	 * The fragment came in over the wire, so it is parsed under the same
	 * hard line Cxml\Parser draws: no entity declarations at all, and
	 * LIBXML_NONET so nothing can be fetched. Returns null — never an
	 * exception — when the fragment is unusable, because a malformed
	 * ShipTo must cost the buyer an address, not the whole order.
	 *
	 * @param string $xml Stored <ShipTo> fragment.
	 * @return array{address: array<string, string>, code: string}|null
	 */
	public static function address_from_ship_to( string $xml ): ?array {
		$xml = trim( $xml );

		// ext-dom is missing on some minimal PHP builds. Losing an address
		// is the documented failure here; a fatal inside order creation is
		// not, so the absence is treated as an unusable fragment.
		if ( '' === $xml || ! class_exists( '\DOMDocument' ) || false !== stripos( $xml, '<!ENTITY' ) ) {
			return null;
		}

		$previous = libxml_use_internal_errors( true );
		$doc      = new \DOMDocument();
		$loaded   = $doc->loadXML( $xml, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( false === $loaded || null === $doc->documentElement ) {
			return null;
		}

		$address = self::child( $doc->documentElement, 'Address' );

		if ( null === $address ) {
			return null;
		}

		$postal  = self::child( $address, 'PostalAddress' );
		$street  = null !== $postal ? self::children( $postal, 'Street' ) : [];
		$country = null !== $postal ? self::child( $postal, 'Country' ) : null;

		return [
			'code'    => $address->getAttribute( 'addressID' ),
			'address' => [
				// DeliverTo is the person or desk the goods are addressed
				// to; Name is the site. WooCommerce has no third line, so
				// they land on first_name and company respectively.
				'first_name' => null !== $postal ? self::text( $postal, 'DeliverTo' ) : '',
				'last_name'  => '',
				'company'    => self::text( $address, 'Name' ),
				'address_1'  => isset( $street[0] ) ? trim( $street[0]->textContent ) : '',
				'address_2'  => isset( $street[1] ) ? trim( $street[1]->textContent ) : '',
				'city'       => null !== $postal ? self::text( $postal, 'City' ) : '',
				'state'      => null !== $postal ? self::text( $postal, 'State' ) : '',
				'postcode'   => null !== $postal ? self::text( $postal, 'PostalCode' ) : '',
				// The ISO code, not the human name: WooCommerce stores
				// country as a two-letter code.
				'country'    => null !== $country ? $country->getAttribute( 'isoCountryCode' ) : '',
			],
		];
	}

	/**
	 * The datetime string a retention purge compares against, in UTC.
	 * A non-positive day count means retention is off, and returns ''.
	 */
	public static function retention_cutoff( int $days, int $now ): string {
		return $days > 0 ? gmdate( 'Y-m-d H:i:s', $now - $days * 86400 ) : '';
	}

	/**
	 * First direct child element with the given tag name, or null.
	 */
	private static function child( \DOMElement $parent, string $tag ): ?\DOMElement {
		foreach ( $parent->childNodes as $node ) {
			if ( $node instanceof \DOMElement && $node->tagName === $tag ) {
				return $node;
			}
		}

		return null;
	}

	/**
	 * Every direct child element with the given tag name, in order.
	 *
	 * @return list<\DOMElement>
	 */
	private static function children( \DOMElement $parent, string $tag ): array {
		$found = [];

		foreach ( $parent->childNodes as $node ) {
			if ( $node instanceof \DOMElement && $node->tagName === $tag ) {
				$found[] = $node;
			}
		}

		return $found;
	}

	/**
	 * Trimmed text content of a direct child, or '' when absent.
	 */
	private static function text( \DOMElement $parent, string $tag ): string {
		$node = self::child( $parent, $tag );

		return null !== $node ? trim( $node->textContent ) : '';
	}
}
