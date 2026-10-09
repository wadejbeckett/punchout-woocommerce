<?php
/**
 * Placed order -> PunchOutOrderMessage builder input (0.5.0).
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Checkout;

use POW\Addresses\QuoteAddress;
use POW\Cart\PoomMapper;
use POW\Cxml\Builder;
use POW\Cxml\Money;
use POW\Partners\Partner;
use POW\Sessions\Session;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the unchanged builder's input (Cxml\Builder::poom()) from the order WooCommerce checkout placed, so what
 * the buyer's purchasing system receives is the order the store keeps.
 *
 * - Lines: the product's SKU, the product and variation ids as the auxiliary id, the order's quantity, and the
 *   line total after discounts and before tax divided by the quantity as the unit price (the same rule as the
 *   cart mapper). Total is the sum of what the lines say. A line whose product has no SKU is left out and named.
 * - Text: the product's name, completed with the variation values the buyer chose, as ShortName and description;
 *   capitals when the connection asks for them. UOM EA; the classification the caller passes.
 * - ShipTo: the order's shipping address, while the connection sends ShipTo and the address is usable.
 * - DeliveryInstructions: the buyer's order note, cleaned of characters cXML cannot carry and capped at 2,000.
 * - No freight line: the order's shipping line stays the store's estimate and never reaches the message.
 * - Envelope: From and To reversed from the setup, Sender identity only (no shared secret exists here), the visit's
 *   BuyerCookie and deployment mode, the connection's dialect, "create" only, no SupplierOrderInfo.
 */
final class OrderMessage {

	/** Characters the builder refuses in text (everything outside the XML 1.0 character range). */
	private const NOT_XML = '/[^\x{09}\x{0A}\x{0D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u';

	/** The builder's own limit for DeliveryInstructions. */
	public const NOTE_LIMIT = 2000;

	/** The fields of an address the cXML ShipTo mapping reads, in its order. */
	private const ADDRESS_FIELDS = [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' ];

	/**
	 * [ builder input, names of the lines left out for want of a SKU ].
	 *
	 * @return array{0: array<string, mixed>, 1: list<string>}
	 */
	public static function builder_args( \WC_Order $order, Partner $partner, Session $visit, string $classification, string $host ): array {
		[ $items, $total_cents, $skipped ] = self::lines( $order, $partner->allcaps_transform, $classification );

		return [
			[
				'version'                      => $partner->cxml_version,
				'payload_id'                   => Builder::payload_id( '' !== $host ? $host : 'localhost' ),
				'timestamp'                    => Builder::timestamp(),
				'deployment_mode'              => $visit->deployment_mode,
				// We originate this document: From and To are the setup's reversed, and Sender is identity only.
				'from'                         => [ 'domain' => $partner->to_domain, 'identity' => $partner->to_identity ],
				'to'                           => [ 'domain' => $partner->from_domain, 'identity' => $partner->from_identity ],
				'sender'                       => [ 'domain' => $partner->to_domain, 'identity' => $partner->to_identity ],
				'user_agent'                   => 'PunchOut for WooCommerce/' . ( defined( 'POW\\VERSION' ) ? \POW\VERSION : '' ),
				'buyer_cookie'                 => $visit->buyer_cookie,
				'operation_allowed'            => 'create',
				'currency'                     => (string) $order->get_currency(),
				'total_cents'                  => $total_cents,
				'supplier_order_info'          => null,
				'items'                        => $items,
				'ship_to'                      => self::ship_to( $order ),
				'emit_ship_to'                 => $partner->emit_ship_to,
				'emit_delivery_code'           => false,
				'delivery_code'                => '',
				'delivery_code_extrinsic_name' => $partner->delivery_code_extrinsic_name,
				'delivery_notes'               => self::note( (string) $order->get_customer_note() ),
				'delivery_notes_policy'        => $partner->delivery_notes_policy,
			],
			$skipped,
		];
	}

	/**
	 * The order's product lines as builder items.
	 *
	 * @return array{0: list<array<string, mixed>>, 1: int, 2: list<string>} items, total in cents, skipped names
	 */
	private static function lines( \WC_Order $order, bool $capitals, string $classification ): array {
		$items   = [];
		$skipped = [];
		$total   = 0;

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product  = $item->get_product();
			$quantity = (float) $item->get_quantity();
			if ( $quantity <= 0 ) { continue; }

			$sku = $product ? (string) $product->get_sku() : '';
			if ( '' === $sku ) {
				$skipped[] = $product ? $product->get_name() : (string) $item->get_name();
				continue;
			}

			// One conversion through the Money boundary per line; the unit price is derived from it, and the Total
			// adds up what the lines say (100.00 over 3 is 33.33 each and 99.99 in all).
			$line_cents = Money::to_cents( (float) $item->get_total() );
			$unit_cents = (int) round( $line_cents / $quantity );
			$total     += (int) round( $unit_cents * $quantity );

			$name = PoomMapper::line_name( $product->get_name(), self::variation( $item, $product ) );

			$items[] = [
				'quantity'         => $quantity,
				'supplier_part_id' => $sku,
				'aux_id'           => (int) $item->get_product_id() . '|' . (int) $item->get_variation_id(),
				'unit_price_cents' => $unit_cents,
				'description'      => $name,
				'short_name'       => $name,
				'uom'              => 'EA',
				'classification'   => $classification,
			];
		}

		return [ $capitals ? PoomMapper::uppercase_items( $items ) : $items, $total, $skipped ];
	}

	/**
	 * A variation line's attribute values in the cart's shape (attribute_* => value): the value the buyer chose,
	 * kept on the order line, or the variation's own.
	 *
	 * @return array<string, string>
	 */
	private static function variation( object $item, object $product ): array {
		if ( (int) $item->get_variation_id() <= 0 || ! method_exists( $product, 'get_variation_attributes' ) ) {
			return [];
		}
		$values = [];
		foreach ( (array) $product->get_variation_attributes() as $key => $value ) {
			$chosen         = $item->get_meta( str_replace( 'attribute_', '', (string) $key ) );
			$values[ $key ] = is_scalar( $chosen ) && '' !== (string) $chosen ? (string) $chosen : (string) $value;
		}
		return $values;
	}

	/**
	 * The ShipTo the builder takes, from the order's shipping address; null when the order has no usable one.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function ship_to( \WC_Order $order ): ?array {
		$stored  = (array) $order->get_address( 'shipping' );
		$address = [];
		foreach ( self::ADDRESS_FIELDS as $field ) {
			$value             = $stored[ $field ] ?? '';
			$address[ $field ] = is_scalar( $value ) ? trim( (string) $value ) : '';
		}
		if ( '' === $address['address_1'] . $address['address_2'] ) { return null; }
		try {
			return QuoteAddress::to_cxml( [ 'address' => $address ] );
		} catch ( \DomainException $error ) {
			return null;
		}
	}

	/** The buyer's note as cXML can carry it: characters outside XML removed, at most 2,000 characters. */
	public static function note( string $note ): string {
		$clean = preg_replace( self::NOT_XML, '', $note );
		$clean = trim( is_string( $clean ) ? $clean : '' );
		return mb_strlen( $clean ) > self::NOTE_LIMIT ? rtrim( mb_substr( $clean, 0, self::NOTE_LIMIT ) ) : $clean;
	}
}
