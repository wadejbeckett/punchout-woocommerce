<?php
/** Optimistic persistence over Woo's existing session row. @package POW @license AGPL-3.0-or-later */
declare( strict_types = 1 );
namespace POW\Cart;
defined( 'ABSPATH' ) || exit;

/** No serialization, cache publication or application callbacks in the locked methods. */
final class NativeSessionRow {
	private bool $loaded = false;
	private bool $conflicted = false;
	private ?string $expected = null;
	public function __construct( private string $key ) {}
	public function load(): ?string {
		if ( ! $this->loaded ) {
			try { $this->expected = $this->read()['session_value'] ?? null; $this->loaded = true; }
			catch ( \Throwable $error ) { $this->conflicted = true; throw $error; }
		}
		// Reading again must never attach a newer baseline to already hydrated old data.
		return $this->expected;
	}
	public function refuse(): void { $this->conflicted = true; }
	/** Conditional lifecycle removal; a stale request cannot delete a replacement row. */
	public function delete_locked(): bool {
		global $wpdb;
		if ( ! $this->matches_locked() ) { return false; }
		try {
			if ( null !== $this->expected ) {
				$affected = $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $wpdb->prefix . 'woocommerce_sessions WHERE BINARY session_key = BINARY %s AND BINARY session_value = BINARY %s', $this->key, $this->expected ) );
				if ( 1 !== $affected || '' !== ( $wpdb->last_error ?? '' ) ) { $this->refuse(); return false; }
			}
			$removed = null === $this->read();
			$this->refuse(); // A deleted native session cannot be resurrected by shutdown.
			return $removed;
		} catch ( \Throwable $error ) { $this->refuse(); return false; }
	}
	public function matches_locked(): bool {
		if ( ! $this->loaded || $this->conflicted ) { return false; }
		try {
			$row = $this->read();
			if ( ( $row['session_value'] ?? null ) === $this->expected && ( null === $row || (int) $row['session_expiry'] > time() ) ) { return true; }
		} catch ( \Throwable $error ) { /* Read errors grant no authority. */ }
		$this->conflicted = true;
		return false;
	}
	/** Caller holds the same partner mutex used by consent and the return winner. */
	public function commit_locked( string $candidate, int $expiry ): bool {
		global $wpdb;
		if ( ! $this->matches_locked() || $expiry <= time() ) { $this->refuse(); return false; }
		try {
			$table = $wpdb->prefix . 'woocommerce_sessions';
			if ( null === $this->expected ) {
				$sql = $wpdb->prepare( 'INSERT IGNORE INTO ' . $table . ' (session_key, session_value, session_expiry) VALUES (%s, %s, %d)', $this->key, $candidate, $expiry );
			} else {
				$sql = $wpdb->prepare( 'UPDATE ' . $table . ' SET session_value = %s, session_expiry = %d WHERE BINARY session_key = BINARY %s AND BINARY session_value = BINARY %s', $candidate, $expiry, $this->key, $this->expected );
			}
			$affected = $wpdb->query( $sql );
			if ( '' !== ( $wpdb->last_error ?? '' ) || false === $affected || ! in_array( $affected, [ 0, 1 ], true ) || ( null === $this->expected && 1 !== $affected ) ) { $this->refuse(); return false; }
			// Zero is a verified unchanged update only, never an optimistic success assumption.
			$row = $this->read();
			if ( null === $row || $row['session_value'] !== $candidate || (int) $row['session_expiry'] !== $expiry || ( 0 === $affected && $this->expected !== $candidate ) ) { $this->refuse(); return false; }
			$this->expected = $candidate;
			return true;
		} catch ( \Throwable $error ) { $this->refuse(); return false; }
	}
	private function read(): ?array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT session_value, session_expiry FROM ' . $wpdb->prefix . 'woocommerce_sessions WHERE BINARY session_key = BINARY %s LIMIT 2', $this->key ), ARRAY_A );
		if ( '' !== ( $wpdb->last_error ?? '' ) || ! is_array( $rows ) || count( $rows ) > 1 ) { throw new \RuntimeException( 'Native cart storage unavailable.' ); }
		if ( [] === $rows ) { return null; }
		$row = $rows[0];
		if ( ! is_string( $row['session_value'] ?? null ) || ! is_numeric( $row['session_expiry'] ?? null ) ) { throw new \RuntimeException( 'Native cart storage invalid.' ); }
		return $row;
	}
}
