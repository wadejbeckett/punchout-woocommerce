<?php
/**
 * The schema-seven session columns: the per-visit WooCommerce key and the
 * buyer identity carried as data on the row — on the row object, and in the
 * table the upgrade is supposed to have produced.
 *
 * A visit is identified by its own `wc_session_key`, never by `user_id` —
 * many visits of one customer account share that id. Buyer identity is
 * optional by design (a purchasing system may send neither name nor
 * e-mail), so every column here must read back as null when the row omits
 * it and when the row stores it empty.
 *
 * The second half of the file is the upgrade itself. `dbDelta()` discards
 * every failed ALTER, so the only evidence a migration landed is reading the
 * table back: `Installer` must refuse to record `pow_db_version` when the
 * four columns or the three indexes are not there, and must say so once in
 * the operational log rather than fataling an admin_init. Schema eight adds
 * one partners column, `visit_endpoints`, under the same read-back, and must
 * not repeat schema seven's close-out of open visits.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Tests\InstallerSchema {

	/**
	 * Recording stand-in for the operational log. The Installer constructs its
	 * own logger, so the lines land in a global rather than on an instance a
	 * test could hold.
	 */
	final class Logger extends \POW\Logger {

		public function log( string $level, string $message, array $context = [] ): void {
			$GLOBALS['pow_installer_log'][] = $level . ': ' . $message;
		}
	}

	// The WordPress surface the Installer touches, bound to this namespace only:
	// the option store it advances the version marker in, and the schema writer
	// whose failures it can no longer see.
	function get_option( string $name, mixed $default = false ): mixed { return $GLOBALS['pow_installer_options'][ $name ] ?? $default; }
	function update_option( string $name, mixed $value, bool $autoload = true ): bool { $GLOBALS['pow_installer_options'][ $name ] = $value; return true; }
	function dbDelta( string $sql ): array { $GLOBALS['pow_installer_ddl'][] = $sql; return []; } // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
	/** The rewrite place the account tab registers at; core defines it, this harness does not load core. */
	const EP_PAGES = 4096;

	function add_rewrite_endpoint( string $name, int $places ): void {}
	function flush_rewrite_rules( bool $hard = true ): void {}

	/**
	 * Test-only: re-namespaces this plugin's OWN Installer from disk so the
	 * fakes above stand in for WordPress — the same technique
	 * NativeSessionHandlerTest and QuantityRollbackTest use. The one
	 * statement removed is the `require_once` of core's upgrade.php, which is
	 * where `dbDelta()` would otherwise come from. Nothing outside
	 * includes/Installer.php is read and no test input reaches eval().
	 */
	function load_installer(): void {
		if ( class_exists( __NAMESPACE__ . '\\Installer', false ) ) { return; }

		$path = dirname( __DIR__, 2 ) . '/includes/Installer.php';
		\PHPUnit\Framework\TestCase::assertTrue( is_file( $path ), 'The Installer source must be readable.' );
		$source = (string) file_get_contents( $path );
		$source = str_replace(
			'namespace POW;',
			'namespace ' . __NAMESPACE__ . '; use POW\\Account; use POW\\Audit; use POW\\Cron; use POW\\Sessions; use POW\\Settings;',
			$source
		);
		$source = str_replace(
			"require_once ABSPATH . 'wp-admin/includes/upgrade.php';",
			'// dbDelta() is the namespace-local recorder in SessionColumnsTest.',
			$source
		);
		\PHPUnit\Framework\TestCase::assertStringNotContainsString( 'upgrade.php', $source, 'The core upgrade include must have been replaced.' );

		eval( substr( $source, 5 ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
	}
}

namespace {

	if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) { require_once dirname( __DIR__ ) . '/bootstrap.php'; }

	use PHPUnit\Framework\TestCase;
	use POW\Sessions\Session;

	/**
	 * The sessions table as the server would answer for it, and the writer
	 * that cannot report its own failures.
	 *
	 * Only the statements the Installer really issues are interpreted;
	 * anything else is a test fault rather than a fixture gap. The open-visit
	 * sweep is answered with no rows, because what is under test here is the
	 * read-back, not the v7 close-out.
	 */
	final class InstallerSchemaDatabase {

		/** Every column the shipped CREATE TABLE names, as SHOW COLUMNS answers. */
		public const COLUMNS = [
			'id', 'partner_id', 'buyer_cookie', 'operation', 'browser_form_post_url', 'selected_item', 'ship_to',
			'user_id', 'wp_session_token', 'wc_session_key', 'buyer_identity', 'buyer_name', 'buyer_identity_hash',
			'one_time_token_hash', 'status', 'order_id', 'payload_id', 'body_hash', 'response_xml', 'cxml_version',
			'deployment_mode', 'extrinsics', 'itemout_lines', 'cart_ready', 'delivery_choice', 'delivery_confirmation',
			'created', 'expires',
		];

		/** The partners columns schemas eight and nine need; the rest of that table is not under test. */
		public const PARTNER_COLUMNS = [ 'id', 'name', 'status', 'owner_user_id', 'sender_domain', 'sender_identity', 'visit_endpoints', 'buyer_addresses', 'owner_settings' ];

		/** Key name => Non_unique, as SHOW INDEX answers it. */
		public const INDEXES = [
			'PRIMARY'         => 0,
			'token_hash'      => 0,
			'partner_payload' => 0,
			'wc_session_key'  => 0,
			'partner_status'  => 1,
			'partner_buyer'   => 1,
			'login'           => 1,
			'user_id'         => 1,
			'expires'         => 1,
		];

		public string $prefix = 'fixture_';
		public string $last_error = '';
		/** @var list<string> */
		public array $columns = self::COLUMNS;
		/** @var array<string, int> */
		public array $indexes = self::INDEXES;
		/** @var list<string> */
		public array $partner_columns = self::PARTNER_COLUMNS;
		/** How many times schema seven's open-visit close-out read the sessions table. */
		public int $closeout_reads = 0;
		/** @var list<string> Statements whose answer is an error rather than rows. */
		public array $unreadable = [];

		public function get_charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4'; }

		public function prepare( string $sql, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$sql = (string) preg_replace_callback( '/%[sd]/', static fn( array $m ): string => '%d' === $m[0] ? (string) (int) $arg : "'" . (string) $arg . "'", $sql, 1 );
			}

			return $sql;
		}

		/** @return list<array<string, mixed>> */
		public function get_results( string $sql, mixed $format = null ): array {
			if ( ARRAY_A !== $format ) { throw new LogicException( 'The Installer reads associative rows only.' ); }

			foreach ( $this->unreadable as $fragment ) {
				if ( str_contains( $sql, $fragment ) ) { $this->last_error = 'Table is unreadable.'; return []; }
			}

			if ( str_contains( $sql, 'SHOW COLUMNS FROM fixture_pow_sessions' ) ) {
				return array_map( static fn( string $column ): array => [ 'Field' => $column, 'Type' => 'varchar(190)' ], $this->columns );
			}

			if ( str_contains( $sql, 'SHOW COLUMNS FROM fixture_pow_partners' ) ) {
				return array_map( static fn( string $column ): array => [ 'Field' => $column, 'Type' => 'varchar(190)' ], $this->partner_columns );
			}

			if ( str_contains( $sql, 'SHOW INDEX FROM fixture_pow_sessions' ) ) {
				$rows = [];
				foreach ( $this->indexes as $name => $non_unique ) {
					$rows[] = [ 'Key_name' => (string) $name, 'Non_unique' => $non_unique, 'Column_name' => (string) $name ];
				}

				return $rows;
			}

			// The v7 close-out reads the open visits; this fixture has none.
			if ( str_contains( $sql, 'SELECT id, user_id, wp_session_token FROM fixture_pow_sessions' ) ) { ++$this->closeout_reads; return []; }

			throw new LogicException( 'Unexpected read: ' . $sql );
		}
	}

	final class SessionColumnsTest extends TestCase {

		private const KEY = 'pow_0123456789abcdef0123456789ab';

		private mixed $saved_db;
		private bool $had_db;
		private InstallerSchemaDatabase $db;

		protected function setUp(): void {
			$this->had_db          = array_key_exists( 'wpdb', $GLOBALS );
			$this->saved_db        = $GLOBALS['wpdb'] ?? null;
			$GLOBALS['wpdb']       = $this->db = new InstallerSchemaDatabase();
			$GLOBALS['pow_installer_log']     = [];
			$GLOBALS['pow_installer_ddl']     = [];
			// A site mid-upgrade: schema six recorded, routing already current.
			$GLOBALS['pow_installer_options'] = [ 'pow_db_version' => '6', 'pow_rewrite_version' => '1' ];
			POW\Tests\InstallerSchema\load_installer();
		}

		protected function tearDown(): void {
			if ( $this->had_db ) { $GLOBALS['wpdb'] = $this->saved_db; } else { unset( $GLOBALS['wpdb'] ); }
			unset( $GLOBALS['pow_installer_log'], $GLOBALS['pow_installer_ddl'], $GLOBALS['pow_installer_options'] );
		}

		private function upgrade(): void {
			POW\Tests\InstallerSchema\Installer::maybe_upgrade();
		}

		private function recorded_version(): string {
			return (string) ( $GLOBALS['pow_installer_options']['pow_db_version'] ?? '' );
		}

		// --------------------------------------------------------- the row shape

		public function test_from_row_maps_the_per_visit_key_and_buyer_identity(): void {
			$session = Session::from_row(
				[
					'id'                  => 9,
					'partner_id'          => 3,
					'status'              => Session::ACTIVE,
					'user_id'             => 42,
					'wc_session_key'      => self::KEY,
					'buyer_identity'      => 'buyer@example.com',
					'buyer_name'          => 'Jane Buyer',
					'buyer_identity_hash' => str_repeat( 'b', 64 ),
				]
			);

			self::assertSame( self::KEY, $session->wc_session_key );
			self::assertSame( 'buyer@example.com', $session->buyer_identity );
			self::assertSame( 'Jane Buyer', $session->buyer_name );
			self::assertSame( str_repeat( 'b', 64 ), $session->buyer_identity_hash );

			// WooCommerce stores session_key as char(32); a longer key would be
			// truncated on write and never match on readback.
			self::assertSame( 32, strlen( (string) $session->wc_session_key ) );
		}

		public function test_empty_columns_read_back_as_absent(): void {
			$session = Session::from_row(
				[
					'id'                  => 9,
					'status'              => Session::ACTIVE,
					'wc_session_key'      => '',
					'buyer_identity'      => '',
					'buyer_name'          => '',
					'buyer_identity_hash' => '',
				]
			);

			self::assertNull( $session->wc_session_key );
			self::assertNull( $session->buyer_identity );
			self::assertNull( $session->buyer_name );
			self::assertNull( $session->buyer_identity_hash );
		}

		public function test_row_without_the_new_columns_still_constructs(): void {
			$session = Session::from_row( [ 'id' => 1, 'status' => Session::PENDING ] );

			self::assertSame(
				[ null, null, null, null ],
				[ $session->wc_session_key, $session->buyer_identity, $session->buyer_name, $session->buyer_identity_hash ]
			);
			self::assertFalse( $session->is_terminal() );
		}

		public function test_an_anonymous_visit_keeps_its_key_without_any_identity(): void {
			$session = Session::from_row( [ 'id' => 4, 'status' => Session::ACTIVE, 'wc_session_key' => self::KEY ] );

			self::assertSame( self::KEY, $session->wc_session_key );
			self::assertNull( $session->buyer_identity );
			self::assertNull( $session->buyer_name );
		}

		// ------------------------------------------------- the upgrade read-back

		/** The ordinary case: the ALTERs applied, so the version marker may advance. */
		public function test_a_migration_that_landed_records_the_schema_version(): void {
			$this->upgrade();

			self::assertSame( '9', $this->recorded_version() );
			self::assertSame( [], $GLOBALS['pow_installer_log'], 'A clean upgrade says nothing.' );
			self::assertCount( 3, $GLOBALS['pow_installer_ddl'], 'One statement per table.' );
		}

		/**
		 * A column the server never added is the whole finding: dbDelta()
		 * reports only what it meant to do, so the read-back is what has to
		 * refuse. The marker stays at six and the next admin_init tries again.
		 */
		public function test_a_session_column_that_did_not_land_leaves_the_version_behind(): void {
			foreach ( [ 'wc_session_key', 'buyer_identity', 'buyer_name', 'buyer_identity_hash' ] as $column ) {
				$this->tearDown();
				$this->setUp();
				$this->db->columns = array_values( array_diff( InstallerSchemaDatabase::COLUMNS, [ $column ] ) );

				$this->upgrade();

				self::assertSame( '6', $this->recorded_version(), $column . ' is missing, so the migration is not done' );
				self::assertCount( 1, $GLOBALS['pow_installer_log'], 'One error line, ' . $column );
				self::assertStringStartsWith( 'error: ', $GLOBALS['pow_installer_log'][0], $column );
				self::assertStringContainsString( 'column ' . $column . ' is missing', $GLOBALS['pow_installer_log'][0], $column );
			}
		}

		/** The same discipline for the three indexes schema seven adds. */
		public function test_an_index_that_did_not_land_leaves_the_version_behind(): void {
			foreach ( [ 'wc_session_key', 'partner_buyer', 'login' ] as $index ) {
				$this->tearDown();
				$this->setUp();
				$this->db->indexes = array_diff_key( InstallerSchemaDatabase::INDEXES, [ $index => 0 ] );

				$this->upgrade();

				self::assertSame( '6', $this->recorded_version(), $index . ' is missing, so the migration is not done' );
				self::assertCount( 1, $GLOBALS['pow_installer_log'], 'One error line, ' . $index );
				self::assertStringContainsString( 'index ' . $index . ' is missing', $GLOBALS['pow_installer_log'][0], $index );
			}
		}

		/**
		 * A `wc_session_key` that exists but is merely indexed lets two visits
		 * hold one basket row, which is the collision the UNIQUE key is for.
		 */
		public function test_a_visit_key_index_without_its_unique_constraint_is_a_fault(): void {
			$this->db->indexes = array_replace( InstallerSchemaDatabase::INDEXES, [ 'wc_session_key' => 1 ] );

			$this->upgrade();

			self::assertSame( '6', $this->recorded_version() );
			self::assertStringContainsString( 'index wc_session_key is not UNIQUE', $GLOBALS['pow_installer_log'][0] );
		}

		/** An answer that could not be read is not evidence of a completed migration. */
		public function test_a_sessions_table_that_cannot_be_read_is_never_a_completed_migration(): void {
			foreach ( [ 'SHOW COLUMNS', 'SHOW INDEX' ] as $statement ) {
				$this->tearDown();
				$this->setUp();
				$this->db->unreadable = [ $statement ];

				$this->upgrade();

				self::assertSame( '6', $this->recorded_version(), $statement . ' failed' );
				self::assertCount( 1, $GLOBALS['pow_installer_log'], $statement );
				self::assertStringContainsString( 'could not be read', $GLOBALS['pow_installer_log'][0], $statement );
			}
		}

		/**
		 * Because the marker stayed behind, the next admin request runs the
		 * migration again — and records seven once the column is finally there.
		 * A marker written over a failed ALTER is permanent; this is the half
		 * of the fix that makes the site self-heal.
		 */
		public function test_a_refused_upgrade_is_retried_on_the_next_admin_request(): void {
			$this->db->columns = array_values( array_diff( InstallerSchemaDatabase::COLUMNS, [ 'wc_session_key' ] ) );

			$this->upgrade();
			self::assertSame( '6', $this->recorded_version() );
			self::assertCount( 3, $GLOBALS['pow_installer_ddl'] );

			$this->db->columns = InstallerSchemaDatabase::COLUMNS;
			$this->upgrade();

			self::assertSame( '9', $this->recorded_version(), 'The retry is what a stuck version marker would have prevented.' );
			self::assertCount( 6, $GLOBALS['pow_installer_ddl'], 'The second run really did re-issue the schema.' );
			self::assertCount( 1, $GLOBALS['pow_installer_log'], 'The successful retry adds no line of its own.' );
		}

		/** A recorded version is never re-migrated: the upgrade is idempotent by marker. */
		public function test_a_current_version_marker_issues_no_schema_statement(): void {
			$GLOBALS['pow_installer_options']['pow_db_version'] = '9';

			$this->upgrade();

			self::assertSame( [], $GLOBALS['pow_installer_ddl'] );
			self::assertSame( [], $GLOBALS['pow_installer_log'] );
		}

		/**
		 * Schema eight over a live schema-seven site: the partners column is
		 * read back like the session columns, and the open visits schema
		 * seven closed are left alone — a site at seven has live baskets.
		 */
		public function test_schema_eight_over_seven_reads_back_the_partner_column_and_keeps_open_visits(): void {
			$GLOBALS['pow_installer_options']['pow_db_version'] = '7';
			$this->db->partner_columns = array_values( array_diff( InstallerSchemaDatabase::PARTNER_COLUMNS, [ 'visit_endpoints' ] ) );

			$this->upgrade();

			self::assertSame( '7', $this->recorded_version(), 'visit_endpoints is missing, so the migration is not done' );
			self::assertCount( 1, $GLOBALS['pow_installer_log'] );
			self::assertStringContainsString( 'fixture_pow_partners: column visit_endpoints is missing', $GLOBALS['pow_installer_log'][0] );
			self::assertStringContainsString( 'visit_endpoints VARCHAR(255) NOT NULL DEFAULT \'\'', $GLOBALS['pow_installer_ddl'][0] );

			$this->db->partner_columns = InstallerSchemaDatabase::PARTNER_COLUMNS;
			$this->upgrade();

			self::assertSame( '9', $this->recorded_version() );
			self::assertSame( 0, $this->db->closeout_reads, 'Schema seven\'s close-out never runs over a site already at seven' );
		}

		/**
		 * Schema nine over a live schema-eight site: both buyer-address columns
		 * are read back, and the marker stays at eight until they are there.
		 */
		public function test_schema_nine_over_eight_reads_back_the_buyer_columns(): void {
			$GLOBALS['pow_installer_options']['pow_db_version'] = '8';
			$this->db->partner_columns = array_values( array_diff( InstallerSchemaDatabase::PARTNER_COLUMNS, [ 'buyer_addresses' ] ) );

			$this->upgrade();

			self::assertSame( '8', $this->recorded_version(), 'buyer_addresses is missing, so the migration is not done' );
			self::assertCount( 1, $GLOBALS['pow_installer_log'] );
			self::assertStringContainsString( 'column buyer_addresses is missing', $GLOBALS['pow_installer_log'][0] );
			self::assertStringContainsString( 'buyer_addresses TINYINT(1) NOT NULL DEFAULT 0', $GLOBALS['pow_installer_ddl'][0] );
			self::assertStringContainsString( "owner_settings VARCHAR(64) NOT NULL DEFAULT ''", $GLOBALS['pow_installer_ddl'][0] );

			$this->db->partner_columns = array_values( array_diff( InstallerSchemaDatabase::PARTNER_COLUMNS, [ 'owner_settings' ] ) );
			$this->upgrade();
			self::assertSame( '8', $this->recorded_version(), 'owner_settings is missing too' );

			$this->db->partner_columns = InstallerSchemaDatabase::PARTNER_COLUMNS;
			$this->upgrade();

			self::assertSame( '9', $this->recorded_version() );
			self::assertSame( 0, $this->db->closeout_reads );
		}

		/** Activation answers to the same read-back as the upgrade path. */
		public function test_activation_does_not_record_a_migration_that_did_not_land(): void {
			$GLOBALS['pow_installer_options'] = [];
			$this->db->columns                = array_values( array_diff( InstallerSchemaDatabase::COLUMNS, [ 'buyer_identity' ] ) );

			POW\Tests\InstallerSchema\Installer::activate();

			self::assertSame( '', $this->recorded_version(), 'A fresh install whose table is wrong records nothing.' );
			self::assertCount( 1, $GLOBALS['pow_installer_log'] );
		}
	}
}
