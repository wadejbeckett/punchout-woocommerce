<?php
/**
 * PunchOutOrderMessage: line mapping fidelity, empty/cancel variant with
 * SupplierOrderInfo, and the no-SharedSecret envelope rule — enforced by
 * test, not convention (scope §6.2/§10).
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Cart\PoomMapper;
use POW\Cxml\Builder;

final class PoomEnvelopeTest extends TestCase {

	private Builder $builder;

	protected function setUp(): void {
		if ( ! extension_loaded( 'dom' ) ) {
			self::markTestSkipped( 'ext-dom not available' );
		}

		$this->builder = new Builder();
	}

	/**
	 * @param list<array<string, mixed>> $items Line set.
	 * @param array<string, mixed>       $overrides Arg overrides.
	 */
	private function poom( array $items, array $overrides = [] ): string {
		return $this->builder->poom(
			array_merge(
				[
					'version'             => '1.2.008',
					'payload_id'          => 'poom-1@shop',
					'timestamp'           => '2026-08-31T09:00:00+00:00',
					'deployment_mode'     => 'test',
					// From/To reversed from the setup: we originate this.
					'from'                => [ 'domain' => 'DUNS', 'identity' => 'SUPPLIER-1' ],
					'to'                  => [ 'domain' => 'DUNS', 'identity' => 'ACME-ZA' ],
					'sender'              => [ 'domain' => 'DUNS', 'identity' => 'SUPPLIER-1' ],
					'buyer_cookie'        => 'Buyercookie admin 610',
					'currency'            => 'ZAR',
					'total_cents'         => array_sum( array_map( static fn( $i ) => (int) $i['unit_price_cents'] * (int) $i['quantity'], $items ) ),
					'supplier_order_info' => null,
					'items'               => $items,
				],
				$overrides
			)
		);
	}

	/** @return list<array<string, mixed>> */
	private function two_lines(): array {
		return [
			[
				'quantity'         => 2,
				'supplier_part_id' => 'CcSku-001a',
				'aux_id'           => '123|0',
				'unit_price_cents' => 1050,
				'description'      => 'Blue Overall — Café grade',
				'short_name'       => 'Blue Overall',
				'uom'              => 'EA',
				'classification'   => '53102500',
			],
			[
				'quantity'         => 1,
				'supplier_part_id' => 'CcSku-002b',
				'aux_id'           => '124|9',
				'unit_price_cents' => 25000,
				'description'      => 'Safety Boot',
				'short_name'       => 'Safety Boot',
				'uom'              => 'PR',
				'classification'   => '46181604',
			],
		];
	}

	public function test_full_poom_line_fidelity(): void {
		$xml = $this->poom( $this->two_lines() );

		// Well-formed, and D365's fixed post-back mapping fields all land.
		$doc = new DOMDocument();
		self::assertTrue( $doc->loadXML( $xml, LIBXML_NONET ) );

		self::assertStringContainsString( '<BuyerCookie>Buyercookie admin 610</BuyerCookie>', $xml );
		self::assertStringContainsString( '<PunchOutOrderMessageHeader operationAllowed="create">', $xml );
		self::assertStringContainsString( '<ItemIn quantity="2">', $xml );
		self::assertStringContainsString( '<SupplierPartID>CcSku-001a</SupplierPartID>', $xml );
		self::assertStringContainsString( '<SupplierPartAuxiliaryID>123|0</SupplierPartAuxiliaryID>', $xml );
		self::assertStringContainsString( '<Money currency="ZAR">10.50</Money>', $xml );
		// 2 x 10.50 + 1 x 250.00 = 271.00 (line cents: 2x1050 + 1x25000 = 27100).
		self::assertStringContainsString( '<Money currency="ZAR">271.00</Money>', $xml, 'header Total = sum of lines' );
		self::assertStringContainsString( '<UnitOfMeasure>EA</UnitOfMeasure>', $xml );
		self::assertStringContainsString( '<Classification domain="UNSPSC">53102500</Classification>', $xml );
		self::assertStringContainsString( '<ShortName>Blue Overall</ShortName>', $xml );
		self::assertSame( 2, substr_count( $xml, '<ItemIn ' ) );
		self::assertStringContainsString( 'deploymentMode="test"', $xml );
	}

	public function test_header_is_reversed_and_identity_only(): void {
		$xml = $this->poom( $this->two_lines() );

		self::assertStringContainsString( '<From><Credential domain="DUNS"><Identity>SUPPLIER-1</Identity></Credential></From>', $xml );
		self::assertStringContainsString( '<To><Credential domain="DUNS"><Identity>ACME-ZA</Identity></Credential></To>', $xml );
	}

	public function test_no_shared_secret_in_full_poom(): void {
		// The envelope rule the DTD spells out: authentication must not be
		// sent in browser-transported Messages. Enforced here by test.
		self::assertStringNotContainsString( 'SharedSecret', $this->poom( $this->two_lines() ) );
	}

	public function test_empty_poom_is_cancel_with_supplier_order_info(): void {
		$xml = $this->poom(
			[],
			[
				'total_cents'         => 0,
				'supplier_order_info' => [
					'order_id'   => '10423',
					'order_date' => '2026-08-31T09:05:00+00:00',
				],
			]
		);

		self::assertStringNotContainsString( '<ItemIn', $xml );
		self::assertStringContainsString( '<Money currency="ZAR">0.00</Money>', $xml );
		self::assertStringContainsString( '<SupplierOrderInfo orderID="10423" orderDate="2026-08-31T09:05:00+00:00"/>', $xml );
	}

	public function test_no_shared_secret_in_empty_poom(): void {
		self::assertStringNotContainsString( 'SharedSecret', $this->poom( [], [ 'total_cents' => 0 ] ) );
	}

	public function test_allcaps_transform_flows_into_the_document(): void {
		$on  = $this->poom( PoomMapper::uppercase_items( $this->two_lines() ) );
		$off = $this->poom( $this->two_lines() );

		self::assertStringContainsString( '<SupplierPartID>CCSKU-001A</SupplierPartID>', $on );
		self::assertStringContainsString( '<ShortName>BLUE OVERALL</ShortName>', $on );
		self::assertStringContainsString( '<SupplierPartID>CcSku-001a</SupplierPartID>', $off, 'mixed case preserved when the toggle is off' );
	}

	public function test_fractional_quantities_serialise_cleanly(): void {
		$items                = $this->two_lines();
		$items[0]['quantity'] = 2.5;
		$items[1]['quantity'] = 3.0;

		$xml = $this->poom( $items, [ 'total_cents' => 0 ] );

		self::assertStringContainsString( 'quantity="2.5"', $xml );
		self::assertStringContainsString( 'quantity="3"', $xml, 'whole floats emit as integers' );
	}
}
