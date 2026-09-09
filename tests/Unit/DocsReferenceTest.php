<?php
/**
 * The reference model: the status table documents exactly the codes the
 * endpoint emits, the "not supported" list is explicit, and the address
 * section renders whether or not delivery codes are switched on.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Docs\Reference;
use POW\Http\SetupEndpoint;

final class DocsReferenceTest extends TestCase {

	private function reference( bool $codes = false ): Reference {
		return new Reference( 'https://shop.example.com', 'DUNS', 'SUPPLIER-DUNS', $codes );
	}

	public function test_status_table_matches_the_endpoint_exactly(): void {
		self::assertSame(
			array_keys( SetupEndpoint::STATUS_REASONS ),
			array_keys( $this->reference()->status_rows() )
		);

		foreach ( $this->reference()->status_rows() as $code => $row ) {
			self::assertSame( SetupEndpoint::STATUS_REASONS[ $code ], $row['text'] );
			self::assertNotSame( '', $row['meaning'] );
			self::assertNotSame( '', $row['action'] );
		}
	}

	public function test_endpoints_are_absolute_and_under_punchout(): void {
		foreach ( $this->reference()->endpoints() as $url ) {
			self::assertStringStartsWith( 'https://shop.example.com/punchout/', $url );
		}
	}

	public function test_not_supported_list_is_explicit(): void {
		$text = strtolower( implode( ' | ', $this->reference()->not_supported() ) );

		foreach ( [ 'ariba', 'credentialmac', 'client certificate', 'signature' ] as $needle ) {
			self::assertStringContainsString( $needle, $text );
		}
	}

	public function test_address_fields_render_with_delivery_codes_off(): void {
		$fields = $this->reference( false )->address_fields();

		self::assertFalse( $this->reference( false )->delivery_codes_enabled() );
		self::assertNotSame( [], $fields );

		$names = array_column( $fields, 'field' );

		self::assertContains( 'ShipTo', $names );
		self::assertContains( 'Address/@addressID', $names );
		self::assertContains( 'Address/@addressIDDomain', $names );
		self::assertContains( 'ItemIn/Extrinsic name="LemaDeliveryCode"', $names );
	}

	public function test_links_point_at_the_three_reference_documents(): void {
		$links = array_values( $this->reference()->links() );

		self::assertContains( 'http://xml.cxml.org/current/cXMLUsersGuide.pdf', $links );
		self::assertContains( 'https://learn.microsoft.com/en-us/dynamics365/supply-chain/procurement/set-up-external-catalog-for-punchout', $links );
		self::assertContains( 'https://learn.microsoft.com/en-us/dynamics365/supply-chain/procurement/purchasing-cxml-enhancements', $links );
	}
}
