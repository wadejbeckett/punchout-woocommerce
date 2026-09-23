<?php
/** The per-visit cart key: its shape, and the Store queries that mint, resolve, count and reap it. @package POW */
declare( strict_types = 1 );

require_once dirname( __DIR__ ) . '/Support/visit-key-database.php';

use POW\Cart\SessionKey;
use POW\Sessions\Session;
use POW\Sessions\Store;

final class SessionKeyTest extends PHPUnit\Framework\TestCase {

	private VisitKeyDatabase $db;
	private array $saved = [];

	protected function setUp(): void {
		foreach ( [ 'wpdb', 'pow_test_session_tokens', 'pow_test_destroyed_tokens' ] as $key ) {
			$this->saved[ $key ] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null ];
			unset( $GLOBALS[ $key ] );
		}
		$GLOBALS['wpdb'] = $this->db = new VisitKeyDatabase();
	}

	protected function tearDown(): void {
		foreach ( $this->saved as $key => [ $exists, $value ] ) {
			if ( $exists ) { $GLOBALS[ $key ] = $value; } else { unset( $GLOBALS[ $key ] ); }
		}
	}

	/**
	 * One visit of the shared account.
	 *
	 * @param array<string, mixed> $overrides Columns that differ.
	 * @return array<string, mixed>
	 */
	private function visit( int $id, array $overrides = [] ): array {
		return array_replace(
			[
				'id'                  => $id,
				'partner_id'          => 7,
				'user_id'             => 99,
				'wp_session_token'    => 'token-' . $id,
				'wc_session_key'      => null,
				'status'              => Session::ACTIVE,
				'expires'             => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
				'buyer_identity'      => 'buyer@example.test',
				'buyer_identity_hash' => 'aa11bb22cc33',
			],
			$overrides
		);
	}

	private function seed( array ...$rows ): void {
		foreach ( $rows as $row ) { $this->db->sessions[ (int) $row['id'] ] = $row; }
	}

	private function key( string $suffix ): string {
		return 'pow_' . str_pad( $suffix, 28, '0', STR_PAD_LEFT );
	}

	// ---------------------------------------------------------------- the key

	public function test_a_minted_key_is_thirty_two_characters_of_the_visit_shape(): void {
		$seen = [];
		for ( $i = 0; $i < 50; $i++ ) {
			$key = SessionKey::mint();
			// 32 is not cosmetic: wp_woocommerce_sessions.session_key is char(32).
			self::assertSame( 32, strlen( $key ) );
			self::assertSame( 1, preg_match( '/\Apow_[0-9a-f]{28}\z/', $key ) );
			self::assertTrue( SessionKey::is_visit_key( $key ) );
			$seen[ $key ] = true;
		}
		self::assertCount( 50, $seen );
		self::assertSame( 32, SessionKey::LENGTH );
	}

	public function test_nothing_but_the_visit_shape_is_a_visit_key(): void {
		$refused = [
			'',
			'99',
			'4',
			't_' . str_repeat( 'a', 30 ),
			'pow_' . str_repeat( 'A', 28 ),
			'pow_' . str_repeat( 'a', 32 ),
			'pow_' . str_repeat( 'a', 27 ),
			'pow_' . str_repeat( 'a', 29 ),
			'POW_' . str_repeat( 'a', 28 ),
			'pow_' . str_repeat( 'g', 28 ),
			' pow_' . str_repeat( 'a', 27 ),
			'pow_' . str_repeat( 'a', 27 ) . ' ',
			'pow_' . str_repeat( 'a', 14 ) . "\n" . str_repeat( 'a', 13 ),
		];
		foreach ( $refused as $key ) {
			self::assertFalse( SessionKey::is_visit_key( $key ), $key );
		}
	}

	public function test_for_session_answers_the_rows_own_key_and_refuses_a_row_without_one(): void {
		$key = $this->key( 'abc' );
		self::assertSame( $key, SessionKey::for_session( Session::from_row( $this->visit( 42, [ 'wc_session_key' => $key ] ) ) ) );
		foreach ( [ null, '', '99', 'pow_' . str_repeat( 'a', 32 ) ] as $stored ) {
			try {
				SessionKey::for_session( Session::from_row( $this->visit( 42, [ 'wc_session_key' => $stored ] ) ) );
				self::fail( 'A row without its own visit key must not answer one.' );
			} catch ( \RuntimeException $error ) {
				// The refusal names no key: a message is not a place for one.
				if ( null !== $stored && '' !== $stored ) { self::assertStringNotContainsString( $stored, $error->getMessage() ); }
			}
		}
	}

	// ------------------------------------------------------------- bind_login

	public function test_bind_login_writes_token_expires_and_key_in_one_update(): void {
		$this->seed( $this->visit( 42, [ 'wp_session_token' => '', 'expires' => null ] ) );
		$key     = $this->key( 'a1' );
		$expires = gmdate( 'Y-m-d H:i:s', time() + 3600 );

		self::assertTrue( ( new Store() )->bind_login( 42, 'token-alice', $expires, $key ) );

		$writes = array_values( array_filter( $this->db->queries, static fn( string $sql ): bool => str_starts_with( $sql, 'UPDATE' ) ) );
		self::assertCount( 1, $writes );
		foreach ( [ "wp_session_token = 'token-alice'", "expires = '" . $expires . "'", "wc_session_key = '" . $key . "'" ] as $fragment ) {
			self::assertStringContainsString( $fragment, $writes[0] );
		}
		self::assertStringContainsString( "wp_session_token IS NULL OR wp_session_token = ''", $writes[0] );
		self::assertStringContainsString( 'wc_session_key IS NULL', $writes[0] );
		self::assertSame( $key, $this->db->sessions[42]['wc_session_key'] );
		self::assertSame( 'token-alice', $this->db->sessions[42]['wp_session_token'] );
		self::assertSame( $expires, $this->db->sessions[42]['expires'] );
	}

	public function test_bind_login_refuses_a_visit_that_already_holds_a_login_or_a_key(): void {
		$expires = gmdate( 'Y-m-d H:i:s', time() + 3600 );
		$this->seed(
			$this->visit( 42, [ 'wp_session_token' => 'token-alice' ] ),
			$this->visit( 43, [ 'wp_session_token' => '', 'wc_session_key' => $this->key( 'b2' ) ] ),
			$this->visit( 44, [ 'wp_session_token' => '', 'status' => Session::PENDING ] )
		);
		$store = new Store();
		foreach ( [ 42, 43, 44 ] as $id ) {
			self::assertFalse( $store->bind_login( $id, 'token-new', $expires, $this->key( 'c3' ) ), (string) $id );
		}
		self::assertSame( 'token-alice', $this->db->sessions[42]['wp_session_token'] );
		self::assertSame( $this->key( 'b2' ), $this->db->sessions[43]['wc_session_key'] );
		self::assertSame( '', $this->db->sessions[44]['wp_session_token'] );
	}

	public function test_bind_login_refuses_an_empty_token_or_a_foreign_key_before_any_sql(): void {
		$this->seed( $this->visit( 42, [ 'wp_session_token' => '' ] ) );
		$store   = new Store();
		$expires = gmdate( 'Y-m-d H:i:s', time() + 3600 );
		$key     = $this->key( 'd4' );

		self::assertFalse( $store->bind_login( 42, '', $expires, $key ) );
		foreach ( [ '', '99', '4', 'pow_' . str_repeat( 'a', 32 ), 't_' . str_repeat( 'a', 30 ), strtoupper( $key ) ] as $foreign ) {
			self::assertFalse( $store->bind_login( 42, 'token-alice', $expires, $foreign ), $foreign );
		}
		self::assertFalse( $store->bind_login( 0, 'token-alice', $expires, $key ) );
		self::assertSame( [], $this->db->queries );
	}

	public function test_bind_login_reports_failure_when_the_stored_key_is_not_the_minted_one(): void {
		$this->seed( $this->visit( 42, [ 'wp_session_token' => '' ] ) );
		$key = $this->key( 'e5' );
		// A char(32) column silently truncating a longer value, or any other
		// write that does not round-trip, must never read back as a bound visit.
		$this->db->after_write = function () use ( $key ): void { $this->db->sessions[42]['wc_session_key'] = substr( $key, 0, 30 ); };
		self::assertFalse( ( new Store() )->bind_login( 42, 'token-alice', gmdate( 'Y-m-d H:i:s', time() + 3600 ), $key ) );

		$this->db->sessions = [ 43 => $this->visit( 43, [ 'wp_session_token' => '' ] ) ];
		$this->db->write_fails = true;
		self::assertFalse( ( new Store() )->bind_login( 43, 'token-bob', gmdate( 'Y-m-d H:i:s', time() + 3600 ), $key ) );
	}

	// --------------------------------------------------- find_by_wc_session_key

	public function test_find_by_wc_session_key_tells_two_visits_of_one_account_apart(): void {
		$alice = $this->key( 'a11ce' );
		$bob   = $this->key( 'b0b' );
		$this->seed(
			$this->visit( 42, [ 'wc_session_key' => $alice ] ),
			$this->visit( 43, [ 'wc_session_key' => $bob ] )
		);
		$store = new Store();

		self::assertSame( 42, $store->find_by_wc_session_key( $alice )?->id );
		self::assertSame( 43, $store->find_by_wc_session_key( $bob )?->id );
		self::assertSame( 99, $store->find_by_wc_session_key( $bob )?->user_id );
	}

	public function test_find_by_wc_session_key_refuses_an_empty_foreign_or_case_folded_key(): void {
		$alice = $this->key( 'a11ce' );
		$this->seed( $this->visit( 42, [ 'wc_session_key' => $alice ] ) );
		$store = new Store();

		foreach ( [ '', '99', 'pow_' . str_repeat( 'a', 32 ) ] as $refused ) {
			self::assertNull( $store->find_by_wc_session_key( $refused ), $refused );
		}
		self::assertSame( [], $this->db->queries );

		// A case-insensitive collation must not hand one visit another's basket.
		self::assertNull( $store->find_by_wc_session_key( strtoupper( $alice ) ) );
		self::assertNull( $store->find_by_wc_session_key( $alice, [] ) );
		self::assertSame( 42, $store->find_by_wc_session_key( $alice )?->id );
	}

	public function test_find_by_wc_session_key_refuses_a_row_whose_key_is_only_pad_equal(): void {
		$alice = $this->key( 'a11ce' );
		$this->seed( $this->visit( 42, [ 'wc_session_key' => $alice . ' ' ] ) );
		// The comparison is left to the index, so the collation may match a
		// stored value that is not the requested bytes; the readback refuses it.
		$this->db->pads_trailing_space = true;
		self::assertNull( ( new Store() )->find_by_wc_session_key( $alice ) );
		self::assertStringContainsString( "wc_session_key = '" . $alice . "'", (string) end( $this->db->queries ) );
	}

	public function test_find_by_wc_session_key_honours_the_requested_statuses(): void {
		$key = $this->key( 'f6' );
		$this->seed( $this->visit( 42, [ 'wc_session_key' => $key, 'status' => Session::RETURNED ] ) );
		$store = new Store();

		self::assertNull( $store->find_by_wc_session_key( $key ) );
		self::assertSame( 42, $store->find_by_wc_session_key( $key, [ Session::RETURNED ] )?->id );
	}

	// ------------------------------------------------- identity and cap queries

	public function test_open_for_identity_is_partner_scoped(): void {
		$this->seed(
			$this->visit( 42, [ 'buyer_identity_hash' => 'aaaa1111' ] ),
			$this->visit( 43, [ 'buyer_identity_hash' => 'aaaa1111', 'status' => Session::PENDING ] ),
			$this->visit( 44, [ 'buyer_identity_hash' => 'bbbb2222' ] ),
			$this->visit( 45, [ 'buyer_identity_hash' => 'aaaa1111', 'partner_id' => 8 ] ),
			$this->visit( 46, [ 'buyer_identity_hash' => 'aaaa1111', 'status' => Session::RETURNED ] )
		);
		$store = new Store();

		$open = $store->open_for_identity( 7, 'aaaa1111' );
		self::assertSame( [ 42, 43 ], array_map( static fn( Session $s ): int => $s->id, $open ) );
		self::assertStringContainsString( "buyer_identity_hash = 'aaaa1111'", end( $this->db->queries ) );
		self::assertStringContainsString( 'partner_id = 7', end( $this->db->queries ) );
		self::assertSame( [], $store->open_for_identity( 7, 'cccc3333' ) );
	}

	public function test_open_for_identity_never_supersedes_an_anonymous_visit(): void {
		$this->seed(
			$this->visit( 42, [ 'buyer_identity_hash' => null ] ),
			$this->visit( 43, [ 'buyer_identity_hash' => '' ] )
		);
		$store = new Store();

		self::assertSame( [], $store->open_for_identity( 7, '' ) );
		self::assertSame( [], $store->open_for_identity( 0, 'aaaa1111' ) );
		self::assertSame( [], $this->db->queries );
	}

	public function test_count_open_for_partner_counts_open_visits_without_hydrating_them(): void {
		$this->seed(
			$this->visit( 42 ),
			$this->visit( 43, [ 'status' => Session::PENDING ] ),
			$this->visit( 44, [ 'status' => Session::ORDERED ] ),
			$this->visit( 45, [ 'status' => Session::RETURNED ] ),
			$this->visit( 46, [ 'status' => Session::EXPIRED ] ),
			$this->visit( 47, [ 'partner_id' => 8 ] )
		);
		$store = new Store();

		self::assertSame( 3, $store->count_open_for_partner( 7 ) );
		self::assertCount( 1, $this->db->queries );
		self::assertStringContainsString( 'COUNT(*)', $this->db->queries[0] );
		self::assertStringNotContainsString( 'SELECT *', $this->db->queries[0] );
		self::assertStringNotContainsString( 'LIMIT', $this->db->queries[0] );
		self::assertSame( 0, $store->count_open_for_partner( 0 ) );
		self::assertSame( 50, Store::MAX_OPEN_VISITS );
	}

	// -------------------------------------------------- orphan cart-row removal

	public function test_expire_locked_deletes_only_the_expiring_visits_cart_row(): void {
		$alice = $this->key( 'a11ce' );
		$bob   = $this->key( 'b0b' );
		$this->seed(
			$this->visit( 42, [ 'wc_session_key' => $alice, 'wp_session_token' => 'token-alice' ] ),
			$this->visit( 43, [ 'wc_session_key' => $bob, 'wp_session_token' => 'token-bob' ] )
		);
		$this->db->carts = [ $alice => time() + 3600, $bob => time() + 3600, '99' => time() + 3600 ];
		$GLOBALS['pow_test_session_tokens'] = [ 99 => [ 'token-alice' => [ 'expiration' => time() + 3600 ], 'token-bob' => [ 'expiration' => time() + 3600 ] ] ];
		$store = new Store();

		self::assertTrue( $store->expire_locked( Session::from_row( $this->db->sessions[42] ) ) );

		self::assertSame( Session::EXPIRED, $this->db->sessions[42]['status'] );
		// Her own basket is gone; the colleague shopping next to her keeps hers,
		// her login, and so does the account's ordinary shopper row.
		self::assertSame( [ $bob, 99 ], array_keys( $this->db->carts ) );
		self::assertSame( Session::ACTIVE, $this->db->sessions[43]['status'] );
		self::assertSame( [ 'token-alice' ], $GLOBALS['pow_test_destroyed_tokens'] ?? [] );

		$deletes = array_values( array_filter( $this->db->queries, static fn( string $sql ): bool => str_starts_with( $sql, 'DELETE' ) ) );
		self::assertCount( 1, $deletes );
		self::assertStringContainsString( 'WHERE session_key = ', $deletes[0] );
		self::assertStringNotContainsString( 'BINARY', $deletes[0], 'the delete must use the indexed equality, not a full scan' );
	}

	public function test_expire_locked_never_deletes_a_row_that_is_not_a_visits_own(): void {
		$this->seed(
			$this->visit( 42, [ 'wc_session_key' => '99', 'wp_session_token' => 'token-alice' ] ),
			$this->visit( 43, [ 'wc_session_key' => null, 'wp_session_token' => 'token-bob' ] )
		);
		$this->db->carts = [ '99' => time() + 3600 ];
		$GLOBALS['pow_test_session_tokens'] = [ 99 => [ 'token-alice' => [ 'expiration' => time() + 3600 ], 'token-bob' => [ 'expiration' => time() + 3600 ] ] ];
		$store = new Store();

		self::assertTrue( $store->expire_locked( Session::from_row( $this->db->sessions[42] ) ) );
		self::assertTrue( $store->expire_locked( Session::from_row( $this->db->sessions[43] ) ) );
		self::assertSame( [ 99 ], array_keys( $this->db->carts ) );
		self::assertSame( [], array_filter( $this->db->queries, static fn( string $sql ): bool => str_starts_with( $sql, 'DELETE' ) ) );
	}

	public function test_expire_locked_reports_a_visit_whose_cart_row_survived(): void {
		$alice = $this->key( 'a11ce' );
		$this->seed( $this->visit( 42, [ 'wc_session_key' => $alice, 'wp_session_token' => 'token-alice' ] ) );
		$this->db->carts = [ $alice => time() + 3600 ];
		$this->db->cart_delete_fails = true;
		$GLOBALS['pow_test_session_tokens'] = [ 99 => [ 'token-alice' => [ 'expiration' => time() + 3600 ] ] ];

		self::assertFalse( ( new Store() )->expire_locked( Session::from_row( $this->db->sessions[42] ) ) );
		self::assertSame( Session::EXPIRED, $this->db->sessions[42]['status'] );
	}
}
