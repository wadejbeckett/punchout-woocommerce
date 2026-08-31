<?php
/**
 * Trading-partner registry.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Partners;

use POW\Installer;
use POW\Support\Ip;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD and credential resolution over the wp_pow_partners table.
 *
 * The (sender_domain, sender_identity) pair is the auth lookup key for
 * inbound setup requests; secrets are sealed by Secrets before they reach
 * this class and never leave it unsealed.
 */
final class Registry {

	public function __construct( private Secrets $secrets ) {}

	private function table(): string {
		return Installer::partners_table();
	}

	public function find( int $id ): ?Partner {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE id = %d', $id ), ARRAY_A );

		return $row ? Partner::from_row( $row ) : null;
	}

	/**
	 * Resolve the partner a Sender credential belongs to. Only exact
	 * domain+identity matches; the caller still verifies the secret.
	 */
	public function find_by_sender( string $domain, string $identity ): ?Partner {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->table() . ' WHERE sender_domain = %s AND sender_identity = %s',
				$domain,
				$identity
			),
			ARRAY_A
		);

		return $row ? Partner::from_row( $row ) : null;
	}

	/**
	 * @return list<Partner>
	 */
	public function all(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( 'SELECT * FROM ' . $this->table() . ' ORDER BY name ASC', ARRAY_A );

		return array_map( [ Partner::class, 'from_row' ], $rows ?: [] );
	}

	/**
	 * Insert a partner. $data uses column names; the plaintext secret (if
	 * any) must be passed separately so it is always sealed on the way in.
	 *
	 * @param array<string, mixed> $data   Column values (no secret columns).
	 * @param string               $secret Plaintext shared secret ('' = none yet).
	 * @return int New row id, 0 on failure.
	 */
	public function insert( array $data, string $secret = '' ): int {
		global $wpdb;

		$data = $this->sanitise( $data );

		$data['secret_current'] = '' !== $secret ? $this->secrets->seal( $secret ) : '';
		$data['created']        = gmdate( 'Y-m-d H:i:s' );
		$data['updated']        = $data['created'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = $wpdb->insert( $this->table(), $data );

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update a partner. A non-empty $secret replaces the current slot
	 * outright (paste-in); use rotate() for dual-slot rotation.
	 *
	 * @param array<string, mixed> $data Column values (no secret columns).
	 */
	public function update( int $id, array $data, string $secret = '' ): bool {
		global $wpdb;

		$data = $this->sanitise( $data );

		if ( '' !== $secret ) {
			$data['secret_current'] = $this->secrets->seal( $secret );
		}

		$data['updated'] = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return false !== $wpdb->update( $this->table(), $data, [ 'id' => $id ] );
	}

	public function delete( int $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return false !== $wpdb->delete( $this->table(), [ 'id' => $id ] );
	}

	/**
	 * Dual-slot rotation (scope §7): the current secret is demoted to the
	 * previous slot (still accepted during the overlap window) and the new
	 * one sealed into current.
	 *
	 * @return string|null The new plaintext secret — shown ONCE by the
	 *                     caller, never stored unsealed — or null.
	 */
	public function rotate( int $id ): ?string {
		global $wpdb;

		$partner = $this->find( $id );

		if ( null === $partner ) {
			return null;
		}

		$new_secret = Secrets::generate_secret();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = $wpdb->update(
			$this->table(),
			[
				'secret_previous'   => $partner->secret_current,
				'secret_current'    => $this->secrets->seal( $new_secret ),
				'secret_rotated_at' => gmdate( 'Y-m-d H:i:s' ),
				'updated'           => gmdate( 'Y-m-d H:i:s' ),
			],
			[ 'id' => $id ]
		);

		return false !== $ok ? $new_secret : null;
	}

	/**
	 * Close the rotation window: clear the previous slot.
	 */
	public function close_rotation( int $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return false !== $wpdb->update(
			$this->table(),
			[
				'secret_previous' => '',
				'updated'         => gmdate( 'Y-m-d H:i:s' ),
			],
			[ 'id' => $id ]
		);
	}

	/**
	 * Constant-time secret check against both slots.
	 *
	 * @return string|null Secrets::SLOT_* that matched, or null.
	 */
	public function verify_secret( Partner $partner, string $candidate ): ?string {
		return $this->secrets->verify( $candidate, $partner->secret_current, $partner->secret_previous );
	}

	/**
	 * Per-partner IP allowlist check. An empty allowlist allows all.
	 */
	public function ip_allowed( Partner $partner, string $ip ): bool {
		$cidrs = $partner->ip_cidrs();

		if ( [] === $cidrs ) {
			return true;
		}

		return Ip::in_any( $ip, $cidrs );
	}

	/**
	 * Restrict writes to real columns and normalise enum-ish values.
	 *
	 * @param array<string, mixed> $data Raw column values.
	 * @return array<string, mixed>
	 */
	private function sanitise( array $data ): array {
		$allowed = [
			'name',
			'status',
			'from_domain',
			'from_identity',
			'sender_domain',
			'sender_identity',
			'to_domain',
			'to_identity',
			'cxml_version',
			'deployment_mode',
			'return_encoding',
			'mode',
			'allow_reentry',
			'allcaps_transform',
			'gateway_allowlist',
			'company_profile',
			'ip_allowlist',
			'session_ttl',
			'token_ttl',
		];

		$data = array_intersect_key( $data, array_flip( $allowed ) );

		if ( isset( $data['status'] ) ) {
			$data['status'] = in_array( $data['status'], [ 'active', 'disabled' ], true ) ? $data['status'] : 'disabled';
		}

		if ( isset( $data['mode'] ) ) {
			$data['mode'] = in_array( $data['mode'], [ Partner::MODE_REQUISITION_ONLY, Partner::MODE_DUAL_EXIT ], true )
				? $data['mode']
				: Partner::MODE_REQUISITION_ONLY;
		}

		if ( isset( $data['return_encoding'] ) ) {
			$data['return_encoding'] = in_array( $data['return_encoding'], [ 'base64', 'urlencoded' ], true ) ? $data['return_encoding'] : 'base64';
		}

		if ( isset( $data['deployment_mode'] ) ) {
			$data['deployment_mode'] = in_array( $data['deployment_mode'], [ 'test', 'production' ], true ) ? $data['deployment_mode'] : 'test';
		}

		if ( isset( $data['cxml_version'] ) && ! preg_match( '/^\d+\.\d+\.\d+$/', (string) $data['cxml_version'] ) ) {
			$data['cxml_version'] = '1.2.008';
		}

		foreach ( [ 'allow_reentry', 'allcaps_transform' ] as $flag ) {
			if ( isset( $data[ $flag ] ) ) {
				$data[ $flag ] = empty( $data[ $flag ] ) ? 0 : 1;
			}
		}

		foreach ( [ 'session_ttl', 'token_ttl' ] as $int_col ) {
			if ( isset( $data[ $int_col ] ) ) {
				$data[ $int_col ] = max( 0, (int) $data[ $int_col ] );
			}
		}

		return $data;
	}
}
