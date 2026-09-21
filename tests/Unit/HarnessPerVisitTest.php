<?php
/**
 * The harness foundations every per-visit suite builds on.
 *
 * One customer account now holds one login, one WP auth cookie, one
 * WP_Session_Tokens entry and one WooCommerce session row per punchout
 * visit. A cross-visit isolation test is only worth its name if the
 * fixtures can actually hold two visits at once — a single-row session
 * fixture and a single-valued session token make such a test pass
 * vacuously. These tests pin the harness itself: the auth recorder, the
 * per-request session token, the multi-row database fixture, and the
 * user-mutating functions that must stay absent.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace {
if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) { require_once dirname( __DIR__ ) . '/bootstrap.php'; }
require_once dirname( __DIR__ ) . '/Support/return-database.php';
}

namespace {
use PHPUnit\Framework\TestCase;
use POW\Sessions\Store;

final class HarnessPerVisitTest extends TestCase {

	/** @var array<string, array{bool, mixed}> */
	private array $saved = [];

	protected function setUp(): void {
		foreach ( [ 'wpdb', 'pow_test_auth_cookies', 'pow_test_current_user_switches', 'pow_test_current_user_id', 'pow_test_roles', 'pow_test_users', 'pow_test_login_token', 'pow_test_login_tokens', 'pow_test_login_token_index', 'pow_test_session_tokens', 'pow_test_destroyed_tokens' ] as $key ) {
			$this->saved[ $key ] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null ];
			unset( $GLOBALS[ $key ] );
		}
	}

	protected function tearDown(): void {
		foreach ( $this->saved as $key => [ $existed, $value ] ) {
			if ( $existed ) { $GLOBALS[ $key ] = $value; } else { unset( $GLOBALS[ $key ] ); }
		}
	}

	/** Every visit mints its own cookie, so the recorder must keep them all, in order, with their tokens. */
	public function test_auth_cookie_recorder_keeps_one_entry_per_visit(): void {
		wp_set_auth_cookie( 99, false, '', 'visit-one-token' );
		wp_set_auth_cookie( 99, false, '', 'visit-two-token' );

		self::assertCount( 2, $GLOBALS['pow_test_auth_cookies'] );
		self::assertSame( [ 99, 'visit-one-token' ], [ $GLOBALS['pow_test_auth_cookies'][0]['user_id'], $GLOBALS['pow_test_auth_cookies'][0]['token'] ] );
		self::assertSame( [ 99, 'visit-two-token' ], [ $GLOBALS['pow_test_auth_cookies'][1]['user_id'], $GLOBALS['pow_test_auth_cookies'][1]['token'] ] );
	}

	/** The token must be stable inside one simulated request and different in the next, or "is this mine" tests prove nothing. */
	public function test_session_token_is_stable_in_a_request_and_varies_between_requests(): void {
		$GLOBALS['pow_test_login_token'] = 'only-visit';
		self::assertSame( 'only-visit', wp_get_session_token() );

		$GLOBALS['pow_test_login_tokens']      = [ 'visit-one-token', 'visit-two-token' ];
		$GLOBALS['pow_test_login_token_index'] = 0;
		self::assertSame( 'visit-one-token', wp_get_session_token() );
		self::assertSame( 'visit-one-token', wp_get_session_token(), 'One simulated request must see one token.' );

		$GLOBALS['pow_test_login_token_index'] = 1;
		self::assertSame( 'visit-two-token', wp_get_session_token() );

		unset( $GLOBALS['pow_test_login_tokens'] );
		self::assertSame( 'only-visit', wp_get_session_token(), 'Without a per-visit list the single default is the whole surface.' );
	}

	/** One account, two live tokens: ending one visit's login must leave the other's alone. */
	public function test_two_visits_hold_their_own_session_token_entry_for_one_account(): void {
		$tokens = \WP_Session_Tokens::get_instance( 99 );
		$tokens->update( 'visit-one-token', [ 'expiration' => 111 ] );
		$tokens->update( 'visit-two-token', [ 'expiration' => 222 ] );

		self::assertTrue( $tokens->verify( 'visit-one-token' ) );
		self::assertTrue( $tokens->verify( 'visit-two-token' ) );

		$tokens->destroy( 'visit-one-token' );

		self::assertFalse( $tokens->verify( 'visit-one-token' ) );
		self::assertTrue( $tokens->verify( 'visit-two-token' ), 'One visit ending must not end the account.' );
		self::assertSame( [ 'visit-one-token' ], $GLOBALS['pow_test_destroyed_tokens'] );
	}

	/** The redeem path switches the acting account and unwinds it on failure; both moves must be visible. */
	public function test_current_user_switch_is_applied_and_recorded(): void {
		$GLOBALS['pow_test_users'] = [ 99 => (object) [ 'ID' => 99, 'roles' => [ 'customer' ], 'allcaps' => [ 'read' => true ] ] ];

		$user = wp_set_current_user( 99 );

		self::assertSame( 99, $user->ID );
		self::assertSame( 99, get_current_user_id() );
		self::assertTrue( is_user_logged_in() );
		self::assertSame( [ 'customer' ], wp_get_current_user()->roles );

		wp_set_current_user( 0 );

		self::assertSame( 0, get_current_user_id() );
		self::assertFalse( is_user_logged_in() );
		self::assertSame( [ 99, 0 ], $GLOBALS['pow_test_current_user_switches'] );
	}

	/** The plugin never creates, renames or deletes users. No stub for those calls means no suite can pretend otherwise. */
	public function test_no_user_mutating_function_is_stubbed(): void {
		foreach ( [ 'wp_insert_user', 'wp_update_user', 'wp_delete_user', 'wp_create_user', 'wp_generate_password', 'update_user_meta', 'add_user_meta', 'delete_user_meta', 'add_role', 'remove_role', 'wp_update_user_meta' ] as $function ) {
			self::assertFalse( function_exists( $function ), $function . '() must stay absent from the shared stubs: the plugin never creates, renames or deletes users.' );
		}
	}

	/** Two employees of one customer are one WP user and two visits, so the database fixture must hold two rows. */
	public function test_return_database_holds_two_visits_for_one_account(): void {
		$db = new \ReturnDatabase();
		$db->add_visit( 43, [ 'wp_session_token' => 'visit-two-token', 'wc_session_key' => 'pow_' . str_repeat( 'b', 28 ), 'buyer_identity' => 'second@example.test', 'buyer_name' => 'Second Buyer' ] );

		self::assertSame( [ 42, 43 ], array_keys( $db->sessions ) );
		self::assertSame( 99, $db->sessions[43]['user_id'], 'Both visits belong to the one bound account.' );
		self::assertSame( $db->sessions[42], $db->session, 'The $session accessor must stay the first visit for the existing suites.' );

		$GLOBALS['wpdb'] = $db;
		$store           = new Store();

		$first  = $store->find_for_login( 99, 'test-login' );
		$second = $store->find_for_login( 99, 'visit-two-token' );

		self::assertNotNull( $first );
		self::assertNotNull( $second );
		self::assertSame( 42, $first->id );
		self::assertSame( 43, $second->id, 'A token must select its own visit, never the first row in the set.' );
		self::assertNull( $store->find_for_login( 99, 'no-such-visit' ), 'An unknown token owns no visit.' );
	}

	/** The row shape carries the per-visit key and the buyer identity, or no suite downstream can assert on them. */
	public function test_session_row_shape_carries_the_per_visit_key_and_buyer_identity(): void {
		$db = new \ReturnDatabase();

		foreach ( [ 'wc_session_key', 'buyer_identity', 'buyer_name', 'buyer_identity_hash' ] as $column ) {
			self::assertArrayHasKey( $column, $db->session, $column . ' must be part of the fixture row shape.' );
		}

		self::assertSame( 32, strlen( $db->session['wc_session_key'] ), 'The per-visit key must fit char(32).' );
		self::assertMatchesRegularExpression( '/^pow_[0-9a-f]{28}$/', $db->session['wc_session_key'] );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{12}$/', $db->session['buyer_identity_hash'] );
	}

	/** Writing through the $session accessor must land in the row set the lookups read. */
	public function test_session_accessor_and_row_set_are_one_row(): void {
		$db = new \ReturnDatabase();

		$db->session['status'] = 'returned';
		self::assertSame( 'returned', $db->sessions[42]['status'] );

		$db->sessions[42]['status'] = 'closed';
		self::assertSame( 'closed', $db->session['status'] );

		$db->session = array_replace( $db->session, [ 'status' => 'active' ] );
		self::assertSame( 'active', $db->sessions[42]['status'], 'A whole-row assignment must not break the alias.' );
	}
}
}
