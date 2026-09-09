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

	/**
	 * One document against the DTD shipped in includes/Cxml/dtd. The
	 * DOCTYPE's SYSTEM identifier is rewritten to the local copy so
	 * nothing is fetched over the network.
	 *
	 * Shared by all four outbound samples: the page publishes them as what
	 * a buyer will receive, so "the POOM is valid" is not enough.
	 */
	private function assert_validates( string $sample, string $label ): void {
		$dtd = dirname( __DIR__, 2 ) . '/includes/Cxml/dtd/cXML-1.2.071.dtd';

		if ( ! is_readable( $dtd ) ) {
			self::markTestSkipped( 'shipped DTD not present' );
		}

		$xml = (string) preg_replace( '#SYSTEM "[^"]+"#', 'SYSTEM "' . $dtd . '"', $sample );

		$previous = libxml_use_internal_errors( true );
		$doc      = new DOMDocument();
		$doc->loadXML( $xml, LIBXML_DTDLOAD | LIBXML_DTDVALID );
		$errors = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$messages = array_map( static fn ( $e ): string => trim( $e->message ), $errors );

		self::assertSame( [], $messages, "The {$label} sample failed DTD validation:\n" . implode( "\n", $messages ) );
	}
}
