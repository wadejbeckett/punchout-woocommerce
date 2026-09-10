<?php
/**
 * Documentation samples come from the live codec, not from hand-written
 * XML: the inbound fixture is proved by the real Parser and the outbound
 * samples are produced by the real Builder, so the page cannot drift.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Cxml\SetupMessage;
use POW\Docs\Samples;
use POW\Http\SetupEndpoint;

require_once dirname( __DIR__ ) . '/Support/ExactCxmlDtd.php';

final class DocsSamplesTest extends TestCase {

	protected function setUp(): void {
		if ( ! extension_loaded( 'dom' ) ) {
			self::markTestSkipped( 'ext-dom not available' );
		}
	}

	public function test_fixture_setup_request_parses(): void {
		$message = Samples::parsed();

		self::assertSame( SetupMessage::KIND_SETUP, $message->kind );
		self::assertSame( 'create', $message->operation );
		self::assertSame( 'NetworkId', $message->sender_domain );
		self::assertNotSame( '', $message->browser_form_post );
		self::assertNotSame( '', $message->buyer_cookie );
	}

	public function test_inbound_example_is_neutral_and_valid_in_its_declared_dtd(): void {
		$this->assert_validates( Samples::setup_request(), 'setup_request' );
		self::assertStringContainsString( 'addressID="BUYER-001"', Samples::setup_request() );
		self::assertStringContainsString( 'EXAMPLE-BUYER-COOKIE', Samples::parsed()->buyer_cookie );
	}

	public function test_published_delivery_totals_are_derived_from_the_emitted_lines(): void {
		$examples = Samples::delivery_examples();
		self::assertSame( [ '1.2.008', '1.2.071' ], array_column( $examples, 'version' ) );
		foreach ( $examples as $example ) {
			$this->assert_validates( $example['xml'], 'published delivery ' . $example['version'] );
			self::assertSame( Samples::delivery_poom( $example['version'] ), $example['xml'] );
			$doc = new DOMDocument(); $doc->loadXML( $example['xml'], LIBXML_NONET );
			$xpath = new DOMXPath( $doc ); $sum = 0; $freight = 0;
			foreach ( $xpath->query( '//ItemIn' ) as $line ) {
				$cents = POW\Cxml\Money::to_cents( $xpath->evaluate( 'string(ItemDetail/UnitPrice/Money)', $line ) );
				$sum += (int) round( (float) $line->getAttribute( 'quantity' ) * $cents );
				if ( 'DELIVERY' === $xpath->evaluate( 'string(ItemID/SupplierPartID)', $line ) ) {
					++$freight;
					self::assertSame( '1', $line->getAttribute( 'quantity' ) );
					self::assertSame( $example['freight_total'], POW\Cxml\Money::format( $cents ) );
					self::assertSame( 0, $xpath->query( 'ItemDetail/Extrinsic', $line )->length );
				}
			}
			self::assertSame( 1, $freight );
			self::assertSame( $example['total'], POW\Cxml\Money::format( $sum ) );
			self::assertSame( $example['total'], $xpath->evaluate( 'string(//PunchOutOrderMessageHeader/Total/Money)' ) );
			self::assertSame( 0, $xpath->query( '//PunchOutOrderMessageHeader/Shipping' )->length );
		}
	}

	public function test_sample_flags_are_independent_and_respect_exact_version_capabilities(): void {
		$off = [ 'emit_ship_to' => false, 'emit_delivery_code' => false, 'emit_delivery_line' => false, 'delivery_notes_policy' => 'off' ];
		$unexpected_codes = [];
		foreach ( [ '1.2.008', '1.2.071' ] as $version ) {
			foreach ( [ [], [ 'emit_ship_to' => true ], [ 'emit_delivery_code' => true ], [ 'emit_delivery_line' => true ], [ 'delivery_notes_policy' => 'item_detail_extrinsic' ] ] as $on ) {
				$config = array_replace( $off, $on );
				$xml = Samples::delivery_poom( $version, $config );
				$this->assert_validates( $xml, 'independent flags ' . $version );
				$doc = new DOMDocument(); $doc->loadXML( $xml, LIBXML_NONET ); $xpath = new DOMXPath( $doc );
				self::assertSame( $config['emit_ship_to'] ? 1 : 0, $xpath->query( '//ShipTo' )->length );
				// Collect this mismatch so both exact dialects and every independent flag still execute.
				if ( 0 !== $xpath->query( '//Address/@addressID | //Address/@addressIDDomain' )->length ) { $unexpected_codes[] = $version . ': destination-only export leaked code attributes'; }
				self::assertSame( $config['emit_delivery_code'] && '1.2.071' === $version ? 2 : 0, $xpath->query( '//ItemIn/Extrinsic[@name="DeliveryAddressCode"]' )->length );
				self::assertSame( $config['emit_delivery_line'] ? 3 : 2, $xpath->query( '//ItemIn' )->length );
				self::assertSame( 'item_detail_extrinsic' === $config['delivery_notes_policy'] ? 2 : 0, $xpath->query( '//ItemDetail/Extrinsic[@name="DeliveryInstructions"]' )->length );
			}
			$doc = new DOMDocument(); $doc->loadXML( Samples::delivery_poom( $version ), LIBXML_NONET ); $xpath = new DOMXPath( $doc );
			self::assertSame( 'BUYER-001', $xpath->evaluate( 'string(//ShipTo/Address/@addressID)' ) );
			self::assertSame( '1.2.071' === $version ? 'supplier' : '', $xpath->evaluate( 'string(//ShipTo/Address/@addressIDDomain)' ) );
		}
		self::assertSame( [], $unexpected_codes );
	}

	public function test_annotations_are_read_off_the_parse_result(): void {
		$annotations = Samples::annotations();

		self::assertSame( Samples::parsed()->payload_id, $annotations['payloadID'] );
		self::assertSame( Samples::parsed()->browser_form_post, $annotations['BrowserFormPost/URL'] );
		self::assertArrayHasKey( 'Sender/Credential/Identity', $annotations );

		// Machine tokens only: the renderer translates, Samples does not.
		self::assertSame( 'present', $annotations['ShipTo'] );
	}

	public function test_fixture_carries_a_shared_secret_that_is_never_rendered(): void {
		self::assertStringContainsString( '<SharedSecret>', Samples::setup_request() );
		self::assertStringNotContainsString( 'SharedSecret', (string) json_encode( Samples::annotations() ) );
	}

	public function test_outbound_samples_are_real_builder_output(): void {
		self::assertStringContainsString( '<StartPage><URL>https://shop.example.com/punchout/start/', Samples::setup_response( 'https://shop.example.com/punchout/start/EXAMPLE-TOKEN' ) );
		self::assertStringContainsString( '<Status code="450" text="Not supported"/>', Samples::status( SetupEndpoint::STATUS_UNSUPPORTED ) );
		self::assertStringContainsString( '<Transaction requestName="PunchOutSetupRequest">', Samples::profile_response( 'https://shop.example.com/punchout/setup' ) );
	}

	public function test_poom_sample_has_no_shared_secret_and_two_lines(): void {
		$poom = Samples::poom();

		self::assertStringNotContainsString( 'SharedSecret', $poom );
		self::assertSame( 2, substr_count( $poom, '<ItemIn ' ) );
		self::assertStringContainsString( 'currency="ZAR"', $poom );

		// The prose total and the document's Total come off the same lines.
		self::assertStringContainsString( '<Total><Money currency="ZAR">' . Samples::poom_total() . '</Money></Total>', $poom );
		self::assertSame( '599.00', Samples::poom_total() );
	}

	/* ---------------------------------------------------------------------
	 * DESIGN §7: every outbound sample validates against the shipped DTD
	 * ------------------------------------------------------------------ */

	public function test_setup_response_sample_validates_against_the_shipped_dtd(): void {
		$this->assert_validates( Samples::setup_response( 'https://shop.example.com/punchout/start/EXAMPLE-TOKEN' ), 'setup_response' );
	}

	public function test_status_sample_validates_against_the_shipped_dtd(): void {
		$this->assert_validates( Samples::status( SetupEndpoint::STATUS_UNSUPPORTED ), 'status' );
	}

	public function test_profile_response_sample_validates_against_the_shipped_dtd(): void {
		$this->assert_validates( Samples::profile_response( 'https://shop.example.com/punchout/setup' ), 'profile_response' );
	}

	public function test_poom_sample_validates_against_the_shipped_dtd(): void {
		$this->assert_validates( Samples::poom(), 'poom' );
	}

	public function test_delivery_sample_uses_the_live_codec_and_exact_declared_dialect(): void {
		foreach ( [ '1.2.008', '1.2.071' ] as $version ) {
			$xml = Samples::delivery_poom( $version );
			self::assertSame( 3, substr_count( $xml, '<ItemIn ' ) );
			self::assertStringContainsString( '<Money currency="ZAR">634.00</Money>', $xml );
			self::assertStringContainsString( '<ShipTo>', $xml );
			self::assertStringContainsString( 'DeliveryInstructions', $xml );
			self::assertStringNotContainsString( 'SharedSecret', $xml );
			$this->assert_validates( $xml, 'delivery_poom ' . $version );
		}
	}

	/**
	 * Resolve only the unchanged official fixture for the exact declared version.
	 *
	 * Shared by all four outbound samples: the page publishes them as what
	 * a buyer will receive, so "the POOM is valid" is not enough.
	 */
	private function assert_validates( string $sample, string $label ): void {
		$result = ExactCxmlDtd::validate( $sample );
		self::assertTrue( $result['valid'], "The {$label} sample failed exact DTD validation: " . implode( '; ', $result['errors'] ) );
	}
}
