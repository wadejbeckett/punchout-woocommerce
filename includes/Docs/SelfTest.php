<?php
/**
 * The documentation page's self-test.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Docs;

use POW\Audit\Log;
use POW\Cxml\ParseException;
use POW\Cxml\Parser;
use POW\Cxml\SetupMessage;
use POW\Http\SetupEndpoint;
use POW\Partners\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Diagnoses a pasted PunchOutSetupRequest against the real parser and the
 * endpoint's own acceptance rules, and answers with the status code the
 * endpoint would have answered with.
 *
 * It exists as its own class rather than as a call into SetupEndpoint
 * because the documentation page is public. Two rules follow from that:
 *
 * 1. Nothing is written. No session row, no provisioned user, no login,
 *    no audit row — run() reads the pasted document and returns an array.
 * 2. An anonymous run must never distinguish "identity unknown" from
 *    "connection disabled" from "secret wrong". Splitting those apart on
 *    a public page turns it into a credential oracle; the live endpoint
 *    collapses all three into one generic 401 (SetupEndpoint::deny_auth),
 *    and so does this. A manage_woocommerce user gets the precise stage.
 *
 * The pasted document is only ever echoed back through redact(), so a
 * shared secret pasted in by a buyer's developer cannot reappear in the
 * page, in a screenshot of it, or in a log line.
 */
final class SelfTest {

	public const RESULT_PASS = 'pass';
	public const RESULT_FAIL = 'fail';
	public const RESULT_INFO = 'info';

	/**
	 * @param Parser    $parser   The live inbound parser.
	 * @param ?Registry $registry Partner lookup; null skips the partner stage
	 *                            entirely, which is what a unit test and any
	 *                            database-free context want.
	 */
	public function __construct( private Parser $parser, private ?Registry $registry = null ) {}

	/**
	 * @param string $xml        The pasted document.
	 * @param bool   $privileged Whether the viewer may see precise auth reasons.
	 * @return array{checks: list<array{label: string, result: string, detail: string}>, verdict: int, verdict_text: string}
	 */
	public function run( string $xml, bool $privileged = false ): array {
		$checks = [];

		try {
			$message = $this->parser->parse( $xml );
		} catch ( ParseException $e ) {
			$checks[] = $this->check( __( 'Document parses as cXML we accept', 'punchout-woocommerce' ), self::RESULT_FAIL, $e->getMessage() );

			return $this->report( $checks, $e->cxml_status );
		}

		$checks[] = $this->check( __( 'Document parses as cXML we accept', 'punchout-woocommerce' ), self::RESULT_PASS, $message->version );

		if ( SetupMessage::KIND_SETUP !== $message->kind ) {
			$checks[] = $this->check( __( 'Request type', 'punchout-woocommerce' ), self::RESULT_FAIL, $message->kind );

			return $this->report( $checks, SetupEndpoint::STATUS_UNSUPPORTED );
		}

		if ( 'create' !== $message->operation ) {
			$checks[] = $this->check( __( 'Operation', 'punchout-woocommerce' ), self::RESULT_FAIL, $message->operation );

			return $this->report( $checks, SetupEndpoint::STATUS_UNSUPPORTED );
		}

		$checks[] = $this->check( __( 'Operation', 'punchout-woocommerce' ), self::RESULT_PASS, 'create' );

		// The endpoint's own rule, kept identical on purpose.
		if ( 1 !== preg_match( '#^https?://#i', $message->browser_form_post ) ) {
			$checks[] = $this->check( __( 'BrowserFormPost URL', 'punchout-woocommerce' ), self::RESULT_FAIL, __( 'Must be an absolute http(s) URL.', 'punchout-woocommerce' ) );

			return $this->report( $checks, SetupEndpoint::STATUS_INVALID );
		}

		$checks[] = $this->check( __( 'BrowserFormPost URL', 'punchout-woocommerce' ), self::RESULT_PASS, $message->browser_form_post );
		$checks[] = $this->check( __( 'BuyerCookie', 'punchout-woocommerce' ), '' !== $message->buyer_cookie ? self::RESULT_PASS : self::RESULT_FAIL, $message->buyer_cookie );
		$checks[] = $this->check( __( 'Identity extrinsics seen', 'punchout-woocommerce' ), self::RESULT_INFO, implode( ', ', array_keys( $message->extrinsics ) ) );
		$checks[] = $this->check( __( 'ShipTo', 'punchout-woocommerce' ), self::RESULT_INFO, null !== $message->ship_to_xml ? __( 'present', 'punchout-woocommerce' ) : __( 'absent', 'punchout-woocommerce' ) );

		if ( '' === $message->buyer_cookie ) {
			return $this->report( $checks, SetupEndpoint::STATUS_INVALID );
		}

		return $this->report( array_merge( $checks, $this->partner_checks( $message, $privileged ) ), SetupEndpoint::STATUS_OK );
	}

	/**
	 * Anonymous callers get ONE collapsed partner check. Splitting it would
	 * turn a public page into a credential oracle — the live endpoint
	 * collapses the same three failures into one 401 (SetupEndpoint::deny_auth).
	 *
	 * @return list<array{label: string, result: string, detail: string}>
	 */
	private function partner_checks( SetupMessage $message, bool $privileged ): array {
		if ( null === $this->registry ) {
			return [ $this->check( __( 'Connection and shared secret', 'punchout-woocommerce' ), self::RESULT_INFO, __( 'Not checked here.', 'punchout-woocommerce' ) ) ];
		}

		$partner = $this->registry->find_by_sender( $message->sender_domain, $message->sender_identity );
		$known   = null !== $partner;
		$active  = $known && $partner->is_active();
		$secret  = $active && null !== $this->registry->verify_secret( $partner, (string) $message->shared_secret );

		return self::partner_result_rows(
			$known,
			$active,
			$secret,
			$privileged,
			[ $known ? $partner->name : $message->sender_domain . ' / ' . $message->sender_identity, $known ? $partner->status : '', '' ]
		);
	}

	/**
	 * The collapse rule, pure so it can be proved without a database.
	 * Anonymous: one row, no detail, so an unknown identity and a wrong
	 * secret are byte-identical. Privileged: the three stages named.
	 *
	 * @param list<string> $details Per-stage detail, privileged only.
	 * @return list<array{label: string, result: string, detail: string}>
	 */
	public static function partner_result_rows( bool $known, bool $active, bool $secret, bool $privileged, array $details = [ '', '', '' ] ): array {
		$verdict = static fn ( bool $ok ): string => $ok ? self::RESULT_PASS : self::RESULT_FAIL;

		if ( ! $privileged ) {
			return [
				[
					'label'  => __( 'Connection and shared secret', 'punchout-woocommerce' ),
					'result' => $verdict( $known && $active && $secret ),
					'detail' => '',
				],
			];
		}

		return [
			[
				'label'  => __( 'Sender identity known', 'punchout-woocommerce' ),
				'result' => $verdict( $known ),
				'detail' => (string) ( $details[0] ?? '' ),
			],
			[
				'label'  => __( 'Connection active', 'punchout-woocommerce' ),
				'result' => $verdict( $active ),
				'detail' => (string) ( $details[1] ?? '' ),
			],
			[
				'label'  => __( 'Shared secret matches', 'punchout-woocommerce' ),
				'result' => $verdict( $secret ),
				'detail' => (string) ( $details[2] ?? '' ),
			],
		];
	}

	/**
	 * @return array{label: string, result: string, detail: string}
	 */
	private function check( string $label, string $result, string $detail ): array {
		return [
			'label'  => $label,
			'result' => $result,
			'detail' => $detail,
		];
	}

	/**
	 * @param list<array{label: string, result: string, detail: string}> $checks Checks.
	 * @return array{checks: list<array{label: string, result: string, detail: string}>, verdict: int, verdict_text: string}
	 */
	private function report( array $checks, int $verdict ): array {
		return [
			'checks'       => $checks,
			'verdict'      => $verdict,
			'verdict_text' => SetupEndpoint::STATUS_REASONS[ $verdict ] ?? '',
		];
	}

	/** Never echo a pasted document without this. */
	public static function redact( string $xml ): string {
		return Log::redact_xml( $xml );
	}
}
