<?php
/**
 * Outbound builder: SetupResponse, Status, ProfileResponse envelopes and
 * the D365 parser fragilities (no newlines in content, clean URLs).
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Cxml\Builder;

final class BuilderTest extends TestCase {

	private Builder $builder;

	protected function setUp(): void {
		if ( ! extension_loaded( 'dom' ) ) {
			self::markTestSkipped( 'ext-dom not available' );
		}

		$this->builder = new Builder();
	}

	public function test_setup_response_shape(): void {
		$xml = $this->builder->setup_response( '1.2.008', 'pid-1@shop', '2026-08-31T08:00:00+00:00', 'https://shop.example.com/punchout/start/abc123' );

		self::assertStringContainsString( '<!DOCTYPE cXML SYSTEM "http://xml.cxml.org/schemas/cXML/1.2.008/cXML.dtd">', $xml );
		self::assertStringContainsString( '<Status code="200" text="success"/>', $xml );
		self::assertStringContainsString( '<StartPage><URL>https://shop.example.com/punchout/start/abc123</URL></StartPage>', $xml );
		self::assertStringContainsString( 'payloadID="pid-1@shop"', $xml );
		self::assertStringNotContainsString( '<Header', $xml, 'Response documents carry no Header' );
		self::assertStringNotContainsString( 'SharedSecret', $xml );
		self::assertStringNotContainsString( '&amp;', $xml, 'StartPage URL must carry no raw query separators (CLEANAMP)' );
	}

	public function test_status_document(): void {
		$xml = $this->builder->status( '1.2.008', 'pid-2@shop', '2026-08-31T08:00:00+00:00', 401, 'Authentication failed' );

		self::assertStringContainsString( '<Status code="401" text="Authentication failed"/>', $xml );
		self::assertStringNotContainsString( '<Header', $xml );
	}

	public function test_profile_response_lists_setup_transaction(): void {
		$xml = $this->builder->profile_response( '1.2.008', 'pid-3@shop', '2026-08-31T08:00:00+00:00', 'https://shop.example.com/punchout/setup' );

		self::assertStringContainsString( '<Transaction requestName="PunchOutSetupRequest">', $xml );
		self::assertStringContainsString( '<URL>https://shop.example.com/punchout/setup</URL>', $xml );
		self::assertStringContainsString( '<Option name="operationAllowed">create</Option>', $xml );
		self::assertStringContainsString( 'effectiveDate=', $xml );
	}

	public function test_no_newlines_inside_element_content(): void {
		// REPLACENEWLINE exists because supplier responses with newlines in
		// element content break D365's XML handling (scope §9.6).
		$xml = $this->builder->status( '1.2.008', "pid\n4@shop", '2026-08-31T08:00:00+00:00', 200, "multi\nline\ttext" );

		self::assertStringContainsString( 'text="multi line text"', $xml );
		self::assertStringContainsString( 'payloadID="pid 4@shop"', $xml );
	}

	public function test_invalid_version_falls_back(): void {
		$xml = $this->builder->setup_response( '<evil>', 'pid-5@shop', '2026-08-31T08:00:00+00:00', 'https://shop.example.com/punchout/start/t' );

		self::assertStringContainsString( '/cXML/1.2.008/cXML.dtd', $xml );
	}

	public function test_payload_id_and_timestamp_helpers(): void {
		self::assertMatchesRegularExpression( '/^\d+\.\d+\.\d{6}@shop\.example\.com$/', Builder::payload_id( 'shop.example.com' ) );
		self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', Builder::timestamp() );
		self::assertSame( '1970-01-01T00:00:00+00:00', Builder::timestamp( 0 ) );
	}
}
