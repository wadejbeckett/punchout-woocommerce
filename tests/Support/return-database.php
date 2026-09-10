<?php
/** Database boundary fixture: exercises the real Store SQL; not a database concurrency simulator.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

final class ReturnDatabase {
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
	public array $session = [ 'id' => 42, 'partner_id' => 7, 'user_id' => 99, 'wp_session_token' => 'test-login', 'status' => 'active', 'order_id' => 0, 'buyer_cookie' => 'basket-reference', 'browser_form_post_url' => 'https://buyer.example.test/return' ];
	public array $queries = [];
	public array $audits = [];
	public mixed $link_result = null;
	public bool $transition_fails = false;
	public mixed $before_transition = null;
	public mixed $after_transition = null;
	public int $guarded_updates = 0;
	public bool $audit_result = true;
	public ?Throwable $audit_error = null;
	public function prepare( string $sql, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			$sql = preg_replace_callback( '/%[sd]/', static fn( $m ) => '%d' === $m[0] ? (string) (int) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'", $sql, 1 );
		}
		return $sql;
	}
	public function get_row( string $sql, string $format ): ?array {
		if ( str_contains( $sql, 'wp_pow_partners' ) ) { return array_replace( [ 'id' => 7, 'name' => 'Example buyer', 'status' => $this->partner_status ], $this->partner_fields ); }
		if ( str_contains( $sql, 'wp_session_token' ) && ( ! str_contains( $sql, "wp_session_token = 'test-login'" ) || ! str_contains( $sql, "'" . $this->session['status'] . "'" ) ) ) { return null; }
		return $this->session;
	}
	public function update( string $table, array $data, array $where ): int {
		$this->session = array_merge( $this->session, $data );
		return 1;
	}
	public function query( string $sql ): int|false {
		$this->queries[] = $sql;
		if ( preg_match( "/SET status = '([^']+)' WHERE id = ([0-9]+) AND status = '([^']+)'(.*)$/s", $sql, $m ) ) {
			if ( $this->before_transition ) { $callback = $this->before_transition; $this->before_transition = null; $callback(); }
			$tail = $m[4]; $seen = []; $matches = true;
			while ( '' !== $tail ) {
				if ( preg_match( '/^ AND (user_id|partner_id) = ([0-9]+)/', $tail, $part ) ) { $field = $part[1]; $matches = $matches && (int) $this->session[$field] === (int) $part[2]; }
				elseif ( preg_match( "/^ AND BINARY (wp_session_token|delivery_choice|delivery_confirmation) = BINARY '((?:''|[^'])*)'/s", $tail, $part ) ) { $field = $part[1]; $matches = $matches && ( $this->session[$field] ?? null ) === str_replace( "''", "'", $part[2] ); }
				elseif ( preg_match( '/^ AND (delivery_choice|delivery_confirmation) IS NULL/', $tail, $part ) ) { $field = $part[1]; $matches = $matches && null === ( $this->session[$field] ?? null ); }
				elseif ( preg_match( "/^ AND expires > '([^']+)'/", $tail, $part ) ) { $field = 'expires'; $matches = $matches && isset( $this->session[$field] ) && $this->session[$field] > $part[1]; }
				else { throw new RuntimeException( 'Unexpected guarded transition predicate: ' . $tail ); }
				if ( isset( $seen[$field] ) ) { throw new RuntimeException( 'Duplicate guarded predicate: ' . $field ); }
				$seen[$field] = true; $tail = substr( $tail, strlen( $part[0] ) );
			}
			if ( [] !== $seen ) {
				$required = 'expired' === $m[1] ? [ 'partner_id', 'user_id', 'wp_session_token' ] : [ 'user_id', 'wp_session_token', 'expires', 'delivery_choice', 'delivery_confirmation' ];
				if ( array_diff( $required, array_keys( $seen ) ) || array_diff( array_keys( $seen ), $required ) ) { throw new RuntimeException( 'Guarded transition is missing an exact predicate.' ); }
				++$this->guarded_updates;
			}
			if ( ! $matches || $this->transition_fails || (int) $this->session['id'] !== (int) $m[2] || $this->session['status'] !== $m[3] ) { return 0; }
			$this->session['status'] = $m[1];
			if ( $this->after_transition ) { $callback = $this->after_transition; $this->after_transition = null; $callback(); }
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
