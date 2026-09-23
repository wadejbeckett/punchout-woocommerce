<?php
/**
 * Registry owner serialization and login binding.
 *
 * `associate_owner()` is the only way a connection gets its store account
 * now that the front-end application flow is gone, so its refusals and the
 * owner mutex it runs under are proved here rather than incidentally
 * through a self-service submission.
 *
 * @package POW
 */
declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Partners\{Registry, Secrets};

/** Records only registry SQL; never represents native database acceptance. */
final class OwnerLockDatabase {
	public string $prefix = 'fixture_';
	public string $last_error = '';
	public int $insert_id = 0;
	public array $rows = [];
	public array $writes = [];
	public array $events = [];
	public bool $suppressed = false;
	public mixed $lock_result = '1';
	public string $failure = '';
	public bool $held = false;
	public bool $fail_write = false;
	public mixed $on_lock = null;
	private array $prepared = [];
	public function prepare( string $sql, mixed ...$args ): string {
		$key = $sql . ' /* ' . count( $this->prepared ) . ' */';
		$this->prepared[ $key ] = [ $sql, $args ];
		return $key;
	}
	public function suppress_errors( bool $suppress = true ): bool {
		$old = $this->suppressed;
		$this->suppressed = $suppress;
		return $old;
	}
	public function get_var( string $key ): mixed {
		[ $sql, $args ] = $this->prepared[ $key ];
		if ( str_contains( $sql, 'RELEASE_LOCK' ) ) {
			$this->events[] = [ 'release', $args ];
			$this->held = false;
			if ( 'release' === $this->failure ) { throw new RuntimeException( 'private database detail' ); }
			return '1';
		}
		if ( ! str_contains( $sql, 'GET_LOCK' ) ) { throw new RuntimeException( 'Unexpected SQL' ); }
		$this->events[] = [ 'lock', $args ];
		if ( 'lock' === $this->failure ) { throw new RuntimeException( 'private database detail' ); }
		$this->held = '1' === (string) $this->lock_result;
		if ( $this->held && $this->on_lock ) { $callback = $this->on_lock; $this->on_lock = null; $callback(); }
		return $this->lock_result;
	}
	public function get_row( string $key, string $output ): ?array {
		[ $sql, $args ] = $this->prepared[ $key ];
		$this->events[] = [ 'find', $args ];
		if ( ! $this->held ) { throw new RuntimeException( 'Lookup outside lock' ); }
		$this->last_error = '';
		if ( 'find' === $this->failure ) { $this->last_error = 'private database detail'; return null; }
		foreach ( $this->rows as $row ) {
			if ( str_contains( $sql, 'WHERE id =' ) && $row['id'] === $args[0] ) { return $row; }
		}
		return null;
	}
	/** The owner lookup asks for two rows on purpose: a second one is an ambiguity, not a candidate. */
	public function get_results( string $key, string $output ): array {
		[ $sql, $args ] = $this->prepared[ $key ];
		$this->events[] = [ 'owner', $args ];
		$this->last_error = '';
		if ( 'owner' === $this->failure ) { $this->last_error = 'private database detail'; return []; }
		if ( ! str_contains( $sql, 'WHERE owner_user_id' ) ) { throw new RuntimeException( 'Unexpected SQL' ); }
		$found = [];
		foreach ( $this->rows as $row ) {
			if ( $row['owner_user_id'] === $args[0] ) { $found[] = $row; }
		}
		return array_slice( $found, 0, 2 );
	}
	public function update( string $table, array $data, array $where ): int|false {
		if ( ! $this->held ) { throw new LogicException( 'Unserialized owner write' ); }
		if ( $this->fail_write ) { return false; }
		foreach ( $this->rows as &$row ) {
			foreach ( $where as $key => $value ) { if ( ( $row[ $key ] ?? null ) !== $value ) { continue 2; } }
			$row = array_replace( $row, $data );
			$this->writes[] = $data;
			return 1;
		}
		return 0;
	}
}

final class OwnerLockTest extends TestCase {
	private array $saved = [];
	private OwnerLockDatabase $db;
	private Registry $registry;

	protected function setUp(): void {
		foreach ( [ 'wpdb', 'pow_test_current_user_id', 'pow_test_users', 'pow_test_user_meta', 'pow_test_options' ] as $key ) {
			$this->saved[ $key ] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null ];
			unset( $GLOBALS[ $key ] );
		}
		$GLOBALS['wpdb'] = $this->db = new OwnerLockDatabase();
		$GLOBALS['pow_test_current_user_id'] = 1;
		$GLOBALS['pow_test_users'] = [
			1 => (object) [ 'ID' => 1, 'roles' => [ 'administrator' ], 'allcaps' => [ 'read' => true, 'manage_woocommerce' => true, 'manage_options' => true ] ],
			7 => (object) [ 'ID' => 7, 'roles' => [ 'customer' ], 'allcaps' => [ 'read' => true ] ],
			8 => (object) [ 'ID' => 8, 'roles' => [ 'customer' ], 'allcaps' => [ 'read' => true ] ],
		];
		$this->registry = new Registry( new Secrets( str_repeat( 'k', 32 ) ) );
	}

	protected function tearDown(): void {
		foreach ( $this->saved as $key => [ $exists, $value ] ) {
			if ( $exists ) { $GLOBALS[ $key ] = $value; } else { unset( $GLOBALS[ $key ] ); }
		}
	}

	private function seed( array $overrides = [] ): void {
		$this->db->rows = [ array_replace( [ 'id' => 20, 'owner_user_id' => 0, 'name' => 'Example Company', 'status' => 'pending' ], $overrides ) ];
	}

	public function test_owner_lock_returns_operation_result_and_releases_on_exception(): void {
		self::assertSame( 'result', $this->registry->with_owner_lock( 7, fn() => 'result' ) );
		self::assertSame( $this->db->events[0][1][0], $this->db->events[1][1][0] );
		self::assertTrue( strlen( $this->db->events[0][1][0] ) <= 64 );
		try {
			$this->registry->with_owner_lock( 7, static function () { throw new LogicException( 'operation failed' ); } );
			self::fail( 'Operation exception must propagate' );
		} catch ( LogicException $e ) {
			self::assertSame( 'operation failed', $e->getMessage() );
		}
		self::assertFalse( $this->db->held );
		self::assertFalse( $this->db->suppressed );
	}

	public function test_invalid_owner_cannot_enter_critical_section(): void {
		foreach ( [ 0, -1 ] as $owner ) {
			try {
				$this->registry->with_owner_lock( $owner, static function () { self::fail( 'Invalid owner ran operation' ); } );
				self::fail( 'Invalid owner must throw' );
			} catch ( InvalidArgumentException $e ) {}
		}
		self::assertSame( [], $this->db->events );
	}

	public function test_owner_lock_names_are_stable_and_isolated_by_site_and_owner(): void {
		foreach ( [ [ 'first_', 7 ], [ 'first_', 7 ], [ 'second_', 7 ], [ 'first_', 8 ] ] as [ $prefix, $owner ] ) {
			$this->db->prefix = $prefix;
			$this->registry->with_owner_lock( $owner, static fn() => true );
		}
		$names = array_map( static fn( $event ) => $event[1][0], array_values( array_filter( $this->db->events, static fn( $event ) => 'lock' === $event[0] ) ) );
		self::assertSame( $names[0], $names[1] );
		self::assertCount( 3, array_unique( $names ) );
	}

	public function test_binding_an_ordinary_customer_is_confirmed_under_both_locks(): void {
		$this->seed();
		self::assertTrue( $this->registry->associate_owner( 20, 7 ) );
		self::assertSame( 7, $this->db->rows[0]['owner_user_id'] );
		self::assertStringContainsString( 'pow_owner_', $this->db->events[0][1][0] );
		self::assertStringContainsString( 'pow_partner_', $this->db->events[1][1][0] );
		self::assertFalse( $this->db->held );
	}

	/** A buyer punches in as this account, so an account that can manage the shop cannot be bound to a connection. */
	public function test_binding_refuses_every_privileged_account(): void {
		foreach ( Registry::PRIVILEGED_CAPABILITIES as $capability ) {
			$this->seed();
			$GLOBALS['pow_test_users'][7]->allcaps = [ 'read' => true, $capability => true ];
			self::assertFalse( $this->registry->associate_owner( 20, 7 ), $capability );
			self::assertSame( 0, $this->db->rows[0]['owner_user_id'], $capability );
		}
		self::assertSame( [], $this->db->writes );
	}

	public function test_binding_refuses_a_missing_account_a_capability_gap_and_a_legacy_association(): void {
		$this->seed();
		self::assertFalse( $this->registry->associate_owner( 20, 99 ) );
		$GLOBALS['pow_test_users'][7]->allcaps = [];
		self::assertFalse( $this->registry->associate_owner( 20, 7 ) );
		$GLOBALS['pow_test_users'][7]->allcaps = [ 'read' => true ];
		// A legacy provisioned buyer still carries this meta, and the redeem
		// path refuses such an account, so binding it would build a
		// connection whose every visit is refused.
		$GLOBALS['pow_test_user_meta'][7]['_pow_partner_id'] = 12;
		self::assertFalse( $this->registry->associate_owner( 20, 7 ) );
		self::assertSame( [], $this->db->writes );
		self::assertSame( 0, $this->db->rows[0]['owner_user_id'] );
	}

	public function test_binding_refuses_a_transfer_and_an_account_owning_another_connection(): void {
		$this->seed( [ 'owner_user_id' => 8 ] );
		self::assertFalse( $this->registry->associate_owner( 20, 7 ) );
		self::assertSame( 8, $this->db->rows[0]['owner_user_id'] );
		$this->db->rows = [
			[ 'id' => 20, 'owner_user_id' => 0, 'name' => 'Example Company', 'status' => 'pending' ],
			[ 'id' => 21, 'owner_user_id' => 7, 'name' => 'Other Company', 'status' => 'active' ],
		];
		self::assertFalse( $this->registry->associate_owner( 20, 7 ) );
		self::assertSame( 0, $this->db->rows[0]['owner_user_id'] );
		self::assertSame( [], $this->db->writes );
	}

	public function test_a_non_administrator_cannot_bind_and_an_unconfirmed_write_is_not_reported(): void {
		$this->seed();
		$GLOBALS['pow_test_current_user_id'] = 7;
		self::assertFalse( $this->registry->associate_owner( 20, 7 ) );
		self::assertSame( [], $this->db->events );
		$GLOBALS['pow_test_current_user_id'] = 1;
		$this->db->fail_write = true;
		self::assertFalse( $this->registry->associate_owner( 20, 7 ) );
		self::assertSame( 0, $this->db->rows[0]['owner_user_id'] );
	}

	/** owner_user_id authenticates every buyer of the connection, so a second row sharing it is an ambiguity, not a pick. */
	public function test_two_connections_sharing_an_owner_refuse_resolution(): void {
		$this->db->held = true;
		$this->db->rows = [
			[ 'id' => 20, 'owner_user_id' => 7, 'name' => 'Example Company', 'status' => 'active' ],
			[ 'id' => 21, 'owner_user_id' => 7, 'name' => 'Other Company', 'status' => 'active' ],
		];
		try {
			$this->registry->find_by_owner( 7 );
			self::fail( 'A shared owner must refuse rather than pick a connection' );
		} catch ( RuntimeException $e ) {
			self::assertStringNotContainsString( 'Example Company', $e->getMessage() );
		}
		self::assertNull( $this->registry->find_by_owner( 8 ) );
		self::assertNull( $this->registry->find_by_owner( 0 ) );
	}

	public function test_owner_resolution_reports_an_unreachable_lookup_as_a_failure(): void {
		$this->db->held = true;
		$this->seed( [ 'owner_user_id' => 7 ] );
		self::assertSame( 20, $this->registry->find_by_owner( 7 )?->id );
		$this->db->failure = 'owner';
		try {
			$this->registry->find_by_owner( 7 );
			self::fail( 'A failed lookup must not read as "this account owns nothing"' );
		} catch ( RuntimeException $e ) {
			self::assertStringNotContainsString( 'private database detail', $e->getMessage() );
		}
	}
}
