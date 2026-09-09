<?php
/**
 * The rules a Punchout Quote order is built from: line args taken from
 * the POOM lines (so order and basket cannot disagree), the shipping
 * address resolution order, and the retention cutoff.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Orders\QuoteOrder;

final class QuoteOrderRulesTest extends TestCase {

	public function test_meta_keys_are_protected(): void {
		foreach ( [ QuoteOrder::META_POOM_XML, QuoteOrder::META_SESSION_ID, QuoteOrder::META_PARTNER_ID, QuoteOrder::META_DELIVERY_CODE ] as $key ) {
			self::assertStringStartsWith( '_', $key, 'A leading underscore is what makes order meta protected' );
		}

		self::assertSame( '_pow_poom_xml', QuoteOrder::META_POOM_XML );
		self::assertSame( '_pow_session_id', QuoteOrder::META_SESSION_ID );
		self::assertSame( '_pow_partner_id', QuoteOrder::META_PARTNER_ID );
		self::assertSame( '_pow_delivery_code', QuoteOrder::META_DELIVERY_CODE );
	}

	/**
	 * The order line carries the price the basket quoted: unit cents from
	 * PoomMapper (post pow_poom_unit_price_cents), times quantity, ex-tax.
	 */
	public function test_line_args_carry_the_basket_price(): void {
		$args = QuoteOrder::line_args(
			[
				'quantity'         => 3,
				'supplier_part_id' => 'SKU-1001',
				'aux_id'           => '412|518',
				'unit_price_cents' => 12533,
				'description'      => 'Example product',
			]
		);

		self::assertSame( 412, $args['product_id'] );
		self::assertSame( 518, $args['variation_id'] );
		self::assertSame( 3.0, $args['quantity'] );
		self::assertSame( 375.99, $args['subtotal'] );
		self::assertSame( 375.99, $args['total'] );
		self::assertSame( 'SKU-1001', $args['sku'] );
	}

	public function test_line_args_tolerate_a_missing_aux_id(): void {
		$args = QuoteOrder::line_args( [ 'quantity' => 1, 'supplier_part_id' => 'SKU-9', 'aux_id' => '', 'unit_price_cents' => 100 ] );

		self::assertSame( 0, $args['product_id'] );
		self::assertSame( 0, $args['variation_id'] );
	}

	public function test_shipping_resolution_order(): void {
		$filtered = [ 'address' => [ 'city' => 'Cape Town' ], 'code' => 'LEMA-002' ];
		$inbound  = [ 'address' => [ 'city' => 'Durban' ], 'code' => 'LEMA-001' ];
		$customer = [ 'address' => [ 'city' => 'Pretoria' ], 'code' => '' ];

		self::assertSame( 'filter', QuoteOrder::resolve_shipping( $filtered, $inbound, $customer )['source'] );
		self::assertSame( 'LEMA-002', QuoteOrder::resolve_shipping( $filtered, $inbound, $customer )['code'] );

		self::assertSame( 'ship_to', QuoteOrder::resolve_shipping( null, $inbound, $customer )['source'] );
		self::assertSame( 'Durban', QuoteOrder::resolve_shipping( null, $inbound, $customer )['address']['city'] );

		self::assertSame( 'customer', QuoteOrder::resolve_shipping( null, null, $customer )['source'] );

		$none = QuoteOrder::resolve_shipping( null, null, null );

		self::assertSame( 'none', $none['source'] );
		self::assertSame( [], $none['address'] );
		self::assertSame( '', $none['code'] );
	}

	/**
	 * A filter result that is the wrong shape must not silently become the
	 * shipping address — it falls through to the next source.
	 */
	public function test_malformed_filter_result_falls_through(): void {
		self::assertSame( 'ship_to', QuoteOrder::resolve_shipping( [ 'nonsense' => true ], [ 'address' => [ 'city' => 'Durban' ], 'code' => '' ], null )['source'] );
	}

	public function test_ship_to_xml_becomes_a_wc_address(): void {
		if ( ! extension_loaded( 'dom' ) ) {
			self::markTestSkipped( 'ext-dom not available' );
		}

		$xml = '<ShipTo><Address addressID="LEMA-001" addressIDDomain="supplier"><Name xml:lang="en">Head office</Name>'
			. '<PostalAddress name="default"><DeliverTo>Receiving</DeliverTo><Street>1 Example Road</Street>'
			. '<City>Johannesburg</City><State>GP</State><PostalCode>2196</PostalCode>'
			. '<Country isoCountryCode="ZA">South Africa</Country></PostalAddress></Address></ShipTo>';

		$parsed = QuoteOrder::address_from_ship_to( $xml );

		self::assertSame( 'LEMA-001', $parsed['code'] );
		self::assertSame( 'Head office', $parsed['address']['company'] );
		self::assertSame( 'Receiving', $parsed['address']['first_name'] );
		self::assertSame( '1 Example Road', $parsed['address']['address_1'] );
		self::assertSame( 'Johannesburg', $parsed['address']['city'] );
		self::assertSame( 'GP', $parsed['address']['state'] );
		self::assertSame( '2196', $parsed['address']['postcode'] );
		self::assertSame( 'ZA', $parsed['address']['country'] );
	}

	public function test_unparseable_ship_to_is_null_not_an_exception(): void {
		if ( ! extension_loaded( 'dom' ) ) {
			self::markTestSkipped( 'ext-dom not available' );
		}

		self::assertNull( QuoteOrder::address_from_ship_to( 'not xml' ) );
		self::assertNull( QuoteOrder::address_from_ship_to( '<ShipTo/>' ) );
	}

	public function test_retention_cutoff(): void {
		$now = 1788858000; // 2026-09-08 09:00:00 UTC (gmmktime( 9, 0, 0, 9, 8, 2026 )).

		self::assertSame( '2026-06-10 09:00:00', QuoteOrder::retention_cutoff( 90, $now ) );
		self::assertSame( '', QuoteOrder::retention_cutoff( 0, $now ), 'Zero days disables retention' );
		self::assertSame( '', QuoteOrder::retention_cutoff( -5, $now ) );
	}
}
