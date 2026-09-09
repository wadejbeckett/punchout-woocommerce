<?php
/** Database boundary fixture: exercises the real Store SQL; not a database concurrency simulator.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

final class ReturnDatabase {
	public string $partner_status = 'active';
	public string $last_error = '';
	private bool $suppressed = false;
	public function suppress_errors( bool $suppress = true ): bool { $old = $this->suppressed; $this->suppressed = $suppress; return $old; }
	public function get_var( string $sql ): mixed { if ( str_contains( $sql, 'GET_LOCK(' ) || str_contains( $sql, 'RELEASE_LOCK(' ) ) { return '1'; } throw new RuntimeException( 'Unexpected scalar query' ); }
	public string $prefix = 'wp_';
	public array $session = [ 'id' => 42, 'partner_id' => 7, 'user_id' => 99, 'wp_session_token' => 'test-login', 'status' => 'active', 'order_id' => 0, 'buyer_cookie' => 'basket-reference', 'browser_form_post_url' => 'https://buyer.example.test/return' ];
	public array $queries = [];
	public array $audits = [];
	public mixed $link_result = null;
	public bool $transition_fails = false;
	public bool $audit_result = true;
	public ?Throwable $audit_error = null;
	public function prepare( string $sql, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			$sql = preg_replace_callback( '/%[sd]/', static fn( $m ) => '%d' === $m[0] ? (string) (int) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'", $sql, 1 );
		}
		return $sql;
	}
	public function get_row( string $sql, string $format ): ?array {
		if ( str_contains( $sql, 'wp_pow_partners' ) ) { return [ 'id' => 7, 'name' => 'Example buyer', 'status' => $this->partner_status ]; }
		if ( str_contains( $sql, 'wp_session_token' ) && ( ! str_contains( $sql, "wp_session_token = 'test-login'" ) || ! str_contains( $sql, "'" . $this->session['status'] . "'" ) ) ) { return null; }
		return $this->session;
	}
	public function update( string $table, array $data, array $where ): int {
		$this->session = array_merge( $this->session, $data );
		return 1;
	}
	public function query( string $sql ): int|false {
		$this->queries[] = $sql;
		if ( preg_match( "/SET status = '([^']+)' WHERE id = 42 AND status = '([^']+)'$/", $sql, $m ) ) {
			if ( $this->transition_fails || $this->session['status'] !== $m[2] ) { return 0; }
			$this->session['status'] = $m[1];
			return 1;
		}
		if ( preg_match( '/SET order_id = (\d+) WHERE id = 42 AND \\(order_id = 0 OR order_id IS NULL\\)$/', $sql, $m ) ) {
			if ( $this->link_result instanceof Throwable ) { throw $this->link_result; }
			if ( null !== $this->link_result ) { return $this->link_result; }
			if ( ! empty( $this->session['order_id'] ) ) { return 0; }
			$this->session['order_id'] = (int) $m[1];
			return 1;
		}
		throw new RuntimeException( 'Unexpected fixture query: ' . $sql );
	}
	public function insert( string $table, array $row ): int|false {
		if ( $this->audit_error ) { throw $this->audit_error; }
		if ( ! $this->audit_result ) { return false; }
		$this->audits[] = $row;
		return 1;
	}
}
