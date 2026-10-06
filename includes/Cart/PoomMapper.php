<?php
/**
 * WC()->cart -> PunchOutOrderMessage line mapping.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Cart;

use POW\Cxml\Money;
use POW\Logger;
use POW\Partners\Partner;
use POW\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Maps the live cart to ItemIn line data (scope §6.2).
 *
 * The cart's line totals ARE the negotiated prices — pricing plugins
 * apply them at cart time via woocommerce_product_get_price,
 * which is exactly why the one-cart design exists: the POOM quotes the
 * same numbers the buyer would have paid at checkout.
 *
 * Unit price policy: the line total after discounts, excluding tax,
 * divided by quantity — D365 requisitions conventionally carry ex-tax unit
 * prices. It is not adjustable from outside. The prices and the line set a
 * buyer's system receives are the shop's own answer to what this basket
 * costs, and nothing may rewrite them between the cart and the document.
 */
final class PoomMapper {

	public function __construct(
		private Settings $settings,
		private Logger $logger,
	) {}

	/**
	 * Build POOM line data from the current cart.
	 *
	 * A line with no SKU is a catalogue-hygiene failure: skipped, logged,
	 * surfaced to the buyer before handoff (scope §9.4). Lines carry the
	 * raw store SKU — mapping to the buyer's internal part numbers is the
	 * buyer's own concern, and happens in the buyer's own system.
	 *
	 * @return array{
	 *   items: list<array<string, mixed>>,
	 *   total_cents: int,
	 *   currency: string,
	 *   skipped: list<string>,
	 * }
	 */
	public function from_cart( Partner $partner ): array {
		$items       = [];
		$skipped     = [];
		$total_cents = 0;
		$currency    = get_woocommerce_currency();

		$cart = WC()->cart;

		if ( null === $cart ) {
			return [
				'items'       => [],
				'total_cents' => 0,
				'currency'    => $currency,
				'skipped'     => [],
			];
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'] ?? null;

			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			$quantity = (float) ( $cart_item['quantity'] ?? 0 );

			if ( $quantity <= 0 ) {
				continue;
			}

			$sku = (string) $product->get_sku();

			if ( '' === $sku ) {
				$skipped[] = $product->get_name();
				$this->logger->warning( 'POOM line skipped: product has no SKU', [ 'product_id' => $product->get_id() ] );
				continue;
			}

			// Ex-tax line total after discounts; ONE conversion through the
			// Money boundary per line, unit price derived from it.
			$line_cents = Money::to_cents( (float) ( $cart_item['line_total'] ?? 0 ) );
			$unit_cents = (int) round( $line_cents / $quantity );

			// Total is accumulated from the EMITTED unit prices, not the raw
			// cart line totals: per-line rounding (100.00/3) would otherwise
			// make Total disagree with sum(UnitPrice x quantity) — the only
			// reconstruction a receiver can perform, since ItemIn carries no
			// extended amount.
			$total_cents += (int) round( $unit_cents * $quantity );

			$name = self::line_name( $product->get_name(), (array) ( $cart_item['variation'] ?? [] ) );

			$items[] = [
				'quantity'         => $quantity,
				'supplier_part_id' => $sku,
				// Durable line correlation: exact rebuild key if re-entry
				// is ever enabled, and it round-trips into any resulting
				// cXML PO (scope §6.2).
				'aux_id'           => (int) ( $cart_item['product_id'] ?? 0 ) . '|' . (int) ( $cart_item['variation_id'] ?? 0 ),
				'unit_price_cents' => $unit_cents,
				'description'      => $name,
				'short_name'       => $name,
				'uom'              => 'EA',
				'classification'   => (string) $this->settings->get( 'default_unspsc', '' ),
			];
		}

		// Per-partner ALL CAPS toggle: every text field pushed back to the
		// buyer's system is uppercased at serialisation time — an OUTBOUND
		// transform only; the stored Woo data is never mutated.
		if ( $partner->allcaps_transform ) {
			$items = self::uppercase_items( $items );
		}

		return [
			'items'       => $items,
			'total_cents' => $total_cents,
			'currency'    => $currency,
			'skipped'     => $skipped,
		];
	}

	/**
	 * The text a buyer's system shows for one line: the product's name,
	 * completed with the variation attributes the buyer chose.
	 *
	 * Purchasing systems map Description/ShortName to the requisition line
	 * and ignore SupplierPartAuxiliaryID, so two sizes of one shirt must
	 * differ in text. WooCommerce writes the values into a variation's title
	 * only sometimes — not with three or more attributes, and never a value
	 * chosen under "Any …", which exists only on the cart line — so each of
	 * the cart line's own values that the title does not already show is
	 * appended, in WooCommerce's own title format and with its own
	 * formatting and "already in the name" test. A simple product, and a
	 * variation whose title already names everything, are unchanged.
	 *
	 * @param array<string, mixed> $variation The cart line's `variation` map (attribute_* => value).
	 */
	public static function line_name( string $name, array $variation ): string {
		if ( [] === $variation || ! function_exists( 'wc_get_formatted_variation' ) || ! function_exists( 'wc_is_attribute_in_product_name' ) ) {
			return $name;
		}

		$shown   = false;
		$missing = [];

		foreach ( $variation as $key => $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			// One attribute at a time, so each value is judged on its own.
			$text = trim( wc_get_formatted_variation( [ (string) $key => (string) $value ], true, false ) );

			if ( '' === $text ) {
				continue;
			}

			if ( wc_is_attribute_in_product_name( $text, $name ) ) {
				$shown = true;
				continue;
			}

			$missing[] = $text;
		}

		if ( [] === $missing ) {
			return $name;
		}

		// "<name> - <values>" as WooCommerce titles a variation; when the
		// title already ends in such values, the rest join that list.
		return $name . ( $shown ? ', ' : ' - ' ) . implode( ', ', $missing );
	}

	/**
	 * Uppercase every text field of an ItemIn line set (SupplierPartID,
	 * descriptions, UOM, classification). Pure and static so the transform
	 * is unit-tested without WooCommerce; numeric fields (quantity, cents)
	 * and the aux_id correlation key pass through untouched.
	 *
	 * @param list<array<string, mixed>> $items Assembled POOM lines.
	 * @return list<array<string, mixed>>
	 */
	public static function uppercase_items( array $items ): array {
		$text_fields = [ 'supplier_part_id', 'description', 'short_name', 'uom', 'classification' ];

		foreach ( $items as $i => $item ) {
			foreach ( $text_fields as $field ) {
				if ( isset( $item[ $field ] ) && is_string( $item[ $field ] ) ) {
					$items[ $i ][ $field ] = function_exists( 'mb_strtoupper' )
						? mb_strtoupper( $item[ $field ], 'UTF-8' )
						: strtoupper( $item[ $field ] );
				}
			}
		}

		return $items;
	}
}
