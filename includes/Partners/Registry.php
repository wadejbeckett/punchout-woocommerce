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

	/** Shared across Registry instances on this request/connection. */
	private static array $partner_locks = [];

	public function __construct( private Secrets $secrets ) {}

	private function table(): string {
		return Installer::partners_table();
	}

	public function find( int $id ): ?Partner {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE id = %d', $id ), ARRAY_A );

		if ( '' !== ( $wpdb->last_error ?? '' ) ) { throw new \RuntimeException( 'Partner lookup failed.' ); }
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

		if ( '' !== ( $wpdb->last_error ?? '' ) ) { throw new \RuntimeException( 'Partner lookup failed.' ); }
		return $row ? Partner::from_row( $row ) : null;
	}

	/**
	 * Resolve the partner a WordPress user owns (self-service registration).
	 * Newest row wins; user 0 owns nothing.
	 */
	public function find_by_owner( int $user_id ): ?Partner {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE owner_user_id = %d ORDER BY id DESC LIMIT 1', $user_id ), ARRAY_A );

		if ( '' !== ( $wpdb->last_error ?? '' ) ) { throw new \RuntimeException( 'Partner lookup failed.' ); }
		return $row ? Partner::from_row( $row ) : null;
	}

	/**
	 * Serialize submission and explicit legacy association for one ordinary owner.
	 * The Sender UNIQUE key separately arbitrates claims by different owners.
	 * Native connection-scoped locks work even before an owner has a row.
	 * Callers must keep the operation bounded and send mail only after it returns.
	 *
	 * @throws \InvalidArgumentException Invalid owner.
	 * @throws \RuntimeException Lock unavailable or release unconfirmed.
	 */
	public function with_owner_lock( int $user_id, callable $operation ): mixed {
		global $wpdb;

		if ( self::$partner_locks ) { throw new \LogicException( 'Owner lock must precede partner lock.' ); }
		if ( $user_id <= 0 ) {
			throw new \InvalidArgumentException( 'Invalid registration owner.' );
		}

		// Server-wide namespace, bounded below MySQL's 64-character name limit.
		$name = 'pow_owner_' . hash( 'sha256', ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|' . $this->table() . '|' . $user_id );
		$name = substr( $name, 0, 64 );
		$previous = $wpdb->suppress_errors( true );
		$acquired = false;
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$acquired = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 5 ) );
			if ( ! $acquired ) {
				throw new \RuntimeException( 'Registration owner lock unavailable.' );
			}
			return $operation();
		} finally {
			try {
				if ( $acquired ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					$released = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
					if ( '1' !== (string) $released ) {
						throw new \RuntimeException( 'Registration owner lock release unconfirmed.' );
					}
				}
			} finally {
				$wpdb->suppress_errors( $previous );
			}
		}
	}


	/** Database critical section shared by lifecycle, setup, start and return. */
	public function with_partner_lock( int $id, callable $operation ): mixed {
		global $wpdb;
		if ( $id <= 0 ) { throw new \InvalidArgumentException( 'Invalid partner.' ); }
		$key = $this->partner_lock_key( $id );
		if ( isset( self::$partner_locks[ $key ] ) ) {
			++self::$partner_locks[ $key ];
			try { return $operation(); } finally { --self::$partner_locks[ $key ]; }
		}
		$previous = $wpdb->suppress_errors( true );
		$acquired = false;
		try {
			$acquired = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $key, 5 ) );
			if ( ! $acquired ) { throw new \RuntimeException( 'Partner lock unavailable.' ); }
			self::$partner_locks[ $key ] = 1;
			return $operation();
		} finally {
			unset( self::$partner_locks[ $key ] );
			try {
				if ( $acquired && '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) ) ) {
					throw new \RuntimeException( 'Partner lock release unconfirmed.' );
				}
			} finally { $wpdb->suppress_errors( $previous ); }
		}
	}

	private function partner_lock_key( int $id ): string {
		return 'pow_partner_' . substr( hash( 'sha256', ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|' . $this->table() . '|' . $id ), 0, 52 );
	}

	/** Explicit admin association only; owner serialization precedes the partner lock. */
	public function associate_owner( int $partner_id, int $owner_user_id ): bool {
		global $wpdb;
		$confirmed = false;
		try {
			$actor = get_userdata( get_current_user_id() );
			if ( ! $actor || ! user_can( $actor, 'manage_woocommerce' ) || $partner_id <= 0 || $owner_user_id <= 0 ) { return false; }
			$this->with_owner_lock( $owner_user_id, function () use ( $partner_id, $owner_user_id, $wpdb, &$confirmed ) {
				$this->with_partner_lock( $partner_id, function () use ( $partner_id, $owner_user_id, $wpdb, &$confirmed ) {
					$owner = get_userdata( $owner_user_id );
					$partner = $this->find( $partner_id );
					if ( ! $owner || ! user_can( $owner, 'read' ) || in_array( Installer::ROLE, (array) $owner->roles, true ) || get_user_meta( $owner_user_id, '_pow_partner_id', true ) ) { return; }
					// A nonzero association owns the company's book; no transfer semantics exist.
					if ( ! $partner || 0 !== $partner->owner_user_id || null !== $this->find_by_owner( $owner_user_id ) ) { return; }
					$data = [ 'owner_user_id' => $owner_user_id, 'updated' => gmdate( 'Y-m-d H:i:s' ) ];
					$confirmed = 1 === $wpdb->update( $this->table(), $data, [ 'id' => $partner_id, 'owner_user_id' => 0 ] ) && $this->matches_fields( $partner_id, $data );
				} );
			} );
		} catch ( \Throwable $e ) { /* Only a confirmed association is reported, even after a release failure. */ }
		return $confirmed;
	}

	/** A lifecycle write must be conditional, changed once, and freshly confirmed. */
	public function transition_status( int $id, string $expected, array $fields, string $secret = '' ): bool {
		global $wpdb;
		if ( ! isset( self::$partner_locks[ $this->partner_lock_key( $id ) ] ) ) { return false; }
		if ( array_key_exists( 'exit_policy', $fields ) && ! \POW\Checkout\ExitPolicy::administrator( get_current_user_id() ) ) { return false; }
		$data = $this->sanitise( $fields );
		foreach ( [ 'secret_previous', 'secret_rotated_at' ] as $key ) {
			if ( array_key_exists( $key, $fields ) ) { $data[ $key ] = $fields[ $key ]; }
		}
		if ( '' !== $secret ) { $data['secret_current'] = $this->secrets->seal( $secret ); }
		$data['updated'] = gmdate( 'Y-m-d H:i:s' );
		$before = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE id = %d', $id ), ARRAY_A );
		if ( '' !== ( $wpdb->last_error ?? '' ) || ! $before || $before['status'] !== $expected ) { return false; }
		try {
			if ( 1 !== $wpdb->update( $this->table(), $data, [ 'id' => $id, 'status' => $expected ] ) ) { return false; }
			if ( $this->matches_fields( $id, $data ) ) { return true; }
		} catch ( \Throwable $e ) { /* An unconfirmed issuance must not install an undisclosed credential. */ }
		if ( '' !== $secret ) {
			$restore = [];
			foreach ( $data as $key => $value ) { $restore[ $key ] = $before[ $key ]; }
			// Compensate only this exact issuance, while still holding the lock.
			try { $wpdb->update( $this->table(), $restore, [ 'id' => $id, 'secret_current' => $data['secret_current'] ] ); }
			catch ( \Throwable $e ) { /* A write can commit before its acknowledgement fails; read afresh. */ }
			try { if ( $this->matches_fields( $id, $restore ) ) { return false; } }
			catch ( \Throwable $e ) { /* Restoration is unconfirmed; attempt the bounded safe fallback below. */ }
			throw new CredentialRecoveryException( $this->recover_disabled_locked( $id ) );
		}
		return false;
	}

	/** One final fence/clear and fresh read under the caller's still-held mutex. */
	private function recover_disabled_locked( int $id ): bool {
		global $wpdb;
		if ( ! isset( self::$partner_locks[ $this->partner_lock_key( $id ) ] ) ) { return false; }
		$safe = [ 'status' => Partner::STATUS_DISABLED, 'secret_current' => '', 'secret_previous' => '', 'secret_rotated_at' => null ];
		// Do not depend on an uncertain credential/status predicate. The held partner lock serializes our writers.
		try { $wpdb->update( $this->table(), $safe + [ 'updated' => gmdate( 'Y-m-d H:i:s' ) ], [ 'id' => $id ] ); }
		catch ( \Throwable $e ) { /* Still verify: an exception does not prove the write was rolled back. */ }
		try { return $this->matches_fields( $id, $safe ); }
		catch ( \Throwable $e ) { return false; }
	}

	private function matches_fields( int $id, array $expected ): bool {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE id = %d', $id ), ARRAY_A );
		if ( ! $row || '' !== ( $wpdb->last_error ?? '' ) ) { return false; }
		foreach ( $expected as $key => $value ) {
			if ( (string) ( $row[ $key ] ?? '' ) !== (string) $value ) { return false; }
		}
		return true;
	}

	private function rotation_actor( Partner $partner ): bool {
		$actor = get_current_user_id();
		$user = $actor > 0 ? get_userdata( $actor ) : false;
		return $user && ( user_can( $user, 'manage_woocommerce' ) || ( $partner->is_owned_by( $actor ) && user_can( $user, 'read' ) && ! in_array( Installer::ROLE, (array) $user->roles, true ) && ! get_user_meta( $actor, '_pow_partner_id', true ) ) );
	}

	/**
	 * @return list<Partner>
	 */
	public function all(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( 'SELECT * FROM ' . $this->table() . ' ORDER BY name ASC', ARRAY_A );

		if ( '' !== ( $wpdb->last_error ?? '' ) ) { throw new \RuntimeException( 'Partner list lookup failed.' ); }
		return array_map( [ Partner::class, 'from_row' ], $rows ?: [] );
	}

	/**
	 * Registrations awaiting an administrator, oldest first.
	 *
	 * @return list<Partner>
	 */
	public function pending(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE status = %s ORDER BY created ASC', Partner::STATUS_PENDING ), ARRAY_A );

		if ( '' !== ( $wpdb->last_error ?? '' ) ) { throw new \RuntimeException( 'Partner list lookup failed.' ); }
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

		if ( array_key_exists( 'exit_policy', $data ) && ! \POW\Checkout\ExitPolicy::administrator( get_current_user_id() ) ) { return 0; }
		// Every new connection carries its own explicit cap; approval never depends on a storefront-wide switch.
		$data['exit_policy'] = $data['exit_policy'] ?? \POW\Checkout\ExitPolicy::CHECKOUT;
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
		try {
			return $this->with_partner_lock( $id, function () use ( $id, $data, $secret, $wpdb ) {
				$partner = $this->find( $id );
				if ( null === $partner ) { return false; }
				// Existing callers may still set mode explicitly. No runtime consumer uses it as entitlement.
				if ( array_key_exists( 'mode', $data ) && ! array_key_exists( 'exit_policy', $data ) ) {
					$data['exit_policy'] = Partner::MODE_DUAL_EXIT === $data['mode'] ? 'punchout_and_checkout' : 'punchout_only';
				}
				if ( array_key_exists( 'exit_policy', $data ) && ! \POW\Checkout\ExitPolicy::administrator( get_current_user_id() ) ) { return false; }
				$data = $this->sanitise( $data );
				// Ordinary saves cannot approve pending or revive a fenced connection.
				if ( ! $partner->is_active() ) { $data['status'] = $partner->status; $secret = ''; }
				if ( '' !== $secret ) { $data['secret_current'] = $this->secrets->seal( $secret ); }
				$data['updated'] = gmdate( 'Y-m-d H:i:s' );
				return false !== $wpdb->update( $this->table(), $data, [ 'id' => $id ] ) && $this->matches_fields( $id, $data );
			} );
		} catch ( \Throwable $e ) { return false; }
	}

	public function delete( int $id ): bool {
		global $wpdb;

		try {
			return $this->with_partner_lock( $id, fn() => 1 === $wpdb->delete( $this->table(), [ 'id' => $id ] ) && null === $this->find( $id ) );
		} catch ( \Throwable $e ) { return false; }
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
		$issued = null;
		try {
			$this->with_partner_lock( $id, function () use ( $id, &$issued ) {
				$p = $this->find( $id );
				if ( ! $p || ! $p->is_active() || '' !== $p->secret_previous || ! $this->rotation_actor( $p ) ) { return; }
				$secret = Secrets::generate_secret();
				if ( $this->transition_status( $id, Partner::STATUS_ACTIVE, [ 'secret_previous' => $p->secret_current, 'secret_rotated_at' => gmdate( 'Y-m-d H:i:s' ) ], $secret ) ) { $issued = $secret; }
			} );
		} catch ( \Throwable $e ) { /* A confirmed issuance survives a release error. */ }
		return $issued;
	}

	/**
	 * Close the rotation window: clear the previous slot.
	 */
	public function close_rotation( int $id ): bool {
		try {
			return $this->with_partner_lock( $id, function () use ( $id ) {
				$p = $this->find( $id );
				return $p && $p->is_active() && $this->rotation_actor( $p ) && ( '' === $p->secret_previous || $this->transition_status( $id, Partner::STATUS_ACTIVE, [ 'secret_previous' => '' ] ) );
			} );
		} catch ( \Throwable $e ) { return false; }
	}

	/**
	 * Revoke outright: BOTH slots cleared, so the old credential stops
	 * working immediately and no overlap window survives. This is the
	 * reset primitive, not rotate() — see the plan's WooCommerce REST
	 * key research.
	 */
	public function revoke_secret( int $id ): bool {
		global $wpdb;
		try {
			return $this->with_partner_lock( $id, function () use ( $id, $wpdb ) {
				$data = [ 'secret_current' => '', 'secret_previous' => '' ];
				return false !== $wpdb->update( $this->table(), $data, [ 'id' => $id ] ) && $this->matches_fields( $id, $data );
			} );
		} catch ( \Throwable $e ) { return false; }
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
		$delivery = Partner::normalise_delivery_config( $data );
		$allowed = [
			'name',
			'status',
			'owner_user_id',
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
			'exit_policy',
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
			$statuses       = [ Partner::STATUS_PENDING, Partner::STATUS_ACTIVE, Partner::STATUS_DISABLED ];
			$data['status'] = in_array( $data['status'], $statuses, true ) ? $data['status'] : Partner::STATUS_DISABLED;
		}

		if ( isset( $data['owner_user_id'] ) ) {
			$data['owner_user_id'] = max( 0, (int) $data['owner_user_id'] );
		}

		if ( isset( $data['mode'] ) ) {
			$data['mode'] = in_array( $data['mode'], [ Partner::MODE_REQUISITION_ONLY, Partner::MODE_DUAL_EXIT ], true )
				? $data['mode']
				: Partner::MODE_REQUISITION_ONLY;
		}

		if ( array_key_exists( 'exit_policy', $data ) ) {
			$data['exit_policy'] = \POW\Checkout\ExitPolicy::normalise_company( $data['exit_policy'] );
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

		// Leave lifecycle fields on their existing path; delivery flags reach wpdb as explicit 0/1.
		foreach ( $delivery as $key => $value ) { $data[ $key ] = is_bool( $value ) ? (int) $value : $value; }
		return $data;
	}
}
