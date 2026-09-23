<?php
/**
 * Punchout session row object + state machine.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Sessions;

defined( 'ABSPATH' ) || exit;

/**
 * One punchout visit: one row per PunchOutSetupRequest (scope §4.2).
 *
 * Many rows share one `user_id` — a connection has a single bound customer
 * account and every buyer of that customer punches in as it. The visit
 * identity is therefore the row itself: `wc_session_key` names the visit's
 * own WooCommerce basket, and `wp_session_token` names the visit's own
 * WordPress login. Neither `user_id` nor the account's capabilities
 * distinguish one visit from another, so no ownership question may be
 * answered with them.
 *
 * Buyer identity (`buyer_identity`, `buyer_name`, `buyer_identity_hash`) is
 * data the purchasing system supplied, not a user: it attributes the visit
 * and may be entirely absent.
 *
 * State machine (scope §4.2/§5.4/§9.7):
 *
 *   pending  --token redeemed-->  active
 *   active   --RFQ exit-------->  returned
 *   active   --empty POOM------->  closed   ("return without ordering")
 *   pending|active|ordered --cron/supersede--> expired
 *
 * ORDERED is inert vocabulary. Checkout is blocked inside every visit, so
 * no code path writes it any more; the constant and its two transition rows
 * stay so rows and audit entries written before the paid exit was removed
 * still parse, and so an ordered row still counts as open (Store defines
 * "open" as pending|active|ordered in six places, including the
 * per-connection visit cap).
 *
 * The transition table is pure and unit-tested; Store enforces it in SQL
 * (UPDATE ... WHERE status IN (...)) so concurrent requests cannot race a
 * row into an illegal state.
 */
final class Session {

	public const PENDING  = 'pending';
	public const ACTIVE   = 'active';
	public const RETURNED = 'returned';
	/** Historical only: nothing writes this status since the paid exit was removed. */
	public const ORDERED  = 'ordered';
	public const CLOSED   = 'closed';
	public const EXPIRED  = 'expired';

	private const TRANSITIONS = [
		self::PENDING => [ self::ACTIVE, self::EXPIRED ],
		// The two ORDERED terms are unreachable by design; see the class docblock.
		self::ACTIVE  => [ self::RETURNED, self::ORDERED, self::CLOSED, self::EXPIRED ],
		self::ORDERED => [ self::CLOSED, self::EXPIRED ],
	];

	public function __construct(
		public readonly int $id,
		public readonly int $partner_id,
		public readonly string $buyer_cookie,
		public readonly string $operation,
		public readonly string $browser_form_post_url,
		public readonly ?string $selected_item,
		public readonly ?string $ship_to,
		public readonly int $user_id,
		public readonly string $wp_session_token,
		public readonly string $one_time_token_hash,
		public readonly string $status,
		public readonly int $order_id,
		public readonly string $payload_id,
		public readonly string $body_hash,
		public readonly ?string $response_xml,
		public readonly string $cxml_version,
		public readonly string $deployment_mode,
		public readonly ?string $extrinsics,
		public readonly ?string $itemout_lines,
		public readonly bool $cart_ready,
		public readonly ?string $created,
		public readonly ?string $expires,
		public readonly ?string $delivery_choice_json = null,
		public readonly ?string $delivery_confirmation_json = null,
		// Buyer attribution. Empty is legal: a purchasing system need send
		// no name and no e-mail, and the visit still buys.
		public readonly ?string $buyer_identity = null,
		public readonly ?string $buyer_name = null,
		public readonly ?string $buyer_identity_hash = null,
		// This visit's WooCommerce basket. 32 characters, `pow_`-prefixed,
		// UNIQUE across the table; minted once when the login is bound.
		public readonly ?string $wc_session_key = null,
	) {}

	/**
	 * @param array<string, mixed> $row Raw wpdb row.
	 */
	public static function from_row( array $row ): self {
		return new self(
			id: (int) ( $row['id'] ?? 0 ),
			partner_id: (int) ( $row['partner_id'] ?? 0 ),
			buyer_cookie: (string) ( $row['buyer_cookie'] ?? '' ),
			operation: (string) ( $row['operation'] ?? 'create' ),
			browser_form_post_url: (string) ( $row['browser_form_post_url'] ?? '' ),
			selected_item: self::nullable( $row, 'selected_item' ),
			ship_to: self::nullable( $row, 'ship_to' ),
			user_id: (int) ( $row['user_id'] ?? 0 ),
			wp_session_token: (string) ( $row['wp_session_token'] ?? '' ),
			one_time_token_hash: (string) ( $row['one_time_token_hash'] ?? '' ),
			status: (string) ( $row['status'] ?? self::PENDING ),
			order_id: (int) ( $row['order_id'] ?? 0 ),
			payload_id: (string) ( $row['payload_id'] ?? '' ),
			body_hash: (string) ( $row['body_hash'] ?? '' ),
			response_xml: self::nullable( $row, 'response_xml' ),
			cxml_version: (string) ( $row['cxml_version'] ?? '' ),
			deployment_mode: (string) ( $row['deployment_mode'] ?? '' ),
			extrinsics: self::nullable( $row, 'extrinsics' ),
			itemout_lines: self::nullable( $row, 'itemout_lines' ),
			cart_ready: ! empty( $row['cart_ready'] ),
			created: self::nullable( $row, 'created' ),
			expires: self::nullable( $row, 'expires' ),
			delivery_choice_json: self::delivery_column( $row, 'delivery_choice' ),
			delivery_confirmation_json: self::delivery_column( $row, 'delivery_confirmation' ),
			// An absent column and an empty one both mean "no identity" and
			// "no basket of its own yet": nothing downstream distinguishes them.
			buyer_identity: self::nullable( $row, 'buyer_identity' ),
			buyer_name: self::nullable( $row, 'buyer_name' ),
			buyer_identity_hash: self::nullable( $row, 'buyer_identity_hash' ),
			wc_session_key: self::nullable( $row, 'wc_session_key' ),
		);
	}

	/** A snapshot decoder only: Resolver must freshly authorize an active selection. */
	public function delivery_choice(): ?array {
		return null === $this->delivery_choice_json ? null : \POW\Addresses\DeliveryData::choice( $this->delivery_choice_json, $this->partner_id );
	}

	/** Historical structured consent; current cart, policy and address eligibility are checked separately. */
	public function delivery_confirmation(): ?array {
		return null === $this->delivery_confirmation_json ? null : \POW\Addresses\DeliveryData::confirmation( $this->delivery_confirmation_json, $this->id, $this->user_id, $this->delivery_choice() );
	}

	private static function delivery_column( array $row, string $key ): ?string {
		if ( ! array_key_exists( $key, $row ) || null === $row[ $key ] ) { return null; }
		if ( ! is_string( $row[ $key ] ) ) { throw new \DomainException( 'delivery_choice' === $key ? 'Invalid stored delivery selection.' : 'Invalid stored delivery confirmation.' ); }
		// Empty, invalid and oversized strings remain present, and the decoder refuses them.
		return $row[ $key ];
	}

	/**
	 * Pure transition check — SQL enforcement mirrors this table.
	 */
	public static function can_transition( string $from, string $to ): bool {
		return in_array( $to, self::TRANSITIONS[ $from ] ?? [], true );
	}

	public function is_terminal(): bool {
		return in_array( $this->status, [ self::RETURNED, self::CLOSED, self::EXPIRED ], true );
	}

	private static function nullable( array $row, string $key ): ?string {
		return ( isset( $row[ $key ] ) && '' !== (string) $row[ $key ] ) ? (string) $row[ $key ] : null;
	}
}
