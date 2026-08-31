<?php
/**
 * POST /punchout/setup — the pre-auth cXML endpoint.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Http;

use POW\Audit\Log;
use POW\Buyers\Provisioner;
use POW\Cxml\Builder;
use POW\Cxml\ParseException;
use POW\Cxml\Parser;
use POW\Cxml\SetupMessage;
use POW\Partners\Partner;
use POW\Partners\Registry;
use POW\Sessions\ReplayPolicy;
use POW\Sessions\Session;
use POW\Sessions\Store;
use POW\Sessions\Tokens;

defined( 'ABSPATH' ) || exit;

/**
 * Handles PunchOutSetupRequest and ProfileRequest (scope §3).
 *
 * Contract: HTTP status is 200 even for cXML-level failures — the failure
 * is expressed in the cXML Status code (a 400 alongside a valid StartPage
 * broke a real integration, gotcha 6). Codes: 401 unknown identity / bad
 * secret / IP reject; 406 unparseable or structurally invalid; 409
 * duplicate payloadID after token redemption; 450 unsupported request or
 * operation; 500 internal; 550 rate limited.
 */
final class SetupEndpoint {

	private const MAX_BODY_BYTES = 2 * 1024 * 1024; // 2 MB hard cap (scope §3).

	public function __construct(
		private Registry $registry,
		private Store $sessions,
		private Provisioner $provisioner,
		private Parser $parser,
		private Builder $builder,
		private RateLimiter $rate_limiter,
		private Log $audit,
	) {}

	public function handle(): void {
		$ip = $this->client_ip();

		try {
			$this->handle_inner( $ip );
		} catch ( ParseException $e ) {
			$this->audit->write(
				'setup_fail',
				[
					'direction' => 'in',
					'result'    => (string) $e->cxml_status,
					'detail'    => [ 'error' => $e->getMessage() ],
					'ip'        => $ip,
				]
			);
			$this->respond( $this->status_doc( $e->cxml_status, $e->getMessage() ) );
		} catch ( \Throwable $e ) {
			$this->audit->write(
				'setup_fail',
				[
					'direction' => 'in',
					'result'    => '500',
					'detail'    => [ 'error' => $e->getMessage() ],
					'ip'        => $ip,
				]
			);
			$this->respond( $this->status_doc( 500, 'Internal error' ) );
		}
	}

	/**
	 * cXML 450 for the not-built /punchout/order endpoint (option O2).
	 */
	public function not_implemented(): void {
		$this->audit->write(
			'po_rx',
			[
				'direction' => 'in',
				'result'    => '450',
				'ip'        => $this->client_ip(),
			]
		);
		$this->respond( $this->status_doc( 450, 'Not implemented' ) );
	}

	private function handle_inner( string $ip ): void {
		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) );

		if ( 'POST' !== $method ) {
			throw new ParseException( 'POST required', 406 );
		}

		$content_type = strtolower( (string) ( $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '' ) );

		if ( ! str_contains( $content_type, 'xml' ) ) {
			throw new ParseException( 'Content-Type must be text/xml', 406 );
		}

		$body = $this->read_body();

		// Pre-auth archive: this row is written before the sender is
		// authenticated, so an anonymous client must not be able to store
		// 2 MB per request. Real setup requests are a few KB; 64 KB keeps
		// full evidence for anything legitimate.
		$this->audit->write(
			'setup_rx',
			[
				'direction' => 'in',
				'xml'       => strlen( $body ) > 65536 ? substr( $body, 0, 65536 ) . "\n<!-- pow: pre-auth archive capped at 64 KB -->" : $body,
				'ip'        => $ip,
			]
		);

		$message   = $this->parser->parse( $body );
		$body_hash = hash( 'sha256', $body );

		// Resolve the customer connection by the Sender credential; failures
		// are all the same generic 401 with no detail (scope §7).
		$partner = $this->registry->find_by_sender( $message->sender_domain, $message->sender_identity );

		if ( null === $partner || ! $partner->is_active() ) {
			// Unknown senders consume (and are bounded by) their own
			// rate-limit bucket so credential scanning cannot hammer the
			// parser — or flood the audit table — for free.
			if ( ! $this->rate_limiter->allow( 'unknown|' . $ip ) ) {
				$this->respond( $this->status_doc( 550, 'Too many requests', '1.2.008' ) );
				return;
			}

			$this->deny_auth( $message, $ip, 'unknown sender' );
			return;
		}

		if ( ! $this->rate_limiter->allow( $partner->id . '|' . $ip ) ) {
			$this->audit->write(
				'setup_fail',
				[
					'partner_id' => $partner->id,
					'direction'  => 'in',
					'payload_id' => $message->payload_id,
					'result'     => '550',
					'detail'     => [ 'error' => 'rate limited' ],
					'ip'         => $ip,
				]
			);
			$this->respond( $this->status_doc( 550, 'Too many requests', $partner->cxml_version ) );
			return;
		}

		if ( ! $this->registry->ip_allowed( $partner, $ip ) ) {
			$this->deny_auth( $message, $ip, 'ip rejected', $partner );
			return;
		}

		$slot = $this->registry->verify_secret( $partner, (string) $message->shared_secret );

		if ( null === $slot ) {
			$this->deny_auth( $message, $ip, 'bad secret', $partner );
			return;
		}

		if ( SetupMessage::KIND_PROFILE === $message->kind ) {
			$response = $this->builder->profile_response(
				$partner->cxml_version,
				Builder::payload_id( $this->host() ),
				Builder::timestamp(),
				home_url( '/punchout/setup' )
			);

			$this->audit->write(
				'profile_rx',
				[
					'partner_id' => $partner->id,
					'direction'  => 'in',
					'payload_id' => $message->payload_id,
					'result'     => 'ok',
					'detail'     => [ 'slot' => $slot ],
					'ip'         => $ip,
				]
			);
			$this->respond( $response );
			return;
		}

		// Re-entry (edit/inspect/source) is a protocol capability the
		// registry parameterises but this build does not service (scope
		// §2.5, option O1): D365 F&O sends operation="create" only.
		if ( 'create' !== $message->operation ) {
			$this->audit->write(
				'setup_fail',
				[
					'partner_id' => $partner->id,
					'direction'  => 'in',
					'payload_id' => $message->payload_id,
					'result'     => '450',
					'detail'     => [ 'error' => 'operation not supported', 'operation' => $message->operation ],
					'ip'         => $ip,
				]
			);
			$this->respond( $this->status_doc( 450, 'Operation not supported', $partner->cxml_version ) );
			return;
		}

		// The punchback target the handoff form will POST to; anything but
		// plain http(s) is refused before it can reach a template.
		if ( ! preg_match( '#^https?://#i', $message->browser_form_post ) ) {
			throw new ParseException( 'BrowserFormPost URL must be http(s)', 406 );
		}

		$payload_id = '' !== $message->payload_id ? $message->payload_id : 'noid-' . substr( $body_hash, 0, 32 );

		// Replay semantics (scope §7), decided by the pure policy and
		// enforced twice: here, and by the UNIQUE(partner_id, payload_id)
		// key underneath.
		$existing = $this->sessions->find_by_payload( $partner->id, $payload_id );
		$decision = ReplayPolicy::decide( $existing?->status, $existing?->body_hash, $body_hash );

		if ( ReplayPolicy::DECISION_REPLAY === $decision && null !== $existing && null !== $existing->response_xml ) {
			$this->audit->write(
				'setup_ok',
				[
					'partner_id' => $partner->id,
					'session_id' => $existing->id,
					'direction'  => 'out',
					'payload_id' => $payload_id,
					'result'     => 'replay',
					'ip'         => $ip,
				]
			);
			$this->respond( $existing->response_xml );
			return;
		}

		if ( ReplayPolicy::DECISION_NEW !== $decision ) {
			$this->audit->write(
				'setup_fail',
				[
					'partner_id' => $partner->id,
					'session_id' => $existing?->id ?? 0,
					'direction'  => 'in',
					'payload_id' => $payload_id,
					'result'     => '409',
					'detail'     => [ 'error' => 'duplicate payloadID' ],
					'ip'         => $ip,
				]
			);
			$this->respond( $this->status_doc( 409, 'Duplicate payloadID', $partner->cxml_version ) );
			return;
		}

		// Provision (or locate) the per-(partner, buyer) user and apply
		// latest-punchout-wins to their open sessions (scope §5.1).
		$user_id = $this->provisioner->provision( $partner, $message );

		if ( 0 === $user_id ) {
			$this->respond( $this->status_doc( 500, 'Provisioning failed', $partner->cxml_version ) );
			return;
		}

		$issued  = Tokens::issue();
		$expires = gmdate( 'Y-m-d H:i:s', time() + $partner->token_ttl );

		$session_id = $this->sessions->create(
			[
				'partner_id'            => $partner->id,
				'buyer_cookie'          => $message->buyer_cookie,
				'operation'             => $message->operation,
				'browser_form_post_url' => $message->browser_form_post,
				'selected_item'         => null !== $message->selected_item ? (string) wp_json_encode( $message->selected_item ) : null,
				'ship_to'               => $message->ship_to_xml,
				'user_id'               => $user_id,
				'one_time_token_hash'   => $issued['hash'],
				'status'                => Session::PENDING,
				'payload_id'            => $payload_id,
				'body_hash'             => $body_hash,
				'cxml_version'          => $message->version,
				'deployment_mode'       => $message->deployment_mode,
				'extrinsics'            => (string) wp_json_encode( $message->extrinsics ),
				'itemout_lines'         => [] !== $message->item_out ? (string) wp_json_encode( $message->item_out ) : null,
				'cart_ready'            => 0,
				'expires'               => $expires,
			]
		);

		if ( 0 === $session_id ) {
			// Lost a race on the UNIQUE(partner_id, payload_id) key: the
			// concurrent twin owns the row now, so this duplicate is a 409.
			$this->respond( $this->status_doc( 409, 'Duplicate payloadID', $partner->cxml_version ) );
			return;
		}

		// Latest-punchout-wins AFTER the new row exists (sparing it): the
		// sweep must never run for a setup that then fails to create its
		// session — a concurrent twin or failed insert would strand the
		// buyer with every session expired and nothing to redeem.
		$this->provisioner->latest_wins( $user_id, $session_id );

		$start_url = home_url( '/punchout/start/' . $issued['token'] );

		$response = $this->builder->setup_response(
			$partner->cxml_version,
			Builder::payload_id( $this->host() ),
			Builder::timestamp(),
			$start_url
		);

		// Stored verbatim for the pending-state replay rule; the one-time
		// token inside it is a bearer credential, but the row already
		// holds its hash and the URL dies at first redemption.
		$this->sessions->update( $session_id, [ 'response_xml' => $response ] );

		$this->audit->write(
			'setup_ok',
			[
				'partner_id' => $partner->id,
				'session_id' => $session_id,
				'user_id'    => $user_id,
				'direction'  => 'out',
				'payload_id' => $payload_id,
				'result'     => 'ok',
				'detail'     => [
					'slot'            => $slot,
					'operation'       => $message->operation,
					'deployment_mode' => $message->deployment_mode,
				],
				'ip'         => $ip,
			]
		);

		$this->respond( $response );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	private function deny_auth( SetupMessage $message, string $ip, string $reason, ?Partner $partner = null ): void {
		$this->audit->write(
			'setup_fail',
			[
				'partner_id' => $partner?->id ?? 0,
				'direction'  => 'in',
				'payload_id' => $message->payload_id,
				'result'     => '401',
				'detail'     => [
					'error'  => $reason,
					'sender' => $message->sender_domain . '/' . $message->sender_identity,
				],
				'ip'         => $ip,
			]
		);

		// Generic wording regardless of the actual reason (scope §7).
		$this->respond( $this->status_doc( 401, 'Authentication failed', $partner?->cxml_version ?? $message->version ) );
	}

	private function read_body(): string {
		$stream = fopen( 'php://input', 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( false === $stream ) {
			throw new ParseException( 'Unreadable request body', 406 );
		}

		$body = stream_get_contents( $stream, self::MAX_BODY_BYTES + 1 );
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( false === $body || '' === $body ) {
			throw new ParseException( 'Empty request body', 406 );
		}

		if ( strlen( $body ) > self::MAX_BODY_BYTES ) {
			throw new ParseException( 'Request body exceeds limit', 406 );
		}

		return $body;
	}

	private function status_doc( int $code, string $text, string $version = '' ): string {
		return $this->builder->status(
			'' !== $version ? $version : '1.2.008',
			Builder::payload_id( $this->host() ),
			Builder::timestamp(),
			$code,
			$text
		);
	}

	private function respond( string $xml ): void {
		status_header( 200 );
		header( 'Content-Type: text/xml; charset=utf-8' );
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- serialised XML document.
	}

	private function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';

		/**
		 * Filter the client IP used for rate limiting, allowlists and the
		 * audit trail — e.g. to read CF-Connecting-IP behind a proxy whose
		 * presence the operator can vouch for.
		 *
		 * @param string $ip Remote address.
		 */
		return (string) apply_filters( 'pow_client_ip', $ip );
	}

	private function host(): string {
		return (string) wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'localhost';
	}
}
