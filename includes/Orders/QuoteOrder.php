<?php
/**
 * Punchout Quote orders.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Orders;

use POW\Addresses\QuoteAddress;
use POW\Addresses\DeliveryData;
use POW\Addresses\DeliveryEstimate;
use POW\Audit\Log;
use POW\Cxml\Money;
use POW\Logger;
use POW\Partners\Partner;
use POW\Sessions\Session;
use POW\Sessions\Store;
use POW\Settings;
use WC_Order_Item_Shipping;

defined( 'ABSPATH' ) || exit;

/**
 * The WooCommerce order a punchout return leaves behind.
 *
 * create_for_session() is the WordPress half: the endpoint prepares the XML and handoff, then claims the return before the winner optionally creates a quote from the same mapped lines and prices. Construction starts in native auto-draft and promotes only after completion; attach_poom() then stores the already-prepared XML. Quote failures cannot block the prepared handoff, and cancellation, logging, audit and notification are individually best effort.
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
 *   cents are the ones PoomMapper emitted, and they are ex-tax.
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
	public const META_DELIVERY      = '_pow_delivery';
	public const META_DELIVERY_NOTES = '_pow_delivery_notes';
	public const META_DELIVERY_CHOICE = '_pow_delivery_choice';
	public const META_DELIVERY_CONFIRMATION = '_pow_delivery_confirmation';

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

	/** Transient that throttles the failure notice to one an hour. */
	private const FAIL_NOTICE_KEY = 'pow_quote_fail_notice';

	/**
	 * The shipping fields WooCommerce stores as order props. Anything a
	 * filter or an inbound ShipTo offers outside this list is dropped
	 * rather than written, which is what keeps HPOS free of orphan meta.
	 */
	private const SHIPPING_FIELDS = [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' ];

	/** Request-local winner values survive the later XML attachment, without adding fields to the stored delivery schemas. */
	private array $prepared_quotes = [];

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
	 * @return int Order id, or 0 when creation failed (reporting is best effort).
	 */
	public function create_for_session( Session $session, Partner $partner, array $poom_lines ): int {
		// Keep the object for best-effort cleanup. Native auto-draft staging starts
		// at the first insert, so even a creation hook that throws before returning
		// the object cannot strand a payable order or an incomplete actionable quote.
		$order = null;
		$note_filter = null;

		try {
			// Sequential reuse only: the return endpoint selects its atomic winner
			// before entering this optional service.
			// Inside the try because the lookup touches the database, and
			// nothing in here may throw into the waiting basket.
			$existing = $this->existing_quote( $session );

			if ( $existing > 0 ) {
				$this->audit_best_effort(
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

			$order = wc_create_order( [ 'customer_id' => $session->user_id, 'status' => 'auto-draft' ] );

			if ( ! $order instanceof \WC_Order ) {
				// A creation failure can return WP_Error or throw from an extension.
				throw new \RuntimeException( 'quote_creation_failed' );
			}

			$staged = $order->get_id() > 0 ? wc_get_order( (int) $order->get_id() ) : false;

			if ( ! $staged instanceof \WC_Order || 'auto-draft' !== $staged->get_status() || 'auto-draft' !== $order->get_status() ) {
				throw new \RuntimeException( 'quote_staging_failed' );
			}

			$currency = (string) ( $poom_lines['currency'] ?? get_woocommerce_currency() );
			$order->set_currency( $currency );

			$prepared_lines = [];
			foreach ( (array) ( $poom_lines['items'] ?? [] ) as $item ) {
				$prepared_lines[] = $this->add_line( $order, self::line_args( (array) $item ) );
			}

			// The return winner owns these prepared values. An explicit null also suppresses all mutable legacy lookups; only callers lacking the key retain the original fallback.
			$has_destination = array_key_exists( 'delivery_destination', $poom_lines );
			$shipping = $has_destination
				? ( QuoteAddress::payload( $poom_lines['delivery_destination'] ) ?? [ 'address' => [], 'code' => '', 'source' => 'none' ] )
				: $this->shipping_for( $session, $partner );

			// An explicit null means no native shipping address, including any creation-hook defaults. Keep the prepared value separate from the mutable order for both readback checks.
			$prepared_shipping = $has_destination ? ( $shipping['address'] ?: array_fill_keys( self::SHIPPING_FIELDS, '' ) ) : null;
			if ( null !== $prepared_shipping || [] !== $shipping['address'] ) {
				$this->set_shipping( $order, $prepared_shipping ?? $shipping['address'], $has_destination );
			}

			// Stored as strings: that is what WooCommerce writes back after
			// a round trip through the meta store, so a reader comparing
			// values sees the same type whether the order was just built or
			// re-fetched.
			$order->update_meta_data( self::META_SESSION_ID, (string) $session->id );
			$order->update_meta_data( self::META_PARTNER_ID, (string) $partner->id );
			$order->update_meta_data( self::META_PARTNER_NAME, $partner->name );
			foreach ( self::attribution( $session ) as $key => $value ) { $order->update_meta_data( $key, $value ); }
			$order->update_meta_data( self::META_DELIVERY_CODE, $shipping['code'] );

			$delivery = null;
			if ( array_key_exists( 'delivery', $poom_lines ) ) {
				$delivery = $this->add_delivery( $order, $poom_lines, $shipping );
			}
			$provenance = $this->add_confirmation( $order, $session, $partner, $poom_lines );
			if ( null !== $provenance ) {
				// Woo's CPT store passes a raw excerpt to wp_update_post(), whose final unslashing otherwise removes confirmed backslashes. HPOS needs no transformation; its optional CPT sync can use this same narrowly scoped native boundary.
				$note_order_id = (int) $order->get_id();
				$notes = $provenance[ self::META_DELIVERY_NOTES ];
				$note_filter = static function ( array $data, array $postarr ) use ( $note_order_id, $notes ): array {
					if ( (int) ( $postarr['ID'] ?? 0 ) === $note_order_id ) { $data['post_excerpt'] = wp_slash( $notes ); }
					return $data;
				};
				add_filter( 'wp_insert_post_data', $note_filter, PHP_INT_MAX, 2 );
			}

			// Totals without taxes: the basket quotes ex-tax unit prices
			// (PoomMapper), so a tax-inclusive order total would not be the
			// number the buyer's system received.
			$order->calculate_totals( false );

			if ( null !== $prepared_shipping ) {
				$groups = $this->collect_verification_items( $order );
				if ( 'auto-draft' !== $order->get_status( 'edit' ) ) { throw new \RuntimeException( 'quote_staging_failed' ); }
				$this->verify_shipping( $order, $prepared_shipping );
				$this->verify_merchandise( $groups['line_items'], $prepared_lines );
			}

			if ( null !== $delivery ) {
				$this->verify_delivery( $order, $delivery, $poom_lines['total_cents'], $groups['shipping_lines'] );
			}
			if ( null !== $provenance ) { $this->verify_confirmation( $order, $provenance ); }

			// Promote only the completed construction. Both native stores defer their
			// new-order hook until this promotion; no payable intermediate status is
			// needed. Keep native conversion hooks unchanged for the later real order.
			$order->set_status( Status::SLUG );

			$order_id = (int) $order->save();

			// Woo may catch a save exception and still return the existing ID.
			$saved = $order_id > 0 ? wc_get_order( $order_id ) : false;

			if ( ! $saved instanceof \WC_Order || Status::SLUG !== $saved->get_status() || (int) $saved->get_meta( self::META_SESSION_ID ) !== $session->id ) {
				throw new \RuntimeException( 'quote_save_failed' );
			}
			if ( null !== $prepared_shipping ) {
				$groups = $this->collect_verification_items( $saved );
				if ( Status::SLUG !== $saved->get_status( 'edit' ) || (int) $saved->get_meta( self::META_SESSION_ID, true, 'edit' ) !== $session->id ) { throw new \RuntimeException( 'quote_save_failed' ); }
				$this->verify_shipping( $saved, $prepared_shipping );
				$this->verify_merchandise( $groups['line_items'], $prepared_lines );
			}
			if ( null !== $delivery ) {
				$this->verify_delivery( $saved, $delivery, $poom_lines['total_cents'], $groups['shipping_lines'] );
			}
			if ( null !== $provenance ) { $this->verify_confirmation( $saved, $provenance ); }
			if ( null !== $prepared_shipping ) {
				$this->prepared_quotes[ $order_id ] = [
					'lines' => $prepared_lines, 'shipping' => $prepared_shipping, 'delivery' => $delivery, 'provenance' => $provenance,
					'currency' => $currency, 'total' => (int) ( $poom_lines['total_cents'] ?? array_sum( array_column( $prepared_lines, 'total' ) ) ),
					'shipping_total' => null !== $delivery && $delivery['emit'] ? $delivery['amount_cents'] : 0,
					'note' => null !== $provenance ? $provenance[ self::META_DELIVERY_NOTES ] : $saved->get_customer_note( 'edit' ),
					'meta' => [ self::META_SESSION_ID => (string) $session->id, self::META_PARTNER_ID => (string) $partner->id, self::META_PARTNER_NAME => $partner->name, self::META_DELIVERY_CODE => $shipping['code'] ] + self::attribution( $session ),
				];
			}
		} catch ( \Throwable $e ) {
			return $this->fail( $session, $partner, $order, 'quote_creation_failed' );
		} finally {
			if ( null !== $note_filter ) { remove_filter( 'wp_insert_post_data', $note_filter, PHP_INT_MAX ); }
		}

		// Persistence succeeded. Linking and reporting may fail independently; neither
		// failure makes this usable quote part-built or eligible for cancellation.
		$link = 'already_owned';

		try {
			if ( $session->order_id <= 0 ) {
				$link = $this->sessions->link_quote_if_empty( $session->id, $order_id );
			}
		} catch ( \Throwable $e ) {
			$link = 'error';
		}

		if ( 'error' === $link ) {
			$this->error_best_effort( 'Quote order session link failed', [ 'session' => $session->id, 'order' => $order_id ] );
		}

		try {
			$noted = $order->add_order_note(
				sprintf(
					/* translators: 1: session id, 2: customer connection name, 3: address source, 4: the "Bought by ..." attribution sentence */
					__( 'Punchout Quote created from punchout session #%1$d (%2$s). Delivery address source: %3$s. %4$s.', 'punchout-woocommerce' ),
					$session->id,
					$partner->name,
					$shipping['source'],
					self::bought_by( (string) $session->buyer_name, (string) $session->buyer_identity, self::connection_label( $partner->name, $partner->id ) )
				)
			);

			if ( ! $noted ) {
				throw new \RuntimeException( 'quote_note_failed' );
			}
		} catch ( \Throwable $e ) {
			$this->error_best_effort( 'Quote order note could not be saved', [ 'order' => $order_id ] );
		}

		if ( null !== $delivery && ! $delivery['emit'] && 'not_required' !== $delivery['status'] ) {
			try {
				$note = null === $delivery['amount_cents']
					? __( 'Delivery estimate unavailable; no delivery charge included in this Quote.', 'punchout-woocommerce' )
					: sprintf( /* translators: 1: currency, 2: ex-tax delivery estimate */ __( 'Delivery estimate: %1$s %2$s (excluded from this Quote).', 'punchout-woocommerce' ), $delivery['currency'], Money::format( $delivery['amount_cents'] ) );
				if ( ! $order->add_order_note( $note ) ) { throw new \RuntimeException( 'quote_note_failed' ); }
			} catch ( \Throwable $e ) {
				$this->error_best_effort( 'Quote delivery estimate note could not be saved', [ 'order' => $order_id ] );
			}
		}

		$this->audit_best_effort(
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
					'session_link'   => $link,
					'address_source' => $shipping['source'],
					'delivery_code'  => $shipping['code'],
				],
			]
		);

		return $order_id;
	}

	/** Attach the winning basket document; both order and audit copies are best effort. */
	public function attach_poom( int $order_id, string $poom_xml ): void {
		$order = null;
		$invalid = false;
		try {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order || Status::SLUG !== $order->get_status() ) { throw new \RuntimeException( 'quote_missing' ); }
			// A legacy order whose baseline cannot be read is left untouched. A winner or stored confirmation already identifies required accepted state and must fail closed if that state is unreadable.
			$invalid = isset( $this->prepared_quotes[ $order_id ] ) || '' !== $order->get_meta( self::META_DELIVERY_CONFIRMATION, true, 'edit' );
			$state = $this->prepared_quotes[ $order_id ] ?? $this->attachment_snapshot( $order );
			$invalid = false;
			try {
				$this->verify_quote_state( $order, $state );
			} catch ( \Throwable $e ) {
				// Only an identified Quote with unverifiable required state is invalidated. XML-only persistence failures do not invalidate an otherwise verified Quote.
				$invalid = true;
				throw $e;
			}

			try {
				$order->update_meta_data( self::META_POOM_XML, $poom_xml );
				// This writes metadata through either native store without running another full order save. Metadata callbacks still require the same immutable readback guard.
				$order->save_meta_data();
			} finally {
				try {
					$this->verify_quote_state( $order, $state );
					$saved = wc_get_order( $order_id );
					if ( ! $saved instanceof \WC_Order ) { throw new \RuntimeException( 'quote_attachment_failed' ); }
					$this->verify_quote_state( $saved, $state );
				} catch ( \Throwable $e ) { $invalid = true; throw $e; }
			}
			if ( $poom_xml !== $saved->get_meta( self::META_POOM_XML, true, 'edit' ) ) { throw new \RuntimeException( 'quote_attachment_failed' ); }
		} catch ( \Throwable $e ) {
			$cancelled = $invalid && $order instanceof \WC_Order ? $this->cancel_part_built( $order ) : false;
			$this->error_best_effort( 'Quote order basket could not be attached', [ 'order' => $order_id ] );
			$this->audit_best_effort(
				'quote_poom_failed',
				[
					'order_id' => $order_id, 'result' => 'error',
					'detail' => [ 'error' => 'quote_attachment_failed', 'cancellation' => $invalid ? ( $cancelled ? 'confirmed' : 'unconfirmed' ) : 'not_needed' ],
				]
			);
		}
	}

	/** Preserve the legacy attachment API across requests. Existing confirmation metadata supplies the accepted destination/note/delivery; remaining native fields form the before-write baseline. */
	private function attachment_snapshot( \WC_Order $order ): array {
		$groups = $this->collect_verification_items( $order );
		$meta = [];
		foreach ( [ self::META_SESSION_ID, self::META_PARTNER_ID, self::META_PARTNER_NAME, self::META_DELIVERY_CODE, self::META_BUYER_IDENTITY, self::META_BUYER_NAME, self::META_BUYER_COOKIE ] as $key ) { $meta[ $key ] = $order->get_meta( $key, true, 'edit' ); }
		$shipping = [];
		foreach ( self::SHIPPING_FIELDS as $field ) { $getter = 'get_shipping_' . $field; $shipping[ $field ] = $order->{$getter}( 'edit' ); }
		$delivery = $order->get_meta( self::META_DELIVERY, true, 'edit' );
		$delivery = '' === $delivery ? null : $delivery;
		$provenance = [];
		foreach ( [ self::META_DELIVERY_CHOICE, self::META_DELIVERY_CONFIRMATION, self::META_DELIVERY_NOTES ] as $key ) { $provenance[ $key ] = $order->get_meta( $key, true, 'edit' ); }
		if ( array_filter( $provenance, static fn( $value ): bool => '' !== $value ) ) {
			$choice = 'null' === $provenance[ self::META_DELIVERY_CHOICE ] ? null : DeliveryData::choice( $provenance[ self::META_DELIVERY_CHOICE ], (int) $meta[ self::META_PARTNER_ID ] );
			// The stored confirmation's buyer_user_id is the connection's own
			// customer account, identical on every visit of that connection,
			// so this pair binds through META_SESSION_ID alone: one visit's
			// accepted destination cannot validate against another's order.
			// The customer id is still checked because a confirmation that
			// names a different account belongs to a different connection.
			$confirmation = DeliveryData::confirmation( $provenance[ self::META_DELIVERY_CONFIRMATION ], (int) $meta[ self::META_SESSION_ID ], (int) $order->get_customer_id( 'edit' ), $choice );
			if ( $confirmation['notes'] !== $provenance[ self::META_DELIVERY_NOTES ] || DeliveryData::fingerprint( $confirmation['delivery'] ) !== DeliveryData::fingerprint( $delivery ) ) { throw new \RuntimeException( 'quote_attachment_failed' ); }
			$shipping = QuoteAddress::payload( $choice )['address'] ?? array_fill_keys( self::SHIPPING_FIELDS, '' );
		} else { $provenance = null; }
		return [
			'lines' => $this->merchandise_snapshot( $groups['line_items'] ), 'shipping' => $shipping, 'delivery' => $delivery, 'provenance' => $provenance,
			'currency' => $order->get_currency( 'edit' ), 'total' => Money::to_cents( $order->get_total( 'edit' ) ),
			'shipping_total' => Money::to_cents( $order->get_shipping_total( 'edit' ) ), 'note' => $order->get_customer_note( 'edit' ), 'meta' => $meta,
		];
	}

	private function verify_quote_state( \WC_Order $order, array $state ): void {
		$groups = $this->collect_verification_items( $order );
		if ( Status::SLUG !== $order->get_status( 'edit' ) || $order->get_currency( 'edit' ) !== $state['currency'] || Money::to_cents( $order->get_total( 'edit' ) ) !== $state['total'] || Money::to_cents( $order->get_shipping_total( 'edit' ) ) !== $state['shipping_total'] || 0 !== Money::to_cents( $order->get_total_tax( 'edit' ) ) || $order->get_customer_note( 'edit' ) !== $state['note'] ) { throw new \RuntimeException( 'quote_attachment_failed' ); }
		foreach ( $state['meta'] as $key => $value ) { if ( $order->get_meta( $key, true, 'edit' ) !== $value ) { throw new \RuntimeException( 'quote_attachment_failed' ); } }
		$this->verify_shipping( $order, $state['shipping'] );
		$this->verify_merchandise( $groups['line_items'], $state['lines'] );
		if ( null !== $state['delivery'] ) { $this->verify_delivery( $order, $state['delivery'], $state['total'], $groups['shipping_lines'] ); }
		if ( null !== $state['provenance'] ) { $this->verify_confirmation( $order, $state['provenance'] ); }
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

		echo '<p class="pow-bought-by"><strong>' . esc_html__( 'PunchOut', 'punchout-woocommerce' ) . '</strong><br />' . esc_html( $this->bought_by_for( $order ) ) . '</p>';
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
	 * "Bought by  <>" reads as a fault in the shop instead of as a
	 * purchasing system that sent no identity.
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
		return sprintf( __( 'Bought by %1$s <%2$s> via PunchOut (%3$s)', 'punchout-woocommerce' ), $name, $identity, $connection );
	}

	/** Name the connection by the name it carried at the time, or by its id when it carried none. */
	private static function connection_label( string $name, int $id ): string {
		/* translators: %d: customer connection id */
		return '' !== $name ? $name : sprintf( __( 'connection #%d', 'punchout-woocommerce' ), $id );
	}

	/**
	 * The buyer meta one visit stamps on its quote, as strings.
	 *
	 * Session::buyer_identity and buyer_name are nullable because the column
	 * is, and an absent column and a stored empty string are the same thing
	 * here: nobody was named.
	 *
	 * @return array<string, string>
	 */
	private static function attribution( Session $session ): array {
		return [
			self::META_BUYER_IDENTITY => (string) $session->buyer_identity,
			self::META_BUYER_NAME     => (string) $session->buyer_name,
			self::META_BUYER_COOKIE   => $session->buyer_cookie,
		];
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
	 * The quote this visit already has, or 0.
	 *
	 * The meta check is not redundant with the column: sessions.order_id
	 * has historically been written by more than one producer, and only a
	 * row carrying META_SESSION_ID back at us is a quote this service
	 * built. An order that answers with a different visit is not this
	 * visit's quote and must not be reused as one.
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
	 * Attempt cancellation and reporting, then return 0 (no completed quote).
	 * A hook can fail after insertion but before returning the order object: no
	 * known ID does not prove no row exists. Abandoned auto-drafts remain subject
	 * to native CPT/HPOS cleanup after a week; they are not durable audit evidence.
	 *
	 * @param \WC_Order|\WP_Error|null $order Whatever wc_create_order() left us with.
	 */
	private function fail( Session $session, Partner $partner, mixed $order, string $error ): int {
		$order_id = $order instanceof \WC_Order ? (int) $order->get_id() : 0;
		$cancelled = $order_id > 0 && $this->cancel_part_built( $order );

		$this->error_best_effort( 'Quote order creation failed', [ 'session' => $session->id, 'order' => $order_id, 'error' => $error ] );
		$this->audit_best_effort(
			'quote_order_failed',
			[
				'partner_id' => $partner->id,
				'session_id' => $session->id,
				'user_id'    => $session->user_id,
				'order_id'   => $order_id,
				'result'     => 'error',
				'detail'     => [
					'error'        => $error,
					'order_id'     => $order_id,
					'cancellation' => $cancelled ? 'confirmed' : 'unconfirmed',
				],
			]
		);

		try {
			$this->notify_failure( $session, $partner, $order_id, $error, $cancelled );
		} catch ( \Throwable $e ) {
			$this->error_best_effort( 'Quote failure notification could not be sent', [ 'session' => $session->id, 'order' => $order_id ] );
		}

		return 0;
	}

	/** Cancel incomplete persistence before attempting its optional explanatory note. */
	private function cancel_part_built( \WC_Order $order ): bool {
		try {
			$order->set_status( 'cancelled' );

			if ( (int) $order->save() <= 0 ) {
				throw new \RuntimeException( 'quote_cancellation_failed' );
			}

			$saved = wc_get_order( (int) $order->get_id() );

			if ( ! $saved instanceof \WC_Order || 'cancelled' !== $saved->get_status() ) {
				throw new \RuntimeException( 'quote_cancellation_failed' );
			}
		} catch ( \Throwable $e ) {
			$this->error_best_effort( 'Part-built quote order cancellation not confirmed' );
			return false;
		}

		try {
			if ( ! $order->add_order_note( __( 'Cancelled automatically: this Punchout Quote was left part-built. Check the punchout session before treating it as an order to fulfil.', 'punchout-woocommerce' ) ) ) {
				throw new \RuntimeException( 'quote_note_failed' );
			}
		} catch ( \Throwable $e ) {
			$this->error_best_effort( 'Part-built quote cancellation note could not be saved' );
		}

		return true;
	}

	private function error_best_effort( string $message, array $context = [] ): void {
		try {
			$this->logger->error( $message, $context );
		} catch ( \Throwable $e ) {
			// Operational handlers are optional and may themselves throw.
		}
	}

	private function audit_best_effort( string $event, array $context ): void {
		try {
			if ( $this->audit->write_checked( $event, $context ) ) {
				return;
			}
		} catch ( \Throwable $e ) {
			// Contain third-party audit overrides as well as the default writer.
		}

		$this->error_best_effort( 'Quote audit persistence not confirmed', [ 'event' => $event, 'order' => $context['order_id'] ?? 0 ] );
	}

	/**
	 * The WordPress side of the delivery-address rule for a legacy caller:
	 * gather the two candidates, then let the pure resolve_shipping() pick.
	 *
	 * Reachable only when the caller supplied no 'delivery_destination'.
	 * A confirmed return always supplies one, so neither candidate here can
	 * overrule an accepted destination.
	 *
	 * @return array{address: array<string, string>, code: string, source: string}
	 */
	private function shipping_for( Session $session, Partner $partner ): array {
		$inbound = null !== $session->ship_to ? self::address_from_ship_to( $session->ship_to ) : null;

		$customer = null;

		// The live WC customer, which inside a visit is this visit's own
		// overlay of the shared profile: the basket the buyer just returned
		// is keyed by the per-visit session key, so no colleague's address
		// can be read here. A visit that supplied none leaves the profile's
		// own saved address as the last candidate, which is what a Quote
		// built without a confirmed destination has always done.
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

		return self::resolve_shipping( $inbound, $customer );
	}

	/**
	 * Write the delivery address as order props rather than through the
	 * legacy set_address(): under HPOS the props are the columns, and an
	 * unknown key handed to set_address() becomes orphan post meta. Keys
	 * outside the shipping set are dropped here, deliberately and visibly.
	 *
	 * @param array<string, mixed> $address WC-style address fields.
	 */
	private function set_shipping( \WC_Order $order, array $address, bool $include_empty = false ): void {
		$props = [];

		foreach ( self::SHIPPING_FIELDS as $field ) {
			// Prepared complete snapshots must also clear defaults supplied by creation hooks. Legacy partial addresses keep their existing nonempty-only behavior.
			if ( isset( $address[ $field ] ) && is_scalar( $address[ $field ] ) && ( $include_empty || '' !== (string) $address[ $field ] ) ) {
				$props[ 'shipping_' . $field ] = (string) $address[ $field ];
			}
		}

		if ( [] !== $props ) {
			if ( is_wp_error( $order->set_props( $props ) ) ) {
				throw new \RuntimeException( 'quote_shipping_failed' );
			}
		}
	}

	/** Reject callback or persistence changes without exposing the prepared address in diagnostics. */
	private function verify_shipping( \WC_Order $order, array $prepared ): void {
		foreach ( self::SHIPPING_FIELDS as $field ) {
			$getter = 'get_shipping_' . $field;
			if ( $order->{$getter}( 'edit' ) !== $prepared[ $field ] ) {
				throw new \RuntimeException( 'quote_shipping_save_failed' );
			}
		}
	}

	/** Store the complete prepared pair and already-confirmed plain notes. The caller's Session may still be its pre-claim ACTIVE snapshot. */
	private function add_confirmation( \WC_Order $order, Session $session, Partner $partner, array $mapped ): ?array {
		$keys = [ 'delivery_choice', 'delivery_confirmation', 'delivery_notes' ];
		if ( ! array_intersect( $keys, array_keys( $mapped ) ) ) { return null; }
		if ( array_diff( [ ...$keys, 'delivery', 'delivery_destination' ], array_keys( $mapped ) ) || $session->partner_id !== $partner->id || ! is_array( $mapped['delivery_confirmation'] ) || ! is_string( $mapped['delivery_notes'] ) ) { throw new \RuntimeException( 'quote_confirmation_invalid' ); }
		$choice_json = json_encode( $mapped['delivery_choice'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$confirmation_json = json_encode( $mapped['delivery_confirmation'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$choice = null === $mapped['delivery_choice'] ? null : DeliveryData::choice( $choice_json, $session->partner_id );
		// Same pair as attachment_snapshot() rebuilds: the session id is the
		// discriminator, the user id is the shared bound account. Who agreed
		// to this destination is answered by the buyer attribution meta, not
		// by buyer_user_id.
		$confirmation = DeliveryData::confirmation( $confirmation_json, $session->id, $session->user_id, $choice );
		$notes = $mapped['delivery_notes'];
		// Decoding must not silently upgrade/rewrite this winner's payload, and neither the native note nor its metadata may contain a different instruction.
		if ( DeliveryData::fingerprint( $choice ) !== DeliveryData::fingerprint( $mapped['delivery_choice'] ) || DeliveryData::fingerprint( QuoteAddress::payload( $choice ) ) !== DeliveryData::fingerprint( QuoteAddress::payload( $mapped['delivery_destination'] ) ) || DeliveryData::fingerprint( $confirmation['delivery'] ) !== DeliveryData::fingerprint( $mapped['delivery'] ) || $confirmation['notes'] !== $notes || sanitize_textarea_field( $notes ) !== $notes ) { throw new \RuntimeException( 'quote_confirmation_invalid' ); }
		// JSON preserves an explicit null choice across both Woo stores, where a null metadata value would otherwise read back as an empty string.
		$values = [ self::META_DELIVERY_CHOICE => $choice_json, self::META_DELIVERY_CONFIRMATION => $confirmation_json, self::META_DELIVERY_NOTES => $notes ];
		foreach ( $values as $key => $value ) { $order->update_meta_data( $key, $value ); }
		$order->set_customer_note( $notes );
		return $values;
	}

	/** Check exact persisted bytes, including blank/multiline notes; a silent truncate is an optional Quote failure. */
	private function verify_confirmation( \WC_Order $order, array $values ): void {
		foreach ( $values as $key => $value ) {
			if ( $order->get_meta( $key, true, 'edit' ) !== $value ) { throw new \RuntimeException( 'quote_confirmation_save_failed' ); }
		}
		if ( $order->get_customer_note( 'edit' ) !== $values[ self::META_DELIVERY_NOTES ] ) { throw new \RuntimeException( 'quote_confirmation_save_failed' ); }
	}

	/** Consume only the winner's prepared snapshot. Current rates and policy belong to pre-claim confirmation. */
	private function add_delivery( \WC_Order $order, array $mapped, array $shipping ): array {
		$delivery = $mapped['delivery'];
		if ( ! is_array( $delivery ) || ! array_key_exists( 'delivery_destination', $mapped ) ) { throw new \RuntimeException( 'quote_delivery_invalid' ); }
		$freight = DeliveryEstimate::poom_line( $delivery );
		if ( $delivery['currency'] !== ( $mapped['currency'] ?? null ) || $delivery['code'] !== $shipping['code'] || ( 'not_required' !== $delivery['status'] && [] === $shipping['address'] ) ) { throw new \RuntimeException( 'quote_delivery_invalid' ); }
		$charge = null === $freight ? 0 : $delivery['amount_cents'];
		$merchandise = 0;
		foreach ( (array) ( $mapped['items'] ?? [] ) as $line ) {
			// A merchandise SKU named DELIVERY is still merchandise. The wire-only freight line must never also be supplied in this list.
			if ( 'merchandise' !== ( $line['line_type'] ?? 'merchandise' ) ) { throw new \RuntimeException( 'quote_delivery_invalid' ); }
			$cents = Money::to_cents( self::line_args( $line )['total'] );
			if ( $cents < 0 || $merchandise > PHP_INT_MAX - $cents ) { throw new \RuntimeException( 'quote_delivery_invalid' ); }
			$merchandise += $cents;
		}
		if ( ( null !== $freight && empty( $mapped['items'] ) ) || $merchandise > PHP_INT_MAX - $charge || ! is_int( $mapped['total_cents'] ?? null ) || $mapped['total_cents'] !== $merchandise + $charge ) { throw new \RuntimeException( 'quote_delivery_invalid' ); }
		if ( null !== $freight ) {
			foreach ( $delivery['rates'] as $rate ) {
				$item = new WC_Order_Item_Shipping();
				$item->set_method_title( $rate['label'] );
				$item->set_method_id( $rate['method_id'] );
				$item->set_instance_id( $rate['instance_id'] );
				$item->set_total( Money::format( $rate['amount_cents'] ) );
				// Native estimated taxes remain in protected snapshot metadata; this Quote and its outgoing freight are ex-tax.
				$item->set_taxes( [ 'total' => [] ] );
				$item->add_meta_data( '_pow_package_key', (string) $rate['package_key'], true );
				$item->add_meta_data( '_pow_rate_id', $rate['rate_id'], true );
				if ( false === $order->add_item( $item ) ) { throw new \RuntimeException( 'quote_delivery_item_failed' ); }
			}
		}
		$order->update_meta_data( self::META_DELIVERY, $delivery );
		return $delivery;
	}

	/** Refuse a hook/store that changes or loses the accepted charge, both before promotion and after native readback. */
	private function verify_delivery( \WC_Order $order, array $delivery, int $total, array $items ): void {
		$stored = $order->get_meta( self::META_DELIVERY, true, 'edit' );
		$charge = $delivery['emit'] ? $delivery['amount_cents'] : 0;
		if ( $stored !== $delivery || $order->get_currency( 'edit' ) !== $delivery['currency'] || Money::to_cents( $order->get_shipping_total( 'edit' ) ) !== $charge || Money::to_cents( $order->get_total( 'edit' ) ) !== $total || 0 !== Money::to_cents( $order->get_total_tax( 'edit' ) ) ) { throw new \RuntimeException( 'quote_delivery_save_failed' ); }
		$expected = [];
		foreach ( $delivery['emit'] ? $delivery['rates'] : [] as $rate ) { $expected[ (string) $rate['package_key'] ] = $rate; }
		if ( count( $items ) !== count( $expected ) ) { throw new \RuntimeException( 'quote_delivery_save_failed' ); }
		foreach ( $items as $item ) {
			if ( ! $item instanceof WC_Order_Item_Shipping ) { throw new \RuntimeException( 'quote_delivery_save_failed' ); }
			$key = (string) $item->get_meta( '_pow_package_key', true, 'edit' );
			$rate = $expected[ $key ] ?? null;
			if ( null === $rate || $item->get_meta( '_pow_rate_id', true, 'edit' ) !== $rate['rate_id'] || $item->get_method_title( 'edit' ) !== $rate['label'] || $item->get_method_id( 'edit' ) !== $rate['method_id'] || (int) $item->get_instance_id( 'edit' ) !== $rate['instance_id'] || Money::to_cents( $item->get_total( 'edit' ) ) !== $rate['amount_cents'] || 0 !== Money::to_cents( $item->get_total_tax( 'edit' ) ) || [ 'total' => [] ] !== $item->get_taxes( 'edit' ) ) { throw new \RuntimeException( 'quote_delivery_save_failed' ); }
			unset( $expected[ $key ] );
		}
	}

	/** Finish native collection filters and lazy metadata loads before comparing accepted edit-context values. */
	private function collect_verification_items( \WC_Order $order ): array {
		$groups = [ 'line_items' => $order->get_items( 'line_item' ), 'shipping_lines' => $order->get_items( 'shipping' ) ];
		$order->get_meta_data();
		foreach ( $groups as $items ) {
			foreach ( $items as $item ) { $item->get_meta_data(); }
		}
		// Woo has no public unfiltered collection getter. Read its already-loaded protected cache without callbacks: keys and object identity must still match both collected groups. No vendor state is written, and an unsupported cache layout fails this optional Quote closed.
		$native = get_mangled_object_vars( $order )[ "\0*\0items" ] ?? null;
		foreach ( $groups as $key => $items ) {
			$current = is_array( $native ) ? ( $native[ $key ] ?? null ) : null;
			if ( ! is_array( $current ) ) { throw new \RuntimeException( 'quote_items_read_failed' ); }
			ksort( $items );
			ksort( $current );
			if ( $current !== $items ) { throw new \RuntimeException( 'quote_items_read_failed' ); }
		}
		return $groups;
	}

	/** A multiset preserves duplicate lines and count while allowing native item ordering. Live product identity is its mapped product/variation pair; a deleted product retains its fallback name and SKU. */
	private function merchandise_snapshot( array $items ): array {
		$lines = [];
		foreach ( $items as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) { throw new \RuntimeException( 'quote_lines_save_failed' ); }
			$product_id = (int) $item->get_product_id( 'edit' );
			$variation_id = (int) $item->get_variation_id( 'edit' );
			$bare = 0 === $product_id && 0 === $variation_id;
			$lines[] = [
				'product_id' => $product_id, 'variation_id' => $variation_id,
				'quantity' => (float) $item->get_quantity( 'edit' ),
				'subtotal' => Money::to_cents( $item->get_subtotal( 'edit' ) ),
				'total' => Money::to_cents( $item->get_total( 'edit' ) ),
				'sku' => $bare ? $item->get_meta( 'SKU', true, 'edit' ) : null,
				'name' => $bare ? $item->get_name( 'edit' ) : null,
			];
		}
		return $lines;
	}

	private function verify_merchandise( array $items, array $prepared ): void {
		$actual = $this->merchandise_snapshot( $items );
		sort( $actual );
		sort( $prepared );
		if ( $actual !== $prepared ) { throw new \RuntimeException( 'quote_lines_save_failed' ); }
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
	 * @return array<string, mixed> Prepared native identity and amounts, independent of callbacks.
	 */
	private function add_line( \WC_Order $order, array $args ): array {
		$product_id = $args['variation_id'] > 0 ? $args['variation_id'] : $args['product_id'];
		$product    = ( $product_id > 0 && function_exists( 'wc_get_product' ) ) ? wc_get_product( $product_id ) : null;

		$expected = [
			'product_id' => $product ? $args['product_id'] : 0,
			'variation_id' => $product ? $args['variation_id'] : 0,
			'quantity' => $args['quantity'],
			'subtotal' => Money::to_cents( $args['subtotal'] ),
			'total' => Money::to_cents( $args['total'] ),
			'sku' => $product ? null : $args['sku'],
			'name' => $product ? null : ( '' !== $args['name'] ? $args['name'] : $args['sku'] ),
		];

		if ( $product ) {
			$item_id = $order->add_product(
				$product,
				$args['quantity'],
				[
					'subtotal' => $args['subtotal'],
					'total'    => $args['total'],
				]
			);

			if ( ! $item_id ) {
				throw new \RuntimeException( 'quote_line_failed' );
			}

			return $expected;
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

		if ( false === $order->add_item( $item ) ) {
			throw new \RuntimeException( 'quote_line_failed' );
		}
		return $expected;
	}

	/**
	 * DESIGN §6: the operator hears about a failed quote order, but at most
	 * once an hour — a systematic failure would otherwise mail on every
	 * punchout return. Each occurrence attempts an independent audit write.
	 */
	private function notify_failure( Session $session, Partner $partner, int $order_id, string $error, bool $cancelled ): void {
		$to = (string) get_option( 'admin_email' );

		if ( '' === $to || false !== get_transient( self::FAIL_NOTICE_KEY ) ) {
			return;
		}

		$outcome = $order_id > 0
			? sprintf(
				/* translators: %d: order id */
				$cancelled
					? __( 'order #%d was left part-built and has been cancelled for you', 'punchout-woocommerce' )
					: __( 'order #%d was left part-built; cancellation is not confirmed and needs checking', 'punchout-woocommerce' ),
				$order_id
			)
			: __( 'no order ID was returned; initial persistence and cancellation are unconfirmed', 'punchout-woocommerce' );

		$sent = wp_mail(
			$to,
			__( 'Punchout: a quote order could not be created', 'punchout-woocommerce' ),
			sprintf(
				/* translators: 1: connection name, 2: session id, 3: what happened to the order, 4: error message */
				__( 'A punchout return from %1$s (session #%2$d) is being returned, but the Punchout Quote order could not be completed: %3$s. The error was: %4$s. Further notifications are limited for the next hour. Check the order and available punchout logs; diagnostic persistence is not guaranteed.', 'punchout-woocommerce' ),
				$partner->name,
				$session->id,
				$outcome,
				$error
			)
		);

		if ( ! $sent ) {
			throw new \RuntimeException( 'quote_notification_failed' );
		}

		if ( ! set_transient( self::FAIL_NOTICE_KEY, 1, HOUR_IN_SECONDS ) ) {
			$this->error_best_effort( 'Quote failure notification throttle could not be saved', [ 'session' => $session->id ] );
		}
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
	 * ShipTo the buyer's system sent at setup, then the customer's own
	 * saved address. There is no third, outside candidate: what a quote
	 * ships to is the shop's own answer, taken from the request or from the
	 * account, and a confirmed return has already decided it.
	 *
	 * A candidate counts only when it carries an 'address' array. Anything
	 * else — true, a string, a bare code — falls through to the next source
	 * rather than becoming a blank shipping address on a real order.
	 *
	 * @param array<string, mixed>|null $inbound  Parsed inbound ShipTo.
	 * @param array<string, mixed>|null $customer WC()->customer shipping address.
	 * @return array{address: array<string, string>, code: string, source: string}
	 */
	public static function resolve_shipping( ?array $inbound, ?array $customer ): array {
		foreach ( [ 'ship_to' => $inbound, 'customer' => $customer ] as $source => $candidate ) {
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
