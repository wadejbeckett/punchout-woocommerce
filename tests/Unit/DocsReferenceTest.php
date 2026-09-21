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
		self::assertContains( 'https://shop.example.com/punchout/confirm', array_values( $this->reference()->endpoints() ) );
	}

	public function test_documented_delivery_defaults_match_the_real_partner_contract(): void {
		$constructor = new ReflectionMethod( POW\Partners\Partner::class, '__construct' );
		$defaults = [];
		foreach ( $constructor->getParameters() as $parameter ) {
			if ( $parameter->isDefaultValueAvailable() ) { $defaults[$parameter->getName()] = $parameter->getDefaultValue(); }
		}
		$rows = $this->reference()->delivery_settings();
		foreach ( [ 'emit_ship_to', 'emit_delivery_code', 'emit_delivery_line', 'delivery_unknown_policy', 'delivery_notes_policy', 'delivery_code_prefix', 'delivery_code_extrinsic_name', 'freight_supplier_part_id', 'freight_uom', 'freight_classification_domain', 'freight_classification' ] as $key ) {
			self::assertArrayHasKey( $key, $rows );
			self::assertSame( $defaults[$key], $rows[$key]['default'] );
			self::assertNotSame( '', $rows[$key]['note'] );
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
		self::assertContains( 'ItemIn/Extrinsic name="DeliveryAddressCode"', $names );
		self::assertContains( 'ItemDetail/Extrinsic name="DeliveryInstructions"', $names );
	}

	public function test_links_point_at_the_three_reference_documents(): void {
		$links = array_values( $this->reference()->links() );

		self::assertContains( 'http://xml.cxml.org/current/cXMLUsersGuide.pdf', $links );
		self::assertContains( 'https://learn.microsoft.com/en-us/dynamics365/supply-chain/procurement/set-up-external-catalog-for-punchout', $links );
		self::assertContains( 'https://learn.microsoft.com/en-us/dynamics365/supply-chain/procurement/purchasing-cxml-enhancements', $links );
	}

	public function test_setup_template_steps_separate_static_connection_fields_from_runtime_values(): void {
		$text = implode( ' ', $this->reference()->setup_template_steps() );

		foreach ( [ 'From', 'Sender', 'To', 'SupplierSetup', 'SharedSecret', 'BuyerCookie', 'UserEmail', 'BrowserFormPost', 'runtime' ] as $term ) {
			self::assertStringContainsString( $term, $text );
		}
		self::assertStringContainsString( 'blank', $text );
		self::assertStringContainsString( 'separate', $text );
		self::assertStringContainsString( 'not the supplier setup URL', $text );
	}

	/**
	 * The supported list names every extrinsic the parser actually reads,
	 * for both the identity and the name, and says what they are for: the
	 * plugin records them, it does not turn them into store accounts.
	 */
	public function test_supported_list_names_every_identity_source_as_attribution(): void {
		$text = implode( ' ', $this->reference()->supported() );

		foreach ( [ 'UserEmail', 'UniqueUsername', 'UniqueName', 'Contact/Email', 'UserPrintableName', 'UserFullName' ] as $term ) {
			self::assertStringContainsString( $term, $text );
		}

		self::assertStringContainsString( 'attribution', $text );
		self::assertStringContainsString( 'one store account per connection', $text );
		self::assertStringContainsString( 'per punchout visit', $text );
		self::assertFalse( str_contains( strtolower( $text ), 'provision' ) );
		self::assertFalse( str_contains( strtolower( $text ), 'separate buyer account' ) );
	}

	/**
	 * The delivery workflow is admin-managed and visit-scoped. Nothing in
	 * it may describe an owner-facing screen, a provisioned buyer or a
	 * per-person login.
	 */
	public function test_delivery_workflow_is_admin_managed_and_visit_scoped(): void {
		$text = implode( ' ', $this->reference()->delivery_workflow() );

		self::assertStringContainsString( 'customer administration screen', $text );
		self::assertStringContainsString( 'punchout visit', $text );
		self::assertFalse( str_contains( strtolower( $text ), 'provision' ) );
		self::assertFalse( str_contains( $text, 'account integration' ) );
		self::assertFalse( str_contains( $text, 'signed-in buyer' ) );
		self::assertFalse( str_contains( $text, 'their own login and cart' ) );
	}

	/**
	 * The unbound connection and the open-visit cap both answer cXML 500.
	 * Neither clears on a retry, so the 500 copy may not tell a buyer to
	 * retry and stop there.
	 */
	public function test_the_internal_status_copy_admits_a_configuration_fault(): void {
		$row = $this->reference()->status_rows()[ SetupEndpoint::STATUS_INTERNAL ];

		self::assertStringContainsString( 'configur', $row['meaning'] );
		self::assertStringContainsString( 'store account', $row['meaning'] );
		self::assertStringContainsString( 'contact', strtolower( $row['action'] ) );
	}
}
