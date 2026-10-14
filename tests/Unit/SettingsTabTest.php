<?php
/**
 * 0.5.3: every PunchOut screen lives under WooCommerce > Settings, in its own "PunchOut" tab beside Payments, with its
 * four screens (Settings, Customers, Log, Integration docs) as WooCommerce sections; the PunchOut payment method's own
 * settings link to it; the old WooCommerce > PunchOut menu is gone and its addresses land on the new tab. Every form,
 * nonce, capability and admin-post action is unchanged.
 *
 * @package POW
 */

declare( strict_types = 1 );

require_once dirname( __DIR__ ) . '/Admin/doubles.php';

use PHPUnit\Framework\TestCase;
use POW\Admin\Page;

final class SettingsTabTest extends TestCase {
	private array $saved = [];

	protected function setUp(): void {
		foreach ( [ 'pow_admin_test', 'pow_test_current_user_id', 'pow_test_users', 'hide_save_button', '_GET', '_SERVER' ] as $key ) {
			$this->saved[ $key ] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null ];
			unset( $GLOBALS[ $key ] );
		}
		$GLOBALS['pow_admin_test']           = [ 'headers' => [] ];
		$GLOBALS['pow_test_current_user_id'] = 1;
		$GLOBALS['pow_test_users']           = [
			1 => (object) [ 'ID' => 1, 'roles' => [ 'shop_manager' ], 'allcaps' => [ 'manage_woocommerce' => true, 'read' => true ] ],
			7 => (object) [ 'ID' => 7, 'roles' => [ 'customer' ], 'allcaps' => [ 'read' => true ] ],
		];
		$_GET    = [];
		$_SERVER = [ 'REQUEST_METHOD' => 'GET' ];
	}

	protected function tearDown(): void {
		foreach ( $this->saved as $key => [ $existed, $value ] ) {
			if ( $existed ) { $GLOBALS[ $key ] = $value; } else { unset( $GLOBALS[ $key ] ); }
		}
	}

	private function page(): Page {
		$page = ( new ReflectionClass( Page::class ) )->newInstanceWithoutConstructor();
		( new ReflectionProperty( $page, 'settings' ) )->setValue( $page, new POW\Settings() );
		return $page;
	}

	/** Where the old address lands, or null when the request is left to WordPress. */
	private function legacy( array $query, int $user = 1 ): ?string {
		$_GET = $query;
		$GLOBALS['pow_test_current_user_id'] = $user;
		try {
			$this->page()->redirect_legacy_page();
			return null;
		} catch ( AdminResponse $response ) {
			return (string) ( $response->args['redirect'] ?? '' );
		}
	}

	public function test_the_screens_register_as_a_woocommerce_settings_tab_and_no_menu(): void {
		$this->page()->register();
		$hooks   = array_keys( $GLOBALS['pow_admin_test']['hooks'] ?? [] );
		$filters = array_keys( $GLOBALS['pow_admin_test']['filters'] ?? [] );
		foreach ( [ 'woocommerce_sections_punchout', 'woocommerce_settings_punchout', 'woocommerce_after_settings_punchout', 'admin_page_access_denied', 'admin_init' ] as $hook ) {
			self::assertContains( $hook, $hooks, 'Registered: ' . $hook );
		}
		self::assertNotContains( 'admin_menu', $hooks, 'No WooCommerce > PunchOut menu item any more' );
		self::assertContains( 'woocommerce_settings_tabs_array', $filters );
		self::assertContains( 'option_page_capability_pow_settings_group', $filters, 'The settings form keeps its capability' );

		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/Admin/Page.php' );
		self::assertStringNotContainsString( 'add_submenu_page', $source );
		self::assertStringNotContainsString( 'add_menu_page', $source );
	}

	public function test_the_tab_sits_beside_payments(): void {
		$tabs = $this->page()->add_tab( [ 'general' => 'General', 'products' => 'Products', 'checkout' => 'Payments', 'account' => 'Accounts &amp; Privacy', 'advanced' => 'Advanced' ] );
		self::assertSame( [ 'general', 'products', 'checkout', 'punchout', 'account', 'advanced' ], array_keys( $tabs ) );
		self::assertSame( 'PunchOut', $tabs['punchout'] );
		self::assertSame( [ 'general', 'punchout' ], array_keys( $this->page()->add_tab( [ 'general' => 'General' ] ) ), 'Without Payments it goes last' );
		self::assertSame( 'not tabs', $this->page()->add_tab( 'not tabs' ), 'Anything else passes untouched' );
	}

	public function test_every_screen_has_an_address_in_the_tab(): void {
		self::assertSame( 'https://shop.example.test/wp-admin/admin.php?page=wc-settings&tab=punchout', Page::url() );
		self::assertSame( Page::url(), Page::url( 'settings' ), 'Settings is the tab\'s first section' );
		self::assertSame( 'https://shop.example.test/wp-admin/admin.php?page=wc-settings&tab=punchout&section=partners&action=edit&partner=3', Page::url( 'partners', [ 'action' => 'edit', 'partner' => 3 ] ) );
		self::assertSame( 'https://shop.example.test/wp-admin/admin.php?page=wc-settings&tab=punchout&section=log&event=setup_ok&paged=2', Page::url( 'log', [ 'event' => 'setup_ok', 'paged' => 2 ] ) );
	}

	public function test_an_old_address_lands_on_the_same_screen_in_the_new_tab(): void {
		self::assertSame( Page::url(), $this->legacy( [ 'page' => 'punchout-woocommerce' ] ) );
		self::assertSame( Page::url(), $this->legacy( [ 'page' => 'punchout-woocommerce', 'tab' => 'settings' ] ) );
		self::assertSame( Page::url( 'partners', [ 'action' => 'edit', 'partner' => '3' ] ), $this->legacy( [ 'page' => 'punchout-woocommerce', 'tab' => 'partners', 'action' => 'edit', 'partner' => '3' ] ) );
		self::assertSame( Page::url( 'log', [ 'partner' => '3', 'event' => 'setup_ok', 'paged' => '2' ] ), $this->legacy( [ 'page' => 'punchout-woocommerce', 'tab' => 'log', 'partner' => '3', 'event' => 'setup_ok', 'paged' => '2' ] ) );
		self::assertSame( Page::url( 'docs' ), $this->legacy( [ 'page' => 'punchout-woocommerce', 'tab' => 'docs', 'odd' => [ 'array' ] ] ), 'Only plain values are carried over' );
		self::assertSame( Page::url(), $this->legacy( [ 'page' => 'punchout-woocommerce', 'tab' => 'no-such-tab' ] ), 'An unknown tab lands on Settings' );
		self::assertNull( $this->legacy( [ 'page' => 'punchout-woocommerce' ], 7 ), 'Without the capability WordPress refuses the page as before' );
		self::assertNull( $this->legacy( [ 'page' => 'another-plugin' ] ), 'Another page is left alone' );
	}

	public function test_the_four_screens_are_the_tabs_sections(): void {
		$_GET = [ 'page' => 'wc-settings', 'tab' => 'punchout', 'section' => 'log' ];
		ob_start();
		$this->page()->render_sections();
		$html = (string) ob_get_clean();
		self::assertStringContainsString( '<ul class="subsubsub">', $html, 'WooCommerce\'s own section list' );
		foreach ( [ 'Settings' => Page::url(), 'Customers' => Page::url( 'partners' ), 'Log' => Page::url( 'log' ), 'Integration docs' => Page::url( 'docs' ) ] as $label => $url ) {
			self::assertStringContainsString( esc_url( $url ), $html, $label );
			self::assertStringContainsString( '>' . $label . '</a>', $html );
		}
		self::assertMatchesRegularExpression( '#section=log" class="current">Log</a>#', $html, 'The open section is marked' );
		self::assertSame( 1, substr_count( $html, 'class="current"' ) );
	}

	public function test_woocommerces_own_save_button_is_hidden_and_the_screens_render_after_its_form(): void {
		$this->page()->hide_save_button();
		self::assertTrue( $GLOBALS['hide_save_button'], 'Each screen keeps its own forms and buttons' );
		$this->page()->register();
		self::assertSame( [ $this->page()::class, 'render' ], [ get_class( $GLOBALS['pow_admin_test']['hooks']['woocommerce_after_settings_punchout'][0] ), $GLOBALS['pow_admin_test']['hooks']['woocommerce_after_settings_punchout'][1] ], 'Rendered after WooCommerce closes its settings form, so no form is nested' );
	}

	public function test_the_tab_is_recognised_as_this_plugins_screen(): void {
		$_GET = [ 'page' => 'wc-settings', 'tab' => 'punchout', 'section' => 'partners' ];
		self::assertTrue( Page::is_screen() );
		foreach ( [ [ 'page' => 'wc-settings', 'tab' => 'checkout' ], [ 'page' => 'wc-settings' ], [ 'page' => 'punchout-woocommerce' ], [] ] as $query ) {
			$_GET = $query;
			self::assertFalse( Page::is_screen(), json_encode( $query ) );
		}
	}

	public function test_the_payment_method_links_to_the_tab(): void {
		$plugin  = ( new ReflectionClass( POW\Plugin::class ) )->newInstanceWithoutConstructor();
		$gateway = new POW\Checkout\Gateway( new POW\Checkout\PaymentMethod( $plugin, new POW\Partners\Registry( new POW\Partners\Secrets( str_repeat( 'f', 32 ) ) ) ) );
		$field   = $gateway->form_fields['punchout_settings'] ?? [];
		self::assertSame( 'title', $field['type'] ?? null, 'A heading WooCommerce draws and never saves' );
		self::assertStringContainsString( 'href="' . esc_url( Page::url() ) . '"', (string) ( $field['description'] ?? '' ) );
		self::assertStringContainsString( 'WooCommerce → Settings → PunchOut', (string) ( $field['description'] ?? '' ) );
		self::assertSame( 'enabled', array_key_first( $gateway->form_fields ), 'The switch stays first' );
		self::assertStringContainsString( 'Settings → PunchOut', $gateway->method_description, 'The method\'s description says where the rest lives' );
	}

	/** The release: 0.5.3 everywhere, with a changelog entry naming what moved and the four closed limits. */
	public function test_the_release_is_0_5_3_everywhere_and_the_changelog_says_what_changed(): void {
		$root   = dirname( __DIR__, 2 );
		$main   = (string) file_get_contents( $root . '/punchout-woocommerce.php' );
		$readme = (string) file_get_contents( $root . '/readme.txt' );
		self::assertMatchesRegularExpression( '/^ \* Version:\s+0\.5\.3$/m', $main );
		self::assertMatchesRegularExpression( "/^const VERSION\s+= '0\.5\.3';$/m", $main );
		self::assertMatchesRegularExpression( '/^Stable tag: 0\.5\.3$/m', $readme );
		$changelog = substr( $readme, (int) strpos( $readme, '== Changelog ==' ) );
		self::assertMatchesRegularExpression( '/\A== Changelog ==\s+= 0\.5\.3 =/', $changelog, 'The newest changelog entry is 0.5.3' );
		$entry = substr( $changelog, 0, (int) strpos( $changelog, '= 0.5.2 =' ) );
		foreach ( [ 'WooCommerce → Settings → PunchOut', 'Payments', 'post_type=product', 'Pages a visit may also open', 'dashboard', 'GET' ] as $named ) {
			self::assertStringContainsString( $named, $entry, 'The 0.5.3 changelog names ' . $named );
		}
		foreach ( [ 'README.md', 'docs/getting-started.md', 'docs/README.md' ] as $doc ) {
			$text = (string) file_get_contents( $root . '/' . $doc );
			self::assertStringContainsString( 'WooCommerce → Settings → PunchOut', $text, $doc . ' names the new place' );
			self::assertDoesNotMatchRegularExpression( '/WooCommerce (?:>|→|▸|&gt;) PunchOut/', $text, $doc . ' no longer names the old menu' );
		}
	}
}
