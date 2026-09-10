<?php
/**
 * Punchout session persistence.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Sessions;

use POW\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * wpdb-backed store over wp_pow_sessions.
 *
 * All state transitions are single conditional UPDATEs (status IN allowed
 * set), so two concurrent requests cannot both win a transition — the
 * atomic token redemption in particular is one query (scope §7).
 */
class Store {

	public function find_by_token_hash( string $hash ): ?Session {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE one_time_token_hash = %s', $hash ), ARRAY_A );
		if ( '' !== ( $wpdb->last_error ?? '' ) ) { throw new \RuntimeException( 'Session lookup failed.' ); }
		return $row ? Session::from_row( $row ) : null;
	}

	/** Keyset pagination includes terminal rows whose recorded login may still exist. */
	public function revocation_batch( int $partner_id, int $after_id = 0, int $limit = 500 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . $this->table() . " WHERE partner_id = %d AND id > %d AND (status IN (%s,%s,%s) OR (wp_session_token IS NOT NULL AND wp_session_token <> '')) ORDER BY id ASC LIMIT %d",
			$partner_id, $after_id, Session::PENDING, Session::ACTIVE, Session::ORDERED, max( 1, min( 500, $limit ) )
		), ARRAY_A );
		if ( '' !== ( $wpdb->last_error ?? '' ) ) { throw new \RuntimeException( 'Session revocation lookup failed.' ); }
		return array_map( [ Session::class, 'from_row' ], $rows ?: [] );
	}

	/** Bind first: a crash must never leave a valid native token without a recorded reference. */
	public function bind_login( int $id, string $token, string $expires ): bool {
		global $wpdb;
		if ( '' === $token ) { return false; }
		$ok = $wpdb->query( $wpdb->prepare(
			'UPDATE ' . $this->table() . " SET wp_session_token = %s, expires = %s WHERE id = %d AND status = %s AND (wp_session_token IS NULL OR wp_session_token = '')",
			$token, $expires, $id, Session::ACTIVE
		) );
		$fresh = 1 === $ok ? $this->find( $id ) : null;
		return $fresh && $fresh->wp_session_token === $token && $fresh->expires === $expires && $fresh->status === Session::ACTIVE;
	}

	/** Caller holds the partner lock; invalidate native token-map cache before every read/write. */
	public function login_valid_checked( Session $session ): bool {
		global $wpdb;
		if ( $session->user_id <= 0 || '' === $session->wp_session_token ) { return false; }
		$wpdb->last_error = '';
		wp_cache_delete( $session->user_id, 'user_meta' );
		$valid = \WP_Session_Tokens::get_instance( $session->user_id )->verify( $session->wp_session_token );
		if ( '' !== $wpdb->last_error ) { throw new \RuntimeException( 'Login verification failed.' ); }
		return $valid;
	}

	/** Native destroy() is void: fresh verification, not its return, confirms revocation. */
	public function destroy_login_checked( Session $session ): bool {
		global $wpdb;
		if ( '' === $session->wp_session_token ) { return true; }
		if ( $session->user_id <= 0 ) { return false; }
		try {
			if ( ! $this->login_valid_checked( $session ) ) { return true; }
			wp_cache_delete( $session->user_id, 'user_meta' );
			$wpdb->last_error = '';
			\WP_Session_Tokens::get_instance( $session->user_id )->destroy( $session->wp_session_token );
			$write_failed = '' !== $wpdb->last_error;
			return ! $write_failed && ! $this->login_valid_checked( $session );
		} catch ( \Throwable $e ) { return false; }
	}

	/** Under the partner lock, retry one contested transition and always attempt token cleanup. */
	public function expire_locked( Session $session ): bool {
		$expired = false;
		try {
			for ( $attempt = 0; $attempt < 2; ++$attempt ) {
				$fresh = $this->find( $session->id );
				if ( ! $fresh || $fresh->partner_id !== $session->partner_id ) { break; }
				$session = $fresh;
				if ( ! in_array( $fresh->status, [ Session::PENDING, Session::ACTIVE, Session::ORDERED ], true ) ) { $expired = true; break; }
				if ( $this->transition( $fresh->id, $fresh->status, Session::EXPIRED ) ) {
					$confirmed = $this->find( $fresh->id );
					$expired = $confirmed && $confirmed->status === Session::EXPIRED;
					break;
				}
			}
		} catch ( \Throwable $e ) { $expired = false; }
		$destroyed = $this->destroy_login_checked( $session );
		return $expired && $destroyed;
	}

	/**
	 * Failed-consent recovery only. Caller holds the partner mutex, but a paid winner may race without it. CAS the exact ACTIVE login and verify that win before native cleanup; never reuse general lifecycle expiry here. Expiry/native validity need not hold to REMOVE consent. False grants no authority over another status or replacement login.
	 */
	public function expire_active_login_locked( Session $session ): bool {
		global $wpdb;
		if ( Session::ACTIVE !== $session->status || $session->id <= 0 || $session->partner_id <= 0 || $session->user_id <= 0 || '' === $session->wp_session_token ) { return false; }
		try {
			$won = $wpdb->query( $wpdb->prepare(
				'UPDATE ' . $this->table() . ' SET status = %s WHERE id = %d AND status = %s AND partner_id = %d AND user_id = %d AND BINARY wp_session_token = BINARY %s',
				Session::EXPIRED, $session->id, Session::ACTIVE, $session->partner_id, $session->user_id, $session->wp_session_token
			) );
			if ( 1 !== $won || '' !== ( $wpdb->last_error ?? '' ) ) { return false; }
			$fresh = $this->find( $session->id );
			if ( ! $fresh || $fresh->id !== $session->id || Session::EXPIRED !== $fresh->status || $fresh->partner_id !== $session->partner_id || $fresh->user_id !== $session->user_id || $fresh->wp_session_token !== $session->wp_session_token ) { return false; }
			return $this->destroy_login_checked( $fresh );
		} catch ( \Throwable $error ) { return false; }
	}

	/** Existing cleanup callers share the lifecycle lock and exact-token implementation. */
	public function expire_and_destroy( Session $session, \POW\Partners\Registry $registry ): bool {
		try { return $registry->with_partner_lock( $session->partner_id, fn() => $this->expire_locked( $session ) ); }
		catch ( \Throwable $e ) { return false; }
	}

	private function table(): string {
		return Installer::sessions_table();
	}

	public function find( int $id ): ?Session {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE id = %d', $id ), ARRAY_A );

		if ( '' !== ( $wpdb->last_error ?? '' ) ) { throw new \RuntimeException( 'Session lookup failed.' ); }
		return $row ? Session::from_row( $row ) : null;
	}

	/**
	 * @param array<string, mixed> $data Column values.
	 * @return int New row id, 0 on failure (including a duplicate
	 *             (partner_id, payload_id) hitting the UNIQUE key).
	 */
	public function create( array $data ): int {
		global $wpdb;

		$data['created'] = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = $wpdb->insert( $this->table(), $data );

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * @param array<string, mixed> $data Column values.
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return false !== $wpdb->update( $this->table(), $data, [ 'id' => $id ] );
	}

	/**
	 * Caller holds the partner mutex and has freshly checked company/address/cart/policy eligibility. Raw expected values come from the loaded server session, never POST. One conditional native UPDATE stores the bound pair; exact byte predicates preserve NULL and resist collation-equivalent stale values. An empty choice represents SQL NULL only for a schema-valid virtual/not-required confirmation.
	 */
	public function save_delivery( int $session_id, int $user_id, string $login_token, ?string $expected_choice, ?string $expected_confirmation, array $choice, array $confirmation ): bool {
		global $wpdb;
		try {
			if ( $session_id <= 0 || $user_id <= 0 || '' === $login_token ) { return false; }
			$session = $this->find( $session_id );
			if ( ! $session || $session->user_id !== $user_id || $session->wp_session_token !== $login_token || Session::ACTIVE !== $session->status || ! $session->expires || $session->expires <= gmdate( 'Y-m-d H:i:s' ) || $session->delivery_choice_json !== $expected_choice || $session->delivery_confirmation_json !== $expected_confirmation || ! $this->login_valid_checked( $session ) ) { return false; }
			$flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
			$choice_json = [] === $choice ? null : json_encode( $choice, $flags, 16 );
			$accepted = null === $choice_json ? null : \POW\Addresses\DeliveryData::choice( $choice_json, $session->partner_id );
			$confirmation_json = json_encode( $confirmation, $flags, 16 );
			\POW\Addresses\DeliveryData::confirmation( $confirmation_json, $session_id, $user_id, $accepted );
			if ( $choice_json === $expected_choice && $confirmation_json === $expected_confirmation ) { return true; }
			$set = 'delivery_choice = ' . ( null === $choice_json ? 'NULL' : '%s' ) . ', delivery_confirmation = %s';
			$args = null === $choice_json ? [] : [ $choice_json ];
			array_push( $args, $confirmation_json, $session_id, $session->partner_id, $user_id, $login_token, Session::ACTIVE, gmdate( 'Y-m-d H:i:s' ) );
			$where = 'id = %d AND partner_id = %d AND user_id = %d AND BINARY wp_session_token = %s AND status = %s AND expires > %s';
			foreach ( [ 'delivery_choice' => $expected_choice, 'delivery_confirmation' => $expected_confirmation ] as $column => $expected ) {
				$where .= ' AND ' . ( null === $expected ? $column . ' IS NULL' : 'BINARY ' . $column . ' = %s' );
				if ( null !== $expected ) { $args[] = $expected; }
			}
			$written = $wpdb->query( $wpdb->prepare( 'UPDATE ' . $this->table() . ' SET ' . $set . ' WHERE ' . $where, ...$args ) );
			if ( 1 !== $written || '' !== $wpdb->last_error ) { return false; }
			$after = $this->find( $session_id );
			return $after && $after->partner_id === $session->partner_id && $after->user_id === $user_id && $after->wp_session_token === $login_token && Session::ACTIVE === $after->status && $after->expires && $after->expires > gmdate( 'Y-m-d H:i:s' ) && $after->delivery_choice_json === $choice_json && $after->delivery_confirmation_json === $confirmation_json && $this->login_valid_checked( $after );
		} catch ( \Throwable $error ) { return false; }
	}

	/**
	 * Caller holds the partner mutex. Remove consent after a cart mutation or an unacknowledged save, preserving the selected destination. Exact stored login binding is required, but the native token may already be revoked: recovery must still remove that ACTIVE row's consent. Completed snapshots are never changed. False requires caller recovery/fencing, not an assumption that old state survived.
	 */
	public function invalidate_delivery( int $session_id, int $user_id, string $login_token ): bool {
		global $wpdb;
		try {
			if ( $session_id <= 0 || $user_id <= 0 || '' === $login_token ) { return false; }
			$before = $this->find( $session_id );
			if ( ! $before || $before->user_id !== $user_id || $before->wp_session_token !== $login_token || Session::ACTIVE !== $before->status ) { return false; }
			if ( null === $before->delivery_confirmation_json ) { return true; }
			$affected = $wpdb->query( $wpdb->prepare(
				'UPDATE ' . $this->table() . ' SET delivery_confirmation = NULL WHERE id = %d AND partner_id = %d AND user_id = %d AND BINARY wp_session_token = %s AND status = %s AND BINARY delivery_confirmation = %s',
				$session_id, $before->partner_id, $user_id, $login_token, Session::ACTIVE, $before->delivery_confirmation_json
			) );
			if ( 1 !== $affected || '' !== $wpdb->last_error ) { return false; }
			$after = $this->find( $session_id );
			return $after && $after->partner_id === $before->partner_id && $after->user_id === $user_id && $after->wp_session_token === $login_token && Session::ACTIVE === $after->status && null === $after->delivery_confirmation_json && $after->delivery_choice_json === $before->delivery_choice_json;
		} catch ( \Throwable $error ) { return false; }
	}

	/**
	 * Fill only an unowned order column. PayExit's generic update remains authoritative.
	 *
	 * @return string linked|already_owned|error. Zero affected rows also covers a missing session.
	 */
	public function link_quote_if_empty( int $id, int $order_id ): string {
		global $wpdb;

		if ( $id <= 0 || $order_id <= 0 ) {
			return 'error';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$affected = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . $this->table() . ' SET order_id = %d WHERE id = %d AND (order_id = 0 OR order_id IS NULL)',
				$order_id,
				$id
			)
		);

		return false === $affected ? 'error' : ( 1 === $affected ? 'linked' : 'already_owned' );
	}

	/**
	 * Existing row for a (partner, payloadID) pair — replay detection.
	 */
	public function find_by_payload( int $partner_id, string $payload_id ): ?Session {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->table() . ' WHERE partner_id = %d AND payload_id = %s',
				$partner_id,
				$payload_id
			),
			ARRAY_A
		);

		if ( '' !== ( $wpdb->last_error ?? '' ) ) { throw new \RuntimeException( 'Session lookup failed.' ); }
		return $row ? Session::from_row( $row ) : null;
	}

	/**
	 * Atomically redeem a one-time StartPage token: exactly one query flips
	 * pending -> active for an unexpired row; zero affected rows means
	 * invalid, expired or already used — the caller cannot tell which, and
	 * neither can an attacker (scope §9.2).
	 */
	public function redeem_token( string $token_hash ): ?Session {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$affected = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . $this->table() . " SET status = %s WHERE one_time_token_hash = %s AND status = %s AND expires > %s",
				Session::ACTIVE,
				$token_hash,
				Session::PENDING,
				gmdate( 'Y-m-d H:i:s' )
			)
		);

		if ( 1 !== $affected ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE one_time_token_hash = %s', $token_hash ),
			ARRAY_A
		);

		if ( '' !== ( $wpdb->last_error ?? '' ) ) { throw new \RuntimeException( 'Session lookup failed.' ); }
		return $row ? Session::from_row( $row ) : null;
	}

	/**
	 * Guarded state transition mirroring Session::can_transition().
	 */
	public function transition( int $id, string $from, string $to, array $extra = [], ?array $expected_guard = null ): bool {
		global $wpdb;

		if ( ! Session::can_transition( $from, $to ) ) {
			return false;
		}
		// Cart return keeps the existing single winner UPDATE. The caller holds the partner mutex and has validated native login/address/cart state; these byte-exact predicates close the remaining persisted-consent race. Legacy callers retain their existing status-only transition.
		if ( null !== $expected_guard ) {
			$keys = [ 'user_id', 'wp_session_token', 'delivery_choice', 'delivery_confirmation' ];
			if ( Session::ACTIVE !== $from || Session::RETURNED !== $to || $id <= 0 || array_diff( $keys, array_keys( $expected_guard ) ) || array_diff( array_keys( $expected_guard ), $keys ) || ! is_int( $expected_guard['user_id'] ) || $expected_guard['user_id'] <= 0 || ! is_string( $expected_guard['wp_session_token'] ) || '' === $expected_guard['wp_session_token'] || ( null !== $expected_guard['delivery_choice'] && ! is_string( $expected_guard['delivery_choice'] ) ) || ! is_string( $expected_guard['delivery_confirmation'] ) || '' === $expected_guard['delivery_confirmation'] ) { return false; }
			if ( strlen( $expected_guard['delivery_confirmation'] ) > \POW\Addresses\DeliveryData::MAX_JSON_BYTES || ( null !== $expected_guard['delivery_choice'] && strlen( $expected_guard['delivery_choice'] ) > \POW\Addresses\DeliveryData::MAX_JSON_BYTES ) ) { return false; }
		}

		$set    = [ 'status = %s' ];
		$values = [ $to ];

		foreach ( $extra as $column => $value ) {
			if ( in_array( $column, [ 'order_id', 'user_id' ], true ) ) {
				$set[]    = "{$column} = %d";
				$values[] = (int) $value;
			} elseif ( in_array( $column, [ 'wp_session_token', 'expires', 'response_xml' ], true ) ) {
				$set[]    = "{$column} = %s";
				$values[] = (string) $value;
			}
		}

		$values[] = $id;
		$values[] = $from;
		$where = ' WHERE id = %d AND status = %s';
		if ( null !== $expected_guard ) {
			$where .= ' AND user_id = %d AND BINARY wp_session_token = BINARY %s AND expires > %s';
			array_push( $values, $expected_guard['user_id'], $expected_guard['wp_session_token'], gmdate( 'Y-m-d H:i:s' ) );
			foreach ( [ 'delivery_choice', 'delivery_confirmation' ] as $column ) {
				if ( null === $expected_guard[$column] ) { $where .= " AND {$column} IS NULL"; }
				else { $where .= " AND BINARY {$column} = BINARY %s"; $values[] = $expected_guard[$column]; }
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$affected = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . $this->table() . ' SET ' . implode( ', ', $set ) . $where,
				...$values
			)
		);

		return 1 === $affected;
	}

	/**
	 * The buyer's live session: bound to BOTH the user id and the exact
	 * WP session token created at auto-login, so a stale login from an
	 * earlier punchout can never act on a newer session (scope §4.2).
	 *
	 * @param list<string> $statuses Acceptable statuses.
	 */
	public function find_for_login( int $user_id, string $wp_session_token, array $statuses = [ Session::ACTIVE ] ): ?Session {
		global $wpdb;

		if ( 0 === $user_id || '' === $wp_session_token || [] === $statuses ) {
			return null;
		}

		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->table() . " WHERE user_id = %d AND wp_session_token = %s AND status IN ({$placeholders}) ORDER BY id DESC LIMIT 1",
				$user_id,
				$wp_session_token,
				...$statuses
			),
			ARRAY_A
		);

		if ( '' !== ( $wpdb->last_error ?? '' ) ) { throw new \RuntimeException( 'Session lookup failed.' ); }
		return $row ? Session::from_row( $row ) : null;
	}

	/**
	 * Open (pending/active/ordered) sessions for a user — the latest-
	 * punchout-wins sweep input (scope §5.1).
	 *
	 * @return list<Session>
	 */
	public function open_for_user( int $user_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->table() . ' WHERE user_id = %d AND status IN (%s, %s, %s)',
				$user_id,
				Session::PENDING,
				Session::ACTIVE,
				Session::ORDERED
			),
			ARRAY_A
		);

		if ( '' !== ( $wpdb->last_error ?? '' ) ) { throw new \RuntimeException( 'Session lookup failed.' ); }
		return array_map( [ Session::class, 'from_row' ], $rows ?: [] );
	}

	/**
	 * Open sessions belonging to one customer connection — swept when the
	 * connection is deleted (Admin\Actions::delete_partner).
	 *
	 * @return list<Session>
	 */
	public function open_for_partner( int $partner_id, int $limit = 500 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->table() . ' WHERE partner_id = %d AND status IN (%s, %s, %s) LIMIT %d',
				$partner_id,
				Session::PENDING,
				Session::ACTIVE,
				Session::ORDERED,
				$limit
			),
			ARRAY_A
		);

		if ( '' !== ( $wpdb->last_error ?? '' ) ) { throw new \RuntimeException( 'Session lookup failed.' ); }
		return array_map( [ Session::class, 'from_row' ], $rows ?: [] );
	}

	/**
	 * Every open session regardless of expiry — the master-switch-off
	 * sweep (Plugin::on_settings_updated) has to reach them all.
	 *
	 * @return list<Session>
	 */
	public function all_open( int $limit = 500 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->table() . ' WHERE status IN (%s, %s, %s) LIMIT %d',
				Session::PENDING,
				Session::ACTIVE,
				Session::ORDERED,
				$limit
			),
			ARRAY_A
		);

		if ( '' !== ( $wpdb->last_error ?? '' ) ) { throw new \RuntimeException( 'Session lookup failed.' ); }
		return array_map( [ Session::class, 'from_row' ], $rows ?: [] );
	}

	/**
	 * Sessions past their expiry that cron must reap.
	 *
	 * @return list<Session>
	 */
	public function expired_open( int $limit = 200 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->table() . ' WHERE status IN (%s, %s, %s) AND expires < %s LIMIT %d',
				Session::PENDING,
				Session::ACTIVE,
				Session::ORDERED,
				gmdate( 'Y-m-d H:i:s' ),
				$limit
			),
			ARRAY_A
		);

		if ( '' !== ( $wpdb->last_error ?? '' ) ) { throw new \RuntimeException( 'Session lookup failed.' ); }
		return array_map( [ Session::class, 'from_row' ], $rows ?: [] );
	}

	/**
	 * The session an order belongs to (payment_complete may arrive after
	 * teardown — the audit trail still needs the linkage, scope §9.7).
	 */
	public function find_by_order( int $order_id ): ?Session {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE order_id = %d ORDER BY id DESC LIMIT 1', $order_id ),
			ARRAY_A
		);

		if ( '' !== ( $wpdb->last_error ?? '' ) ) { throw new \RuntimeException( 'Session lookup failed.' ); }
		return $row ? Session::from_row( $row ) : null;
	}
}
