<?php
/**
 * Trading-partner row object.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Partners;

defined( 'ABSPATH' ) || exit;

/**
 * One configured customer connection (buyer-side tenant).
 *
 * Immutable snapshot of a registry row; all persistence goes through
 * Registry. `mode` is the per-partner exit policy:
 *
 * - MODE_REQUISITION_ONLY (default): only the RFQ/"send for approval" exit
 *   renders; checkout is blocked for that partner's punchout sessions.
 * - MODE_DUAL_EXIT: the RFQ button renders alongside the completely
 *   untouched stock WooCommerce checkout.
 */
final class Partner {

	public const MODE_REQUISITION_ONLY = 'requisition_only';
	public const MODE_DUAL_EXIT        = 'dual_exit';

	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly string $status,
		public readonly string $from_domain,
		public readonly string $from_identity,
		public readonly string $sender_domain,
		public readonly string $sender_identity,
		public readonly string $to_domain,
		public readonly string $to_identity,
		public readonly string $secret_current,
		public readonly string $secret_previous,
		public readonly ?string $secret_rotated_at,
		public readonly string $cxml_version,
		public readonly string $deployment_mode,
		public readonly string $return_encoding,
		public readonly string $mode,
		public readonly bool $allow_reentry,
		public readonly bool $allcaps_transform,
		public readonly ?string $gateway_allowlist,
		public readonly ?string $company_profile,
		public readonly ?string $ip_allowlist,
		public readonly int $session_ttl,
		public readonly int $token_ttl,
	) {}

	/**
	 * @param array<string, mixed> $row Raw wpdb row.
	 */
	public static function from_row( array $row ): self {
		return new self(
			id: (int) ( $row['id'] ?? 0 ),
			name: (string) ( $row['name'] ?? '' ),
			status: (string) ( $row['status'] ?? 'active' ),
			from_domain: (string) ( $row['from_domain'] ?? '' ),
			from_identity: (string) ( $row['from_identity'] ?? '' ),
			sender_domain: (string) ( $row['sender_domain'] ?? '' ),
			sender_identity: (string) ( $row['sender_identity'] ?? '' ),
			to_domain: (string) ( $row['to_domain'] ?? '' ),
			to_identity: (string) ( $row['to_identity'] ?? '' ),
			secret_current: (string) ( $row['secret_current'] ?? '' ),
			secret_previous: (string) ( $row['secret_previous'] ?? '' ),
			secret_rotated_at: isset( $row['secret_rotated_at'] ) && '' !== (string) $row['secret_rotated_at'] ? (string) $row['secret_rotated_at'] : null,
			cxml_version: (string) ( $row['cxml_version'] ?? '1.2.008' ),
			deployment_mode: (string) ( $row['deployment_mode'] ?? 'test' ),
			return_encoding: (string) ( $row['return_encoding'] ?? 'base64' ),
			mode: (string) ( $row['mode'] ?? self::MODE_REQUISITION_ONLY ),
			allow_reentry: ! empty( $row['allow_reentry'] ),
			allcaps_transform: ! empty( $row['allcaps_transform'] ),
			gateway_allowlist: isset( $row['gateway_allowlist'] ) && '' !== (string) $row['gateway_allowlist'] ? (string) $row['gateway_allowlist'] : null,
			company_profile: isset( $row['company_profile'] ) && '' !== (string) $row['company_profile'] ? (string) $row['company_profile'] : null,
			ip_allowlist: isset( $row['ip_allowlist'] ) && '' !== (string) $row['ip_allowlist'] ? (string) $row['ip_allowlist'] : null,
			session_ttl: max( 60, (int) ( $row['session_ttl'] ?? 14400 ) ),
			token_ttl: max( 30, (int) ( $row['token_ttl'] ?? 300 ) ),
		);
	}

	public function is_active(): bool {
		return 'active' === $this->status;
	}

	public function is_requisition_only(): bool {
		return self::MODE_DUAL_EXIT !== $this->mode;
	}

	/**
	 * IP allowlist as a list of CIDR strings ([] = no restriction).
	 *
	 * @return list<string>
	 */
	public function ip_cidrs(): array {
		if ( null === $this->ip_allowlist ) {
			return [];
		}

		$decoded = json_decode( $this->ip_allowlist, true );

		if ( ! is_array( $decoded ) ) {
			return [];
		}

		return array_values( array_filter( array_map( 'strval', $decoded ) ) );
	}
}
