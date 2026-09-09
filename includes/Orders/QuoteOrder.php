<?php
/**
 * Punchout Quote orders.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * The WooCommerce order a punchout return leaves behind.
 *
 * This file holds the rules the order is built from, all of them pure and
 * unit-tested without WordPress:
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

		if ( '' === $xml || false !== stripos( $xml, '<!ENTITY' ) ) {
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
