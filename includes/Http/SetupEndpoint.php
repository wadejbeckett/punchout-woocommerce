<?php
/**
 * POST /punchout/setup — the pre-auth cXML endpoint.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Http;

use POW\Support\Transport;

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
use POW\Support\Ip;

defined( 'ABSPATH' ) || exit;

/**
 * Handles PunchOutSetupRequest and ProfileRequest (scope §3).
 *
 * Contract: HTTP status is 200 even for cXML-level failures — the failure
 * is expressed in the cXML Status code (a 400 alongside a valid StartPage
 * broke a real integration, gotcha 6). The codes and what each one means
 * are STATUS_REASONS below, which is also what the buyer-facing table is
 * built from; prose here could only go stale against it.
 */
final class SetupEndpoint {

	/**
	 * 2 MB hard cap on the request body (scope §3). Public: Docs\SelfTest
	 * refuses an oversized paste with the same limit rather than a
	 * second copy of the number.
	 */
	public const MAX_BODY_BYTES = 2 * 1024 * 1024;

	/**
	 * cXML Status codes this endpoint emits. HTTP is always 200; the
	 * failure lives in the cXML Status (a 400 alongside a valid StartPage
	 * broke a real integration, gotcha 6). Public: Docs\Reference builds
	 * the buyer-facing table from STATUS_REASONS, so the documented set
	 * and the emitted set cannot diverge.
	 */
	public const STATUS_OK           = 200;
	public const STATUS_AUTH_FAILED  = 401;
	public const STATUS_INVALID      = 406;
	public const STATUS_DUPLICATE    = 409;
	public const STATUS_UNSUPPORTED  = 450;
	public const STATUS_INTERNAL     = 500;
	public const STATUS_RATE_LIMITED = 550;

	/** @var array<int, string> Canonical Status/@text for each code. */
	public const STATUS_REASONS = [
		self::STATUS_OK           => 'success',
		self::STATUS_AUTH_FAILED  => 'Authentication failed',
		self::STATUS_INVALID      => 'Invalid document',
		self::STATUS_DUPLICATE    => 'Duplicate payloadID',
		self::STATUS_UNSUPPORTED  => 'Not supported',
		self::STATUS_INTERNAL     => 'Internal error',
		self::STATUS_RATE_LIMITED => 'Too many requests',
	];

	public function __construct(
		private Registry $registry,
		private Store $sessions,
		private Provisioner $provisioner,
		private Parser $parser,
		private Builder $builder,
		private RateLimiter $rate_limiter,
		private Log $audit,
		private RateLimiter $edge_limiter,
	) {}

	public function handle(): void {
		if ( ! Transport::request_allowed() ) { $this->transport_denied(); return; }
		$ip = $this->client_ip();

		try {
			$this->handle_inner( $ip );
		} catch ( ParseException $e ) {
			$this->audit_event(
				'setup_fail',
				[
					'direction' => 'in',
					'result'    => (string) $e->cxml_status,
					'detail'    => [ 'error' => 'Request processing failed' ],
					'ip'        => $ip,
				]
			);
			$this->respond( $this->status_doc( $e->cxml_status, $e->getMessage() ) );
		} catch ( \Throwable $e ) {
			$this->audit_event(
				'setup_fail',
				[
					'direction' => 'in',
					'result'    => '500',
					'detail'    => [ 'error' => 'Request processing failed' ],
					'ip'        => $ip,
				]
			);
			$this->respond( $this->status_doc( self::STATUS_INTERNAL, 'Internal error' ) );
		}
	}

	/**
	 * cXML 450 for the not-built /punchout/order endpoint (option O2).
	 */
	public function not_implemented(): void {
		if ( ! Transport::request_allowed() ) { $this->transport_denied(); return; }
		$this->audit_event(
			'po_rx',
			[
				'direction' => 'in',
				'result'    => '450',
				'ip'        => $this->client_ip(),
			]
		);
		$this->respond( $this->status_doc( self::STATUS_UNSUPPORTED, 'Not implemented' ) );
	}

	/** Preserve the endpoint's HTTP-200/cXML-status contract without reading the request body or changing state. */
	public function transport_denied(): void {
		Transport::private_headers();
		$this->respond( $this->status_doc( self::STATUS_AUTH_FAILED, Transport::message() ) );
	}

	private function handle_inner( string $ip ): void {
		// Pre-resolution, per-IP budget: refuse body reads, XML parsing and pre-auth archive rows once exhausted. Do not audit this rejection: avoiding that write is the purpose of the edge limit.
		if ( ! $this->edge_limiter->allow( 'edge|' . $ip ) ) {
			$this->respond( $this->status_doc( self::STATUS_RATE_LIMITED, self::STATUS_REASONS[ self::STATUS_RATE_LIMITED ] ) );
			return;
		}

		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) );

		if ( 'POST' !== $method ) {
			throw new ParseException( 'POST required', self::STATUS_INVALID );
		}

		$content_type = strtolower( (string) ( $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '' ) );

		if ( ! str_contains( $content_type, 'xml' ) ) {
			throw new ParseException( 'Content-Type must be text/xml', self::STATUS_INVALID );
		}

		$body = $this->read_body();

		// Pre-auth archive: this row is written before the sender is
		// authenticated, so an anonymous client must not be able to store
		// 2 MB per request. Real setup requests are a few KB; 64 KB keeps
		// full evidence for anything legitimate.
		$this->audit_event(
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
			// Unknown senders share a downstream budget per IP. Body reads, parsing and the pre-auth archive have already happened; the edge bucket above bounds those costs.
			if ( ! $this->rate_limiter->allow( 'unknown|' . $ip ) ) {
				$this->respond( $this->status_doc( self::STATUS_RATE_LIMITED, 'Too many requests', '1.2.008' ) );
				return;
			}

			$this->deny_auth( $message, $ip, 'unknown sender' );
			return;
		}

		if ( ! $this->rate_limiter->allow( $partner->id . '|' . $ip ) ) {
			$this->audit_event(
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
			$this->respond( $this->status_doc( self::STATUS_RATE_LIMITED, 'Too many requests', $partner->cxml_version ) );
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
				Router::setup_url()
			);

			$response = $this->registry->with_partner_lock( $partner->id, function () use ( $partner, $message, $ip, $response ) { $this->fresh_authorized( $partner, $message, $ip ); return $response; } );
			$this->audit_event(
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
			$this->audit_event(
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
			$this->respond( $this->status_doc( self::STATUS_UNSUPPORTED, 'Operation not supported', $partner->cxml_version ) );
			return;
		}

		// The punchback target the handoff form will POST to; anything but
		// plain http(s) is refused before it can reach a template.
		if ( ! Transport::receiver_allowed( $message->browser_form_post ) ) {
			throw new ParseException( 'BrowserFormPost requires a valid HTTPS URL (HTTP is allowed only in local/development environments)', self::STATUS_INVALID );
		}

		$payload_id = '' !== $message->payload_id ? $message->payload_id : 'noid-' . substr( $body_hash, 0, 32 );

		// Replay semantics (scope §7), decided by the pure policy and
		// enforced twice: here, and by the UNIQUE(partner_id, payload_id)
		// key underneath.
		$existing = $this->sessions->find_by_payload( $partner->id, $payload_id );
		$decision = ReplayPolicy::decide( $existing?->status, $existing?->body_hash, $body_hash );

		if ( ReplayPolicy::DECISION_NEW !== $decision ) {
			$replay = $this->registry->with_partner_lock( $partner->id, function () use ( $partner, $message, $ip, $payload_id, $body_hash ) {
				$this->fresh_authorized( $partner, $message, $ip );
				$current = $this->sessions->find_by_payload( $partner->id, $payload_id );
				return $current && $current->expires && $current->expires > gmdate( 'Y-m-d H:i:s' ) && ReplayPolicy::DECISION_REPLAY === ReplayPolicy::decide( $current->status, $current->body_hash, $body_hash ) && $current->response_xml ? $current : null;
			} );
			$this->audit_event( $replay ? 'setup_ok' : 'setup_fail', [ 'partner_id' => $partner->id, 'session_id' => $replay?->id ?? 0, 'direction' => 'out', 'payload_id' => $payload_id, 'result' => $replay ? 'ok' : '409', 'detail' => [ 'replay' => (bool) $replay ], 'ip' => $ip ] );
			$this->respond( $replay ? $replay->response_xml : $this->status_doc( self::STATUS_DUPLICATE, 'Duplicate payloadID', $partner->cxml_version ) );
			return;
		}

		// Provision (or locate) the per-(partner, buyer) user and apply
		// latest-punchout-wins to their open sessions (scope §5.1).
		$user_id = $this->provisioner->provision( $partner, $message );

		if ( 0 === $user_id ) {
			$this->respond( $this->status_doc( self::STATUS_INTERNAL, 'Provisioning failed', $partner->cxml_version ) );
			return;
		}

		$issued  = Tokens::issue();
		$expires = gmdate( 'Y-m-d H:i:s', time() + $partner->token_ttl );

		$start_url = Transport::supplier_url( home_url( '/punchout/start/' . $issued['token'] ) );
		$response = $this->builder->setup_response( $partner->cxml_version, Builder::payload_id( $this->host() ), Builder::timestamp(), $start_url );
		$session_id = 0;
		$response = $this->registry->with_partner_lock( $partner->id, function () use ( $partner, $message, $ip, $payload_id, $body_hash, $user_id, $issued, $expires, $response, &$session_id ) {
			$this->fresh_authorized( $partner, $message, $ip );
			$current = $this->sessions->find_by_payload( $partner->id, $payload_id );
			if ( $current ) {
				if ( $current->expires && $current->expires > gmdate( 'Y-m-d H:i:s' ) && ReplayPolicy::DECISION_REPLAY === ReplayPolicy::decide( $current->status, $current->body_hash, $body_hash ) && $current->response_xml ) { $session_id = $current->id; return $current->response_xml; }
				throw new ParseException( 'Duplicate payloadID', self::STATUS_DUPLICATE );
			}
			try {
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
					'response_xml'          => $response,
				]
				);
				if ( $session_id <= 0 ) { throw new ParseException( 'Session creation failed', self::STATUS_INTERNAL ); }
				$created = $this->sessions->find( $session_id );
				if ( ! $created || $created->response_xml !== $response || $created->status !== Session::PENDING ) { throw new ParseException( 'Session creation unconfirmed', self::STATUS_INTERNAL ); }
				// Provisioning hooks already ran outside the lock; only exact cleanup runs here.
				foreach ( $this->sessions->open_for_user( $user_id ) as $older ) {
					if ( $older->id === $session_id ) { continue; }
					if ( $older->partner_id !== $partner->id || ! $this->sessions->expire_locked( $older ) ) {
						$this->sessions->expire_locked( $created );
						$this->registry->transition_status( $partner->id, Partner::STATUS_ACTIVE, [ 'status' => Partner::STATUS_DISABLED ] );
						throw new ParseException( 'Session cleanup failed', self::STATUS_INTERNAL );
					}
					$this->audit_event( 'session_expired', [ 'partner_id' => $partner->id, 'session_id' => $older->id, 'user_id' => $user_id, 'result' => 'superseded' ] );
				}
			} catch ( \Throwable $e ) {
				$this->registry->transition_status( $partner->id, Partner::STATUS_ACTIVE, [ 'status' => Partner::STATUS_DISABLED ] );
				if ( $session_id > 0 ) {
					$failed = $this->sessions->find( $session_id );
					if ( $failed ) { $this->sessions->expire_locked( $failed ); }
				}
				throw new ParseException( 'Session persistence unconfirmed', self::STATUS_INTERNAL );
			}

			return $response;
		} );

		$this->audit_event(
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

	/** Must run inside the partner lock immediately before a setup result is committed. */
	private function fresh_authorized( Partner $snapshot, SetupMessage $message, string $ip ): void {
		$current = $this->registry->find_by_sender( $message->sender_domain, $message->sender_identity );
		if ( ! $current || $current->id !== $snapshot->id || ! $current->is_active() || ! $this->registry->ip_allowed( $current, $ip ) || null === $this->registry->verify_secret( $current, (string) $message->shared_secret ) ) {
			throw new ParseException( 'Authentication failed', self::STATUS_AUTH_FAILED );
		}
	}

	private function audit_event( string $event, array $context ): void {
		try { $this->audit->write_checked( $event, $context ); }
		catch ( \Throwable $e ) { /* Diagnostics cannot undo a confirmed setup result. */ }
	}

	private function deny_auth( SetupMessage $message, string $ip, string $reason, ?Partner $partner = null ): void {
		$this->audit_event(
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
		$this->respond( $this->status_doc( self::STATUS_AUTH_FAILED, 'Authentication failed', $partner?->cxml_version ?? $message->version ) );
	}

	private function read_body(): string {
		$stream = fopen( 'php://input', 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( false === $stream ) {
			throw new ParseException( 'Unreadable request body', self::STATUS_INVALID );
		}

		$body = stream_get_contents( $stream, self::MAX_BODY_BYTES + 1 );
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( false === $body || '' === $body ) {
			throw new ParseException( 'Empty request body', self::STATUS_INVALID );
		}

		if ( strlen( $body ) > self::MAX_BODY_BYTES ) {
			throw new ParseException( 'Request body exceeds limit', self::STATUS_INVALID );
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
		return Ip::client();
	}

	private function host(): string {
		return (string) wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'localhost';
	}
}
