<?php
/**
 * The delegated-login gate (findings PO-01 and PO-02, 0.4.9).
 *
 * A WordPress login the plugin minted for a visit is recorded on that visit's
 * row. The gate runs on WordPress's own determine_current_user filter, so it
 * judges every request — page, admin, AJAX, REST — before anything acts as the
 * account:
 *
 * - a login whose visit is over (expired, returned, closed) never
 *   authenticates, even when its native token survived a failed cleanup; the
 *   gate retries the revocation and the request continues as a guest;
 * - a login whose account has since gained an authoring or administrative
 *   capability ends its visit and does not authenticate;
 * - every other login, the bound account's own password login included, is
 *   untouched.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

require_once dirname( __DIR__ ) . '/Support/visit-key-database.php';

use PHPUnit\Framework\TestCase;
use POW\Audit\Log;
use POW\Partners\Registry;
use POW\Partners\Secrets;
use POW\Sessions\DelegatedLogin;
use POW\Sessions\Session;
use POW\Sessions\Store;

final class DelegatedLoginTestLog extends Log {
	/** @var list<array{0: string, 1: array<string, mixed>}> */
	public array $written = [];
	public function __construct() {}
	public function write( string $event, array $context = [] ): void { $this->written[] = [ $event, $context ]; }
	public function write_checked( string $event, array $context = [] ): bool { $this->write( $event, $context ); return true; }
}

final class DelegatedLoginTest extends TestCase {

	private VisitKeyDatabase $db;
	private DelegatedLoginTestLog $audit;
	private array $saved = [];

	protected function setUp(): void {
		foreach ( [ 'wpdb', 'pow_test_session_tokens', 'pow_test_destroyed_tokens', 'pow_test_login_token', 'pow_test_login_tokens', 'pow_test_users', 'pow_test_cookies_cleared', 'pow_test_destroy_fails' ] as $key ) {
			$this->saved[ $key ] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null ];
			unset( $GLOBALS[ $key ] );
		}
		$GLOBALS['wpdb'] = $this->db = new VisitKeyDatabase();
		$this->db->partners[7] = [ 'id' => 7, 'status' => 'active', 'owner_user_id' => 99 ];
		$GLOBALS['pow_test_users'][99] = (object) [ 'ID' => 99, 'roles' => [ 'customer' ], 'allcaps' => [ 'read' => true, 'customer' => true ] ];
		$this->audit = new DelegatedLoginTestLog();
	}

	protected function tearDown(): void {
		foreach ( $this->saved as $key => [ $exists, $value ] ) {
			if ( $exists ) { $GLOBALS[ $key ] = $value; } else { unset( $GLOBALS[ $key ] ); }
		}
	}

	private function gate(): DelegatedLogin {
		return new DelegatedLogin( new Store(), new Registry( new Secrets( str_repeat( 'a', 32 ) ) ), $this->audit );
	}

	private function visit( int $id, string $status, string $token ): void {
		$this->db->sessions[ $id ] = [
			'id'               => $id,
			'partner_id'       => 7,
			'user_id'          => 99,
			'wp_session_token' => $token,
			'wc_session_key'   => null,
			'status'           => $status,
			'expires'          => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
		];
	}

	/** The request presents this login, and WordPress has already validated it. */
	private function request( string $token ): void {
		$GLOBALS['pow_test_login_token']                 = $token;
		$GLOBALS['pow_test_session_tokens'][99][ $token ] = [ 'expiration' => time() + 3600 ];
	}

	public function test_an_ordinary_login_of_the_bound_account_is_untouched(): void {
		$this->visit( 42, Session::ACTIVE, 'visit-token' );
		$this->request( 'password-login-token' );

		self::assertSame( 99, $this->gate()->authenticate( 99 ) );
		self::assertSame( [], $GLOBALS['pow_test_destroyed_tokens'] ?? [] );
		self::assertSame( [], $this->audit->written );
	}

	public function test_a_live_visit_of_an_ordinary_account_authenticates(): void {
		$this->visit( 42, Session::ACTIVE, 'visit-token' );
		$this->request( 'visit-token' );

		self::assertSame( 99, $this->gate()->authenticate( 99 ) );
		self::assertSame( Session::ACTIVE, $this->db->sessions[42]['status'] );
	}

	public function test_a_guest_or_another_filter_result_passes_through(): void {
		self::assertFalse( $this->gate()->authenticate( false ) );
		self::assertSame( 0, $this->gate()->authenticate( 0 ) );
	}

	/** PO-02: the row says the visit is over, but its login survived a failed cleanup. */
	public function test_a_surviving_login_of_an_ended_visit_does_not_authenticate_and_is_revoked(): void {
		foreach ( [ Session::EXPIRED, Session::RETURNED, Session::CLOSED ] as $i => $status ) {
			$this->visit( 50 + $i, $status, 'ended-' . $status );
			$this->request( 'ended-' . $status );

			self::assertSame( 0, $this->gate()->authenticate( 99 ), $status . ' login must not authenticate.' );
			self::assertFalse( isset( $GLOBALS['pow_test_session_tokens'][99][ 'ended-' . $status ] ), $status . ' login is revoked.' );
		}
		self::assertSame( 'visit_login_revoked', $this->audit->written[0][0] );
		self::assertSame( 'revoked', $this->audit->written[0][1]['result'] );
	}

	/** PO-02: while revocation keeps failing the login stays confined — it is a guest, never the account. */
	public function test_an_unrevocable_login_of_an_ended_visit_still_does_not_authenticate(): void {
		$this->visit( 51, Session::EXPIRED, 'stuck-token' );
		$this->request( 'stuck-token' );
		$GLOBALS['pow_test_destroy_fails'] = true;

		self::assertSame( 0, $this->gate()->authenticate( 99 ) );
		self::assertSame( 0, $this->gate()->authenticate( 99 ), 'A later request is refused again.' );
		self::assertSame( 'revoke_failed', $this->audit->written[0][1]['result'] );

		unset( $GLOBALS['pow_test_destroy_fails'] );
		self::assertSame( 0, $this->gate()->authenticate( 99 ) );
		self::assertFalse( isset( $GLOBALS['pow_test_session_tokens'][99][ 'stuck-token' ] ), 'The next request recovers the cleanup.' );
	}

	/** PO-01: the account was promoted after the visit started. */
	public function test_a_promoted_account_ends_its_visit_and_does_not_authenticate(): void {
		$this->visit( 42, Session::ACTIVE, 'visit-token' );
		$this->visit( 43, Session::ACTIVE, 'colleague-token' );
		$this->request( 'colleague-token' );
		$this->request( 'visit-token' );
		$GLOBALS['pow_test_users'][99]->allcaps += [ 'edit_posts' => true, 'publish_posts' => true ];

		self::assertSame( 0, $this->gate()->authenticate( 99 ) );
		self::assertSame( Session::EXPIRED, $this->db->sessions[42]['status'] );
		self::assertFalse( isset( $GLOBALS['pow_test_session_tokens'][99][ 'visit-token' ] ) );
		self::assertSame( 'visit_login_ineligible', $this->audit->written[0][0] );
		self::assertSame( 'revoked', $this->audit->written[0][1]['result'] );
		self::assertSame( Session::ACTIVE, $this->db->sessions[43]['status'], 'Only the presenting visit is judged on this request.' );
	}

	public function test_a_promoted_account_is_refused_even_when_its_visit_cannot_be_ended(): void {
		$this->visit( 42, Session::ACTIVE, 'visit-token' );
		$this->request( 'visit-token' );
		$GLOBALS['pow_test_users'][99]->allcaps['manage_woocommerce'] = true;
		$GLOBALS['pow_test_destroy_fails'] = true;

		self::assertSame( 0, $this->gate()->authenticate( 99 ) );
		self::assertSame( 'revoke_failed', $this->audit->written[0][1]['result'] );
	}

	/** An unreadable store is not proof of a delegated login; the existing request guards still fail closed. */
	public function test_a_lookup_failure_leaves_authentication_to_wordpress(): void {
		$this->request( 'some-token' );
		$this->db->write_fails = false;
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';
			public string $last_error = '';
			public function prepare( string $sql, mixed ...$args ): string { return $sql; }
			public function get_row( string $sql, string $format ): ?array { $this->last_error = 'gone'; return null; }
		};

		self::assertSame( 99, $this->gate()->authenticate( 99 ) );
	}

	public function test_the_decision_is_made_once_per_request(): void {
		$this->visit( 42, Session::ACTIVE, 'visit-token' );
		$this->request( 'visit-token' );
		$gate = $this->gate();
		$gate->authenticate( 99 );
		$before = count( $this->db->queries );
		$gate->authenticate( 99 );
		self::assertSame( $before, count( $this->db->queries ) );
	}
}
