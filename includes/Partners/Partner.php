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
 * Immutable snapshot of a registry row; all persistence goes through Registry. `exit_policy` is the company entitlement, resolved with the global default and buyer restriction by Checkout\ExitPolicy. `mode`, its constants and is_requisition_only() remain legacy compatibility APIs; runtime authorization never uses that snapshot flag.
 *
 * `status` is the lifecycle: STATUS_PENDING (self-service registration
 * submitted, awaiting an administrator — never authenticates), then
 * STATUS_ACTIVE or STATUS_DISABLED. Only STATUS_ACTIVE authenticates, so
 * anything unrecognised fails closed.
 */
final class Partner {

	public const STATUS_PENDING  = 'pending';
	public const STATUS_ACTIVE   = 'active';
	public const STATUS_DISABLED = 'disabled';

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
		public readonly int $owner_user_id,
		public readonly string $delivery_code_prefix = '',
		public readonly string $delivery_code_extrinsic_name = 'DeliveryAddressCode',
		public readonly bool $emit_ship_to = false,
		public readonly bool $emit_delivery_code = false,
		public readonly bool $emit_delivery_line = false,
		public readonly string $delivery_unknown_policy = 'require_rate',
		public readonly string $delivery_notes_policy = 'off',
		public readonly string $freight_supplier_part_id = 'DELIVERY',
		public readonly string $freight_uom = 'EA',
		public readonly string $freight_classification_domain = 'supplier',
		public readonly string $freight_classification = 'freight',
		public readonly string $exit_policy = 'inherit',
	) {}

	/**
	 * @param array<string, mixed> $row Raw wpdb row.
	 */
	public static function from_row( array $row ): self {
		$delivery = self::normalise_delivery_config( $row );
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
			owner_user_id: (int) ( $row['owner_user_id'] ?? 0 ),
			delivery_code_prefix: $delivery['delivery_code_prefix'] ?? '',
			delivery_code_extrinsic_name: $delivery['delivery_code_extrinsic_name'] ?? 'DeliveryAddressCode',
			emit_ship_to: $delivery['emit_ship_to'] ?? false,
			emit_delivery_code: $delivery['emit_delivery_code'] ?? false,
			emit_delivery_line: $delivery['emit_delivery_line'] ?? false,
			delivery_unknown_policy: $delivery['delivery_unknown_policy'] ?? 'require_rate',
			delivery_notes_policy: $delivery['delivery_notes_policy'] ?? 'off',
			freight_supplier_part_id: $delivery['freight_supplier_part_id'] ?? 'DELIVERY',
			freight_uom: $delivery['freight_uom'] ?? 'EA',
			freight_classification_domain: $delivery['freight_classification_domain'] ?? 'supplier',
			freight_classification: $delivery['freight_classification'] ?? 'freight',
			exit_policy: \POW\Checkout\ExitPolicy::normalise( $row['exit_policy'] ?? 'inherit' ),
		);
	}

	/**
	 * Validate only supplied delivery columns so partial saves never erase configuration.
	 * Database strings and explicit boolean/integer ones can enable emission; other values fail closed.
	 * Text is Unicode data, never XML markup; wire builders must escape it in its output context.
	 *
	 * @return array<string, string|bool>
	 * @throws \InvalidArgumentException For malformed or oversized textual configuration.
	 */
	public static function normalise_delivery_config( array $data ): array {
		$result = [];
		if ( array_key_exists( 'delivery_code_prefix', $data ) ) {
			if ( ! is_string( $data['delivery_code_prefix'] ) ) { throw new \InvalidArgumentException( 'Invalid delivery code prefix.' ); }
			$result['delivery_code_prefix'] = \POW\Addresses\Codes::sanitise_prefix( $data['delivery_code_prefix'] );
		}
		foreach ( [ 'delivery_code_extrinsic_name' => 64, 'freight_supplier_part_id' => 190, 'freight_uom' => 8, 'freight_classification_domain' => 64, 'freight_classification' => 64 ] as $key => $limit ) {
			if ( ! array_key_exists( $key, $data ) ) { continue; }
			$value = $data[ $key ];
			// Bound bytes before UTF-8 work; VARCHAR limits count characters, not bytes. Exclude controls and XML-invalid code points.
			if ( ! is_string( $value ) || strlen( $value ) > 4 * $limit || '' === trim( $value ) || 1 !== preg_match( '/\A[\x20-\x7E\x{A0}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]{1,' . $limit . '}\z/u', $value ) ) {
				throw new \InvalidArgumentException( 'Invalid delivery configuration field: ' . $key . '.' );
			}
			$result[ $key ] = $value;
		}
		foreach ( [ 'emit_ship_to', 'emit_delivery_code', 'emit_delivery_line' ] as $key ) {
			if ( array_key_exists( $key, $data ) ) { $result[ $key ] = in_array( $data[ $key ], [ true, 1, '1' ], true ); }
		}
		foreach ( [ 'delivery_unknown_policy' => [ 'require_rate', 'quote_separately' ], 'delivery_notes_policy' => [ 'off', 'item_detail_extrinsic' ] ] as $key => $values ) {
			if ( array_key_exists( $key, $data ) ) { $result[ $key ] = in_array( $data[ $key ], $values, true ) ? $data[ $key ] : $values[0]; }
		}
		return $result;
	}

	public function is_active(): bool {
		return self::STATUS_ACTIVE === $this->status;
	}

	public function is_pending(): bool {
		return self::STATUS_PENDING === $this->status;
	}

	/**
	 * True only for a real user who owns this row — user 0 owns nothing.
	 */
	public function is_owned_by( int $user_id ): bool {
		return $user_id > 0 && $user_id === $this->owner_user_id;
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
