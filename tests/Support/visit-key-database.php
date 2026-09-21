<?php
/** Database boundary fixture for the per-visit key: the real Store and Registry SQL, parsed.
 *
 * Shared rather than file-local because more than one suite now needs a
 * multi-visit boundary: the key's own queries (tests/Unit/SessionKeyTest.php)
 * and the redeem path that mints it (tests/Unit/StartEndpointTest.php). It
 * parses the WHERE clause the production SQL carries, so a query that drops a
 * predicate stops matching instead of quietly answering with whichever row
 * sorts first — with one shared customer account that is the only thing
 * telling two visits apart.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

/**
 * wpdb double over the tables a visit touches: its punchout session row, its
 * own WooCommerce session row, its connection row and the audit log.
 */
final class VisitKeyDatabase {
	public string $prefix = 'wp_';
	public string $last_error = '';
	/** @var array<int, array<string, mixed>> Punchout session rows, keyed by id. */
	public array $sessions = [];
	/** @var array<int, array<string, mixed>> Connection rows, keyed by id. */
	public array $partners = [];
	/** @var array<string, int> WooCommerce session rows: session_key => session_expiry. */
	public array $carts = [];
	/** @var list<string> Every statement, in order. */
	public array $queries = [];
	/** @var list<array<string, mixed>> Audit rows this fixture accepted. */
	public array $audits = [];
	public bool $write_fails = false;
	public bool $cart_delete_fails = false;
	/** Whether an audit insert is accepted; a refusal is not an exception. */
	public bool $audit_result = true;
	/** Compare keys the way a PAD SPACE collation on a char(32) column does. */
	public bool $pads_trailing_space = false;
	/** Runs immediately after a successful session-row write, as a racing request would. */
	public mixed $after_write = null;

	private bool $suppressed = false;

	/** Registry's critical section suppresses errors around GET_LOCK; the flag is observable, nothing more. */
	public function suppress_errors( bool $suppress = true ): bool {
		$previous         = $this->suppressed;
		$this->suppressed = $suppress;
		return $previous;
	}

	public function prepare( string $sql, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			$sql = preg_replace_callback( '/%[sd]/', static fn( array $m ): string => '%d' === $m[0] ? (string) (int) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'", $sql, 1 );
		}
		return $sql;
	}

	public function get_row( string $sql, string $format ): ?array {
		$this->queries[] = $sql;
		if ( str_contains( $sql, 'pow_partners' ) ) {
			return preg_match( '/(?<![a-z_])id = (\d+)/', $sql, $m ) ? ( $this->partners[ (int) $m[1] ] ?? null ) : null;
		}
		return $this->matching( $sql )[0] ?? null;
	}

	public function get_results( string $sql, string $format ): array {
		$this->queries[] = $sql;
		return $this->matching( $sql );
	}

	public function get_var( string $sql ): mixed {
		$this->queries[] = $sql;
		// The partner mutex is a MySQL advisory lock; this fixture is not a
		// concurrency simulator, so it always grants and always releases.
		if ( str_contains( $sql, 'GET_LOCK(' ) || str_contains( $sql, 'RELEASE_LOCK(' ) ) {
			return '1';
		}
		if ( str_contains( $sql, 'woocommerce_sessions' ) ) {
			return isset( $this->carts[ $this->cart_key( $sql ) ] ) ? '1' : null;
		}
		if ( str_contains( $sql, 'COUNT(*)' ) ) {
			return (string) count( $this->matching( $sql ) );
		}
		throw new RuntimeException( 'Unexpected scalar query: ' . $sql );
	}

	public function query( string $sql ): int|false {
		$this->queries[] = $sql;
		if ( str_starts_with( $sql, 'DELETE' ) && str_contains( $sql, 'woocommerce_sessions' ) ) {
			if ( $this->cart_delete_fails ) { return 0; }
			$key = $this->cart_key( $sql );
			if ( ! isset( $this->carts[ $key ] ) ) { return 0; }
			unset( $this->carts[ $key ] );
			return 1;
		}
		if ( ! str_starts_with( $sql, 'UPDATE' ) || ! str_contains( $sql, 'pow_sessions' ) ) {
			throw new RuntimeException( 'Unexpected fixture query: ' . $sql );
		}
		if ( $this->write_fails ) { $this->last_error = 'Injected write failure'; return false; }
		$matched = $this->matching( $sql );
		if ( [] === $matched ) { return 0; }
		$id = (int) $matched[0]['id'];
		$this->sessions[ $id ] = array_replace( $this->sessions[ $id ], $this->assignments( $sql ) );
		if ( $this->after_write ) { $callback = $this->after_write; $this->after_write = null; $callback(); }
		return 1;
	}

	/**
	 * Column writes through wpdb's own helper: the connection lifecycle uses
	 * it, the session store does not.
	 *
	 * @param array<string, mixed> $data  Column values.
	 * @param array<string, mixed> $where Addressed row.
	 */
	public function update( string $table, array $data, array $where ): int|false {
		$this->queries[] = 'UPDATE ' . $table . ' SET ' . implode( ', ', array_keys( $data ) ) . ' WHERE ' . implode( ', ', array_keys( $where ) );
		$id = (int) ( $where['id'] ?? 0 );
		if ( str_contains( $table, 'pow_partners' ) ) {
			if ( ! isset( $this->partners[ $id ] ) ) { return 0; }
			foreach ( $where as $column => $value ) {
				if ( 'id' !== $column && (string) ( $this->partners[ $id ][ $column ] ?? '' ) !== (string) $value ) { return 0; }
			}
			$this->partners[ $id ] = array_replace( $this->partners[ $id ], $data );
			return 1;
		}
		if ( ! isset( $this->sessions[ $id ] ) ) { return 0; }
		$this->sessions[ $id ] = array_replace( $this->sessions[ $id ], $data );
		return 1;
	}

	/**
	 * Audit rows. A refusal is reported, not thrown: the log is diagnostics
	 * and never undoes a confirmed login.
	 *
	 * @param array<string, mixed> $row Audit columns.
	 */
	public function insert( string $table, array $row ): int|false {
		if ( ! $this->audit_result ) { return false; }
		$this->audits[] = $row;
		return 1;
	}

	/** The key a WooCommerce session statement names, byte-exact. */
	private function cart_key( string $sql ): string {
		return preg_match( "/session_key = (?:BINARY )?'((?:''|[^'])*)'/", $sql, $m ) ? str_replace( "''", "'", $m[1] ) : '';
	}

	/**
	 * Session rows the statement's WHERE clause selects.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function matching( string $sql ): array {
		$where = str_contains( $sql, ' WHERE ' ) ? substr( $sql, strpos( $sql, ' WHERE ' ) + 7 ) : '';
		$unbound_token = str_contains( $where, "wp_session_token IS NULL OR wp_session_token = ''" );
		$unbound_key   = str_contains( $where, 'wc_session_key IS NULL' );
		$where = str_replace( [ "(wp_session_token IS NULL OR wp_session_token = '')", 'wc_session_key IS NULL' ], '', $where );

		$found = [];
		foreach ( $this->sessions as $row ) {
			if ( $unbound_token && '' !== (string) ( $row['wp_session_token'] ?? '' ) ) { continue; }
			if ( $unbound_key && '' !== (string) ( $row['wc_session_key'] ?? '' ) ) { continue; }
			foreach ( [ 'id', 'partner_id', 'user_id' ] as $column ) {
				if ( preg_match( '/(?<![a-z_])' . $column . ' = (\d+)/', $where, $m ) && (int) ( $row[ $column ] ?? 0 ) !== (int) $m[1] ) { continue 2; }
			}
			foreach ( [ 'wp_session_token', 'wc_session_key', 'buyer_identity_hash', 'one_time_token_hash' ] as $column ) {
				if ( ! preg_match( '/' . $column . " = (?:BINARY )?'((?:''|[^'])*)'/", $where, $m ) ) { continue; }
				$stored    = (string) ( $row[ $column ] ?? '' );
				$requested = str_replace( "''", "'", $m[1] );
				if ( $this->pads_trailing_space && ! str_contains( $where, 'BINARY ' . $column ) ) { $stored = rtrim( $stored, ' ' ); $requested = rtrim( $requested, ' ' ); }
				if ( $stored !== $requested ) { continue 2; }
			}
			// An expiry predicate is a string comparison on a UTC timestamp,
			// exactly as MySQL compares the DATETIME column.
			foreach ( [ '>', '<' ] as $operator ) {
				if ( ! preg_match( "/expires " . $operator . " '([^']*)'/", $where, $m ) ) { continue; }
				$expires = (string) ( $row['expires'] ?? '' );
				if ( '' === $expires || ( '>' === $operator ? $expires <= $m[1] : $expires >= $m[1] ) ) { continue 2; }
			}
			if ( preg_match( '/status IN \(([^)]*)\)/', $where, $m ) ) {
				preg_match_all( "/'([^']*)'/", $m[1], $all );
				if ( ! in_array( (string) ( $row['status'] ?? '' ), $all[1], true ) ) { continue; }
			}
			if ( preg_match( "/status = '([^']*)'/", $where, $m ) && (string) ( $row['status'] ?? '' ) !== $m[1] ) { continue; }
			$found[] = $row;
		}
		if ( str_contains( $sql, 'ORDER BY id DESC' ) ) { usort( $found, static fn( array $a, array $b ): int => (int) $b['id'] <=> (int) $a['id'] ); }
		return $found;
	}

	/**
	 * Columns a session UPDATE writes.
	 *
	 * @return array<string, mixed>
	 */
	private function assignments( string $sql ): array {
		$set = substr( $sql, strlen( 'UPDATE ' ) );
		$set = substr( $set, strpos( $set, ' SET ' ) + 5, strpos( $set, ' WHERE ' ) - strpos( $set, ' SET ' ) - 5 );
		$written = [];
		foreach ( explode( ', ', $set ) as $assignment ) {
			if ( preg_match( "/^([a-z_]+) = '((?:''|[^'])*)'$/", $assignment, $m ) ) { $written[ $m[1] ] = str_replace( "''", "'", $m[2] ); }
			elseif ( preg_match( '/^([a-z_]+) = (\d+)$/', $assignment, $m ) ) { $written[ $m[1] ] = (int) $m[2]; }
			else { throw new RuntimeException( 'Unexpected assignment: ' . $assignment ); }
		}
		return $written;
	}
}
