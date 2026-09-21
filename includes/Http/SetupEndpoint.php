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
use POW\Buyers\Identity;
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
 *
 * A setup request produces a VISIT, not a person. The login is the
 * connection's own customer account (partners.owner_user_id) and no user is
 * ever created, found by identity or renamed here; the buyer's name and
 * e-mail are data on the visit row (Buyers\Identity). Consequences that hold
 * everywhere below: every open visit of one connection carries the same
 * user_id, so the StartPage token is the only thing separating them and the
 * buyer identity hash is the only thing a supersede may key on; a connection
 * with no usable account is refused outright; and the number of visits one
 * connection may hold open at once is capped.
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
	private const REPLAY_WAIT_MICROSECONDS = 3_000_000;
	private const REPLAY_POLL_MICROSECONDS = 25_000;

	/** @var array<string, mixed> Safe request identifiers for terminal failure audit. */
	private array $failure_context = [];

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
		private Parser $parser,
		private Builder $builder,
		private RateLimiter $rate_limiter,
		private Log $audit,
		private RateLimiter $edge_limiter,
	) {}

	public function handle(): void {
		if ( ! Transport::request_allowed() ) { $this->transport_denied(); return; }
		$ip = $this->client_ip();
		$this->failure_context = [ 'direction' => 'in', 'ip' => $ip ];

		try {
			$this->handle_inner( $ip );
		} catch ( ParseException $e ) {
			$this->audit_event(
				'setup_fail',
				array_replace(
					$this->failure_context,
					[
					'result'    => (string) $e->cxml_status,
					'detail'    => [ 'error' => 'Request processing failed' ],
					]
				)
			);
			$this->respond( $this->status_doc( $e->cxml_status, $e->getMessage() ) );
		} catch ( \Throwable $e ) {
			$this->audit_event(
				'setup_fail',
				array_replace(
					$this->failure_context,
					[
					'result'    => '500',
					'detail'    => [ 'error' => 'Request processing failed' ],
					]
				)
			);
			$this->respond( $this->status_doc( self::STATUS_INTERNAL, 'Internal error' ) );
		} finally {
			$this->failure_context = [];
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
		$this->failure_context['payload_id'] = $message->payload_id;

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
		$this->failure_context['partner_id'] = $partner->id;

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

		// The connection's own customer account is the login every buyer of
		// this connection is signed in as. Resolved here — after the sender
		// is authenticated, before the operation and punchback checks and
		// long before any claim row — so an unbound or unusable account
		// never leaves a pending row behind to compensate for. A
		// ProfileRequest is deliberately above this line: it answers the
		// buyer's connectivity test and needs no login at all.
		$user_id = $this->bound_account( $partner );

		if ( $user_id <= 0 ) {
			$this->audit_event(
				'setup_no_login',
				[
					'partner_id' => $partner->id,
					'direction'  => 'in',
					'payload_id' => $message->payload_id,
					'result'     => 'no_login',
					'detail'     => [ 'error' => 'no customer account bound to this connection' ],
					'ip'         => $ip,
				]
			);
			$this->respond( $this->status_doc( self::STATUS_INTERNAL, self::STATUS_REASONS[ self::STATUS_INTERNAL ], $partner->cxml_version ) );
			return;
		}

		$identity = Identity::from_message( $message );

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

		$this->failure_context['payload_id'] = $payload_id;
		$claim = null;
		$replay = null;
		$response = '';
		for ( $attempt = 0; $attempt < 2 && ! $claim && ! $replay; ++$attempt ) {
			// A fresh token per attempt is load-bearing, not hygiene: every
			// visit of this connection is the same account, so the StartPage
			// token is the only thing that tells one visit from another. It
			// may never be reused across visits or attempts.
			$issued = Tokens::issue();
			$expires = gmdate( 'Y-m-d H:i:s', time() + $partner->token_ttl );
			$start_url = Transport::supplier_url( home_url( '/punchout/start/' . $issued['token'] ) );
			$candidate_response = $this->builder->setup_response( $partner->cxml_version, Builder::payload_id( $this->host() ), Builder::timestamp(), $start_url );
			$outcome = $this->claim_setup( $partner, $message, $identity, $ip, $payload_id, $body_hash, $issued['hash'], $expires );
			if ( 'conflict' === $outcome['state'] ) {
				$this->audit_event( 'setup_fail', [ 'partner_id' => $partner->id, 'session_id' => $outcome['session']?->id ?? 0, 'direction' => 'out', 'payload_id' => $payload_id, 'result' => '409', 'detail' => [ 'replay' => false ], 'ip' => $ip ] );
				$this->respond( $this->status_doc( self::STATUS_DUPLICATE, 'Duplicate payloadID', $partner->cxml_version ) );
				return;
			}
			if ( 'replay' === $outcome['state'] ) {
				$replay = $outcome['session'];
				break;
			}
			if ( 'waiting' === $outcome['state'] ) {
				$replay = $this->await_setup_response( $partner->id, $payload_id, $body_hash );
				continue;
			}
			$claim = $outcome['session'];
			$response = $candidate_response;
		}

		if ( $replay ) {
			$this->audit_event( 'setup_ok', [ 'partner_id' => $partner->id, 'session_id' => $replay->id, 'user_id' => $replay->user_id, 'direction' => 'out', 'payload_id' => $payload_id, 'result' => 'replay', 'detail' => [ 'replay' => true ], 'ip' => $ip ] );
			$this->respond( (string) $replay->response_xml );
			return;
		}
		if ( ! $claim ) {
			throw new ParseException( 'Setup still processing', self::STATUS_INTERNAL );
		}
		$this->failure_context['session_id'] = $claim->id;
		// The visit is committed against the account resolved above; no user
		// is created, looked up by identity or renamed anywhere in this path.
		$this->failure_context['user_id'] = $user_id;

		$committed = null;
		try {
			$committed = $this->registry->with_partner_lock( $partner->id, function () use ( $partner, $message, $identity, $ip, $claim, $user_id, $response ) {
				$this->fresh_authorized( $partner, $message, $ip, $user_id );
				if ( ! $this->sessions->complete_setup_claim( $claim, $user_id, $response ) ) {
					$this->registry->transition_status( $partner->id, Partner::STATUS_ACTIVE, [ 'status' => Partner::STATUS_DISABLED ] );
					throw new ParseException( 'Session persistence unconfirmed', self::STATUS_INTERNAL );
				}
				$created = $this->sessions->find( $claim->id );
				if ( ! $this->is_committed_setup( $created, $partner->id, $claim->payload_id, $claim->body_hash, $user_id, $response ) ) {
					$this->registry->transition_status( $partner->id, Partner::STATUS_ACTIVE, [ 'status' => Partner::STATUS_DISABLED ] );
					throw new ParseException( 'Session persistence unconfirmed', self::STATUS_INTERNAL );
				}
				// Latest-wins is per buyer, not per account: every buyer of
				// this connection shares $user_id, so superseding by user id
				// would end a colleague's live visit on every punchin — and,
				// when an expiry could not be confirmed, disable the whole
				// connection. The identity hash is the only discriminator,
				// it is scoped to this connection in SQL, and an empty one
				// matches nothing: a purchasing system that names nobody
				// gets independent visits, held only by the cap above.
				$identity_hash = $identity->hash( $partner->id );

				foreach ( $this->sessions->open_for_identity( $partner->id, $identity_hash ) as $older ) {
					if ( $older->id === $claim->id ) { continue; }
					if ( $older->partner_id !== $partner->id || ! $this->sessions->expire_locked( $older ) ) {
						$this->sessions->expire_locked( $created );
						$this->registry->transition_status( $partner->id, Partner::STATUS_ACTIVE, [ 'status' => Partner::STATUS_DISABLED ] );
						throw new ParseException( 'Session cleanup failed', self::STATUS_INTERNAL );
					}
					$this->audit_event( 'session_expired', [ 'partner_id' => $partner->id, 'session_id' => $older->id, 'user_id' => $user_id, 'result' => 'superseded', 'detail' => [ 'buyer_hash' => substr( $identity_hash, 0, 12 ) ] ] );
				}
				return $created;
			} );
		} catch ( \Throwable $error ) {
			$fresh = $this->sessions->find( $claim->id );
			if ( $this->is_committed_setup( $fresh, $partner->id, $payload_id, $body_hash, $user_id, $response ) ) {
				$committed = $fresh;
			} else {
				$this->abandon_setup_claim( $claim );
				throw $error;
			}
		}
		if ( ! $committed ) {
			throw new ParseException( 'Session persistence unconfirmed', self::STATUS_INTERNAL );
		}
		$session_id = $committed->id;

		$this->audit_event(
			'setup_ok',
			[
				'partner_id' => $partner->id,
				'session_id' => $session_id,
				'user_id'    => $user_id,
				'direction'  => 'out',
				'payload_id' => $payload_id,
				'result'     => 'ok',
				// Who punched in, without the raw e-mail: the log is read by
				// more people than the orders screen is, and the identity is
				// already on the visit row and the quote order for the
				// operator who legitimately needs it.
				'detail'     => array_merge(
					[
						'slot'            => $slot,
						'operation'       => $message->operation,
						'deployment_mode' => $message->deployment_mode,
					],
					'' !== $identity->identity
						? [ 'buyer_hash' => substr( $identity->hash( $partner->id ), 0, 12 ), 'buyer_name' => $identity->name ]
						: [ 'buyer' => 'none' ]
				),
				'ip'         => $ip,
			]
		);

		$this->respond( $response );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/** @return array{state: 'winner'|'waiting'|'replay'|'conflict', session: ?Session} */
	private function claim_setup( Partner $partner, SetupMessage $message, Identity $identity, string $ip, string $payload_id, string $body_hash, string $token_hash, string $expires ): array {
		$inserted_claim = null;
		try {
			return $this->registry->with_partner_lock( $partner->id, function () use ( $partner, $message, $identity, $ip, $payload_id, $body_hash, $token_hash, $expires, &$inserted_claim ) {
				$this->fresh_authorized( $partner, $message, $ip );
				$current = $this->sessions->find_by_payload( $partner->id, $payload_id );
				if ( $this->is_uncommitted_setup( $current, $partner->id, $payload_id, $body_hash ) && ( ! $current->expires || $current->expires <= gmdate( 'Y-m-d H:i:s' ) ) ) {
					if ( ! $this->sessions->abandon_setup_claim( $current ) ) {
						throw new ParseException( 'Stale setup claim could not be released', self::STATUS_INTERNAL );
					}
					$current = null;
				}
				if ( $current ) {
					return $this->setup_outcome( $current, $partner->id, $payload_id, $body_hash );
				}

				$this->refuse_over_cap( $partner, $payload_id, $ip );

				$session_id = $this->sessions->create(
					[
						'partner_id'            => $partner->id,
						'buyer_cookie'          => $message->buyer_cookie,
						'operation'             => $message->operation,
						'browser_form_post_url' => $message->browser_form_post,
						'selected_item'         => null !== $message->selected_item ? (string) wp_json_encode( $message->selected_item ) : null,
						'ship_to'               => $message->ship_to_xml,
						'user_id'               => 0,
						'one_time_token_hash'   => $token_hash,
						'status'                => Session::PENDING,
						'payload_id'            => $payload_id,
						'body_hash'             => $body_hash,
						'cxml_version'          => $message->version,
						'deployment_mode'       => $message->deployment_mode,
						'extrinsics'            => (string) wp_json_encode( $message->extrinsics ),
						'itemout_lines'         => [] !== $message->item_out ? (string) wp_json_encode( $message->item_out ) : null,
						'cart_ready'            => 0,
						'expires'               => $expires,
						'response_xml'          => null,
						// Who punched in, as data on the visit. The raw value
						// is stored in clear because an administrator has to
						// be able to see who bought; the indexed column is
						// the hash. An unidentified buyer stores NULL rather
						// than a hash every anonymous visit would share.
						'buyer_identity'        => $identity->identity,
						'buyer_name'            => $identity->name,
						'buyer_identity_hash'   => '' !== $identity->identity ? $identity->hash( $partner->id ) : null,
					]
				);
				if ( $session_id <= 0 ) {
					$current = $this->sessions->find_by_payload( $partner->id, $payload_id );
					if ( $current ) {
						return $this->setup_outcome( $current, $partner->id, $payload_id, $body_hash );
					}
					throw new ParseException( 'Session claim failed', self::STATUS_INTERNAL );
				}
				$inserted_claim = Session::from_row(
					[
						'id'                  => $session_id,
						'partner_id'          => $partner->id,
						'user_id'             => 0,
						'one_time_token_hash' => $token_hash,
						'status'              => Session::PENDING,
						'payload_id'          => $payload_id,
						'body_hash'           => $body_hash,
						'expires'             => $expires,
						'response_xml'        => null,
					]
				);
				$claim = $this->sessions->find( $session_id );
				if ( ! $this->is_uncommitted_setup( $claim, $partner->id, $payload_id, $body_hash, $token_hash ) || ! $claim->expires || $claim->expires <= gmdate( 'Y-m-d H:i:s' ) ) {
					throw new ParseException( 'Session claim unconfirmed', self::STATUS_INTERNAL );
				}
				$inserted_claim = $claim;
				return [ 'state' => 'winner', 'session' => $claim ];
			} );
		} catch ( \Throwable $error ) {
			// Only this invocation's freshly inserted ID/token pair is eligible for
			// guarded cleanup. Pre-insert auth/lock failures own no row.
			if ( $inserted_claim ) { $this->abandon_setup_claim( $inserted_claim ); }
			throw $error;
		}
	}

	/** @return array{state: 'waiting'|'replay'|'conflict', session: Session} */
	private function setup_outcome( Session $session, int $partner_id, string $payload_id, string $body_hash ): array {
		if ( ReplayPolicy::DECISION_REPLAY !== ReplayPolicy::decide( $session->status, $session->body_hash, $body_hash ) || $session->partner_id !== $partner_id || $session->payload_id !== $payload_id || ! $session->expires || $session->expires <= gmdate( 'Y-m-d H:i:s' ) ) {
			return [ 'state' => 'conflict', 'session' => $session ];
		}
		if ( $this->is_uncommitted_setup( $session, $partner_id, $payload_id, $body_hash ) ) {
			return [ 'state' => 'waiting', 'session' => $session ];
		}
		if ( $session->user_id > 0 && null !== $session->response_xml && '' !== $session->response_xml ) {
			return [ 'state' => 'replay', 'session' => $session ];
		}
		throw new ParseException( 'Stored setup response is incomplete', self::STATUS_INTERNAL );
	}

	/** Poll only the persisted claim, bounded well below request timeouts; null means the failed winner released it. */
	private function await_setup_response( int $partner_id, string $payload_id, string $body_hash ): ?Session {
		$deadline = hrtime( true ) + ( self::REPLAY_WAIT_MICROSECONDS * 1000 );
		do {
			$current = $this->sessions->find_by_payload( $partner_id, $payload_id );
			if ( ! $current ) {
				return null;
			}
			$outcome = $this->setup_outcome( $current, $partner_id, $payload_id, $body_hash );
			if ( 'replay' === $outcome['state'] ) {
				return $current;
			}
			if ( 'conflict' === $outcome['state'] ) {
				throw new ParseException( 'Duplicate payloadID', self::STATUS_DUPLICATE );
			}
			usleep( self::REPLAY_POLL_MICROSECONDS );
		} while ( hrtime( true ) < $deadline );
		throw new ParseException( 'Setup still processing', self::STATUS_INTERNAL );
	}

	private function abandon_setup_claim( Session $claim ): bool {
		try {
			$released = $this->registry->with_partner_lock( $claim->partner_id, fn(): bool => $this->sessions->abandon_setup_claim( $claim ) );
			if ( $released ) { return true; }
		} catch ( \Throwable $error ) {
			// A release exception after the DELETE is resolved by the fresh read below.
		}
		try { return null === $this->sessions->find( $claim->id ); }
		catch ( \Throwable $error ) { return false; }
	}

	private function is_uncommitted_setup( ?Session $session, int $partner_id, string $payload_id, string $body_hash, ?string $token_hash = null ): bool {
		return $session && $session->partner_id === $partner_id && $session->payload_id === $payload_id && $session->body_hash === $body_hash && ( null === $token_hash || $session->one_time_token_hash === $token_hash ) && Session::PENDING === $session->status && 0 === $session->user_id && null === $session->response_xml;
	}

	private function is_committed_setup( ?Session $session, int $partner_id, string $payload_id, string $body_hash, int $user_id, string $response_xml ): bool {
		return $session && $session->partner_id === $partner_id && $session->payload_id === $payload_id && $session->body_hash === $body_hash && Session::PENDING === $session->status && $session->user_id === $user_id && $session->response_xml === $response_xml && $session->expires && $session->expires > gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Must run inside the partner lock immediately before a setup result is
	 * committed. With $expected_account non-zero it also re-proves the login:
	 * a connection unbound, re-pointed at another account or pointed at one
	 * that has since gained a privileged capability between the claim and the
	 * commit must not commit a visit against the account it was claimed for.
	 */
	private function fresh_authorized( Partner $snapshot, SetupMessage $message, string $ip, int $expected_account = 0 ): void {
		$current = $this->registry->find_by_sender( $message->sender_domain, $message->sender_identity );
		if ( ! $current || $current->id !== $snapshot->id || ! $current->is_active() || ! $this->registry->ip_allowed( $current, $ip ) || null === $this->registry->verify_secret( $current, (string) $message->shared_secret ) ) {
			throw new ParseException( 'Authentication failed', self::STATUS_AUTH_FAILED );
		}
		if ( $expected_account > 0 && $this->bound_account( $current ) !== $expected_account ) {
			throw new ParseException( 'Bound login changed', self::STATUS_INTERNAL );
		}
	}

	/**
	 * The connection's customer account, proved usable, or 0.
	 *
	 * A read and a capability test, never a write: the plugin creates,
	 * renames and deletes no users. `read` is what makes an account a
	 * customer that can be signed in at all, and
	 * Registry::PRIVILEGED_CAPABILITIES — the same list the bind screen
	 * refuses on — is what stops a buyer's purchasing system punching into
	 * the shop's own management. 0 is the single answer for every unusable
	 * state — unset, deleted, or too powerful — because the refusal and the
	 * admin notice are the same either way.
	 */
	private function bound_account( Partner $partner ): int {
		$user_id = $partner->owner_user_id;

		if ( $user_id <= 0 ) {
			return 0;
		}

		$user = get_userdata( $user_id );

		if ( ! $user || (int) $user->ID !== $user_id || ! user_can( $user, 'read' ) ) {
			return 0;
		}

		if ( Registry::privileged( $user ) ) {
			return 0;
		}

		return $user_id;
	}

	/**
	 * Refuse a connection that already holds its maximum open visits.
	 *
	 * Runs inside the partner lock and before the insert, so the count cannot
	 * be raced and there is one refusal point rather than one per caller.
	 * Expired-but-open rows of this connection are swept first: they count
	 * toward the cap, so a connection whose buyers never punch back would
	 * otherwise fill it permanently. Only this connection's rows are swept —
	 * another connection's row belongs to another lock. The limit is
	 * Store::MAX_OPEN_VISITS and is deliberately not filterable.
	 */
	private function refuse_over_cap( Partner $partner, string $payload_id, string $ip ): void {
		foreach ( $this->sessions->expired_open() as $stale ) {
			if ( $stale->partner_id === $partner->id ) {
				$this->sessions->expire_locked( $stale );
			}
		}

		$open = $this->sessions->count_open_for_partner( $partner->id );

		if ( $open < Store::MAX_OPEN_VISITS ) {
			return;
		}

		$this->audit_event(
			'setup_visit_cap',
			[
				'partner_id' => $partner->id,
				'direction'  => 'in',
				'payload_id' => $payload_id,
				'result'     => 'cap',
				'detail'     => [ 'error' => 'open visit limit reached', 'open' => $open, 'limit' => Store::MAX_OPEN_VISITS ],
				'ip'         => $ip,
			]
		);

		throw new ParseException( 'Open visit limit reached', self::STATUS_INTERNAL );
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
