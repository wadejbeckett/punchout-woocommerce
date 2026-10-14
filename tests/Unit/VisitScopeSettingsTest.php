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
	if ( ! function_exists( 'add_settings_error' ) ) { function add_settings_error( string $setting, string $code, string $message, string $type = 'error' ): void { $GLOBALS['pow_test_settings_errors'][] = [ $setting, $code, $message, $type ]; } }
	if ( ! function_exists( 'add_settings_field' ) ) { function add_settings_field( string $id, string $title, mixed $callback, string $page, string $section = 'default', array $args = [] ): void { $GLOBALS['pow_test_settings_fields'][ $id ] = $args; } }

	use PHPUnit\Framework\TestCase;
	use POW\Settings;

	final class VisitScopeSettingsTest extends TestCase {
		private array $saved = [];

		protected function setUp(): void {
			foreach ( [ 'pow_test_filters', 'pow_test_options', 'pow_test_settings_fields', 'pow_test_posts', 'pow_test_settings_errors' ] as $key ) {
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
			$saved = $admin->sanitize_settings( [ 'visit_pages' => 'shop', 'visit_open_pages' => ' 345, 12 12,abc -4 0 346' ] );
			self::assertSame( 'shop', $saved['visit_pages'] );
			self::assertSame( '345,12,346', $saved['visit_open_pages'], 'Positive whole IDs, each once, in the order given' );
			$empty = $admin->sanitize_settings( [ 'visit_pages' => 'SHOP', 'visit_open_pages' => [ '5' ] ] );
			self::assertSame( 'all', $empty['visit_pages'], 'Anything else saves as the default' );
			self::assertSame( '5', $empty['visit_open_pages'] );
			self::assertSame( [ 'all', '' ], [ $admin->sanitize_settings( [] )['visit_pages'], $admin->sanitize_settings( [] )['visit_open_pages'] ] );
		}

		/** 0.5.3: "Pages a visit may also open" says what a save dropped, and which kept page cannot open yet. */
		public function test_the_pages_list_reports_what_a_save_dropped_and_what_cannot_open_yet(): void {
			$GLOBALS['pow_test_posts'] = [
				12 => [ 'status' => 'publish' ],
				40 => [ 'status' => 'draft' ],
				41 => [ 'status' => 'private' ],
			];
			$GLOBALS['pow_test_settings_errors'] = [];
			$admin = $this->admin();
			$saved = $admin->sanitize_settings( [ 'visit_pages' => 'shop', 'visit_open_pages' => '12, abc, 40, -4, 0, 99999, 12 ,41' ] );
			self::assertSame( '12,40,99999,41', $saved['visit_open_pages'], 'Every whole ID is kept, in the order given, as before: only what is not an ID is dropped' );
			$errors = $GLOBALS['pow_test_settings_errors'];
			self::assertCount( 1, $errors, 'One notice for the field' );
			[ $setting, $code, $message, $type ] = $errors[0];
			self::assertSame( [ Settings::OPTION_KEY, 'pow_visit_open_pages', 'warning' ], [ $setting, $code, $type ] );
			self::assertStringContainsString( 'Pages a visit may also open', $message, 'It names the field' );
			foreach ( [ '“abc”', '“-4”', '“0”' ] as $dropped ) { self::assertStringContainsString( $dropped, $message, 'It names what is not a page ID: ' . $dropped ); }
			self::assertStringContainsString( 'dropped', $message );
			self::assertMatchesRegularExpression( '/\\b99999\\b/', $message, 'It names a kept ID with no page or post behind it' );
			self::assertMatchesRegularExpression( '/\\b40\\b/', $message, 'and kept pages that are not published' );
			self::assertMatchesRegularExpression( '/\\b41\\b/', $message );
			self::assertStringContainsString( 'cannot open', $message, 'saying a visit cannot open them yet' );
			self::assertDoesNotMatchRegularExpression( '/\\b12\\b/', $message, 'A published page is not mentioned' );

			$GLOBALS['pow_test_settings_errors'] = [];
			$this->admin()->sanitize_settings( [ 'visit_pages' => 'shop', 'visit_open_pages' => '12,<img/src=x/onerror=alert(1)>' ] );
			self::assertStringNotContainsString( '<img', $GLOBALS['pow_test_settings_errors'][0][2] ?? '', 'WordPress prints the notice as HTML: what was typed is escaped' );
			self::assertStringContainsString( '&lt;img', $GLOBALS['pow_test_settings_errors'][0][2] ?? '' );
			$GLOBALS['pow_test_settings_errors'] = [ $errors[0] ];
			$admin->sanitize_settings( [ 'visit_pages' => 'shop', 'visit_open_pages' => '12, abc' ] );
			self::assertCount( 1, $GLOBALS['pow_test_settings_errors'], 'WordPress may sanitise a new option twice in one save: the notice is added once' );

			$GLOBALS['pow_test_settings_errors'] = [];
			$this->admin()->sanitize_settings( [ 'visit_pages' => 'shop', 'visit_open_pages' => ' 12 ,12' ] );
			$this->admin()->sanitize_settings( [ 'visit_pages' => 'all', 'visit_open_pages' => '' ] );
			self::assertSame( [], $GLOBALS['pow_test_settings_errors'], 'A clean list says nothing' );
			unset( $GLOBALS['pow_test_posts'], $GLOBALS['pow_test_settings_errors'] );
		}

		public function test_the_list_reader_names_the_entries_it_cannot_read(): void {
			self::assertSame( [ 'ids' => [ 12, 345, 7 ], 'rejected' => [ 'abc', '-3', '0', '1.5' ] ], Settings::page_id_entries( ' 12, 345 ,, 7 12 abc -3 0 1.5 abc' ) );
			self::assertSame( [ 'ids' => [ 4, 5 ], 'rejected' => [ 'x' ] ], Settings::page_id_entries( [ '4', 5, 'x', 4 ] ) );
			self::assertSame( [ 'ids' => [], 'rejected' => [] ], Settings::page_id_entries( null ) );
			self::assertSame( [ 12, 345, 7 ], Settings::page_ids( ' 12, 345 ,, 7 12 abc -3 0' ), 'page_ids() is the IDs alone, as before' );
		}

		/** The 0.5.2 changelog entry names both settings and the filter (the release itself is checked in SettingsTabTest). */
		public function test_the_0_5_2_changelog_names_the_new_settings(): void {
			$root      = dirname( __DIR__, 2 );
			$readme    = (string) file_get_contents( $root . '/readme.txt' );
			$changelog = substr( $readme, (int) strpos( $readme, '= 0.5.2 =' ) );
			self::assertStringStartsWith( '= 0.5.2 =', $changelog );
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
