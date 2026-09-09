<?php
/** Compound persistence faults at the SQL boundary; native acceptance is separate. @package POW */
declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Partners\{Partner, Registration, Registry, Secrets};

/** Applies conditional writes and injects faults only after credential installation. */
final class RegistrationRecoveryDatabase {
	public string $prefix = 'fixture_';
	public string $last_error = '';
	public bool $held = false;
	public bool $suppressed = false;
	public array $row;
	public string $activation_read = 'false';
	public string $restore_write = 'false';
	public string $restore_read = '';
	public string $recovery_write = '';
	public string $recovery_read = '';
	public string $release = '';
	public array $events = [];
	private string $next_read = '';
	private array $prepared = [];
	public function __construct( array $row ) { $this->row = $row; }
	public function prepare( string $sql, mixed ...$args ): string {
		$key = $sql . ' /* ' . count( $this->prepared ) . ' */';
		$this->prepared[ $key ] = [ $sql, $args ];
		return $key;
	}
	public function suppress_errors( bool $value = true ): bool { $old = $this->suppressed; $this->suppressed = $value; return $old; }
	private function fails( string $mode ): bool {
		if ( 'throw' === $mode ) { throw new RuntimeException( 'private database detail' ); }
		if ( 'false' === $mode ) { $this->last_error = 'private database detail'; return true; }
		return false;
	}
	public function get_var( string $key ): mixed {
		[ $sql ] = $this->prepared[ $key ];
		if ( str_contains( $sql, 'RELEASE_LOCK(' ) ) {
			$this->events[] = 'release';
			$this->next_read = '';
			$this->held = false;
			return $this->fails( $this->release ) ? '0' : '1';
		}
		if ( ! str_contains( $sql, 'GET_LOCK(' ) ) { throw new LogicException( 'Unexpected scalar SQL' ); }
		$this->held = true;
		return '1';
	}
	public function get_row( string $key, string $format ): ?array {
		[ $sql, $args ] = $this->prepared[ $key ];
		$this->last_error = '';
		if ( str_contains( $sql, 'WHERE sender_domain' ) ) { return $this->row['sender_identity'] === $args[1] ? $this->row : null; }
		if ( ! str_contains( $sql, 'WHERE id = %d' ) || $args[0] !== $this->row['id'] ) { throw new LogicException( 'Unexpected row SQL' ); }
		$phase = $this->next_read;
		$this->next_read = '';
		if ( '' !== $phase ) {
			$this->events[] = $phase . '_read';
			if ( ! $this->held ) { throw new LogicException( 'Verification outside mutex' ); }
			if ( $this->fails( $this->{$phase . '_read'} ) ) { return null; }
		}
		return $this->row;
	}
	public function get_results( string $key, string $format ): array {
		[ $sql ] = $this->prepared[ $key ];
		if ( ! $this->held || ! str_contains( $sql, 'pow_sessions' ) ) { throw new LogicException( 'Unexpected session SQL' ); }
		$this->last_error = '';
		return [];
	}
	public function update( string $table, array $data, array $where ): int|false {
		if ( ! $this->held || ! $this->suppressed || $table !== $this->prefix . 'pow_partners' ) { throw new LogicException( 'Write outside mutex' ); }
		$this->last_error = '';
		$phase = isset( $where['secret_current'] ) ? 'restore' : ( ! empty( $data['secret_current'] ) ? 'activation' : ( isset( $data['status'], $data['secret_current'] ) ? 'recovery' : '' ) );
		if ( '' !== $phase ) {
			$this->events[] = $phase . '_write';
			$this->next_read = $phase;
			if ( 'activation' !== $phase && $this->fails( $this->{$phase . '_write'} ) ) { return false; }
		}
		foreach ( $where as $key => $value ) { if ( $this->row[ $key ] !== $value ) { return 0; } }
		$changed = array_replace( $this->row, $data ) !== $this->row;
		$this->row = array_replace( $this->row, $data );
		if ( 'recovery' === $phase && 'after_throw' === $this->recovery_write ) { throw new RuntimeException( 'private database detail' ); }
		return $changed ? 1 : 0;
	}
}

final class RegistrationRecoveryAudit extends POW\Audit\Log {
	public array $events = [];
	public function __construct() {}
	public function write_checked( string $event, array $context = [] ): bool { $this->events[ $event ] = $context; return true; }
}

final class RegistrationRecoveryTest extends TestCase {
	private array $saved = [];
	private RegistrationRecoveryDatabase $db;
	private RegistrationRecoveryAudit $audit;
	private Registry $registry;
	private Registration $service;
	protected function setUp(): void {
		foreach ( [ 'wpdb', 'pow_test_current_user_id', 'pow_test_users', 'pow_test_mail', 'pow_test_mail_result', 'pow_test_mail_error', 'pow_test_wc' ] as $key ) {
			$this->saved[ $key ] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null ];
			unset( $GLOBALS[ $key ] );
		}
		$GLOBALS['pow_test_current_user_id'] = 7;
		$GLOBALS['pow_test_users'][7] = (object) [ 'ID' => 7, 'roles' => [ 'administrator' ], 'allcaps' => [ 'manage_woocommerce' => true ], 'user_email' => 'admin@example.invalid' ];
	}
	protected function tearDown(): void {
		foreach ( $this->saved as $key => [ $exists, $value ] ) { if ( $exists ) { $GLOBALS[ $key ] = $value; } else { unset( $GLOBALS[ $key ] ); } }
	}
	private function fixture( string $action ): void {
		$secrets = new Secrets( str_repeat( 'k', 32 ) );
		$row = [ 'id' => 12, 'owner_user_id' => 7, 'name' => 'Example', 'status' => 'approve' === $action ? 'pending' : 'active', 'from_domain' => 'NetworkID', 'from_identity' => 'BUYER', 'sender_domain' => 'NetworkID', 'sender_identity' => 'BUYER', 'to_domain' => 'NetworkID', 'to_identity' => 'SUPPLIER', 'secret_current' => 'approve' === $action ? '' : $secrets->seal( 'old-current' ), 'secret_previous' => 'approve' === $action ? '' : $secrets->seal( 'old-previous' ), 'secret_rotated_at' => null, 'updated' => '2026-01-01 00:00:00', 'company_profile' => '{"book":"preserve"}' ];
		$GLOBALS['wpdb'] = $this->db = new RegistrationRecoveryDatabase( $row );
		$this->registry = new Registry( $secrets );
		$this->audit = new RegistrationRecoveryAudit();
		$this->service = new Registration( $this->registry, new POW\Sessions\Store(), $this->audit );
		$GLOBALS['pow_test_mail'] = [];
	}
	private function issue( string $action ): string { return 'approve' === $action ? $this->service->approve( 12, 7 ) : $this->service->reset( 12, $this->db->row, 7 ); }
	private function failed( string $action, string $reason ): void {
		self::assertSame( '', $this->issue( $action ) );
		$event = 'approve' === $action ? 'registration_approval_failed' : 'registration_reset_failed';
		self::assertSame( $reason, $this->audit->events[ $event ]['detail']['reason'] );
		self::assertStringNotContainsString( 'private database detail', json_encode( $this->audit->events ) );
		self::assertSame( [], $GLOBALS['pow_test_mail'] );
		self::assertFalse( $this->db->held );
		self::assertFalse( $this->db->suppressed );
	}
	private function safe_row(): void {
		$p = $this->registry->find( 12 );
		self::assertSame( 'disabled', $p->status );
		self::assertSame( '', $p->secret_current );
		self::assertSame( '', $p->secret_previous );
		self::assertSame( 7, $p->owner_user_id );
		self::assertSame( '{"book":"preserve"}', $p->company_profile );
	}
	private function recovered( string $action, string $write ): void {
		foreach ( [ 'false', 'throw' ] as $read ) {
			$this->fixture( $action );
			$this->db->activation_read = $read;
			$this->db->restore_write = $write;
			// Assert durable state first: the original bug leaves this row active.
			self::assertSame( '', $this->issue( $action ) );
			$this->safe_row();
			$event = 'approve' === $action ? 'registration_approval_failed' : 'registration_reset_failed';
			self::assertSame( 'recovery_confirmed_disabled', $this->audit->events[ $event ]['detail']['reason'] );
			self::assertSame( [], $GLOBALS['pow_test_mail'] );
			self::assertSame( [ 'activation_write', 'activation_read', 'restore_write', 'restore_read', 'recovery_write', 'recovery_read', 'release' ], $this->db->events );
		}
	}
	public function test_approval_recovers_after_false_compensation(): void { $this->recovered( 'approve', 'false' ); }
	public function test_approval_recovers_after_thrown_compensation(): void { $this->recovered( 'approve', 'throw' ); }
	public function test_reset_recovers_after_false_compensation(): void { $this->recovered( 'reset', 'false' ); }
	public function test_reset_recovers_after_thrown_compensation(): void { $this->recovered( 'reset', 'throw' ); }
	public function test_unconfirmed_restoration_read_also_requires_recovery(): void {
		foreach ( [ 'approve', 'reset' ] as $action ) {
			foreach ( [ 'false', 'throw' ] as $read ) {
				$this->fixture( $action ); $this->db->restore_write = ''; $this->db->restore_read = $read;
				$this->failed( $action, 'recovery_confirmed_disabled' ); $this->safe_row();
			}
		}
	}
	public function test_failed_recovery_write_reports_unconfirmed_without_claiming_disable(): void {
		foreach ( [ 'approve', 'reset' ] as $action ) {
			foreach ( [ 'false', 'throw' ] as $write ) {
				$this->fixture( $action ); $this->db->recovery_write = $write;
				$this->failed( $action, 'recovery_unconfirmed' );
				self::assertSame( 'active', $this->registry->find( 12 )->status );
				self::assertSame( 1, count( array_keys( $this->db->events, 'recovery_write', true ) ) );
				self::assertSame( 'recovery_read', $this->db->events[ count( $this->db->events ) - 2 ] );
			}
		}
	}
	public function test_failed_recovery_read_does_not_claim_confirmed_safe_state(): void {
		foreach ( [ 'approve', 'reset' ] as $action ) {
			foreach ( [ 'false', 'throw' ] as $read ) {
				$this->fixture( $action ); $this->db->recovery_read = $read;
				$this->failed( $action, 'recovery_unconfirmed' ); $this->safe_row();
			}
		}
	}
	public function test_recovery_write_that_committed_before_throw_is_freshly_verified(): void {
		foreach ( [ 'approve', 'reset' ] as $action ) {
			$this->fixture( $action ); $this->db->recovery_write = 'after_throw';
			$this->failed( $action, 'recovery_confirmed_disabled' ); $this->safe_row();
		}
	}
	public function test_confirmed_restoration_preserves_original_failure_behavior(): void {
		foreach ( [ 'approve', 'reset' ] as $action ) {
			$this->fixture( $action ); $this->db->restore_write = '';
			$this->failed( $action, 'approve' === $action ? 'approval_write' : 'replacement_write' );
			$p = $this->registry->find( 12 );
			self::assertSame( 'approve' === $action ? 'pending' : 'disabled', $p->status );
			self::assertSame( '', $p->secret_current ); self::assertSame( '', $p->secret_previous );
			self::assertFalse( in_array( 'recovery_write', $this->db->events, true ) );
		}
	}
	public function test_release_failure_does_not_discard_confirmed_issuance(): void {
		foreach ( [ 'approve', 'reset' ] as $action ) {
			foreach ( [ 'false', 'throw' ] as $release ) {
				$this->fixture( $action ); $this->db->activation_read = ''; $this->db->release = $release;
				$secret = $this->issue( $action ); $p = $this->registry->find( 12 );
				self::assertTrue( '' !== $secret ); self::assertTrue( null !== $this->registry->verify_secret( $p, $secret ) );
				self::assertSame( 'active', $p->status );
				self::assertStringNotContainsString( $secret, json_encode( $this->audit->events ) );
				if ( 'approve' === $action ) { self::assertSame( '', $this->issue( $action ) ); }
			}
		}
	}
	public function test_mail_failure_does_not_discard_confirmed_issuance(): void {
		foreach ( [ 'approve', 'reset' ] as $action ) {
			foreach ( [ 'false', 'throw' ] as $mail ) {
				$this->fixture( $action ); $this->db->activation_read = '';
				$GLOBALS['pow_test_mail_result'] = false;
				$GLOBALS['pow_test_mail_error'] = 'throw' === $mail ? new RuntimeException( 'private mail detail' ) : null;
				$secret = $this->issue( $action );
				self::assertTrue( '' !== $secret ); self::assertTrue( null !== $this->registry->verify_secret( $this->registry->find( 12 ), $secret ) );
				self::assertTrue( isset( $this->audit->events['registration_notification_failed'] ) );
				self::assertStringNotContainsString( $secret, json_encode( $this->audit->events ) . json_encode( $GLOBALS['pow_test_mail'] ) );
			}
		}
	}
	public function test_release_failure_cannot_mask_recovery_diagnostics(): void {
		foreach ( [ 'approve', 'reset' ] as $action ) {
			foreach ( [ 'false', 'throw' ] as $release ) {
				foreach ( [ '', 'false' ] as $recovery ) {
					$this->fixture( $action ); $this->db->release = $release; $this->db->recovery_write = $recovery;
					$this->failed( $action, '' === $recovery ? 'recovery_confirmed_disabled' : 'recovery_unconfirmed' );
				}
			}
		}
	}
}
