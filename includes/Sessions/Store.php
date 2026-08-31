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
final class Store {

	private function table(): string {
		return Installer::sessions_table();
	}

	public function find( int $id ): ?Session {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE id = %d', $id ), ARRAY_A );

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

		return $row ? Session::from_row( $row ) : null;
	}

	/**
	 * Guarded state transition mirroring Session::can_transition().
	 */
	public function transition( int $id, string $from, string $to, array $extra = [] ): bool {
		global $wpdb;

		if ( ! Session::can_transition( $from, $to ) ) {
			return false;
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$affected = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . $this->table() . ' SET ' . implode( ', ', $set ) . ' WHERE id = %d AND status = %s',
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

		return $row ? Session::from_row( $row ) : null;
	}
}
