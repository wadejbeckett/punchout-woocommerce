<?php
/**
 * The dual exit is gone.
 *
 * One regression suite for everything the removal of the pay path and the
 * exit entitlement has to keep true: the two classes and the close-out
 * template are absent, no production file names them, the retired
 * `exit_policy` column is still admin-only, every connection reads as
 * punchout-only, and `Session::ORDERED` survives as vocabulary that
 * nothing writes.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace {
	if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) { require_once dirname( __DIR__ ) . '/bootstrap.php'; }
	require_once dirname( __DIR__ ) . '/Admin/doubles.php';

	use POW\Admin\Page;
	use POW\Partners\{Partner, Registration, Registry, Secrets};
	use POW\Sessions\Session;
	use POW\Settings;

	final class DualExitRemovalTest extends PHPUnit\Framework\TestCase {

		private const FORBIDDEN = [
			'PayExit',
			'ExitPolicy',
			'punchout_and_checkout',
			'dual_exit',
			'MODE_DUAL_EXIT',
			'_pow_exit_policy_',
			'pow_closeout_copy',
			'pow_save_buyer_exit',
			'closeout-button',
			'paid-order closeout',
			'->exit_policy()',
			'Buyer restrictions',
			'Checkout access',
		];

		private array $saved = [];
		private AdminDatabase $db;
		private AdminAudit $audit;
		private Registry $registry;

		protected function setUp(): void {
			foreach ( [ 'wpdb', 'pow_admin_test', 'pow_test_current_user_id', 'pow_test_users', 'pow_test_user_meta', 'pow_test_options', 'pow_test_transients', 'pow_test_valid_nonce', '_SERVER', '_POST', '_GET' ] as $key ) {
				$this->saved[ $key ] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null ];
				unset( $GLOBALS[ $key ] );
			}
			$GLOBALS['pow_admin_test']         = [ 'headers' => [] ];
			$GLOBALS['pow_test_current_user_id'] = 1;
			$GLOBALS['pow_test_user_meta']     = [];
			$GLOBALS['pow_test_users']         = [
				1  => (object) [ 'ID' => 1, 'roles' => [ 'administrator' ], 'allcaps' => [ 'manage_woocommerce' => true, 'read' => true ] ],
				20 => (object) [ 'ID' => 20, 'roles' => [ 'customer' ], 'allcaps' => [ 'read' => true ] ],
			];
			$GLOBALS['pow_test_valid_nonce'] = 'valid';
			$_SERVER                         = [ 'REQUEST_METHOD' => 'GET' ];
			$_POST                           = [];
			$_GET                            = [];
			$GLOBALS['wpdb'] = $this->db = new AdminDatabase();
			$this->db->rows  = [ $this->row() ];
			$this->registry  = new Registry( new Secrets( str_repeat( 'x', 32 ) ) );
			$this->audit     = new AdminAudit();
		}

		protected function tearDown(): void {
			foreach ( $this->saved as $key => [ $exists, $value ] ) {
				if ( $exists ) { $GLOBALS[ $key ] = $value; } else { unset( $GLOBALS[ $key ] ); }
			}
		}

		/** @return array<string, mixed> */
		private function row( array $extra = [] ): array {
			return array_replace(
				[
					'id' => 20, 'name' => 'Example Company', 'status' => 'active', 'owner_user_id' => 0,
					'from_domain' => 'NetworkID', 'from_identity' => 'BUYER', 'sender_domain' => 'NetworkID',
					'sender_identity' => 'BUYER', 'to_domain' => 'NetworkID', 'to_identity' => 'STORE',
					'deployment_mode' => 'test', 'return_encoding' => 'base64', 'cxml_version' => '1.2.008',
					'secret_current' => '', 'secret_previous' => '', 'company_profile' => '{"book":"preserve"}',
				],
				$extra
			);
		}

		/** @return list<string> Every shipped PHP source file. */
		private function sources(): array {
			$root  = dirname( __DIR__, 2 );
			$files = [];
			foreach ( [ '/includes', '/templates' ] as $dir ) {
				$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . $dir ) );
				foreach ( $iterator as $file ) {
					if ( $file->isFile() && 'php' === $file->getExtension() ) { $files[] = $file->getPathname(); }
				}
			}
			sort( $files );
			self::assertGreaterThan( 40, count( $files ), 'Source scan found too few files to be trusted' );
			return $files;
		}

		private function screen( array $query = [] ): string {
			$_GET = [ 'tab' => 'partners' ] + $query;
			ob_start();
			try { ( new Page( new Settings(), $this->registry, $this->audit ) )->render(); return (string) ob_get_contents(); }
			finally { ob_end_clean(); }
		}

		public function test_the_pay_path_and_the_exit_entitlement_no_longer_exist(): void {
			$root = dirname( __DIR__, 2 );
			foreach ( [ '/includes/Checkout/PayExit.php', '/includes/Checkout/ExitPolicy.php', '/templates/closeout-button.php' ] as $path ) {
				self::assertFalse( is_file( $root . $path ), 'Removed file still present: ' . $path );
			}
			foreach ( [ 'POW\\Checkout\\PayExit', 'POW\\Checkout\\ExitPolicy' ] as $class ) {
				self::assertFalse( class_exists( $class ), 'Removed class still loadable: ' . $class );
			}
			self::assertFalse( method_exists( Settings::class, 'exit_policy' ), 'The retired global reader is gone' );
			self::assertFalse( method_exists( \POW\Admin\Actions::class, 'save_buyer_exit' ), 'The per-buyer restriction handler is gone' );
			self::assertFalse( method_exists( \POW\Docs\Reference::class, 'exit_policy' ), 'The public exit-policy documentation is gone' );
			self::assertFalse( method_exists( Partner::class, 'is_requisition_only' ), 'The legacy dual-exit predicate is gone' );
			self::assertFalse( defined( Partner::class . '::MODE_DUAL_EXIT' ), 'The dual-exit mode constant is gone' );
		}

		public function test_no_shipped_source_file_names_the_dual_exit(): void {
			foreach ( $this->sources() as $file ) {
				$source = (string) file_get_contents( $file );
				foreach ( self::FORBIDDEN as $needle ) {
					self::assertStringNotContainsString( $needle, $source, basename( $file ) . ' still names ' . $needle );
				}
			}
		}

		/**
		 * The checkout hooks themselves survive in RouteGuard, which uses
		 * them to refuse. What may never come back is a listener that
		 * completes a payment, reacts to a failed one, or renders anything on
		 * the order-received page, and the order meta the pay path stamped.
		 */
		public function test_nothing_listens_for_a_payment_or_renders_a_close_out(): void {
			foreach ( $this->sources() as $file ) {
				$source = (string) file_get_contents( $file );
				foreach ( [ "add_action( 'woocommerce_payment_complete'", "add_action( 'woocommerce_order_status_failed'", "add_action( 'woocommerce_thankyou'", "'_pow_outcome'", "'direct_order'" ] as $needle ) {
					self::assertStringNotContainsString( $needle, $source, basename( $file ) . ' still carries ' . $needle );
				}
			}
		}

		public function test_exit_policy_writes_still_require_the_shop_administrator_capability(): void {
			$GLOBALS['pow_test_current_user_id'] = 20;
			self::assertSame( 0, $this->registry->insert( $this->row( [ 'id' => 0, 'exit_policy' => 'punchout_only' ] ) ), 'A non-administrator cannot create a connection naming the retired column' );
			self::assertFalse( $this->registry->update( 20, [ 'exit_policy' => 'punchout_only' ] ), 'A non-administrator cannot update the retired column' );
			self::assertFalse( $this->registry->with_partner_lock( 20, fn() => $this->registry->transition_status( 20, 'active', [ 'exit_policy' => 'punchout_only' ] ) ), 'A non-administrator cannot reach the retired column through the lifecycle primitive' );
			self::assertSame( [], $this->db->writes, 'A refused entitlement write must not touch storage' );
		}

		public function test_an_administrator_write_cannot_revive_the_retired_column(): void {
			$id = $this->registry->insert( $this->row( [ 'id' => 0, 'name' => 'New company', 'exit_policy' => 'punchout_and_checkout' ] ) );
			self::assertGreaterThan( 0, $id );
			foreach ( $this->db->writes as $write ) {
				self::assertFalse( array_key_exists( 'exit_policy', $write ), 'No write may carry the retired column' );
				self::assertFalse( array_key_exists( 'mode', $write ), 'No write may carry the retired legacy mode' );
			}
			self::assertTrue( $this->registry->update( 20, [ 'exit_policy' => 'punchout_and_checkout', 'mode' => 'anything' ] ) );
			self::assertSame( 'punchout_only', $this->registry->find( 20 )->exit_policy );
		}

		public function test_every_connection_reads_as_punchout_only_whatever_the_column_holds(): void {
			$default = array_values( array_filter(
				( new ReflectionMethod( Partner::class, '__construct' ) )->getParameters(),
				static fn( ReflectionParameter $parameter ): bool => 'exit_policy' === $parameter->getName()
			) )[0];
			self::assertSame( 'punchout_only', $default->getDefaultValue() );
			foreach ( [ [], [ 'exit_policy' => null ], [ 'exit_policy' => 'punchout_and_checkout' ], [ 'exit_policy' => 'inherit' ] ] as $stored ) {
				self::assertSame( 'punchout_only', Partner::from_row( array_replace( [ 'id' => 2 ], $stored ) )->exit_policy );
			}
		}

		public function test_approval_no_longer_depends_on_a_mode_or_an_entitlement(): void {
			$pending = $this->row( [ 'status' => 'pending' ] );
			self::assertTrue( Registration::valid_approval( Partner::from_row( $pending ) ) );
			foreach ( [ 'mode' => 'invalid', 'exit_policy' => 'inherit' ] as $key => $value ) {
				self::assertTrue( Registration::valid_approval( Partner::from_row( array_replace( $pending, [ $key => $value ] ) ) ), $key . ' is no longer part of approval' );
			}
			foreach ( [ 'to_identity' => '', 'cxml_version' => 'invalid', 'deployment_mode' => 'invalid', 'return_encoding' => 'invalid' ] as $key => $value ) {
				self::assertFalse( Registration::valid_approval( Partner::from_row( array_replace( $pending, [ $key => $value ] ) ) ), $key . ' still refuses approval' );
			}
		}

		public function test_no_admin_screen_offers_a_checkout_choice_or_a_buyer_restriction(): void {
			$page = new Page( new Settings(), $this->registry, $this->audit );
			self::assertFalse( array_key_exists( 'exit_policy', $page->sanitize_settings( [ 'exit_policy' => 'punchout_only' ] ) ), 'The settings screen holds no global entitlement' );
			self::assertFalse( array_key_exists( 'exit_policy', ( new Settings() )->all() ), 'The option defaults hold no retired entitlement' );
			foreach ( [ [], [ 'action' => 'edit', 'partner' => '20' ] ] as $query ) {
				$html = $this->screen( $query );
				foreach ( [ 'name="exit_policy"', 'Checkout access', 'PunchOut and checkout', 'Buyer restrictions', 'Legacy inherited policy', 'Normal checkout is available' ] as $text ) {
					self::assertStringNotContainsString( $text, $html, 'The connection screens must not offer: ' . $text );
				}
			}
		}

		public function test_the_connection_list_names_the_bound_store_account_instead_of_a_mode(): void {
			$html = $this->screen();
			self::assertStringContainsString( 'Store account', $html );
			self::assertStringContainsString( 'not bound', $html, 'An unbound connection must be visible in the list' );
			$this->db->rows = [ $this->row( [ 'owner_user_id' => 20 ] ) ];
			$html           = $this->screen();
			self::assertStringContainsString( '#20', $html, 'A bound connection names its account' );
			self::assertStringNotContainsString( 'not bound', $html );
		}

		public function test_ordered_survives_as_vocabulary_that_nothing_writes(): void {
			self::assertTrue( Session::can_transition( Session::ACTIVE, Session::ORDERED ), 'Historical rows still have to parse' );
			self::assertTrue( Session::can_transition( Session::ORDERED, Session::CLOSED ) );
			self::assertTrue( Session::can_transition( Session::ORDERED, Session::EXPIRED ) );
			foreach ( $this->sources() as $file ) {
				foreach ( explode( "\n", (string) file_get_contents( $file ) ) as $number => $line ) {
					self::assertSame( 0, preg_match( '/transition(_status)?\s*\(.*ORDERED/', $line ), basename( $file ) . ':' . ( $number + 1 ) . ' writes ORDERED' );
				}
			}
		}
	}
}
