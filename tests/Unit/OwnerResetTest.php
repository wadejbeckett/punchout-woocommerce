<?php
/**
 * The account holder's opt-in reset: Registration::reset_by_owner and the
 * administrator's checkbox that switches it on. SQL boundary doubles only;
 * native acceptance is separate. @package POW
 */
declare( strict_types = 1 );

require_once dirname( __DIR__ ) . '/Admin/doubles.php';

use PHPUnit\Framework\TestCase;
use POW\Partners\{Partner, Registration, Registry, Secrets};
use POW\Sessions\Session;

/** RegistrationRecoveryTest's partner boundary without its fault phases: writes only under the mutex. */
final class OwnerResetDatabase {
	public string $prefix = 'fixture_';
	public string $last_error = '';
	public bool $held = false;
	public bool $suppressed = false;
	public array $row;
	public array $writes = [];
	private array $prepared = [];
	public function __construct( array $row ) { $this->row = $row; }
	public function prepare( string $sql, mixed ...$args ): string {
		$key = $sql . ' /* ' . count( $this->prepared ) . ' */';
		$this->prepared[ $key ] = [ $sql, $args ];
		return $key;
	}
	public function suppress_errors( bool $value = true ): bool { $old = $this->suppressed; $this->suppressed = $value; return $old; }
	public function get_var( string $key ): mixed {
		[ $sql ] = $this->prepared[ $key ];
		if ( str_contains( $sql, 'RELEASE_LOCK(' ) ) { $this->held = false; return '1'; }
		if ( ! str_contains( $sql, 'GET_LOCK(' ) ) { throw new LogicException( 'Unexpected scalar SQL' ); }
		$this->held = true;
		return '1';
	}
	public function get_row( string $key, string $format ): ?array {
		[ $sql, $args ] = $this->prepared[ $key ];
		$this->last_error = '';
		if ( str_contains( $sql, 'WHERE sender_domain' ) ) { return $this->row['sender_domain'] === $args[0] && $this->row['sender_identity'] === $args[1] ? $this->row : null; }
		if ( ! str_contains( $sql, 'WHERE id = %d' ) || $args[0] !== $this->row['id'] ) { throw new LogicException( 'Unexpected row SQL' ); }
		return $this->row;
	}
	public function get_results( string $key, string $format ): array { throw new LogicException( 'Session SQL belongs to the store double' ); }
	public function update( string $table, array $data, array $where ): int|false {
		if ( ! $this->held || $table !== $this->prefix . 'pow_partners' ) { throw new LogicException( 'Write outside mutex' ); }
		foreach ( $where as $key => $value ) { if ( $this->row[ $key ] !== $value ) { return 0; } }
		$this->writes[] = $data;
		$changed = array_replace( $this->row, $data ) !== $this->row;
		$this->row = array_replace( $this->row, $data );
		return $changed ? 1 : 0;
	}
}

/** The session boundary: this request's visit, and the open visits a reset must end. */
final class OwnerResetStore extends POW\Sessions\Store {
	public ?Session $visit = null;
	public bool $visit_fails = false;
	/** @var array<int,Session> */
	public array $open = [];
	public array $expired = [];
	public array $asked = [];
	public function find_for_login( int $user_id, string $wp_session_token, array $statuses = [ Session::ACTIVE ] ): ?Session {
		$this->asked[] = [ $user_id, $wp_session_token ];
		if ( $this->visit_fails ) { throw new RuntimeException( 'private session lookup' ); }
		return $this->visit;
	}
	public function revocation_batch( int $partner_id, int $after_id = 0, int $limit = 500 ): array {
		return array_values( array_filter( $this->open, static fn( Session $s ): bool => $s->partner_id === $partner_id && $s->id > $after_id ) );
	}
	public function expire_locked( Session $session ): bool {
		$this->expired[] = $session->id;
		unset( $this->open[ $session->id ] );
		return true;
	}
	public function open_for_partner( int $partner_id, int $limit = 500 ): array { return array_values( $this->open ); }
}

final class OwnerResetAudit extends POW\Audit\Log {
	public array $events = [];
	public function __construct() {}
	public function write_checked( string $event, array $context = [] ): bool { $this->events[] = [ $event, $context ]; return true; }
	public function names(): array { return array_column( $this->events, 0 ); }
	public function last( string $event ): ?array { foreach ( array_reverse( $this->events ) as [ $name, $context ] ) { if ( $name === $event ) { return $context; } } return null; }
}

final class OwnerResetTest extends TestCase {
	private array $saved = [];
	private OwnerResetDatabase $db;
	private OwnerResetStore $store;
	private OwnerResetAudit $audit;
	private Registry $registry;
	private Registration $service;
	private Secrets $secrets;
	protected function setUp(): void {
		foreach ( [ 'wpdb', 'pow_test_current_user_id', 'pow_test_users', 'pow_test_mail', 'pow_test_mail_result', 'pow_test_mail_error', 'pow_test_wc', 'pow_test_login_token', 'pow_test_login_tokens', 'pow_admin_test', 'pow_test_options' ] as $key ) {
			$this->saved[ $key ] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null ];
			unset( $GLOBALS[ $key ] );
		}
		$this->prime();
	}
	private function prime(): void {
		$GLOBALS['pow_test_current_user_id'] = 7;
		$GLOBALS['pow_test_login_token'] = 'owner-login-token';
		$GLOBALS['pow_test_users'][7] = (object) [ 'ID' => 7, 'roles' => [ 'customer' ], 'allcaps' => [ 'read' => true ], 'user_email' => 'owner@example.invalid' ];
		$GLOBALS['pow_test_users'][8] = (object) [ 'ID' => 8, 'roles' => [ 'customer' ], 'allcaps' => [ 'read' => true ], 'user_email' => 'other@example.invalid' ];
		$GLOBALS['pow_test_mail'] = [];
		$this->fixture();
	}
	protected function tearDown(): void {
		foreach ( $this->saved as $key => [ $exists, $value ] ) { if ( $exists ) { $GLOBALS[ $key ] = $value; } else { unset( $GLOBALS[ $key ] ); } }
	}
	private function fixture( array $overrides = [] ): void {
		$this->secrets = new Secrets( str_repeat( 'k', 32 ) );
		$row = array_replace( [ 'id' => 12, 'owner_user_id' => 7, 'name' => 'Example', 'status' => 'active', 'from_domain' => 'NetworkID', 'from_identity' => 'BUYER', 'sender_domain' => 'NetworkID', 'sender_identity' => 'BUYER', 'to_domain' => 'NetworkID', 'to_identity' => 'SUPPLIER', 'deployment_mode' => 'production', 'secret_current' => $this->secrets->seal( 'old-current' ), 'secret_previous' => $this->secrets->seal( 'old-previous' ), 'secret_rotated_at' => null, 'updated' => '2026-01-01 00:00:00', 'company_profile' => '{"book":"preserve"}', 'owner_settings' => 'reset_connection', 'buyer_addresses' => 0 ], $overrides );
		$GLOBALS['wpdb'] = $this->db = new OwnerResetDatabase( $row );
		$this->registry = new Registry( $this->secrets );
		$this->audit = new OwnerResetAudit();
		$this->store = new OwnerResetStore();
		foreach ( [ 31, 32 ] as $id ) { $this->store->open[ $id ] = Session::from_row( [ 'id' => $id, 'partner_id' => 12, 'user_id' => 7, 'status' => Session::ACTIVE, 'wc_session_key' => 'pow_' . str_repeat( (string) ( $id - 30 ), 28 ) ] ); }
		$this->service = new Registration( $this->registry, $this->store, $this->audit );
	}
	private function unchanged( string $case ): void {
		$p = $this->registry->find( 12 );
		self::assertSame( [], $this->db->writes, $case );
		self::assertSame( [], $this->store->expired, $case );
		self::assertSame( [], $GLOBALS['pow_test_mail'], $case );
		self::assertSame( Secrets::SLOT_CURRENT, $this->registry->verify_secret( $p, 'old-current' ), $case );
		self::assertFalse( in_array( 'registration_owner_reset', $this->audit->names(), true ), $case );
		self::assertFalse( $this->db->held, $case );
	}

	public function test_owner_reset_issues_a_new_secret_ends_every_visit_and_is_audited_as_the_owner(): void {
		$secret = $this->service->reset_by_owner( 12, 7 );
		self::assertNotSame( '', $secret );
		$p = $this->registry->find( 12 );
		self::assertSame( 'active', $p->status );
		self::assertSame( Secrets::SLOT_CURRENT, $this->registry->verify_secret( $p, $secret ) );
		self::assertNull( $this->registry->verify_secret( $p, 'old-current' ) );
		self::assertNull( $this->registry->verify_secret( $p, 'old-previous' ) );
		self::assertSame( [ 'BUYER', 'BUYER', 'SUPPLIER', 'production', 7, 'reset_connection', '{"book":"preserve"}' ], [ $p->from_identity, $p->sender_identity, $p->to_identity, $p->deployment_mode, $p->owner_user_id, $p->owner_settings, $p->company_profile ] );
		self::assertSame( [ 31, 32 ], $this->store->expired );
		self::assertSame( [ [ 7, 'owner-login-token' ] ], $this->store->asked, 'The in-lock visit check asks about this request' );
		$event = $this->audit->last( 'registration_owner_reset' );
		self::assertSame( [ 7, 12, 'ok', [] ], [ $event['user_id'], $event['partner_id'], $event['result'], $event['detail'] ] );
		self::assertSame( [ 'registration_disabled', 'session_revoked', 'session_revoked', 'registration_owner_reset' ], $this->audit->names() );
		self::assertNull( $this->audit->last( 'registration_reset' ), 'Not the administrator event' );
		self::assertCount( 1, $GLOBALS['pow_test_mail'] );
		self::assertSame( 'owner@example.invalid', $GLOBALS['pow_test_mail'][0]['to'] );
		self::assertStringContainsString( 'Your punchout connection was reset from your store account.', $GLOBALS['pow_test_mail'][0]['message'] );
		self::assertStringContainsString( 'it is not in this email', $GLOBALS['pow_test_mail'][0]['message'] );
		self::assertStringNotContainsString( $secret, json_encode( [ $this->audit->events, $GLOBALS['pow_test_mail'] ] ) );
		self::assertFalse( $this->db->held );
	}

	public function test_refusals_issue_nothing_and_change_nothing(): void {
		$cases = [
			'not opted in'   => [ [ 'owner_settings' => '' ], 7, null ],
			'unknown token'  => [ [ 'owner_settings' => 'rotate_secret' ], 7, null ],
			'another owner'  => [ [ 'owner_user_id' => 8 ], 7, null ],
			'unbound'        => [ [ 'owner_user_id' => 0 ], 7, null ],
			'disabled'       => [ [ 'status' => 'disabled' ], 7, null ],
			'pending'        => [ [ 'status' => 'pending' ], 7, null ],
			'other actor'    => [ [], 8, null ],
			'no actor'       => [ [], 0, null ],
			'no read'        => [ [], 7, 'no_read' ],
			'signed out'     => [ [], 7, 'signed_out' ],
			'live visit'     => [ [], 7, 'visit' ],
			'visit unknown'  => [ [], 7, 'visit_fails' ],
		];
		foreach ( $cases as $case => [ $row, $actor, $mode ] ) {
			$this->prime();
			$this->fixture( $row );
			match ( $mode ) {
				'no_read'     => $GLOBALS['pow_test_users'][7]->allcaps = [],
				'signed_out'  => $GLOBALS['pow_test_current_user_id'] = 0,
				'visit'       => $this->store->visit = Session::from_row( [ 'id' => 31, 'partner_id' => 12, 'user_id' => 7, 'status' => Session::ACTIVE, 'wc_session_key' => 'pow_' . str_repeat( '1', 28 ) ] ),
				'visit_fails' => $this->store->visit_fails = true,
				default       => null,
			};
			self::assertSame( '', $this->service->reset_by_owner( 12, $actor ), $case );
			$this->unchanged( $case );
			self::assertTrue( in_array( 'registration_owner_reset_failed', $this->audit->names(), true ), $case );
		}
	}

	/** The administrator's reset keeps its own entry point, events and wording. */
	public function test_admin_reset_is_unchanged_and_owner_reset_is_not_an_admin_path(): void {
		$GLOBALS['pow_test_users'][7]->allcaps = [ 'read' => true ];
		self::assertSame( '', $this->service->reset( 12, $this->db->row, 7 ), 'An ordinary account cannot use the administrator reset' );
		self::assertSame( 'authorization', $this->audit->last( 'registration_reset_failed' )['detail']['reason'] );
		$this->fixture();
		$GLOBALS['pow_test_users'][7]->allcaps = [ 'read' => true, 'manage_woocommerce' => true ];
		$secret = $this->service->reset( 12, $this->db->row, 7 );
		self::assertNotSame( '', $secret );
		self::assertSame( [ 'registration_disabled', 'session_revoked', 'session_revoked', 'registration_reset' ], $this->audit->names() );
		self::assertStringContainsString( 'Contact the store to arrange credential handover.', $GLOBALS['pow_test_mail'][0]['message'] );
		self::assertSame( [], $this->store->asked, 'The administrator reset asks nothing about a visit' );
	}

	private function partner_form( array $row ): string {
		$page = new POW\Admin\Page( new POW\Settings(), $this->registry, $this->audit );
		ob_start();
		try { ( new ReflectionMethod( $page, 'render_partner_form' ) )->invoke( $page, Partner::from_row( $row ) ); return (string) ob_get_contents(); }
		finally { ob_end_clean(); }
	}

	public function test_connection_form_offers_the_reset_checkbox_ticked_only_when_granted(): void {
		$html = $this->partner_form( $this->db->row );
		self::assertStringContainsString( 'Account holder actions', $html );
		self::assertStringContainsString( 'Reset connection from the My Account “Punchout integration” tab', $html );
		self::assertStringContainsString( 'A per-connection return button label does not exist', $html );
		self::assertSame( 1, preg_match( '#<input type="checkbox" name="owner_reset_connection" value="1"([^>]*)/>#', $html, $m ) );
		self::assertStringContainsString( 'checked="checked"', $m[1] );
		$html = $this->partner_form( array_replace( $this->db->row, [ 'owner_settings' => '' ] ) );
		self::assertSame( 1, preg_match( '#<input type="checkbox" name="owner_reset_connection" value="1"([^>]*)/>#', $html, $m ) );
		self::assertStringNotContainsString( 'checked', $m[1] );
		self::assertTrue( strpos( $html, 'Buyer-added addresses' ) < strpos( $html, 'Account holder actions' ), 'After the buyer-address row' );
		self::assertTrue( strpos( $html, 'Account holder actions' ) < strpos( $html, 'Token TTL / session TTL' ), 'Before the TTL row' );
	}
}
