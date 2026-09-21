<?php
/** Service and SQL-boundary unit checks; native concurrency is separate. @package POW */
declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Partners\{Registration, Registry, Secrets};
use POW\Sessions\Store;
use POW\Audit\Log;

/** Records only registration SQL; never represents native database acceptance. */
final class RegistrationDatabase {
	public string $prefix = 'fixture_';
	public string $last_error = '';
	public int $insert_id = 0;
	public array $rows = [];
	public array $events = [];
	public bool $suppressed = false;
	public mixed $lock_result = '1';
	public string $failure = '';
	public bool $held = false;
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
		return $this->lock_result;
	}
	public function get_row( string $key, string $output ): ?array {
		[ $sql, $args ] = $this->prepared[ $key ];
		$kind = str_contains( $sql, 'WHERE owner_user_id' ) ? 'owner' : 'sender';
		$this->events[] = [ $kind, $args ];
		if ( ! $this->held ) { throw new RuntimeException( 'Lookup outside lock' ); }
		$this->last_error = '';
		if ( $this->failure === $kind . '_throw' ) { throw new RuntimeException( 'private database detail' ); }
		if ( $this->failure === $kind ) { $this->last_error = 'private database detail'; return null; }
		foreach ( array_reverse( $this->rows ) as $row ) {
			if ( 'owner' === $kind ? $row['owner_user_id'] === $args[0] : ( $row['sender_domain'] === $args[0] && $row['sender_identity'] === $args[1] ) ) { return $row; }
		}
		return null;
	}
	public function insert( string $table, array $data ): int|false {
		$this->events[] = [ 'insert', $data ];
		if ( ! $this->held || ! $this->suppressed ) { throw new RuntimeException( 'Insert requires private critical section' ); }
		if ( 'insert_throw' === $this->failure ) { throw new RuntimeException( 'private database detail' ); }
		if ( 'insert' === $this->failure ) { $this->last_error = 'duplicate or storage detail'; return false; }
		$this->rows[] = $data + [ 'id' => ++$this->insert_id, 'secret_previous' => '' ];
		return 1;
	}
}

final class RegistrationAudit extends Log {
	public array $events = [];
	public array $suppressions = [];
	public bool $throws = false;
	public function __construct() {}
	public function write_checked( string $event, array $context = [] ): bool {
		$this->suppressions[] = $GLOBALS['wpdb']->suppressed;
		if ( $this->throws ) { throw new RuntimeException( 'private audit detail' ); }
		$this->events[] = [ $event, $context ];
		return true;
	}
}

/** Native Woo method signatures, recording only our mail arguments. */
final class RegistrationMailer {
	public array $wrapped = [];
	public array $sent = [];
	public bool $wrap_throws = false;
	public bool $send_result = true;
	public function wrap_message( $heading, $message, $deprecated = false ) {
		if ( $this->wrap_throws ) { throw new RuntimeException( 'Template unavailable' ); }
		$this->wrapped[] = [ $heading, $message ];
		return '<html>' . $message . '</html>';
	}
	public function send( $to, $subject, $message, $headers = "Content-Type: text/html\r\n", $attachments = '' ) {
		if ( $GLOBALS['wpdb']->held || 1 !== count( $GLOBALS['wpdb']->rows ) ) { throw new LogicException( 'Mail before persisted unlocked row' ); }
		$this->sent[] = [ $to, $subject, $message ];
		return $this->send_result;
	}
}

final class RegistrationWoo extends POW_Test_WC {
	public function __construct( public RegistrationMailer $registration_mailer ) {}
	public function mailer(): RegistrationMailer { return $this->registration_mailer; }
}

final class RegistrationSubmitTest extends TestCase {
	private array $saved = [];
	private RegistrationDatabase $db;
	private RegistrationAudit $audit;
	private Registry $registry;
	private Registration $registration;
	protected function setUp(): void {
		foreach ( [ 'wpdb', 'pow_test_current_user_id', 'pow_test_users', 'pow_test_user_meta', 'pow_test_options', 'pow_test_mail', 'pow_test_mail_result', 'pow_test_mail_error', 'pow_test_wc' ] as $key ) {
			$this->saved[ $key ] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null ];
			unset( $GLOBALS[ $key ] );
		}
		$GLOBALS['wpdb'] = $this->db = new RegistrationDatabase();
		$GLOBALS['pow_test_current_user_id'] = 7;
		$GLOBALS['pow_test_users'][7] = (object) [ 'ID' => 7, 'roles' => [ 'customer' ], 'allcaps' => [ 'read' => true ] ];
		$GLOBALS['pow_test_options'] = [ 'admin_email' => 'admin@example.test' ];
		$this->audit = new RegistrationAudit();
		$this->registry = new Registry( new Secrets( str_repeat( 'k', 32 ) ) );
		$this->registration = new Registration( $this->registry, new Store(), $this->audit );
	}
	protected function tearDown(): void {
		foreach ( $this->saved as $key => [ $exists, $value ] ) {
			if ( $exists ) { $GLOBALS[ $key ] = $value; } else { unset( $GLOBALS[ $key ] ); }
		}
	}
	private function fields(): array {
		return [ 'name' => 'Example Buyer', 'from_domain' => 'NetworkID', 'from_identity' => 'BUYER', 'notes' => 'Please review' ];
	}
	private function refused( mixed $result ): void {
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( Registration::neutral_message(), $result->get_error_message() );
		self::assertSame( 'registration_refused', $result->get_error_code() );
		self::assertSame( [], $GLOBALS['pow_test_mail'] ?? [] );
		self::assertFalse( in_array( 'registration_submitted', array_column( $this->audit->events, 0 ), true ) );
		self::assertStringNotContainsString( 'private database detail', json_encode( $this->audit->events ) );
	}
	public function test_customer_creates_pending_owned_row_without_entitlements_or_secrets(): void {
		$id = $this->registration->submit( 7, $this->fields() + [ 'status' => 'active', 'owner_user_id' => 99, 'secret' => 'forged', 'secret_previous' => 'forged', 'mode' => 'dual_exit' ] );
		self::assertSame( 1, $id );
		$row = $this->db->rows[0];
		self::assertSame( 'pending', $row['status'] );
		self::assertSame( 7, $row['owner_user_id'] );
		self::assertSame( '', $row['secret_current'] );
		self::assertSame( '', $row['secret_previous'] );
		self::assertFalse( isset( $row['mode'] ) );
		self::assertSame( [ 'notes' => 'Please review' ], json_decode( $row['company_profile'], true ) );
		self::assertSame( [ 'lock', 'owner', 'sender', 'insert', 'release' ], array_column( $this->db->events, 0 ) );
		self::assertFalse( $this->db->held );
		self::assertFalse( $this->db->suppressed );
		self::assertSame( 'registration_submitted', $this->audit->events[0][0] );
		self::assertSame( 1, $this->audit->events[0][1]['partner_id'] );
		self::assertSame( 7, $this->audit->events[0][1]['user_id'] );
		self::assertSame( [], $GLOBALS['pow_test_user_meta'] ?? [] );
		self::assertSame( [ 'customer' ], $GLOBALS['pow_test_users'][7]->roles );
		$mail = $GLOBALS['pow_test_mail'][0];
		self::assertSame( 'admin@example.test', $mail['to'] );
		foreach ( [ 'Example Buyer', 'NetworkID', 'BUYER', 'tab=partners' ] as $text ) { self::assertStringContainsString( $text, $mail['message'] ); }
		self::assertStringNotContainsString( 'forged', $mail['message'] );
	}
	public function test_wrong_actor_anonymous_missing_and_provisioned_users_cannot_submit(): void {
		$this->refused( $this->registration->submit( 8, $this->fields() ) );
		$GLOBALS['pow_test_current_user_id'] = 0;
		$this->refused( $this->registration->submit( 0, $this->fields() ) );
		$GLOBALS['pow_test_current_user_id'] = 7;
		unset( $GLOBALS['pow_test_users'][7] );
		$this->refused( $this->registration->submit( 7, $this->fields() ) );
		$GLOBALS['pow_test_users'][7] = (object) [ 'ID' => 7, 'roles' => [ 'customer' ], 'allcaps' => [] ];
		$this->refused( $this->registration->submit( 7, $this->fields() ) );
		$GLOBALS['pow_test_users'][7]->allcaps = [ 'read' => true ];
		// No role is consulted any more: what refuses an actor is the missing
		// capability above and the legacy association meta below.
		$GLOBALS['pow_test_user_meta'][7]['_pow_partner_id'] = 42;
		$this->refused( $this->registration->submit( 7, $this->fields() ) );
		self::assertSame( [], $this->db->rows );
		self::assertSame( [], $this->db->events );
	}
	public function test_existing_owner_and_sender_in_any_state_share_neutral_refusal(): void {
		foreach ( [ 'pending', 'active', 'disabled' ] as $state ) {
			foreach ( [ [ 7, 'OTHER' ], [ 8, 'BUYER' ] ] as [ $owner, $sender ] ) {
				$this->db->rows = [ [ 'id' => 20, 'owner_user_id' => $owner, 'sender_domain' => 'NetworkID', 'sender_identity' => $sender, 'status' => $state ] ];
				$this->refused( $this->registration->submit( 7, $this->fields() ) );
				self::assertCount( 1, $this->db->rows );
				self::assertFalse( $this->db->held );
			}
		}
	}
	public function test_lock_lookup_and_insert_failures_are_neutral_without_success_mail(): void {
		foreach ( [ 'lock', 'owner', 'owner_throw', 'sender', 'sender_throw', 'insert', 'insert_throw' ] as $failure ) {
			$this->db->failure = $failure;
			$this->refused( $this->registration->submit( 7, $this->fields() ) );
			self::assertSame( [], $this->db->rows );
			self::assertFalse( $this->db->held );
			self::assertFalse( $this->db->suppressed );
		}
		$this->db->failure = '';
		foreach ( [ null, '0', false ] as $result ) {
			$this->db->lock_result = $result;
			$this->refused( $this->registration->submit( 7, $this->fields() ) );
		}
	}
	public function test_validation_failure_does_not_touch_registry(): void {
		self::assertInstanceOf( WP_Error::class, $this->registration->submit( 7, [ 'name' => [] ] ) );
		self::assertSame( [], $this->db->events );
		self::assertSame( [], $GLOBALS['pow_test_mail'] ?? [] );
		self::assertSame( 'registration_rejected', $this->audit->events[0][0] );
	}
	public function test_failed_mail_preserves_one_created_row_and_records_notification_failure(): void {
		$GLOBALS['pow_test_mail_result'] = false;
		self::assertSame( 1, $this->registration->submit( 7, $this->fields() ) );
		self::assertCount( 1, $this->db->rows );
		self::assertSame( [ 'registration_submitted', 'registration_notification_failed' ], array_column( $this->audit->events, 0 ) );
		$result = $this->registration->submit( 7, $this->fields() );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertCount( 1, $this->db->rows );
		self::assertCount( 1, $GLOBALS['pow_test_mail'] );
	}
	public function test_thrown_mail_and_audit_do_not_reclassify_created_row_as_failed(): void {
		$GLOBALS['pow_test_mail_error'] = new RuntimeException( 'private mail detail' );
		self::assertSame( 1, $this->registration->submit( 7, $this->fields() ) );
		self::assertSame( [ 'registration_submitted', 'registration_notification_failed' ], array_column( $this->audit->events, 0 ) );
		$this->db->rows = [];
		$this->audit->throws = true;
		self::assertSame( 2, $this->registration->submit( 7, $this->fields() ) );
		self::assertCount( 1, $this->db->rows );
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

	public function test_woo_wrapper_escapes_applicant_text_and_sends_after_persistence(): void {
		$mailer = new RegistrationMailer();
		$GLOBALS['pow_test_wc'] = new RegistrationWoo( $mailer );
		self::assertSame( 1, $this->registration->submit( 7, array_replace( $this->fields(), [ 'name' => '<img src=x onerror=alert(1)>', 'sender_identity' => 'BUYER&<b>' ] ) ) );
		self::assertCount( 1, $mailer->wrapped );
		self::assertCount( 1, $mailer->sent );
		self::assertSame( 'admin@example.test', $mailer->sent[0][0] );
		self::assertStringNotContainsString( '<img', $mailer->sent[0][2] );
		self::assertStringContainsString( '&lt;img', $mailer->sent[0][2] );
		self::assertStringContainsString( 'BUYER&amp;&lt;b&gt;', $mailer->sent[0][2] );
		self::assertSame( [], $GLOBALS['pow_test_mail'] ?? [] );
	}

	public function test_broken_woo_template_falls_back_to_plain_native_mail(): void {
		$mailer = new RegistrationMailer();
		$mailer->wrap_throws = true;
		$GLOBALS['pow_test_wc'] = new RegistrationWoo( $mailer );
		self::assertSame( 1, $this->registration->submit( 7, $this->fields() ) );
		self::assertSame( [], $mailer->sent );
		self::assertCount( 1, $GLOBALS['pow_test_mail'] );
	}

	public function test_woo_send_failure_is_audited_without_second_transport(): void {
		$mailer = new RegistrationMailer();
		$mailer->send_result = false;
		$GLOBALS['pow_test_wc'] = new RegistrationWoo( $mailer );
		self::assertSame( 1, $this->registration->submit( 7, $this->fields() ) );
		self::assertCount( 1, $mailer->sent );
		self::assertSame( [], $GLOBALS['pow_test_mail'] ?? [] );
		self::assertSame( [ 'registration_submitted', 'registration_notification_failed' ], array_column( $this->audit->events, 0 ) );
	}

	public function test_release_exception_preserves_confirmed_insert_and_restores_error_reporting(): void {
		$this->db->failure = 'release';
		self::assertSame( 1, $this->registration->submit( 7, $this->fields() ) );
		self::assertCount( 1, $this->db->rows );
		self::assertFalse( $this->db->suppressed );
		self::assertSame( [ 'registration_lock_release_failed', 'registration_submitted' ], array_column( $this->audit->events, 0 ) );
		self::assertCount( 1, $GLOBALS['pow_test_mail'] );
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

	public function test_audit_database_errors_cannot_print_into_applicant_response(): void {
		self::assertSame( 1, $this->registration->submit( 7, $this->fields() ) );
		self::assertSame( [ true ], $this->audit->suppressions );
		self::assertFalse( $this->db->suppressed );
	}
}
