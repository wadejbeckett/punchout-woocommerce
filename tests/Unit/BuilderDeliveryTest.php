<?php
/** Exact-dialect optional address, notes and freight serialization. */
declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Cxml\Builder;

require_once dirname( __DIR__ ) . '/Support/ExactCxmlDtd.php';

final class BuilderDeliveryTest extends TestCase {
	protected function setUp(): void {
		if ( ! extension_loaded( 'dom' ) ) { self::markTestSkipped( 'ext-dom not available' ); }
	}
	private function args( string $version = '1.2.008' ): array {
		$product = [ 'quantity' => 2, 'supplier_part_id' => 'DELIVERY', 'aux_id' => '12|0', 'unit_price_cents' => 12500, 'description' => 'Café & <product>', 'uom' => 'EA', 'classification_domain' => 'supplier', 'classification' => 'supplies' ];
		return [ 'version' => $version, 'payload_id' => 'sample@shop.example.test', 'timestamp' => '2026-09-09T10:00:00+00:00', 'from' => [ 'domain' => 'NetworkID', 'identity' => 'SUPPLIER' ], 'to' => [ 'domain' => 'NetworkID', 'identity' => 'BUYER' ], 'sender' => [ 'domain' => 'NetworkID', 'identity' => 'SUPPLIER' ], 'buyer_cookie' => 'unchanged-cookie', 'currency' => 'ZAR', 'total_cents' => 50000, 'items' => [ $product, array_replace( $product, [ 'supplier_part_id' => 'SECOND' ] ) ], 'ship_to' => [ 'name' => 'Receiving & <Company>', 'deliver_to' => [ 'Zoë O\'Brien' ], 'street' => [ '1 Example Road', 'Building <B> & C' ], 'city' => 'Cape Town', 'state' => 'WC', 'postal_code' => '8001', 'iso_country' => 'ZA' ], 'delivery_code' => 'BUYER-001', 'emit_ship_to' => true, 'emit_delivery_code' => true, 'delivery_notes' => "Handle <carefully> & call\nreceiving.", 'delivery_notes_policy' => 'item_detail_extrinsic' ];
	}
	private function xml( array $args ): array {
		$xml = ( new Builder() )->poom( $args );
		$doc = new DOMDocument();
		self::assertTrue( $doc->loadXML( $xml, LIBXML_NONET ) );
		return [ $xml, new DOMXPath( $doc ) ];
	}
	private function valid( string $xml, string $version ): void {
		$result = ExactCxmlDtd::validate( $xml );
		self::assertTrue( $result['valid'], implode( '; ', $result['errors'] ) );
		self::assertSame( [ 'http://xml.cxml.org/schemas/cXML/' . $version . '/cXML.dtd' ], $result['resolved'] );
	}
	public function test_old_dialect_address_and_notes_omit_unsupported_fields(): void {
		[ $xml, $path ] = $this->xml( $this->args() );
		self::assertSame( 1, $path->query( '//PunchOutOrderMessageHeader/ShipTo' )->length );
		self::assertSame( 'BUYER-001', $path->evaluate( 'string(//ShipTo/Address/@addressID)' ) );
		self::assertSame( 0, $path->query( '//Address/@addressIDDomain | //ItemIn/Extrinsic | //SupplierOrderInfo' )->length );
		self::assertSame( 2, $path->query( '//ItemDetail/Extrinsic[@name="DeliveryInstructions"]' )->length );
		self::assertSame( 'Handle <carefully> & call receiving.', $path->evaluate( 'string(//ItemDetail/Extrinsic)' ) );
		self::assertSame( 'Receiving & <Company>', $path->evaluate( 'string(//ShipTo/Address/Name)' ) );
		self::assertSame( 2, $path->query( '//PostalAddress/Street' )->length );
		self::assertSame( 'ZA', $path->evaluate( 'string(//Country/@isoCountryCode)' ) );
		self::assertSame( 0, $path->query( '//Header/Extrinsic | //PunchOutOrderMessageHeader/Shipping | //SharedSecret' )->length );
		$this->valid( $xml, '1.2.008' );
	}
	public function test_new_dialect_supports_independent_direct_code_and_address_domain(): void {
		$args = $this->args( '1.2.071' );
		$args['delivery_code_extrinsic_name'] = 'Destination & "Code"';
		[ $xml, $path ] = $this->xml( $args );
		self::assertSame( 'supplier', $path->evaluate( 'string(//ShipTo/Address/@addressIDDomain)' ) );
		self::assertSame( 2, $path->query( '//ItemIn/Extrinsic' )->length );
		self::assertSame( 'Destination & "Code"', $path->evaluate( 'string(//ItemIn/Extrinsic/@name)' ) );
		$this->valid( $xml, '1.2.071' );
		$args['emit_ship_to'] = false;
		[ $xml, $path ] = $this->xml( $args );
		self::assertSame( 0, $path->query( '//ShipTo' )->length );
		self::assertSame( 2, $path->query( '//ItemIn/Extrinsic' )->length );
		$this->valid( $xml, '1.2.071' );
	}
	public function test_full_address_does_not_require_a_code_in_either_dialect(): void {
		foreach ( [ '1.2.008', '1.2.071' ] as $version ) {
			$args = $this->args( $version ); $args['delivery_code'] = '';
			[ $xml, $path ] = $this->xml( $args );
			self::assertSame( 1, $path->query( '//ShipTo' )->length );
			self::assertSame( 0, $path->query( '//Address/@addressID | //Address/@addressIDDomain | //ItemIn/Extrinsic' )->length );
			$this->valid( $xml, $version );
		}
	}
	public function test_flags_are_off_by_default_and_notes_never_move_into_header(): void {
		$args = $this->args( '1.2.071' );
		unset( $args['emit_ship_to'], $args['emit_delivery_code'], $args['delivery_notes_policy'] );
		[ $xml, $path ] = $this->xml( $args );
		self::assertSame( 0, $path->query( '//ShipTo | //Extrinsic' )->length );
		$this->valid( $xml, '1.2.071' );
	}
	public function test_unverified_version_preserves_declaration_and_suppresses_optional_fields(): void {
		$args = $this->args( '1.2.070' );
		$args['supplier_order_info'] = [ 'order_id' => 'order-1', 'order_date' => '' ];
		[ $xml, $path ] = $this->xml( $args );
		self::assertStringContainsString( '/1.2.070/cXML.dtd', $xml );
		self::assertSame( 0, $path->query( '//ShipTo | //Extrinsic | //SupplierOrderInfo' )->length );
	}
	public function test_invalid_version_uses_same_fallback_for_capabilities_and_declaration(): void {
		$args = $this->args( '<bad>' );
		[ $xml, $path ] = $this->xml( $args );
		self::assertSame( 1, $path->query( '//ShipTo' )->length );
		self::assertSame( 0, $path->query( '//ItemIn/Extrinsic | //Address/@addressIDDomain' )->length );
		$this->valid( $xml, '1.2.008' );
	}
	public function test_freight_is_independent_of_address_flags_and_product_sku(): void {
		foreach ( [ '1.2.008', '1.2.071' ] as $version ) {
			$args = $this->args( $version );
			$args['emit_ship_to'] = false; $args['emit_delivery_code'] = false;
			$args['items'][] = [ 'line_type' => 'freight', 'quantity' => 1, 'supplier_part_id' => 'DELIVERY', 'aux_id' => '', 'unit_price_cents' => 3500, 'description' => 'Delivery estimate', 'uom' => 'EA', 'classification_domain' => 'supplier', 'classification' => 'freight' ];
			$args['total_cents'] += 3500;
			[ $xml, $path ] = $this->xml( $args );
			self::assertSame( 3, $path->query( '//ItemIn' )->length );
			self::assertSame( 2, $path->query( '//ItemDetail/Extrinsic' )->length, 'Product named DELIVERY retains instructions; typed freight does not.' );
			self::assertSame( 0, $path->query( '//ItemIn[3]//SupplierPartAuxiliaryID | //ItemIn[3]//Extrinsic | //ShipTo' )->length );
			self::assertSame( '535.00', $path->evaluate( 'string(//PunchOutOrderMessageHeader/Total/Money)' ) );
			self::assertSame( '35.00', $path->evaluate( 'string(//ItemIn[3]/ItemDetail/UnitPrice/Money)' ) );
			$this->valid( $xml, $version );
		}
	}
	public function test_closeout_reference_is_emitted_only_in_the_verified_new_dialect(): void {
		foreach ( [ '1.2.008', '1.2.071' ] as $version ) {
			$args = $this->args( $version ); $args['items'] = []; $args['total_cents'] = 0; $args['emit_ship_to'] = false;
			$args['supplier_order_info'] = [ 'order_id' => 'Q-1', 'order_date' => '2026-09-09T10:00:00+00:00' ];
			[ $xml, $path ] = $this->xml( $args );
			self::assertSame( '1.2.071' === $version ? 1 : 0, $path->query( '//SupplierOrderInfo' )->length );
			self::assertSame( 0, $path->query( '//ItemIn | //SharedSecret' )->length );
			$this->valid( $xml, $version );
		}
	}
	public function test_loader_refuses_an_unlisted_dtd_without_external_resolution(): void {
		[ $xml ] = $this->xml( $this->args( '1.2.070' ) );
		$result = ExactCxmlDtd::validate( $xml );
		self::assertFalse( $result['valid'] ); self::assertSame( [], $result['resolved'] );
	}
	public function test_unusable_code_does_not_discard_the_postal_destination(): void {
		$args = $this->args( '1.2.071' ); $args['delivery_code'] = 'not a code';
		[ $xml, $path ] = $this->xml( $args );
		self::assertSame( 1, $path->query( '//ShipTo' )->length );
		self::assertSame( 0, $path->query( '//Address/@addressID | //ItemIn/Extrinsic' )->length );
		$this->valid( $xml, '1.2.071' );
	}
	public function test_malformed_postal_values_and_invalid_xml_text_refuse(): void {
		foreach ( [ ['name'=>[]], ['street'=>[]], ['street'=>['']], ['iso_country'=>'South Africa'], ['city'=>"bad\0city"], ['name'=>"\xff"] ] as $change ) {
			$args = $this->args(); $args['ship_to'] = array_replace( $args['ship_to'], $change );
			try { ( new Builder() )->poom( $args ); self::fail( 'Malformed destination was serialized.' ); }
			catch ( DomainException $error ) { self::assertStringContainsString( 'Invalid delivery XML', $error->getMessage() ); }
		}
	}
	public function test_boundaries_preserve_full_native_person_name_and_refuse_oversized_notes(): void {
		$args = $this->args(); $args['ship_to']['name'] = str_repeat( 'a', 190 ) . ' ' . str_repeat( 'b', 190 );
		[ $xml, $path ] = $this->xml( $args );
		self::assertSame( $args['ship_to']['name'], $path->evaluate( 'string(//ShipTo/Address/Name)' ) );
		$this->valid( $xml, '1.2.008' );
		$args['delivery_notes'] = str_repeat( 'x', 2001 );
		self::expectException( DomainException::class );
		( new Builder() )->poom( $args );
	}
}
