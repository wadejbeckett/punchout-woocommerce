<?php
/**
 * The public self-test: real parse diagnostics, no session, no secret
 * echo, and no credential oracle for anonymous visitors.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Cxml\Parser;
use POW\Docs\Samples;
use POW\Docs\SelfTest;
use POW\Http\SetupEndpoint;

final class DocsSelfTestTest extends TestCase {

	private SelfTest $self_test;

	protected function setUp(): void {
		if ( ! extension_loaded( 'dom' ) ) {
			self::markTestSkipped( 'ext-dom not available' );
		}

		// No registry: the partner stage reports "not checked" rather
		// than touching the database.
		$this->self_test = new SelfTest( new Parser(), null );
	}

	public function test_valid_document_passes_every_parse_check(): void {
		$report = $this->self_test->run( Samples::setup_request() );

		foreach ( $report['checks'] as $check ) {
			self::assertNotSame( SelfTest::RESULT_FAIL, $check['result'], $check['label'] );
		}

		self::assertSame( SetupEndpoint::STATUS_OK, $report['verdict'] );
	}

	public function test_unparseable_document_reports_406(): void {
		$report = $this->self_test->run( 'not xml at all' );

		self::assertSame( SetupEndpoint::STATUS_INVALID, $report['verdict'] );
		self::assertSame( SetupEndpoint::STATUS_REASONS[ SetupEndpoint::STATUS_INVALID ], $report['verdict_text'] );
	}

	public function test_entity_declaration_is_refused(): void {
		$xml = '<?xml version="1.0"?><!DOCTYPE cXML [<!ENTITY x SYSTEM "file:///etc/passwd">]><cXML/>';

		self::assertSame( SetupEndpoint::STATUS_INVALID, $this->self_test->run( $xml )['verdict'] );
	}

	public function test_unsupported_operation_reports_450(): void {
		$xml = str_replace( 'operation="create"', 'operation="edit"', Samples::setup_request() );

		self::assertSame( SetupEndpoint::STATUS_UNSUPPORTED, $this->self_test->run( $xml )['verdict'] );
	}

	public function test_non_http_browser_form_post_fails(): void {
		$xml = str_replace( 'https://buyer.example.com/punchout/receive', 'javascript:alert(1)', Samples::setup_request() );

		self::assertSame( SetupEndpoint::STATUS_INVALID, $this->self_test->run( $xml )['verdict'] );
	}

	public function test_shared_secret_is_never_echoed(): void {
		$report = $this->self_test->run( Samples::setup_request() );
		$dump   = (string) json_encode( $report );

		self::assertStringNotContainsString( 'your-shared-secret', $dump );
		self::assertStringContainsString( '[redacted]', SelfTest::redact( Samples::setup_request() ) );
	}

	/**
	 * The collapse rule is a pure static so it is provable without a
	 * database — a Registry needs wpdb, and the whole point of the rule is
	 * that it holds for a failed lookup as well as a wrong secret.
	 */
	public function test_anonymous_partner_stage_is_collapsed(): void {
		$anonymous  = SelfTest::partner_result_rows( true, true, false, false, [ 'Coke', 'active', '' ] );
		$privileged = SelfTest::partner_result_rows( true, true, false, true, [ 'Coke', 'active', '' ] );

		self::assertCount( 1, $anonymous );
		self::assertCount( 3, $privileged );
		self::assertSame( SelfTest::RESULT_FAIL, $anonymous[0]['result'] );
		self::assertSame( '', $anonymous[0]['detail'], 'an anonymous run must never say which stage failed' );
		self::assertSame( 'Coke', $privileged[0]['detail'] );
	}

	public function test_unknown_identity_and_wrong_secret_are_indistinguishable(): void {
		self::assertSame(
			SelfTest::partner_result_rows( false, false, false, false ),
			SelfTest::partner_result_rows( true, true, false, false )
		);
	}

	public function test_with_no_registry_the_partner_stage_is_not_checked(): void {
		$report = $this->self_test->run( Samples::setup_request() );

		self::assertContains( 'Connection and shared secret', array_column( $report['checks'], 'label' ) );
		self::assertStringNotContainsString( 'AN01000000123-T', (string) json_encode( $report ) );
	}
}
