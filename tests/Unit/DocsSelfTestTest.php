<?php
/**
 * The public self-test: real parse diagnostics, no session, no secret
 * echo, no credential oracle for anonymous visitors, and no way to buy
 * unthrottled guesses through the documentation page.
 *
 * The ext-dom guard sits on the tests that actually parse a document, not
 * on setUp(), so the security rules — the collapse, the constant-cost
 * partner stage, the size cap, the throttle and the redaction — are proved
 * on a stock PHP too.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Cxml\Parser;
use POW\Docs\Samples;
use POW\Docs\SelfTest;
use POW\Http\RateLimiter;
use POW\Http\SetupEndpoint;
use POW\Partners\Partner;
use POW\Partners\Registry;
use POW\Partners\Secrets;

final class DocsSelfTestTest extends TestCase {

	/**
	 * No registry, limiter or log: the partner stage reports "not checked"
	 * rather than touching the database.
	 */
	private function self_test(): SelfTest {
		return new SelfTest( new Parser(), null );
	}

	/**
	 * The same, for a test that reaches the parser.
	 */
	private function parsing_self_test(): SelfTest {
		if ( ! extension_loaded( 'dom' ) ) {
			self::markTestSkipped( 'ext-dom not available' );
		}

		return $this->self_test();
	}

	/**
	 * A real RateLimiter wired to in-memory callables, so the throttle
	 * under test is the endpoint's own class and not a fake of it.
	 */
	private function limiter( int $count_already_used ): RateLimiter {
		return new RateLimiter(
			1,
			static fn ( string $key ): int => $count_already_used,
			static function ( string $key, int $count ): void {}
		);
	}

	/**
	 * A Registry that has never seen a database. Any call into it fatals on
	 * the null $wpdb, which is what makes "the registry was not touched" a
	 * provable claim rather than an assertion about a mock.
	 */
	private function unusable_registry(): Registry {
		return new Registry( new Secrets( str_repeat( 'k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) ) );
	}

	public function test_valid_document_passes_every_parse_check(): void {
		$report = $this->parsing_self_test()->run( Samples::setup_request() );

		foreach ( $report['checks'] as $check ) {
			self::assertNotSame( SelfTest::RESULT_FAIL, $check['result'], $check['label'] );
		}

		self::assertSame( SetupEndpoint::STATUS_OK, $report['verdict'] );
	}

	public function test_unparseable_document_reports_406(): void {
		$report = $this->parsing_self_test()->run( 'not xml at all' );

		self::assertSame( SetupEndpoint::STATUS_INVALID, $report['verdict'] );
		self::assertSame( SetupEndpoint::STATUS_REASONS[ SetupEndpoint::STATUS_INVALID ], $report['verdict_text'] );
	}

	public function test_entity_declaration_is_refused(): void {
		$xml = '<?xml version="1.0"?><!DOCTYPE cXML [<!ENTITY x SYSTEM "file:///etc/passwd">]><cXML/>';

		self::assertSame( SetupEndpoint::STATUS_INVALID, $this->parsing_self_test()->run( $xml )['verdict'] );
	}

	public function test_unsupported_operation_reports_450(): void {
		$xml = str_replace( 'operation="create"', 'operation="edit"', Samples::setup_request() );

		self::assertSame( SetupEndpoint::STATUS_UNSUPPORTED, $this->parsing_self_test()->run( $xml )['verdict'] );
	}

	public function test_non_http_browser_form_post_fails(): void {
		$xml = str_replace( 'https://buyer.example.com/punchout/receive', 'javascript:alert(1)', Samples::setup_request() );

		self::assertSame( SetupEndpoint::STATUS_INVALID, $this->parsing_self_test()->run( $xml )['verdict'] );
	}

	public function test_shared_secret_is_never_echoed(): void {
		$report = $this->parsing_self_test()->run( Samples::setup_request() );

		self::assertStringNotContainsString( 'your-shared-secret', (string) json_encode( $report ) );
	}

	public function test_redact_removes_the_shared_secret(): void {
		$redacted = SelfTest::redact( Samples::setup_request() );

		self::assertStringContainsString( '[redacted]', $redacted );
		self::assertStringNotContainsString( 'your-shared-secret', $redacted );
	}

	/**
	 * A namespace prefix on the element is legal cXML and some buyers send
	 * it. A redaction that only knows the bare element name would archive
	 * the credential verbatim.
	 */
	public function test_redact_removes_a_namespace_prefixed_shared_secret(): void {
		$redacted = SelfTest::redact( '<cxml:Credential><cxml:SharedSecret>hunter2</cxml:SharedSecret></cxml:Credential>' );

		self::assertStringNotContainsString( 'hunter2', $redacted );
		self::assertStringContainsString( '[redacted]', $redacted );
		self::assertStringContainsString( '</cxml:SharedSecret>', $redacted );
	}

	/**
	 * The collapse rule is a pure static so it is provable without a
	 * database — a Registry needs wpdb, and the whole point of the rule is
	 * that it holds for a failed lookup as well as a wrong secret.
	 */
	public function test_anonymous_partner_stage_is_collapsed(): void {
		$anonymous  = SelfTest::partner_result_rows( true, true, false, false, [ 'Example Buyer Company', 'active', '' ] );
		$privileged = SelfTest::partner_result_rows( true, true, false, true, [ 'Example Buyer Company', 'active', '' ] );

		self::assertCount( 1, $anonymous );
		self::assertCount( 3, $privileged );
		self::assertSame( SelfTest::RESULT_FAIL, $anonymous[0]['result'] );
		self::assertSame( '', $anonymous[0]['detail'], 'an anonymous run must never say which stage failed' );
		self::assertSame( 'Example Buyer Company', $privileged[0]['detail'] );
	}

	public function test_unknown_identity_and_wrong_secret_are_indistinguishable(): void {
		self::assertSame(
			SelfTest::partner_result_rows( false, false, false, false ),
			SelfTest::partner_result_rows( true, true, false, false )
		);
	}

	public function test_with_no_registry_the_partner_stage_is_not_checked(): void {
		$report = $this->parsing_self_test()->run( Samples::setup_request() );

		self::assertContains( 'Connection and shared secret', array_column( $report['checks'], 'label' ) );
		self::assertStringNotContainsString( 'AN01000000123-T', (string) json_encode( $report ) );
	}

	/**
	 * A document over the endpoint's own body cap is refused before the
	 * parser sees it, so a multi-megabyte paste cannot be used to burn CPU
	 * on a public page.
	 */
	public function test_oversized_document_is_refused_before_parsing(): void {
		$report = $this->self_test()->run( str_repeat( 'x', SetupEndpoint::MAX_BODY_BYTES + 1 ) );

		self::assertSame( SetupEndpoint::STATUS_INVALID, $report['verdict'] );
		self::assertCount( 1, $report['checks'] );
		self::assertSame( SelfTest::RESULT_FAIL, $report['checks'][0]['result'] );
	}

	/**
	 * The throttle is charged before any registry lookup: the unusable
	 * Registry would fatal on the null $wpdb if the run got that far.
	 */
	public function test_a_limited_rate_bucket_stops_before_the_registry_is_touched(): void {
		$self_test = new SelfTest( new Parser(), $this->unusable_registry(), $this->limiter( 5 ) );

		$report = $self_test->run( Samples::setup_request(), false, '198.51.100.7' );

		self::assertSame( SetupEndpoint::STATUS_RATE_LIMITED, $report['verdict'] );
		self::assertSame( SetupEndpoint::STATUS_REASONS[ SetupEndpoint::STATUS_RATE_LIMITED ], $report['verdict_text'] );
		self::assertCount( 1, $report['checks'] );
		self::assertSame( SelfTest::RESULT_FAIL, $report['checks'][0]['result'] );
	}

	/**
	 * The other half of the claim above: with the bucket open, the same run
	 * really does reach the registry — so the previous test is measuring
	 * the throttle and not some unrelated early exit.
	 */
	public function test_an_open_rate_bucket_lets_the_run_reach_the_registry(): void {
		if ( ! extension_loaded( 'dom' ) ) {
			self::markTestSkipped( 'ext-dom not available' );
		}

		$self_test = new SelfTest( new Parser(), $this->unusable_registry(), $this->limiter( 0 ) );

		$this->expectException( Error::class );
		$self_test->run( Samples::setup_request(), false, '198.51.100.7' );
	}

	/**
	 * All three stage flags are computed whatever the first one says, and
	 * the secret comparison runs against a real sealed value even when
	 * there is no partner — otherwise an unknown sender would answer
	 * faster than a known one with a wrong secret.
	 */
	public function test_every_partner_stage_flag_is_evaluated_for_an_unknown_sender(): void {
		$verified = 0;
		$subject  = null;

		$flags = SelfTest::partner_flags(
			null,
			'guess',
			function ( Partner $partner, string $candidate ) use ( &$verified, &$subject ): bool {
				$verified++;
				$subject = $partner;

				return true;
			}
		);

		self::assertSame( 1, $verified, 'the secret must still be compared when the sender is unknown' );
		self::assertNotSame( '', $subject->secret_current, 'the dummy must carry a sealed value, or the comparison short-circuits' );
		self::assertSame( [ 'known' => false, 'active' => false, 'secret' => false ], $flags );
	}

	public function test_a_disabled_connection_is_also_verified_against_the_dummy(): void {
		$disabled = Partner::from_row(
			[
				'name'           => 'Example Buyer Company',
				'status'         => 'disabled',
				'secret_current' => 'the-real-sealed-value',
			]
		);

		$subject = null;

		$flags = SelfTest::partner_flags(
			$disabled,
			'guess',
			function ( Partner $partner ) use ( &$subject ): bool {
				$subject = $partner;

				return true;
			}
		);

		self::assertNotSame( 'the-real-sealed-value', $subject->secret_current );
		self::assertSame( [ 'known' => true, 'active' => false, 'secret' => false ], $flags );
	}

	public function test_an_active_partner_with_a_matching_secret_passes_every_flag(): void {
		$active = Partner::from_row(
			[
				'name'           => 'Example Buyer Company',
				'status'         => 'active',
				'secret_current' => 'the-real-sealed-value',
			]
		);

		$flags = SelfTest::partner_flags( $active, 'right', static fn (): bool => true );

		self::assertSame( [ 'known' => true, 'active' => true, 'secret' => true ], $flags );
	}

	/**
	 * The verdict follows the partner stage, whether or not the wording
	 * says which stage failed.
	 */
	public function test_a_failed_partner_stage_makes_the_verdict_401(): void {
		self::assertSame(
			SetupEndpoint::STATUS_AUTH_FAILED,
			SelfTest::partner_verdict( SelfTest::partner_result_rows( true, true, false, false ) )
		);
		self::assertSame(
			SetupEndpoint::STATUS_AUTH_FAILED,
			SelfTest::partner_verdict( SelfTest::partner_result_rows( false, false, false, true ) )
		);
		self::assertSame(
			SetupEndpoint::STATUS_OK,
			SelfTest::partner_verdict( SelfTest::partner_result_rows( true, true, true, true ) )
		);
	}

	/**
	 * The endpoint answers a ProfileRequest with a profile response once it
	 * has authenticated it (SetupEndpoint::handle_inner), so the self-test
	 * must not call it unsupported.
	 */
	public function test_profile_request_is_answered_not_refused(): void {
		$xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<cXML payloadID="p1@example.com" timestamp="2026-09-08T09:00:00+00:00" version="1.2.008">
 <Header>
  <From><Credential domain="NetworkId"><Identity>AN01000000123-T</Identity></Credential></From>
  <To><Credential domain="DUNS"><Identity>SUPPLIER-DUNS</Identity></Credential></To>
  <Sender>
   <Credential domain="NetworkId"><Identity>AN01000000123-T</Identity><SharedSecret>your-shared-secret</SharedSecret></Credential>
   <UserAgent>Buyer procurement system 10.0</UserAgent>
  </Sender>
 </Header>
 <Request deploymentMode="test"><ProfileRequest/></Request>
</cXML>
XML;

		$report = $this->parsing_self_test()->run( $xml );

		self::assertSame( SetupEndpoint::STATUS_OK, $report['verdict'] );

		foreach ( $report['checks'] as $check ) {
			self::assertNotSame( SelfTest::RESULT_FAIL, $check['result'], $check['label'] );
		}

		self::assertContains( 'Connection and shared secret', array_column( $report['checks'], 'label' ) );
		self::assertStringContainsString( 'ProfileRequest', (string) json_encode( $report ) );
	}

	/**
	 * The parser accepts an empty BuyerCookie and the endpoint stores it —
	 * D365's "Validate settings" probe sends one — so it is information,
	 * not a fault.
	 */
	public function test_empty_buyer_cookie_is_accepted(): void {
		$self_test = $this->parsing_self_test();
		$xml       = str_replace( '<BuyerCookie>' . Samples::parsed()->buyer_cookie . '</BuyerCookie>', '<BuyerCookie/>', Samples::setup_request() );
		self::assertSame( '', ( new Parser() )->parse( $xml )->buyer_cookie );
		$report = $self_test->run( $xml );

		self::assertSame( SetupEndpoint::STATUS_OK, $report['verdict'] );

		foreach ( $report['checks'] as $check ) {
			if ( 'BuyerCookie' === $check['label'] ) {
				self::assertSame( SelfTest::RESULT_INFO, $check['result'] );
				self::assertStringContainsString( 'Validate settings', $check['detail'] );

				return;
			}
		}

		self::fail( 'no BuyerCookie row in the report' );
	}

	/**
	 * The diagnostic that replaced "which buyer account did we create": what
	 * Buyers\Identity read off the document, and nothing more — no account
	 * is created by a visit, let alone by this page.
	 *
	 * @return array{label: string, result: string, detail: string}
	 */
	private function buyer_row( string $xml ): array {
		$report = $this->parsing_self_test()->run( $xml );

		foreach ( $report['checks'] as $check ) {
			if ( 'Buyer read from them' === $check['label'] ) {
				self::assertSame( SelfTest::RESULT_INFO, $check['result'] );

				return $check;
			}
		}

		self::fail( 'no buyer row in the report' );
	}

	public function test_the_report_names_the_buyer_the_extrinsics_carried(): void {
		self::assertSame( 'buyer.user@example.invalid', $this->buyer_row( Samples::setup_request() )['detail'] );

		$named = str_replace(
			'<Extrinsic name="UniqueName">buyer.user@example.invalid</Extrinsic>',
			'<Extrinsic name="UserPrintableName">A Buyer</Extrinsic>',
			Samples::setup_request()
		);
		self::assertSame( 'A Buyer <buyer.user@example.invalid>', $this->buyer_row( $named )['detail'] );
	}

	/** An unidentified buyer is legal: the row says so instead of failing, and the verdict is untouched. */
	public function test_a_document_naming_nobody_reports_an_unidentified_buyer(): void {
		$anonymous = str_replace(
			[
				'<Extrinsic name="UserEmail">buyer.user@example.invalid</Extrinsic>',
				'<Extrinsic name="UniqueName">buyer.user@example.invalid</Extrinsic>',
				'<Email>buyer.user@example.invalid</Email>',
			],
			'',
			Samples::setup_request()
		);

		self::assertStringContainsString( 'none supplied', $this->buyer_row( $anonymous )['detail'] );
		self::assertSame( SetupEndpoint::STATUS_OK, $this->parsing_self_test()->run( $anonymous )['verdict'] );
	}

	/**
	 * A page of green ticks must not imply a check the self-test cannot
	 * make.
	 */
	public function test_the_report_says_the_ip_allowlist_is_not_evaluated(): void {
		$report = $this->parsing_self_test()->run( Samples::setup_request() );

		self::assertContains( 'IP allowlist', array_column( $report['checks'], 'label' ) );

		foreach ( $report['checks'] as $check ) {
			if ( 'IP allowlist' === $check['label'] ) {
				self::assertSame( SelfTest::RESULT_INFO, $check['result'] );
				self::assertStringContainsString( 'Not evaluated', $check['detail'] );

				return;
			}
		}
	}
}
