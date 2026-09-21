<?php
/**
 * Binding a connection to the store account its buyers shop as.
 *
 * The connection screen is the only place that binding happens, and the
 * only place it is visible: this suite covers the mandatory binding
 * section, the global admin notice an unbound (or unusable) connection
 * raises, and every refusal the binding itself makes. The refusals live in
 * Registry::associate_owner(); they are exercised here because the screen
 * promises them in its copy.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace {
	if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) { require_once dirname( __DIR__ ) . '/bootstrap.php'; }
	require_once dirname( __DIR__ ) . '/Admin/doubles.php';

	use POW\Admin\Page;
	use POW\Partners\{Registry, Secrets};
	use POW\Settings;

	/**
	 * The registry list read, made to fail. Wraps the shared AdminDatabase
	 * rather than replacing it: only Registry::all() is answered
	 * differently, so every other boundary the page touches is the one the
	 * peer admin suites use.
	 */
	final class UnreadableRegistryDatabase {
		public function __construct( private AdminDatabase $inner ) {}
		public function &__get( string $name ): mixed { return $this->inner->$name; }
		public function __set( string $name, mixed $value ): void { $this->inner->$name = $value; }
		public function __call( string $method, array $args ): mixed { return $this->inner->$method( ...$args ); }
		public function get_results( string $query, string $format ): array {
			$this->inner->last_error = 'Injected list failure';
			return [];
		}
	}

	final class AdminBindingTest extends PHPUnit\Framework\TestCase {

		private array $saved = [];
		private AdminDatabase $db;
		private AdminAudit $audit;
		private Registry $registry;

		protected function setUp(): void {
			foreach ( [ 'wpdb', 'pow_admin_test', 'pow_test_current_user_id', 'pow_test_users', 'pow_test_user_meta', 'pow_test_options', 'pow_test_transients', 'pow_test_valid_nonce', '_SERVER', '_POST', '_GET' ] as $key ) {
				$this->saved[ $key ] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null ];
				unset( $GLOBALS[ $key ] );
			}
			$GLOBALS['pow_admin_test']           = [ 'headers' => [] ];
			$GLOBALS['pow_test_current_user_id'] = 1;
			$GLOBALS['pow_test_user_meta']       = [];
			$GLOBALS['pow_test_users']           = [
				1  => (object) [ 'ID' => 1, 'roles' => [ 'administrator' ], 'allcaps' => [ 'manage_woocommerce' => true, 'manage_options' => true, 'read' => true ] ],
				20 => (object) [ 'ID' => 20, 'roles' => [ 'customer' ], 'allcaps' => [ 'read' => true ] ],
				21 => (object) [ 'ID' => 21, 'roles' => [ 'customer' ], 'allcaps' => [ 'read' => true ] ],
				22 => (object) [ 'ID' => 22, 'roles' => [ 'customer' ], 'allcaps' => [] ],
				23 => (object) [ 'ID' => 23, 'roles' => [ 'editor' ], 'allcaps' => [ 'read' => true, 'edit_users' => true ] ],
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
					'id' => 20, 'name' => 'Example Buyer Company', 'status' => 'active', 'owner_user_id' => 0,
					'from_domain' => 'NetworkID', 'from_identity' => 'BUYER', 'sender_domain' => 'NetworkID',
					'sender_identity' => 'BUYER', 'to_domain' => 'NetworkID', 'to_identity' => 'STORE',
					'deployment_mode' => 'test', 'return_encoding' => 'base64', 'cxml_version' => '1.2.008',
					'secret_current' => '', 'secret_previous' => '', 'company_profile' => '{"book":"preserve"}',
				],
				$extra
			);
		}

		private function page(): Page {
			return new Page( new Settings(), $this->registry, $this->audit );
		}

		private function screen( array $query = [] ): string {
			$_GET = [ 'tab' => 'partners' ] + $query;
			ob_start();
			try { $this->page()->render(); return (string) ob_get_contents(); }
			finally { ob_end_clean(); }
		}

		private function form( array $extra = [] ): string {
			$this->db->rows = [ $this->row( $extra ) ];
			return $this->screen( [ 'action' => 'edit', 'partner' => '20' ] );
		}

		/** The notice as an unrelated admin screen would render it: no screen context is consulted. */
		private function notice(): string {
			ob_start();
			try { $this->page()->render_unbound_notice(); return (string) ob_get_contents(); }
			finally { ob_end_clean(); }
		}

		/* -----------------------------------------------------------------
		 * The binding section
		 * -------------------------------------------------------------- */

		public function test_an_unbound_connection_offers_the_mandatory_binding_form(): void {
			$html = $this->form();
			self::assertStringContainsString( 'Store account buyers shop as', $html );
			self::assertStringContainsString( 'value="pow_associate_partner"', $html );
			self::assertStringContainsString( 'name="owner_user_id"', $html );
			self::assertStringContainsString( 'Bind store account', $html );
			foreach ( [ 'no administrative capability', 'own no other connection', 'never creates, renames or deletes users', 'cXML Status 500' ] as $promise ) {
				self::assertStringContainsString( $promise, $html, 'The binding screen must state: ' . $promise );
			}
			self::assertStringNotContainsString( 'Company management account', $html, 'The legacy optional-association heading is gone' );
		}

		public function test_a_bound_connection_names_its_account_and_cannot_be_transferred(): void {
			$html = $this->form( [ 'owner_user_id' => 20 ] );
			self::assertStringContainsString( '#20', $html, 'A bound connection names its account' );
			self::assertStringContainsString( 'cannot be transferred', $html );
			self::assertStringContainsString( 'delivery book', $html, 'The bound account owns the delivery book' );
			self::assertStringNotContainsString( 'name="owner_user_id"', $html, 'A bound connection offers no rebinding control' );
			self::assertStringNotContainsString( 'value="pow_associate_partner"', $html );
		}

		public function test_the_connection_screens_offer_no_exit_policy_or_buyer_restriction_control(): void {
			foreach ( [ $this->screen(), $this->form(), $this->form( [ 'owner_user_id' => 20 ] ) ] as $html ) {
				foreach ( [ 'name="exit_policy"', 'Checkout access', 'Buyer restrictions', 'PunchOut and checkout' ] as $control ) {
					self::assertStringNotContainsString( $control, $html, 'The connection screens must not offer: ' . $control );
				}
			}
		}

		public function test_the_customers_tab_states_that_a_connection_needs_a_bound_account(): void {
			$html = $this->screen();
			self::assertStringContainsString( 'POST /punchout/setup', $html );
			self::assertStringContainsString( 'one WooCommerce customer account bound', $html );
			self::assertStringContainsString( 'cXML Status 500', $html );
			self::assertStringNotContainsString( 'and entitlements', $html, 'Nothing on a connection is an entitlement any more' );
		}

		/* -----------------------------------------------------------------
		 * The global notice
		 * -------------------------------------------------------------- */

		public function test_an_unbound_connection_raises_the_notice_on_every_admin_screen(): void {
			$html = $this->notice();
			self::assertStringContainsString( 'Example Buyer Company', $html );
			self::assertStringContainsString( 'cXML Status 500', $html );
			self::assertStringContainsString( 'notice-error', $html );
			self::assertStringContainsString( 'action=edit', $html, 'The notice links to the connection it names' );
			self::assertStringContainsString( 'partner=20', $html );
		}

		/**
		 * The notice is deliberately unscoped: it consults no screen at all.
		 * get_current_screen() is not stubbed in this suite, so a scoped
		 * implementation could not even render here.
		 */
		public function test_the_notice_is_silent_once_the_connection_is_bound(): void {
			$this->db->rows = [ $this->row( [ 'owner_user_id' => 20 ] ) ];
			self::assertSame( '', $this->notice() );
		}

		public function test_the_notice_flags_a_bound_account_that_cannot_sign_buyers_in(): void {
			foreach ( [ 99 => 'deleted', 22 => 'cannot read', 23 => 'privileged' ] as $owner => $case ) {
				$this->db->rows = [ $this->row( [ 'owner_user_id' => $owner ] ) ];
				$html           = $this->notice();
				self::assertStringContainsString( 'Example Buyer Company', $html, 'An unusable account must be named: ' . $case );
				self::assertStringContainsString( 'cXML Status 500', $html, $case );
				self::assertStringContainsString( 'cannot be transferred', $html, 'The operator must be told rebinding is not the fix: ' . $case );
			}
		}

		public function test_the_notice_only_speaks_for_a_connection_that_would_accept_traffic(): void {
			foreach ( [ 'pending', 'disabled' ] as $status ) {
				$this->db->rows = [ $this->row( [ 'status' => $status ] ) ];
				self::assertSame( '', $this->notice(), 'A ' . $status . ' connection refuses setup for its own reason' );
			}
		}

		public function test_the_notice_is_silent_for_a_user_who_cannot_see_the_plugins_screens(): void {
			$GLOBALS['pow_test_current_user_id'] = 20;
			self::assertSame( '', $this->notice() );
		}

		public function test_an_unreadable_registry_cannot_white_screen_wp_admin(): void {
			$GLOBALS['wpdb'] = new UnreadableRegistryDatabase( $this->db );
			self::assertSame( '', $this->notice() );
		}

		public function test_every_unbound_connection_gets_its_own_notice(): void {
			$this->db->rows = [ $this->row(), $this->row( [ 'id' => 21, 'name' => 'Second Company' ] ), $this->row( [ 'id' => 22, 'name' => 'Bound Company', 'owner_user_id' => 20 ] ) ];
			$html           = $this->notice();
			self::assertSame( 2, substr_count( $html, 'notice-error' ) );
			self::assertStringContainsString( 'Second Company', $html );
			self::assertStringNotContainsString( 'Bound Company', $html );
		}

		/**
		 * The registration is source-checked because boot() cannot be run
		 * against this suite's boundaries; the behaviour above is what the
		 * hook calls. The screen test is the point of the notice: unlike
		 * Plugin::render_key_notice(), nothing here consults the current
		 * screen, so the warning reaches an operator wherever they are.
		 */
		public function test_the_notice_is_registered_for_every_admin_screen(): void {
			self::assertTrue( method_exists( Page::class, 'render_unbound_notice' ) );
			$plugin = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/Plugin.php' );
			self::assertSame( 1, substr_count( $plugin, "add_action( 'admin_notices', [ \$admin_page, 'render_unbound_notice' ] );" ), 'Plugin::boot() must hook the notice beside the sealing-key one' );
			self::assertStringNotContainsString( 'get_current_screen', (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/Admin/Page.php' ), 'The unbound notice must not be scoped to a screen' );
		}

		/* -----------------------------------------------------------------
		 * The binding itself
		 * -------------------------------------------------------------- */

		public function test_binding_a_usable_account_is_confirmed_and_then_visible(): void {
			self::assertTrue( $this->registry->associate_owner( 20, 20 ) );
			self::assertSame( 20, $this->registry->find( 20 )->owner_user_id );
			$html = $this->screen( [ 'action' => 'edit', 'partner' => '20' ] );
			self::assertStringContainsString( '#20', $html );
			self::assertStringNotContainsString( 'name="owner_user_id"', $html );
			self::assertSame( '', $this->notice(), 'A bound connection no longer warns' );
		}

		public function test_binding_refuses_every_account_the_screen_promises_to_refuse(): void {
			foreach ( [ 99 => 'no such account', 22 => 'cannot read the shop', 23 => 'holds an administrative capability' ] as $owner => $case ) {
				$this->db->rows = [ $this->row() ];
				self::assertFalse( $this->registry->associate_owner( 20, $owner ), 'Binding must refuse an account that: ' . $case );
				self::assertSame( 0, $this->registry->find( 20 )->owner_user_id, $case );
				self::assertSame( [], $this->db->writes, 'A refused binding must not touch storage: ' . $case );
			}
		}

		public function test_binding_refuses_an_account_that_already_owns_another_connection(): void {
			$this->db->rows = [ $this->row(), $this->row( [ 'id' => 21, 'name' => 'Other Company', 'owner_user_id' => 20 ] ) ];
			self::assertFalse( $this->registry->associate_owner( 20, 20 ) );
			self::assertSame( 0, $this->registry->find( 20 )->owner_user_id );
			self::assertSame( [], $this->db->writes );
		}

		public function test_a_binding_cannot_be_transferred_or_cleared(): void {
			$this->db->rows = [ $this->row( [ 'owner_user_id' => 20 ] ) ];
			self::assertFalse( $this->registry->associate_owner( 20, 21 ), 'A bound connection cannot be rebound' );
			self::assertFalse( $this->registry->associate_owner( 20, 0 ), 'A binding cannot be cleared' );
			self::assertSame( 20, $this->registry->find( 20 )->owner_user_id );
			self::assertSame( [], $this->db->writes );
		}
	}
}
