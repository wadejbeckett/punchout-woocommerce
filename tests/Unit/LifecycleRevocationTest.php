<?php
/**
 * Removing the plugin ends its delegated logins first (finding PO-03, 0.4.9).
 *
 * Deactivation unhooks every guard that confines a visit, and uninstall drops
 * the rows that record which logins are visits. Either one done while a
 * delegated login is still valid would leave the bound account signed in with
 * no confinement at all. So both first revoke every login a visit row records
 * — exactly those tokens, connection by connection under the connection lock —
 * and refuse to go on while any of them survives. Logins the plugin did not
 * mint, such as the account holder's own password login, are not touched.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

require_once dirname( __DIR__ ) . '/Support/visit-key-database.php';

use PHPUnit\Framework\TestCase;
use POW\Installer;
use POW\Sessions\Session;

final class LifecycleRevocationTest extends TestCase {

	private VisitKeyDatabase $db;
	private array $saved = [];

	protected function setUp(): void {
		foreach ( [ 'wpdb', 'pow_test_session_tokens', 'pow_test_destroyed_tokens', 'pow_test_destroy_fails' ] as $key ) {
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

	private function visit( int $id, int $partner, string $status, string $token, int $user = 99 ): void {
		$this->db->sessions[ $id ] = [
			'id'               => $id,
			'partner_id'       => $partner,
			'user_id'          => $user,
			'wp_session_token' => $token,
			'wc_session_key'   => null,
			'status'           => $status,
			'expires'          => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
		];
		if ( '' !== $token ) { $GLOBALS['pow_test_session_tokens'][ $user ][ $token ] = [ 'expiration' => time() + 3600 ]; }
	}

	public function test_every_recorded_delegated_login_is_revoked_and_unrelated_logins_survive(): void {
		$this->visit( 1, 7, Session::ACTIVE, 'live-visit' );
		$this->visit( 2, 7, Session::EXPIRED, 'cleanup-failed-earlier' );
		$this->visit( 3, 8, Session::ORDERED, 'other-connection', 55 );
		$this->visit( 4, 7, Session::PENDING, '' );
		$GLOBALS['pow_test_session_tokens'][99]['account-holder-password-login'] = [ 'expiration' => time() + 3600 ];
		$GLOBALS['pow_test_session_tokens'][12]['administrator-login']           = [ 'expiration' => time() + 3600 ];

		self::assertTrue( Installer::revoke_delegated_logins() );

		self::assertSame( [ 'account-holder-password-login' ], array_keys( $GLOBALS['pow_test_session_tokens'][99] ) );
		self::assertSame( [], $GLOBALS['pow_test_session_tokens'][55] );
		self::assertSame( [ 'administrator-login' ], array_keys( $GLOBALS['pow_test_session_tokens'][12] ) );
		foreach ( [ 1, 3, 4 ] as $id ) {
			self::assertSame( Session::EXPIRED, $this->db->sessions[ $id ]['status'], 'Open visit ' . $id . ' is ended.' );
		}
	}

	public function test_a_login_that_cannot_be_revoked_is_reported(): void {
		$this->visit( 1, 7, Session::ACTIVE, 'live-visit' );
		$GLOBALS['pow_test_destroy_fails'] = true;

		self::assertFalse( Installer::revoke_delegated_logins() );
		self::assertArrayHasKey( 'live-visit', $GLOBALS['pow_test_session_tokens'][99] );
	}

	public function test_no_session_table_means_nothing_to_revoke(): void {
		$this->db->tables_exist = false;
		self::assertTrue( Installer::revoke_delegated_logins() );
	}

	public function test_deactivation_and_uninstall_revoke_before_removing_anything(): void {
		$root      = dirname( __DIR__, 2 );
		$installer = (string) file_get_contents( $root . '/includes/Installer.php' );
		$body      = substr( $installer, strpos( $installer, 'public static function deactivate()' ) );
		$body      = substr( $body, 0, strpos( $body, "\n\t}\n" ) );
		self::assertTrue( false !== strpos( $body, 'revoke_delegated_logins()' ) && strpos( $body, 'revoke_delegated_logins()' ) < strpos( $body, 'wp_clear_scheduled_hook' ), 'Deactivation revokes first.' );
		self::assertStringContainsString( 'wp_die(', $body, 'An incomplete revocation stops the deactivation.' );

		$uninstall = (string) file_get_contents( $root . '/uninstall.php' );
		self::assertTrue( false !== strpos( $uninstall, 'revoke_delegated_logins()' ) && strpos( $uninstall, 'revoke_delegated_logins()' ) < strpos( $uninstall, "defined( 'POW_KEEP_DATA' )" ), 'Logins are revoked even when data is kept.' );
		self::assertTrue( false !== strpos( $uninstall, 'wp_die(' ) && strpos( $uninstall, 'wp_die(' ) < strpos( $uninstall, 'DROP TABLE' ), 'An incomplete revocation stops before the tables go.' );
	}
}
