<?php
/**
 * Trading-partner row object.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Partners;

use POW\Account\VisitEndpoints;

defined( 'ABSPATH' ) || exit;

/**
 * One configured customer connection (buyer-side tenant).
 *
 * Immutable snapshot of a registry row; all persistence goes through Registry. `exit_policy` and `mode` are retired columns: punchout is the only exit a visit has, so `exit_policy` always reads `punchout_only` whatever the row holds, `mode` always reads the requisition default, neither is written any more, and no checkout decision consults either. The one reader of `exit_policy` is allows_native_checkout(), for the payment-method pages of the My Account list, and it therefore answers false for every connection.
 *
 * `status` is the lifecycle: STATUS_PENDING (self-service registration
 * submitted, awaiting an administrator — never authenticates), then
 * STATUS_ACTIVE or STATUS_DISABLED. Only STATUS_ACTIVE authenticates, so
 * anything unrecognised fails closed.
 *
 * `visit_endpoints` is the comma-separated list of My Account pages this
 * connection's visits may open (`dashboard` is the account page itself),
 * normalised by Account\VisitEndpoints on the way in and again here, so a
 * hand-edited row holds only well-formed names. Empty keeps the whole
 * account area closed; a new connection starts with `dashboard`.
 *
 * `buyer_addresses` (schema 9, off by default) lets a buyer inside one of
 * this connection's visits add a new entry to the company delivery book from
 * the delivery review page. It is add-only: the entry is saved enabled and
 * coded, and buyers never change or remove entries. Only an administrator
 * switches it on.
 *
 * `owner_settings` (schema 9, empty by default) is the comma-separated list
 * of actions the bound customer account may take for itself, drawn from
 * OWNER_ACTIONS and normalised by normalise_owner_settings() on the way in
 * and again here. owner_may() is the one question callers ask of it.
 */
final class Partner {

	public const STATUS_PENDING  = 'pending';
	public const STATUS_ACTIVE   = 'active';
	public const STATUS_DISABLED = 'disabled';

	public const MODE_REQUISITION_ONLY = 'requisition_only';

	/** The actions an administrator can grant the bound account in owner_settings. */
	public const OWNER_ACTIONS = [ 'reset_connection' ];

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
		public readonly string $exit_policy = 'punchout_only',
		public readonly string $visit_endpoints = '',
		public readonly bool $buyer_addresses = false,
		public readonly string $owner_settings = '',
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
			// Retired column: the stored value is history, never configuration.
			mode: self::MODE_REQUISITION_ONLY,
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
			// Retired column: punchout is the only exit, whatever the row holds.
			exit_policy: 'punchout_only',
			// Absent before schema 8: an older row allows nothing.
			visit_endpoints: VisitEndpoints::normalise( (string) ( $row['visit_endpoints'] ?? '' ) ),
			// Both absent before schema 9, which reads as off.
			buyer_addresses: ! empty( $row['buyer_addresses'] ),
			owner_settings: self::normalise_owner_settings( (string) ( $row['owner_settings'] ?? '' ) ),
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

	/**
	 * The known owner actions in $raw, once each, in OWNER_ACTIONS order,
	 * comma-joined. Anything else is dropped.
	 */
	public static function normalise_owner_settings( string $raw ): string {
		$given = array_map( 'trim', explode( ',', $raw ) );
		return implode( ',', array_values( array_filter( self::OWNER_ACTIONS, static fn( string $action ): bool => in_array( $action, $given, true ) ) ) );
	}

	/** Whether an administrator granted the bound account this known action. */
	public function owner_may( string $action ): bool {
		return in_array( $action, self::OWNER_ACTIONS, true ) && in_array( $action, explode( ',', $this->owner_settings ), true );
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

	/**
	 * The My Account pages a visit of this connection may open ([] = none).
	 * The pages that never open in a visit are left out, and so are the
	 * payment-method pages unless the exit policy allows WooCommerce's own
	 * checkout.
	 *
	 * @return list<string>
	 */
	public function visit_endpoint_list(): array {
		return VisitEndpoints::listed( $this->visit_endpoints, $this->allows_native_checkout() );
	}

	/**
	 * Whether this connection's exit policy lets a visit pay through
	 * WooCommerce's own checkout. `exit_policy` always reads punchout_only
	 * (see the class note), so this is false for every connection in this
	 * release. It is asked by the My Account list only, so the payment-method
	 * pages follow the exit policy rather than a rule of their own; checkout
	 * itself is refused inside every visit by RouteGuard, whatever this says.
	 */
	public function allows_native_checkout(): bool {
		return 'punchout_only' !== $this->exit_policy;
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
