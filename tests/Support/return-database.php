<?php
/** Database boundary fixture: exercises the real Store SQL; not a database concurrency simulator.
 *
 * The session table is a row SET, not a row. One bound customer account holds
 * one session row per punchout visit, so a fixture that can only hold one row
 * makes every cross-visit isolation test pass vacuously: the single row
 * answers whatever token is asked for. `$sessions` is keyed by session id and
 * `$session` is an alias of the first visit, so the suites that speak about
 * one visit are unchanged while a two-visit fixture is now expressible.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

final class ReturnDatabase {
	/** The visit `$session` addresses: the one row every single-visit suite means. */
	public const PRIMARY_VISIT = 42;

	/**
	 * Columns whose value identifies exactly one visit. A lookup naming one
	 * of them must be answered by the row that carries the requested value or
	 * by no row at all — never by whichever row happens to sort first.
	 */
	private const VISIT_KEYS = [ 'wp_session_token', 'wc_session_key' ];

	public string $partner_status = 'active';
	public array $partner_fields = [];
	public string $last_error = '';
	private bool $suppressed = false;
	public function suppress_errors( bool $suppress = true ): bool { $old = $this->suppressed; $this->suppressed = $suppress; return $old; }
	public function get_var( string $sql ): mixed { if ( str_contains( $sql, 'GET_LOCK(' ) || str_contains( $sql, 'RELEASE_LOCK(' ) ) { return '1'; } throw new RuntimeException( 'Unexpected scalar query' ); }
	public string $prefix = 'wp_';
	public string $options = 'wp_options';
	public function get_results( string $sql, string $format ): array {
		if ( ! str_contains( $sql, 'SELECT option_name, option_value FROM wp_options' ) ) { throw new RuntimeException( 'Unexpected policy query' ); }
		return [ [ 'option_name' => 'pow_settings', 'option_value' => 'a:0:{}' ], [ 'option_name' => 'woocommerce_currency', 'option_value' => 'ZAR' ] ];
	}
	/**
	 * Every open visit, keyed by session id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $sessions;
	/** Alias of `$sessions[ self::PRIMARY_VISIT ]`: reads and writes on either name land in the one row. */
	public array $session;
	public array $queries = [];
	public array $audits = [];
	public mixed $link_result = null;
	public bool $transition_fails = false;
	public mixed $before_transition = null;
	public mixed $after_transition = null;
	public int $guarded_updates = 0;
	public bool $audit_result = true;
	public ?Throwable $audit_error = null;
	public function __construct() {
		$this->sessions = [ self::PRIMARY_VISIT => self::visit( self::PRIMARY_VISIT ) ];
		$this->session  = &$this->sessions[ self::PRIMARY_VISIT ];
	}
	/**
	 * One visit's row. `wc_session_key` is the per-visit WooCommerce session
	 * key: `pow_` plus 28 hex, 32 characters in total, because the core
	 * column it has to fit is char(32). `buyer_identity` is stored in
	 * cleartext (an administrator must be able to see who bought) and
	 * `buyer_identity_hash` is the indexed form the supersede query matches
	 * on: the full sha256 of `partner_id|identity`, 64 hex in a CHAR(64)
	 * column, exactly as `Buyers\Identity::hash()` writes it. The truncated
	 * 12-hex form is the audit detail and belongs nowhere near this row.
	 *
	 * @return array<string, mixed>
	 */
	private static function visit( int $id ): array {
		return [
			'id'                    => $id,
			'partner_id'            => 7,
			'user_id'               => 99,
			'wp_session_token'      => 'test-login',
			'wc_session_key'        => 'pow_1a2b3c4d5e6f708192a3b4c5d6e7',
			'status'                => 'active',
			'order_id'              => 0,
			'buyer_cookie'          => 'basket-reference',
			'buyer_identity'        => 'buyer@example.test',
			'buyer_name'            => 'Zoë Buyer',
			'buyer_identity_hash'   => hash( 'sha256', '7|buyer@example.test' ),
			'browser_form_post_url' => 'https://buyer.example.test/return',
		];
	}
	/**
	 * A second visit of the same account: same user id, its own session id,
	 * login token and per-visit key. Overrides that leave a visit key equal
	 * to another row's would make the lookups ambiguous, so they are refused.
	 *
	 * @param array<string, mixed> $overrides Columns to differ from the first visit.
	 * @return array<string, mixed>
	 */
	public function add_visit( int $id, array $overrides = [] ): array {
		if ( isset( $this->sessions[ $id ] ) ) { throw new RuntimeException( 'The fixture already holds visit ' . $id . '.' ); }
		$row = array_replace( self::visit( $id ), $overrides, [ 'id' => $id ] );
		foreach ( self::VISIT_KEYS as $column ) {
			foreach ( $this->sessions as $existing ) {
				if ( ( $existing[ $column ] ?? null ) === ( $row[ $column ] ?? null ) ) { throw new RuntimeException( 'Visit ' . $id . ' must carry its own ' . $column . '.' ); }
			}
		}
		$this->sessions[ $id ] = $row;
		return $row;
	}
	public function prepare( string $sql, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			$sql = preg_replace_callback( '/%[sd]/', static fn( $m ) => '%d' === $m[0] ? (string) (int) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'", $sql, 1 );
		}
		return $sql;
	}
	public function get_row( string $sql, string $format ): ?array {
		if ( str_contains( $sql, 'wp_pow_partners' ) ) { return array_replace( [ 'id' => 7, 'name' => 'Example buyer', 'status' => $this->partner_status ], $this->partner_fields ); }
		foreach ( self::VISIT_KEYS as $column ) {
			if ( str_contains( $sql, $column ) ) { return $this->visit_named( $sql, $column ); }
		}
		// A re-read by row id is the visit that id names. Answering it with the
		// first visit would let a second visit's winner guard compare itself
		// against somebody else's row and still agree.
		if ( str_contains( $sql, 'pow_sessions' ) && preg_match( '/(?<![a-z_])id = (\d+)/', $sql, $m ) ) {
			return $this->sessions[ (int) $m[1] ] ?? null;
		}
		return $this->session;
	}
	/**
	 * The one visit a login lookup names. Two rows share a user id, so the
	 * requested token — not the row order — decides, and the status list in
	 * the query still has to admit that row's status. No match is no row: a
	 * stale or foreign key must own nothing.
	 *
	 * @return array<string, mixed>|null
	 */
	private function visit_named( string $sql, string $column ): ?array {
		foreach ( $this->sessions as $row ) {
			$value = (string) ( $row[ $column ] ?? '' );
			if ( '' === $value || ! str_contains( $sql, $column . " = '" . str_replace( "'", "''", $value ) . "'" ) ) { continue; }
			if ( ! str_contains( $sql, "'" . $row['status'] . "'" ) ) { continue; }
			return $row;
		}
		return null;
	}
	/**
	 * A session update addresses the visit its WHERE names. This fixture also
	 * answers for the partners table, whose ids are not visit ids, so
	 * anything that is not a session row keeps the historical tolerance of
	 * merging into the first visit.
	 */
	public function update( string $table, array $data, array $where ): int {
		$id  = str_contains( $table, 'pow_sessions' ) ? (int) ( $where['id'] ?? 0 ) : 0;
		$row = &$this->sessions[ isset( $this->sessions[ $id ] ) ? $id : self::PRIMARY_VISIT ];
		$row = array_merge( $row, $data );
		return 1;
	}
	public function query( string $sql ): int|false {
		$this->queries[] = $sql;
		if ( preg_match( "/SET status = '([^']+)' WHERE id = ([0-9]+) AND status = '([^']+)'(.*)$/s", $sql, $m ) ) {
			if ( $this->before_transition ) { $callback = $this->before_transition; $this->before_transition = null; $callback(); }
			$row = &$this->row( (int) $m[2] );
			$tail = $m[4]; $seen = []; $matches = true;
			while ( '' !== $tail ) {
				if ( preg_match( '/^ AND (user_id|partner_id) = ([0-9]+)/', $tail, $part ) ) { $field = $part[1]; $matches = $matches && (int) $row[$field] === (int) $part[2]; }
				elseif ( preg_match( "/^ AND BINARY (wp_session_token|wc_session_key|delivery_choice|delivery_confirmation) = BINARY '((?:''|[^'])*)'/s", $tail, $part ) ) { $field = $part[1]; $matches = $matches && ( $row[$field] ?? null ) === str_replace( "''", "'", $part[2] ); }
				elseif ( preg_match( '/^ AND (delivery_choice|delivery_confirmation) IS NULL/', $tail, $part ) ) { $field = $part[1]; $matches = $matches && null === ( $row[$field] ?? null ); }
				elseif ( preg_match( "/^ AND expires > '([^']+)'/", $tail, $part ) ) { $field = 'expires'; $matches = $matches && isset( $row[$field] ) && $row[$field] > $part[1]; }
				else { throw new RuntimeException( 'Unexpected guarded transition predicate: ' . $tail ); }
				if ( isset( $seen[$field] ) ) { throw new RuntimeException( 'Duplicate guarded predicate: ' . $field ); }
				$seen[$field] = true; $tail = substr( $tail, strlen( $part[0] ) );
			}
			if ( [] !== $seen ) {
				// The EXACT predicate set each guarded transition must carry. It is a
				// fail-closed check, not a convenience: a transition that gains or
				// loses one predicate — a visit-scoped wc_session_key, say — must
				// update this list in the same commit, or all 21 ReturnIntegrationTest
				// and 12 DeliveryReturnTransitionTest cases fail together and say so.
				$required = 'expired' === $m[1] ? [ 'partner_id', 'user_id', 'wp_session_token' ] : [ 'user_id', 'wp_session_token', 'expires', 'delivery_choice', 'delivery_confirmation' ];
				if ( array_diff( $required, array_keys( $seen ) ) || array_diff( array_keys( $seen ), $required ) ) { throw new RuntimeException( 'Guarded transition is missing an exact predicate.' ); }
				++$this->guarded_updates;
			}
			if ( ! $matches || $this->transition_fails || (int) $row['id'] !== (int) $m[2] || $row['status'] !== $m[3] ) { return 0; }
			$row['status'] = $m[1];
			if ( $this->after_transition ) { $callback = $this->after_transition; $this->after_transition = null; $callback(); }
			return 1;
		}
		if ( preg_match( '/SET order_id = (\d+) WHERE id = (\d+) AND \\(order_id = 0 OR order_id IS NULL\\)$/', $sql, $m ) ) {
			if ( $this->link_result instanceof Throwable ) { throw $this->link_result; }
			if ( null !== $this->link_result ) { return $this->link_result; }
			$row = &$this->row( (int) $m[2] );
			if ( ! empty( $row['order_id'] ) ) { return 0; }
			$row['order_id'] = (int) $m[1];
			return 1;
		}
		throw new RuntimeException( 'Unexpected fixture query: ' . $sql );
	}
	/**
	 * The addressed visit, by reference so a transition writes the row set
	 * itself. An unknown id is a fixture error, never a silent write to the
	 * first visit.
	 *
	 * @return array<string, mixed>
	 */
	private function &row( int $id ): array {
		if ( ! isset( $this->sessions[ $id ] ) ) { throw new RuntimeException( 'Query names a visit the fixture does not hold: ' . $id ); }
		return $this->sessions[ $id ];
	}
	public function insert( string $table, array $row ): int|false {
		if ( $this->audit_error ) { throw $this->audit_error; }
		if ( ! $this->audit_result ) { return false; }
		$this->audits[] = $row;
		return 1;
	}
}
