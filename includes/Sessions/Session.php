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
 * One punchout session: one row per PunchOutSetupRequest (scope §4.2).
 *
 * State machine (scope §4.2/§5.4/§9.7):
 *
 *   pending  --token redeemed-->  active
 *   active   --RFQ exit-------->  returned
 *   active   --payment_complete-> ordered
 *   active   --empty POOM------->  closed   ("return without ordering")
 *   ordered  --close-out POOM--->  closed
 *   pending|active|ordered --cron/latest-wins--> expired
 *
 * The transition table is pure and unit-tested; Store enforces it in SQL
 * (UPDATE ... WHERE status IN (...)) so concurrent requests cannot race a
 * row into an illegal state.
 */
final class Session {

	public const PENDING  = 'pending';
	public const ACTIVE   = 'active';
	public const RETURNED = 'returned';
	public const ORDERED  = 'ordered';
	public const CLOSED   = 'closed';
	public const EXPIRED  = 'expired';

	private const TRANSITIONS = [
		self::PENDING => [ self::ACTIVE, self::EXPIRED ],
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
		);
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
