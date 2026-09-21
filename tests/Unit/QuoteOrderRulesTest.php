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
		foreach ( [ QuoteOrder::META_POOM_XML, QuoteOrder::META_SESSION_ID, QuoteOrder::META_PARTNER_ID, QuoteOrder::META_PARTNER_NAME, QuoteOrder::META_DELIVERY_CODE, QuoteOrder::META_BUYER_IDENTITY, QuoteOrder::META_BUYER_NAME, QuoteOrder::META_BUYER_COOKIE ] as $key ) {
			self::assertStringStartsWith( '_', $key, 'A leading underscore is what makes order meta protected' );
		}

		self::assertSame( '_pow_poom_xml', QuoteOrder::META_POOM_XML );
		self::assertSame( '_pow_session_id', QuoteOrder::META_SESSION_ID );
		// ORDER meta, not the buyer-to-connection USER meta of the same name: historical quotes are read through it.
		self::assertSame( '_pow_partner_id', QuoteOrder::META_PARTNER_ID );
		self::assertSame( '_pow_partner_name', QuoteOrder::META_PARTNER_NAME );
		self::assertSame( '_pow_delivery_code', QuoteOrder::META_DELIVERY_CODE );
		self::assertSame( '_pow_buyer_identity', QuoteOrder::META_BUYER_IDENTITY );
		self::assertSame( '_pow_buyer_name', QuoteOrder::META_BUYER_NAME );
		self::assertSame( '_pow_buyer_cookie', QuoteOrder::META_BUYER_COOKIE );
	}

	/**
	 * One sentence, three shapes, used verbatim in the order note and on the
	 * admin order screen. The anonymous case is worded rather than blank,
	 * because "Bought by  <>" reads as a bug in the shop.
	 */
	public function test_the_bought_by_sentence_names_whoever_the_purchasing_system_named(): void {
		self::assertSame( 'Bought by Zoë Buyer <zoe@buyer.example.test> via PunchOut (Example Buyer Company)', QuoteOrder::bought_by( 'Zoë Buyer', 'zoe@buyer.example.test', 'Example Buyer Company' ) );
		self::assertSame( 'Bought by zoe@buyer.example.test via PunchOut (Example Buyer Company)', QuoteOrder::bought_by( '', 'zoe@buyer.example.test', 'Example Buyer Company' ) );
		self::assertSame( 'Bought by Zoë Buyer via PunchOut (Example Buyer Company)', QuoteOrder::bought_by( 'Zoë Buyer', '', 'Example Buyer Company' ) );
		self::assertSame( 'Bought by an unnamed buyer via PunchOut (Example Buyer Company); the purchasing system sent no name or e-mail', QuoteOrder::bought_by( '', '', 'Example Buyer Company' ) );
	}

	/**
	 * The order line carries the price the basket quoted: unit cents from
	 * PoomMapper, times quantity, ex-tax.
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
		$inbound  = [ 'address' => [ 'city' => 'Durban' ], 'code' => 'BUYER-001' ];
		$customer = [ 'address' => [ 'city' => 'Pretoria' ], 'code' => '' ];

		self::assertSame( 'ship_to', QuoteOrder::resolve_shipping( $inbound, $customer )['source'] );
		self::assertSame( 'BUYER-001', QuoteOrder::resolve_shipping( $inbound, $customer )['code'] );
		self::assertSame( 'Durban', QuoteOrder::resolve_shipping( $inbound, $customer )['address']['city'] );

		self::assertSame( 'customer', QuoteOrder::resolve_shipping( null, $customer )['source'] );

		$none = QuoteOrder::resolve_shipping( null, null );

		self::assertSame( 'none', $none['source'] );
		self::assertSame( [], $none['address'] );
		self::assertSame( '', $none['code'] );
	}

	/**
	 * A candidate that is the wrong shape must not silently become the
	 * shipping address — it falls through to the next source.
	 */
	public function test_malformed_candidate_falls_through(): void {
		self::assertSame( 'customer', QuoteOrder::resolve_shipping( [ 'nonsense' => true ], [ 'address' => [ 'city' => 'Pretoria' ], 'code' => '' ] )['source'] );
		self::assertSame( 'none', QuoteOrder::resolve_shipping( [ 'nonsense' => true ], null )['source'] );
	}

	public function test_ship_to_xml_becomes_a_wc_address(): void {
		if ( ! extension_loaded( 'dom' ) ) {
			self::markTestSkipped( 'ext-dom not available' );
		}

		$xml = '<ShipTo><Address addressID="BUYER-001" addressIDDomain="supplier"><Name xml:lang="en">Head office</Name>'
			. '<PostalAddress name="default"><DeliverTo>Receiving</DeliverTo><Street>1 Example Road</Street>'
			. '<City>Johannesburg</City><State>GP</State><PostalCode>2196</PostalCode>'
			. '<Country isoCountryCode="ZA">South Africa</Country></PostalAddress></Address></ShipTo>';

		$parsed = QuoteOrder::address_from_ship_to( $xml );

		self::assertSame( 'BUYER-001', $parsed['code'] );
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
