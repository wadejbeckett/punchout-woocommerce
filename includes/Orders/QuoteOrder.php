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

	/** Transient that throttles the failure notice to one an hour. */
	private const FAIL_NOTICE_KEY = 'pow_quote_fail_notice';

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
		try {
			$order = wc_create_order( [ 'customer_id' => $session->user_id ] );

			$order->set_currency( (string) ( $poom_lines['currency'] ?? get_woocommerce_currency() ) );

			foreach ( (array) ( $poom_lines['items'] ?? [] ) as $item ) {
				$this->add_line( $order, self::line_args( (array) $item ) );
			}

			$shipping = $this->shipping_for( $session, $partner );

			if ( [] !== $shipping['address'] ) {
				$order->set_address( $shipping['address'], 'shipping' );
			}

			$order->update_meta_data( self::META_SESSION_ID, $session->id );
			$order->update_meta_data( self::META_PARTNER_ID, $partner->id );
			$order->update_meta_data( self::META_DELIVERY_CODE, $shipping['code'] );

			// Totals without taxes: the basket quotes ex-tax unit prices
			// (PoomMapper), so a tax-inclusive order total would not be the
			// number the buyer's system received.
			$order->calculate_totals( false );

			// Status set last, so the one transition WooCommerce sees is
			// into punchout-quote — a status nothing in core binds stock or
			// mail to (wc-stock-functions.php:124-127, class-wc-emails.php:91).
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
			$this->logger->error( 'Quote order creation failed', [ 'session' => $session->id, 'error' => $e->getMessage() ] );
			$this->audit->write(
				'quote_order_failed',
				[
					'partner_id' => $partner->id,
					'session_id' => $session->id,
					'user_id'    => $session->user_id,
					'result'     => 'error',
					'detail'     => [ 'error' => $e->getMessage() ],
				]
			);
			$this->notify_failure( $session, $partner, $e->getMessage() );

			return 0;
		}

		$this->sessions->update( $session->id, [ 'order_id' => $order_id ] );

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
	 */
	public function attach_poom( int $order_id, string $poom_xml ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		$order->update_meta_data( self::META_POOM_XML, $poom_xml );
		$order->save();
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
			$customer = [
				'address' => WC()->customer->get_shipping(),
				'code'    => '',
			];
		}

		return self::resolve_shipping( is_array( $filtered ) ? $filtered : null, $inbound, $customer );
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
	 * @param \WC_Order                                                                                         $order Order being built.
	 * @param array{product_id: int, variation_id: int, quantity: float, subtotal: float, total: float, sku: string, name: string} $args  Line args.
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
	private function notify_failure( Session $session, Partner $partner, string $error ): void {
		$to = (string) get_option( 'admin_email' );

		if ( '' === $to || false !== get_transient( self::FAIL_NOTICE_KEY ) ) {
			return;
		}

		set_transient( self::FAIL_NOTICE_KEY, 1, HOUR_IN_SECONDS );

		wp_mail(
			$to,
			__( 'Punchout: a quote order could not be created', 'punchout-woocommerce' ),
			sprintf(
				/* translators: 1: connection name, 2: session id, 3: error message */
				__( 'A punchout return from %1$s (session #%2$d) went back to the buyer normally, but no Punchout Quote order was created: %3$s. Further failures in the next hour are logged but not emailed; see the punchout audit log.', 'punchout-woocommerce' ),
				$partner->name,
				$session->id,
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
