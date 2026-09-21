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

			$items[] = [
				'quantity'         => $quantity,
				'supplier_part_id' => $sku,
				// Durable line correlation: exact rebuild key if re-entry
				// is ever enabled, and it round-trips into any resulting
				// cXML PO (scope §6.2).
				'aux_id'           => (int) ( $cart_item['product_id'] ?? 0 ) . '|' . (int) ( $cart_item['variation_id'] ?? 0 ),
				'unit_price_cents' => $unit_cents,
				'description'      => $product->get_name(),
				'short_name'       => $product->get_name(),
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
