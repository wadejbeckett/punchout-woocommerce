<?php
/**
 * The hourly housekeeping job.
 *
 * Under one shared login the GC is the only thing that ever closes an
 * abandoned visit: nobody logs out, the buyer closes the tab, and the
 * per-connection open-visit cap is released only when these rows expire.
 * So this suite pins what run() does — expire the TTL stragglers of one
 * account row by row, trim the audit table, cancel unconverted quotes —
 * and, just as importantly, what it no longer does: read a user, write
 * user meta or fire a hook for site glue to hang off.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace {
	if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) { require_once dirname( __DIR__ ) . '/bootstrap.php'; }

	use PHPUnit\Framework\TestCase;
	use POW\Audit\Log;
	use POW\Cron;
	use POW\Orders\QuoteOrder;
	use POW\Partners\Registry;
	use POW\Partners\Secrets;
	use POW\Plugin;
	use POW\Sessions\Session;
	use POW\Sessions\Store;
	use POW\Settings;

	/** Expiry is per row and destroys only that row's login, so the double records ids, never users. */
	final class CronTestStore extends Store {

		/** @var list<Session> */
		public array $open = [];

		/** @var list<int> */
		public array $expired = [];

		public bool $refuse = false;

		public function __construct() {}

		/** @return list<Session> */
		public function expired_open( int $limit = 200 ): array {
			return $this->open;
		}

		public function expire_and_destroy( Session $session, \POW\Partners\Registry $registry ): bool {
			$this->expired[] = $session->id;

			return ! $this->refuse;
		}
	}

	final class CronTestLog extends Log {

		/** @var list<array{0: string, 1: array<string, mixed>}> */
		public array $written = [];

		/** @var list<int> */
		public array $trimmed = [];

		public function __construct() {}

		/** @param array<string, mixed> $context Audit context. */
		public function write( string $event, array $context = [] ): void {
			$this->written[] = [ $event, $context ];
		}

		/** @param array<string, mixed> $context Audit context. */
		public function write_checked( string $event, array $context = [] ): bool {
			$this->write( $event, $context );

			return true;
		}

		public function trim( int $days ): int {
			$this->trimmed[] = $days;

			return 0;
		}
	}

	final class CronTestSettings extends Settings {

		public function __construct( private int $retention = 400 ) {}

		public function int( string $key ): int {
			return 'log_retention_days' === $key ? $this->retention : 0;
		}
	}

	final class CronTest extends TestCase {

		/** @var array<string, array{bool, mixed}> */
		private array $saved = [];

		private CronTestStore $sessions;
		private CronTestLog $audit;
		private ReflectionProperty $registry;
		private mixed $before = null;

		protected function setUp(): void {
			foreach ( [ 'pow_test_orders', 'pow_test_order_queries', 'pow_test_query_order_ids', 'pow_test_get_order_errors', 'pow_test_errors', 'pow_test_options', 'pow_test_users', 'pow_test_user_meta', 'wpdb' ] as $key ) {
				$this->saved[ $key ] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null ];
				unset( $GLOBALS[ $key ] );
			}
			$GLOBALS['pow_test_orders']          = [];
			$GLOBALS['pow_test_order_queries']   = [];
			$GLOBALS['pow_test_query_order_ids'] = [];
			$GLOBALS['pow_test_get_order_errors'] = [];

			$this->sessions = new CronTestStore();
			$this->audit    = new CronTestLog();

			$this->registry = new ReflectionProperty( Plugin::class, 'registry' );
			$this->before   = $this->registry->getValue( Plugin::instance() );
			$this->registry->setValue( Plugin::instance(), new Registry( new Secrets( str_repeat( 'a', 32 ) ) ) );
		}

		protected function tearDown(): void {
			$this->registry->setValue( Plugin::instance(), $this->before );
			foreach ( $this->saved as $key => [ $existed, $value ] ) {
				if ( $existed ) { $GLOBALS[ $key ] = $value; } else { unset( $GLOBALS[ $key ] ); }
			}
		}

		private function visit( int $id, string $token ): Session {
			return Session::from_row(
				[
					'id'               => $id,
					'partner_id'       => 7,
					'user_id'          => 99,
					'wp_session_token' => $token,
					'wc_session_key'   => 'pow_' . str_pad( (string) $id, 28, 'a', STR_PAD_LEFT ),
					'status'           => Session::ACTIVE,
				]
			);
		}

		private function cron( int $retention = 400 ): Cron {
			return new Cron(
				$this->sessions,
				$this->audit,
				new CronTestSettings( $retention ),
				new QuoteOrder( new QuoteOrderTestStore(), new QuoteOrderTestLog(), new QuoteOrderTestSettings(), new QuoteOrderTestLogger() )
			);
		}

		/** Two abandoned visits of ONE bound account: both are expired on their own row, and each is audited on its own. */
		public function test_run_expires_ttl_stragglers_trims_the_log_and_cancels_unconverted_quotes(): void {
			$this->sessions->open = [ $this->visit( 42, 'visit-one-token' ), $this->visit( 43, 'visit-two-token' ) ];

			$this->cron( 120 )->run();

			self::assertSame( [ 42, 43 ], $this->sessions->expired, 'Every straggler is expired, row by row.' );
			self::assertSame( [ 120 ], $this->audit->trimmed, 'The audit trim uses the configured retention.' );
			self::assertCount( 2, $this->audit->written );
			foreach ( [ 0 => 42, 1 => 43 ] as $index => $id ) {
				[ $event, $context ] = $this->audit->written[ $index ];
				self::assertSame( 'session_expired', $event );
				self::assertSame( $id, $context['session_id'] );
				self::assertSame( 'ttl', $context['result'] );
				self::assertSame( 7, $context['partner_id'] );
			}
			self::assertCount( 1, $GLOBALS['pow_test_order_queries'], 'Unconverted quotes are swept in the same run.' );
		}

		/** An unverified expiry is not a closed visit, so it must not leave evidence saying it was. */
		public function test_a_visit_the_store_cannot_expire_is_not_audited(): void {
			$this->sessions->open   = [ $this->visit( 42, 'visit-one-token' ) ];
			$this->sessions->refuse = true;

			$this->cron()->run();

			self::assertSame( [ 42 ], $this->sessions->expired );
			self::assertSame( [], $this->audit->written );
			self::assertSame( [ 400 ], $this->audit->trimmed, 'A refused expiry does not abandon the rest of the run.' );
		}

		/** Without a registry there is no lock to expire under, so nothing is touched and nothing is claimed. */
		public function test_no_registry_means_no_expiry_and_no_audit_row(): void {
			$this->registry->setValue( Plugin::instance(), null );
			$this->sessions->open = [ $this->visit( 42, 'visit-one-token' ) ];

			$this->cron()->run();

			self::assertSame( [], $this->sessions->expired );
			self::assertSame( [], $this->audit->written );
		}

		/**
		 * The dormant-buyer sweep is gone with the accounts it swept. This is
		 * a live proof, not a grep: the user-mutating functions are absent
		 * from the harness on purpose, so a housekeeping run that touched a
		 * user would fatal here instead of passing quietly.
		 */
		public function test_housekeeping_touches_no_user_and_fires_no_hook(): void {
			foreach ( [ 'update_user_meta', 'delete_user_meta', 'add_user_meta', 'wp_insert_user', 'wp_delete_user', 'do_action' ] as $function ) {
				self::assertFalse( function_exists( $function ), $function . '() must stay absent, or this proof is vacuous.' );
			}

			$this->sessions->open = [ $this->visit( 42, 'visit-one-token' ) ];
			$this->cron()->run();

			self::assertSame( [ 42 ], $this->sessions->expired );

			$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/Cron.php' );
			foreach ( [ 'WP_User_Query', 'do_action', 'update_user_meta', 'get_user_meta', '_pow_last_seen', '_pow_deactivated', 'buyer_inactive_days', 'deactivate_stale_buyers', 'pow_buyer_deactivated' ] as $needle ) {
				self::assertStringNotContainsString( $needle, $source, 'Housekeeping still names ' . $needle );
			}
			self::assertFalse( method_exists( Cron::class, 'deactivate_stale_buyers' ) );
		}
	}
}
