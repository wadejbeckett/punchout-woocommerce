<?php
/**
 * The 0.5.2 settings: who sees the My Account "Punchout integration" tab, which pages outside the shop a visit may
 * open, and the pages it may open besides. Behaviour settings with neutral defaults: the tab goes to the accounts
 * bound to a connection (the self-service flow it was built for is gone), and every page still opens in a visit
 * unless the store chooses shop pages only, so an upgrade changes no visit.
 *
 * @package POW
 */

declare( strict_types = 1 );

namespace {
	if ( ! function_exists( 'register_setting' ) ) { function register_setting( string $group, string $name, array $args = [] ): void {} }
	if ( ! function_exists( 'add_settings_section' ) ) { function add_settings_section( string $id, string $title, mixed $callback, string $page ): void {} }
	if ( ! function_exists( 'add_settings_field' ) ) { function add_settings_field( string $id, string $title, mixed $callback, string $page, string $section = 'default', array $args = [] ): void { $GLOBALS['pow_test_settings_fields'][ $id ] = $args; } }

	use PHPUnit\Framework\TestCase;
	use POW\Settings;

	final class VisitScopeSettingsTest extends TestCase {
		private array $saved = [];

		protected function setUp(): void {
			foreach ( [ 'pow_test_filters', 'pow_test_options', 'pow_test_settings_fields' ] as $key ) {
				$this->saved[ $key ] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null ];
				unset( $GLOBALS[ $key ] );
			}
		}

		protected function tearDown(): void {
			foreach ( $this->saved as $key => [ $existed, $value ] ) {
				if ( $existed ) { $GLOBALS[ $key ] = $value; } else { unset( $GLOBALS[ $key ] ); }
			}
		}

		private function admin(): POW\Admin\Page {
			$admin = ( new ReflectionClass( POW\Admin\Page::class ) )->newInstanceWithoutConstructor();
			( new ReflectionProperty( $admin, 'settings' ) )->setValue( $admin, new Settings() );
			return $admin;
		}

		/** @return array<string, array<string, mixed>> */
		private function fields(): array {
			$GLOBALS['pow_test_settings_fields'] = [];
			$this->admin()->register_settings();
			return $GLOBALS['pow_test_settings_fields'];
		}

		public function test_the_defaults_are_neutral(): void {
			$settings = new Settings();
			self::assertSame( 'owners', $settings->account_tab(), 'The tab goes to the accounts bound to a connection' );
			self::assertSame( 'all', $settings->visit_pages(), 'Every page opens in a visit, as in 0.5.1' );
			self::assertSame( [], $settings->visit_open_pages() );
			self::assertSame( [ 'owners', 'everyone', 'off' ], Settings::ACCOUNT_TAB_MODES );
			self::assertSame( [ 'all', 'shop' ], Settings::VISIT_PAGE_MODES );
		}

		public function test_the_settings_screen_offers_the_tab_choice_and_saves_only_a_known_value(): void {
			$fields = $this->fields();
			$tab    = $fields['pow_account_tab'] ?? [];
			self::assertSame( 'select', $tab['type'] ?? null );
			self::assertSame( [ 'owners', 'everyone', 'off' ], array_keys( $tab['options'] ?? [] ) );
			self::assertSame( 'Accounts bound to a connection (default)', $tab['options']['owners'] );
			self::assertStringContainsString( 'Every signed-in account', $tab['options']['everyone'] );
			self::assertStringContainsString( 'Nobody', $tab['options']['off'] );
			self::assertStringContainsString( 'Integration docs', $tab['help'], 'The help says where the setup XML is then' );

			$admin = $this->admin();
			foreach ( [ 'owners', 'everyone', 'off' ] as $mode ) { self::assertSame( $mode, $admin->sanitize_settings( [ 'account_tab' => $mode ] )['account_tab'] ); }
			foreach ( [ null, '', 'Everyone', 'all', [ 'off' ] ] as $bad ) {
				self::assertSame( 'owners', $admin->sanitize_settings( null === $bad ? [] : [ 'account_tab' => $bad ] )['account_tab'], var_export( $bad, true ) );
			}
		}

		public function test_the_settings_screen_offers_the_visit_pages_choice_beside_the_landing_page(): void {
			$fields = $this->fields();
			$pages  = $fields['pow_visit_pages'] ?? [];
			self::assertSame( 'select', $pages['type'] ?? null );
			self::assertSame( [ 'all', 'shop' ], array_keys( $pages['options'] ?? [] ) );
			self::assertSame( 'Every page (default)', $pages['options']['all'] );
			self::assertSame( 'Shop pages only, plus the pages listed', $pages['options']['shop'] );
			foreach ( [ 'landing page', 'cart', 'checkout', 'state page' ] as $named ) { self::assertStringContainsString( $named, $pages['help'], 'The help names what stays open: ' . $named ); }
			$open = $fields['pow_visit_open_pages'] ?? [];
			self::assertSame( 'text', $open['type'] ?? null );
			self::assertStringContainsString( 'page IDs', $open['help'] );
			$keys = array_keys( $fields );
			self::assertSame( array_search( 'pow_landing_page_id', $keys, true ) + 1, array_search( 'pow_visit_pages', $keys, true ), 'Next to the landing page, where a refused page sends the buyer' );
			self::assertSame( array_search( 'pow_visit_pages', $keys, true ) + 1, array_search( 'pow_visit_open_pages', $keys, true ) );
		}

		public function test_the_visit_pages_settings_save_clean(): void {
			$admin = $this->admin();
			$saved = $admin->sanitize_settings( [ 'visit_pages' => 'shop', 'visit_open_pages' => ' 53061, 12 12,abc -4 0 53062' ] );
			self::assertSame( 'shop', $saved['visit_pages'] );
			self::assertSame( '53061,12,53062', $saved['visit_open_pages'], 'Positive whole IDs, each once, in the order given' );
			$empty = $admin->sanitize_settings( [ 'visit_pages' => 'SHOP', 'visit_open_pages' => [ '5' ] ] );
			self::assertSame( 'all', $empty['visit_pages'], 'Anything else saves as the default' );
			self::assertSame( '5', $empty['visit_open_pages'] );
			self::assertSame( [ 'all', '' ], [ $admin->sanitize_settings( [] )['visit_pages'], $admin->sanitize_settings( [] )['visit_open_pages'] ] );
		}

		/** The release: 0.5.2 everywhere, with a changelog entry naming both settings and the filter. */
		public function test_the_release_is_0_5_2_everywhere_and_the_changelog_names_the_new_settings(): void {
			$root   = dirname( __DIR__, 2 );
			$main   = (string) file_get_contents( $root . '/punchout-woocommerce.php' );
			$readme = (string) file_get_contents( $root . '/readme.txt' );
			self::assertMatchesRegularExpression( '/^ \* Version:\s+0\.5\.2$/m', $main );
			self::assertMatchesRegularExpression( "/^const VERSION\s+= '0\.5\.2';$/m", $main );
			self::assertMatchesRegularExpression( '/^Stable tag: 0\.5\.2$/m', $readme );
			$changelog = substr( $readme, (int) strpos( $readme, '== Changelog ==' ) );
			self::assertMatchesRegularExpression( '/\A== Changelog ==\s+= 0\.5\.2 =/', $changelog, 'The newest changelog entry is 0.5.2' );
			$entry = substr( $changelog, 0, (int) strpos( $changelog, '= 0.5.1 =' ) );
			foreach ( [ '`account_tab`', '`visit_pages`', '`visit_open_pages`', 'punchout_visit_page_open', 'Punchout integration tab in My Account', 'Other pages in a visit', 'Pages a visit may also open' ] as $named ) {
				self::assertStringContainsString( $named, $entry, 'The 0.5.2 changelog names ' . $named );
			}
			$docs = (string) file_get_contents( $root . '/README.md' );
			foreach ( [ 'Punchout integration tab in My Account', 'Other pages in a visit', 'Pages a visit may also open', 'punchout_visit_page_open' ] as $named ) {
				self::assertStringContainsString( $named, $docs, 'README.md documents ' . $named );
			}
		}
	}
}
