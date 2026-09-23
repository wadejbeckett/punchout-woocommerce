<?php
/**
 * The documentation page's self-test.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Docs;

use POW\Support\Transport;

use POW\Audit\Log;
use POW\Buyers\Identity;
use POW\Cxml\ParseException;
use POW\Cxml\Parser;
use POW\Cxml\SetupMessage;
use POW\Http\RateLimiter;
use POW\Http\SetupEndpoint;
use POW\Partners\Partner;
use POW\Partners\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Diagnoses a pasted PunchOutSetupRequest or ProfileRequest against the
 * real parser and the real registry, and answers with the cXML Status the
 * live endpoint would have answered with.
 *
 * It is its own class rather than a call into SetupEndpoint because the
 * documentation page is public. Four rules follow from that, and each one
 * is held here rather than trusted to the page:
 *
 * 1. Nothing is written. No session row, no login, no basket, no account of
 *    any kind — the plugin creates no users at all, and this page creates
 *    nothing else either — and no response document: run() reads and
 *    returns an array. The only write is the audit row the partner stage
 *    leaves behind, which is the point of rule 3.
 * 2. An anonymous run never distinguishes "identity unknown" from
 *    "connection disabled" from "secret wrong". Splitting those on a
 *    public page is a credential oracle; the live endpoint collapses all
 *    three into one generic 401 (SetupEndpoint::deny_auth), and so does
 *    this. A manage_woocommerce user gets the precise stage. The stages
 *    are also evaluated in constant-ish time (see partner_flags), so the
 *    wall clock does not leak what the wording refuses to.
 * 3. The partner stage is throttled and logged. It consumes the same
 *    unknown-sender rate-limit bucket the endpoint does, so the page
 *    cannot be used to buy free guesses that the endpoint would have
 *    refused, and every evaluation leaves an audit row in the shape
 *    deny_auth writes — an oracle nobody can see being used is worse than
 *    no oracle at all.
 * 4. The pasted document is only ever echoed back through redact(), so a
 *    shared secret pasted in by a buyer's developer cannot reappear on
 *    the page, in a screenshot of it, or in a log line.
 *
 * Where this class and the plan disagreed, the endpoint won: the verdict
 * is whatever includes/Http/SetupEndpoint.php would return for the same
 * document, including its ordering (authentication is decided before the
 * operation and the BrowserFormPost URL are). The checks list is still
 * complete — a buyer's developer sees every fault in one pass — but the
 * verdict names only the first one the endpoint would have stopped at.
 */
final class SelfTest {

	public const RESULT_PASS = 'pass';
	public const RESULT_FAIL = 'fail';
	public const RESULT_INFO = 'info';

	/**
	 * A fixed, deliberately undecryptable sealed value, verified against
	 * when the sender is unknown or the connection is disabled.
	 *
	 * Long enough to clear Secrets::open's length gate so the secretbox
	 * open really runs and really fails its MAC check: without it,
	 * Secrets::verify would short-circuit on an empty slot and an unknown
	 * sender would answer measurably faster than a known one with a wrong
	 * secret. The same trick as verifying a password against a fixed dummy
	 * hash when the account does not exist. It is not a secret and it
	 * decrypts to nothing under any key.
	 */
	private const DUMMY_SEALED = '9IUEvLLMXOHOBvfpvBKIZepgpxiT0ycQdf9GaXrIXRoe3AFVtnDn1qR4YqAXWa+3fx3rxukPSC1u6Tm6YNv+FQzzi8DufsGp';

	/**
	 * @param Parser       $parser   The live inbound parser.
	 * @param ?Registry    $registry Partner lookup; null skips the partner stage
	 *                               entirely, which is what a unit test and any
	 *                               database-free context want.
	 * @param ?RateLimiter $limiter  Throttle for the partner stage; null disables
	 *                               it, which is only safe when $registry is null.
	 * @param ?Log         $log      Audit trail for the partner stage; null
	 *                               disables it, same caveat.
	 */
	public function __construct(
		private Parser $parser,
		private ?Registry $registry = null,
		private ?RateLimiter $limiter = null,
		private ?Log $log = null,
	) {}

	/**
	 * @param string $xml        The pasted document.
	 * @param bool   $privileged Whether the viewer may see precise auth reasons.
	 * @param string $client_ip  Viewer's address, for the rate-limit bucket and
	 *                           the audit row.
	 * @return array{checks: list<array{label: string, result: string, detail: string}>, verdict: int, verdict_text: string}
	 */
	public function run( string $xml, bool $privileged = false, string $client_ip = '' ): array {
		if ( ! Transport::request_allowed() ) {
			return $this->report( [ $this->check( __( 'Transport', 'punchout-woocommerce' ), self::RESULT_FAIL, Transport::message() ) ], SetupEndpoint::STATUS_AUTH_FAILED );
		}
		if ( strlen( $xml ) > SetupEndpoint::MAX_BODY_BYTES ) {
			return $this->report(
				[
					$this->check(
						__( 'Document size', 'punchout-woocommerce' ),
						self::RESULT_FAIL,
						sprintf(
							/* translators: %d: maximum accepted request body size, in bytes. */
							__( 'The endpoint refuses any request body over %d bytes.', 'punchout-woocommerce' ),
							SetupEndpoint::MAX_BODY_BYTES
						)
					),
				],
				SetupEndpoint::STATUS_INVALID
			);
		}

		// Before the parser and before any registry lookup: the same
		// bucket the endpoint charges unknown senders to, so a scanner
		// gains nothing by coming through the documentation page. The
		// endpoint parses first and throttles second; here the throttle
		// comes first, because unlike the endpoint this entry point is
		// reachable by anyone.
		if ( null !== $this->limiter && ! $this->limiter->allow( 'unknown|' . $client_ip ) ) {
			return $this->report(
				[
					$this->check(
						__( 'Rate limit', 'punchout-woocommerce' ),
						self::RESULT_FAIL,
						__( 'Too many attempts from this address in the last minute. Wait and try again.', 'punchout-woocommerce' )
					),
				],
				SetupEndpoint::STATUS_RATE_LIMITED
			);
		}

		$checks = [];

		try {
			$message = $this->parser->parse( $xml );
		} catch ( ParseException $e ) {
			$checks[] = $this->check( __( 'Document parses as cXML we accept', 'punchout-woocommerce' ), self::RESULT_FAIL, $e->getMessage() );

			return $this->report( $checks, $e->cxml_status );
		}

		$checks[] = $this->check( __( 'Document parses as cXML we accept', 'punchout-woocommerce' ), self::RESULT_PASS, $message->version );

		// Authentication is decided before anything else about the
		// request body, exactly as the endpoint decides it.
		$partner_rows = $this->partner_checks( $message, $privileged, $client_ip );
		$checks       = array_merge( $checks, $partner_rows );
		$auth_verdict = self::partner_verdict( $partner_rows );

		if ( SetupMessage::KIND_PROFILE === $message->kind ) {
			$checks[] = $this->check(
				__( 'Request type', 'punchout-woocommerce' ),
				self::RESULT_PASS,
				__( 'ProfileRequest: answered with a profile response after authentication', 'punchout-woocommerce' )
			);

			return $this->report( $checks, $auth_verdict );
		}

		$checks[] = $this->check( __( 'Request type', 'punchout-woocommerce' ), self::RESULT_PASS, __( 'PunchOutSetupRequest', 'punchout-woocommerce' ) );

		$operation_ok = 'create' === $message->operation;
		$checks[]     = $this->check(
			__( 'Operation', 'punchout-woocommerce' ),
			$operation_ok ? self::RESULT_PASS : self::RESULT_FAIL,
			$operation_ok ? 'create' : $message->operation
		);

		// The endpoint's own rule, kept identical on purpose.
		$url_ok   = Transport::receiver_allowed( $message->browser_form_post );
		$checks[] = $this->check(
			__( 'BrowserFormPost URL', 'punchout-woocommerce' ),
			$url_ok ? self::RESULT_PASS : self::RESULT_FAIL,
			$url_ok ? $message->browser_form_post : __( 'Requires a valid HTTPS URL; HTTP is allowed only in local/development environments.', 'punchout-woocommerce' )
		);

		// Informational, not a fault: the parser accepts an empty
		// BuyerCookie and the endpoint stores it as-is.
		$checks[] = $this->check(
			__( 'BuyerCookie', 'punchout-woocommerce' ),
			self::RESULT_INFO,
			'' !== $message->buyer_cookie
				? $message->buyer_cookie
				: __( 'Empty, which we accept — Dynamics 365\'s "Validate settings" probe sends an empty one. It is echoed back unchanged on the basket.', 'punchout-woocommerce' )
		);

		$checks[] = $this->check( __( 'Identity extrinsics seen', 'punchout-woocommerce' ), self::RESULT_INFO, implode( ', ', array_keys( $message->extrinsics ) ) );
		$checks[] = $this->check( __( 'Buyer read from them', 'punchout-woocommerce' ), self::RESULT_INFO, self::buyer_detail( $message ) );
		$checks[] = $this->check( __( 'ShipTo', 'punchout-woocommerce' ), self::RESULT_INFO, null !== $message->ship_to_xml ? __( 'present', 'punchout-woocommerce' ) : __( 'absent', 'punchout-woocommerce' ) );
		$checks[] = $this->cart_handler_check();

		return $this->report( $checks, $this->verdict( $auth_verdict, $operation_ok, $url_ok ) );
	}

	/**
	 * The status the endpoint would answer with, in the endpoint's own
	 * order of refusal: authentication, then the operation, then the
	 * punchback URL (SetupEndpoint::handle_inner).
	 */
	private function verdict( int $auth_verdict, bool $operation_ok, bool $url_ok ): int {
		if ( SetupEndpoint::STATUS_OK !== $auth_verdict ) {
			return $auth_verdict;
		}

		if ( ! $operation_ok ) {
			return SetupEndpoint::STATUS_UNSUPPORTED;
		}

		return $url_ok ? SetupEndpoint::STATUS_OK : SetupEndpoint::STATUS_INVALID;
	}

	/**
	 * Anonymous callers get ONE collapsed partner check. Splitting it would
	 * turn a public page into a credential oracle — the live endpoint
	 * collapses the same three failures into one 401 (SetupEndpoint::deny_auth).
	 *
	 * @return list<array{label: string, result: string, detail: string}>
	 */
	private function partner_checks( SetupMessage $message, bool $privileged, string $client_ip ): array {
		if ( null === $this->registry ) {
			return [
				$this->check( __( 'Connection and shared secret', 'punchout-woocommerce' ), self::RESULT_INFO, __( 'Not checked here.', 'punchout-woocommerce' ) ),
				$this->ip_allowlist_note(),
			];
		}

		$registry = $this->registry;
		$partner  = $registry->find_by_sender( $message->sender_domain, $message->sender_identity );

		$flags = self::partner_flags(
			$partner,
			(string) $message->shared_secret,
			static fn ( Partner $subject, string $candidate ): bool => null !== $registry->verify_secret( $subject, $candidate )
		);

		$rows = self::partner_result_rows(
			$flags['known'],
			$flags['active'],
			$flags['secret'],
			$privileged,
			[
				null !== $partner ? $partner->name : $message->sender_domain . ' / ' . $message->sender_identity,
				null !== $partner ? $partner->status : '',
				'',
			]
		);

		$this->audit( $message, $flags, $partner, $client_ip );

		$rows[] = $this->ip_allowlist_note();

		return $rows;
	}

	/**
	 * The three stage flags, all of them evaluated whatever the first one
	 * says.
	 *
	 * An unknown sender and a disabled connection are verified against a
	 * fixed dummy sealed value, so the secret comparison costs the same
	 * whether or not the identity resolved. Without that, a public page
	 * that refuses to say which stage failed still answers the question in
	 * milliseconds.
	 *
	 * Pure, and the verifier is injected, so the rule is provable without
	 * a database.
	 *
	 * @param ?Partner                       $partner   Resolved partner, or null.
	 * @param string                         $candidate Secret presented in the document.
	 * @param callable(Partner, string):bool $verify    Secret comparison.
	 * @return array{known: bool, active: bool, secret: bool}
	 */
	public static function partner_flags( ?Partner $partner, string $candidate, callable $verify ): array {
		$known   = null !== $partner;
		$active  = $known && $partner->is_active();
		$subject = $active ? $partner : self::dummy_partner();
		$matched = $verify( $subject, $candidate );

		return [
			'known'  => $known,
			'active' => $active,
			'secret' => $active && $matched,
		];
	}

	/**
	 * The partner stage's contribution to the verdict: 401 the moment any
	 * evaluated row failed, 200 only when every one of them passed. A
	 * stage that was not evaluated at all reports INFO and so passes.
	 *
	 * @param list<array{label: string, result: string, detail: string}> $rows Partner rows.
	 */
	public static function partner_verdict( array $rows ): int {
		foreach ( $rows as $row ) {
			if ( self::RESULT_FAIL === $row['result'] ) {
				return SetupEndpoint::STATUS_AUTH_FAILED;
			}
		}

		return SetupEndpoint::STATUS_OK;
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
	 * The self-test has no source address to offer the allowlist, so it
	 * never evaluates one. Said out loud, because a page of green ticks
	 * that omits a check the endpoint does make is worse than no page.
	 *
	 * @return array{label: string, result: string, detail: string}
	 */
	private function ip_allowlist_note(): array {
		return $this->check(
			__( 'IP allowlist', 'punchout-woocommerce' ),
			self::RESULT_INFO,
			__( 'Not evaluated by this self-test. The live endpoint also checks the source address of the request against any allowlist on your connection.', 'punchout-woocommerce' )
		);
	}

	/**
	 * One audit row per partner-stage evaluation, in the shape
	 * SetupEndpoint::deny_auth writes. Never the secret — only which
	 * stage stopped it, which is the same thing the admin sees for real
	 * traffic.
	 *
	 * @param array{known: bool, active: bool, secret: bool} $flags   Stage flags.
	 * @param ?Partner                                       $partner Resolved partner, or null.
	 */
	private function audit( SetupMessage $message, array $flags, ?Partner $partner, string $client_ip ): void {
		if ( null === $this->log ) {
			return;
		}

		if ( ! $flags['known'] ) {
			$reason = 'unknown sender';
		} elseif ( ! $flags['active'] ) {
			$reason = 'connection not active';
		} elseif ( ! $flags['secret'] ) {
			$reason = 'bad secret';
		} else {
			$reason = 'ok';
		}

		$this->log->write(
			'selftest_auth',
			[
				'partner_id' => $partner?->id ?? 0,
				// Not inbound traffic: nothing was served, nothing stored.
				'direction'  => 'internal',
				'payload_id' => $message->payload_id,
				'result'     => (string) ( $flags['secret'] ? SetupEndpoint::STATUS_OK : SetupEndpoint::STATUS_AUTH_FAILED ),
				'detail'     => [
					'error'  => $reason,
					'sender' => $message->sender_domain . '/' . $message->sender_identity,
				],
				'ip'         => $client_ip,
			]
		);
	}

	/**
	 * The stand-in the secret comparison runs against when there is no
	 * real partner to run it against. Its only meaningful field is the
	 * sealed slot; nothing else about it is ever read.
	 */
	private static function dummy_partner(): Partner {
		return Partner::from_row( [ 'secret_current' => self::DUMMY_SEALED ] );
	}

	/**
	 * Core session-handler methods Cart\NativeSessionHandler stands on, and the
	 * visibility each one must still have.
	 *
	 * The subclass bypasses WooCommerce's private init_session() because that
	 * sequence deletes a visit's basket row and then migrates what is left onto
	 * the shared user id (see the handler's class docblock). A bypass is only
	 * safe while the thing being bypassed is still shaped the way it was read:
	 * if `init_hooks()` stops being protected the visit's basket is never
	 * persisted at all, and if one of the private methods becomes overridable
	 * the bypass is no longer the only lever and should be revisited rather
	 * than kept.
	 *
	 * @var array<string, string>
	 */
	private const CART_HANDLER_SHAPE = [
		'init_hooks'                          => 'protected',
		'init_session'                        => 'private',
		'init_session_from_request'           => 'private',
		'is_session_cookie_valid'             => 'private',
		'is_customer_guest'                   => 'private',
		'migrate_guest_session_to_user_session' => 'private',
		'generate_customer_id'                => 'public',
		'init_session_cookie'                 => 'public',
		'get_session_cookie'                  => 'public',
		'set_customer_session_cookie'         => 'public',
		'maybe_set_customer_session_cookie'   => 'public',
		'has_session'                         => 'public',
		'get_session_data'                    => 'public',
		'get_customer_unique_id'              => 'public',
		'get_session'                         => 'public',
		'delete_session'                      => 'public',
		'destroy_session'                     => 'public',
		'forget_session'                      => 'public',
		'save_data'                           => 'public',
		'update_session_timestamp'            => 'public',
		'set_session_expiration'              => 'public',
	];

	/**
	 * What is wrong with WooCommerce's session handler for our purposes, as a
	 * list of one-line faults. Empty means the shape the per-visit cart
	 * handler was written against is still there.
	 *
	 * Pure: it reflects and returns, and makes no decision about what to do
	 * with the answer. The cart handler refuses a visit with a 409 when this
	 * is not empty, and the documentation page reports it as a check, because
	 * a shape change is a WooCommerce upgrade fault an administrator
	 * has to see rather than a fault in the pasted document.
	 *
	 * An absent \WC_Session_Handler is not a fault: WooCommerce may simply not
	 * be loaded (the documentation page and the test suite both run without
	 * it), and the handler is never constructed in that case.
	 *
	 * @param string $handler Class to inspect; the parameter exists so the shape
	 *                        rule can be tested against a fixture without
	 *                        WooCommerce being loaded.
	 * @return list<string>
	 */
	public static function cart_handler_faults( string $handler = '\\WC_Session_Handler' ): array {
		if ( ! class_exists( $handler ) ) {
			return [];
		}

		$faults = [];

		foreach ( self::CART_HANDLER_SHAPE as $name => $visibility ) {
			// Reflection rather than method_exists(): the latter answers false for
			// an inherited private method, and the private methods this bypass
			// exists because of are exactly the ones that must still be there.
			try {
				$method = new \ReflectionMethod( $handler, $name );
			} catch ( \ReflectionException $e ) {
				$faults[] = sprintf(
					/* translators: %s: name of a WooCommerce session handler method. */
					__( '%s is gone', 'punchout-woocommerce' ),
					$name
				);

				continue;
			}

			$actual = $method->isPrivate() ? 'private' : ( $method->isProtected() ? 'protected' : 'public' );

			if ( $actual !== $visibility || $method->isStatic() ) {
				$faults[] = sprintf(
					/* translators: 1: method name, 2: expected visibility, 3: visibility found. */
					__( '%1$s is %3$s, expected %2$s', 'punchout-woocommerce' ),
					$name,
					$visibility,
					$method->isStatic() ? 'static' : $actual
				);
			}
		}

		return $faults;
	}

	/**
	 * What the buyer resolver read off this document, for the operator who
	 * used to ask "which buyer account did that create". Nothing is created:
	 * Buyers\Identity is a pure value object, so this row is the whole
	 * visible result of a buyer identity — the visit row and the quote order
	 * carry the same two values as evidence, and nothing else.
	 */
	private static function buyer_detail( SetupMessage $message ): string {
		$buyer = Identity::from_message( $message );

		if ( '' === $buyer->identity && '' === $buyer->name ) {
			return __( 'none supplied — the visit is recorded without a buyer', 'punchout-woocommerce' );
		}

		if ( '' === $buyer->name ) {
			return $buyer->identity;
		}

		if ( '' === $buyer->identity ) {
			return $buyer->name;
		}

		// Not translatable: it is the buyer's own name beside their own
		// address, in the same shape the order note and the admin line use.
		return sprintf( '%1$s <%2$s>', $buyer->name, $buyer->identity );
	}

	/**
	 * The handler-shape row. A fault here has nothing to do with the pasted
	 * document, so it never changes the verdict — it is the one row on the
	 * page that reports the shop rather than the request.
	 *
	 * @return array{label: string, result: string, detail: string}
	 */
	private function cart_handler_check(): array {
		$label = __( 'Cart session handler', 'punchout-woocommerce' );

		if ( ! class_exists( '\\WC_Session_Handler' ) ) {
			return $this->check( $label, self::RESULT_INFO, __( 'Not evaluated here: WooCommerce is not loaded.', 'punchout-woocommerce' ) );
		}

		$faults = self::cart_handler_faults();

		if ( [] === $faults ) {
			return $this->check( $label, self::RESULT_PASS, __( 'WooCommerce still provides every session method a per-visit basket depends on.', 'punchout-woocommerce' ) );
		}

		return $this->check(
			$label,
			self::RESULT_FAIL,
			sprintf(
				/* translators: %s: comma-separated list of session handler faults. */
				__( 'This WooCommerce version changed its session handler, so catalog sessions are refused until the plugin is updated: %s', 'punchout-woocommerce' ),
				implode( ', ', $faults )
			)
		);
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
