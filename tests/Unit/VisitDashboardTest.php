<?php
/**
 * The dashboard a visit sees: WooCommerce's template is swapped for the
 * plugin's copy inside a visit only, and that copy has no logout link.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) { require_once dirname( __DIR__ ) . '/bootstrap.php'; }

// Enough of WordPress and WooCommerce to render the plugin's dashboard
// template. Each is inert where the real function exists.
if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public function __construct( public int $ID = 0, public string $display_name = '' ) {}
	}
}
if ( ! function_exists( 'wp_kses' ) ) { function wp_kses( string $text, mixed $allowed_html ): string { return $text; } }
if ( ! function_exists( 'wc_shipping_enabled' ) ) { function wc_shipping_enabled(): bool { return true; } }
if ( ! function_exists( 'wc_get_endpoint_url' ) ) { function wc_get_endpoint_url( string $endpoint, string $value = '', string $permalink = '' ): string { return 'https://shop.example.test/my-account/' . $endpoint . '/'; } }
if ( ! function_exists( 'do_action' ) ) { function do_action( string $hook, mixed ...$args ): void { $GLOBALS['pow_visit_dashboard_actions'][] = $hook; } }

/** A session store that cannot answer. */
final class VisitDashboardUnreachableStore extends POW\Sessions\Store {
	public function find_for_login( int $user_id, string $wp_session_token, array $statuses = [ POW\Sessions\Session::ACTIVE ] ): ?POW\Sessions\Session {
		throw new RuntimeException( 'Session lookup failed.' );
	}
}

final class VisitDashboardTest extends PHPUnit\Framework\TestCase {

	private const WOOCOMMERCE = '/srv/www/wp-content/plugins/woocommerce/templates/myaccount/dashboard.php';

	private array $saved = [];

	protected function setUp(): void {
		foreach ( [ 'pow_test_current_user_id', 'pow_test_filters', 'pow_visit_dashboard_actions' ] as $key ) {
			$this->saved[ $key ] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null ];
			unset( $GLOBALS[ $key ] );
		}
		$GLOBALS['pow_test_current_user_id'] = 20;
	}

	protected function tearDown(): void {
		foreach ( $this->saved as $key => [ $exists, $value ] ) {
			if ( $exists ) { $GLOBALS[ $key ] = $value; } else { unset( $GLOBALS[ $key ] ); }
		}
	}

	private function visit(): POW\Sessions\Session {
		return POW\Sessions\Session::from_row( [
			'id'               => 44,
			'partner_id'       => 7,
			'user_id'          => 20,
			'status'           => 'active',
			'wp_session_token' => 'visit-a',
			'wc_session_key'   => 'pow_' . str_repeat( 'a', 28 ),
			'expires'          => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
		] );
	}

	/** The swap on a container whose resolved visit is $visit; with $store, the visit is looked up there instead. */
	private function dashboard( ?POW\Sessions\Session $visit, ?POW\Sessions\Store $store = null ): POW\Account\VisitDashboard {
		$plugin = ( new ReflectionClass( POW\Plugin::class ) )->newInstanceWithoutConstructor();
		foreach ( [ 'sessions' => $store ?? new POW\Sessions\Store(), 'current_session' => $store ? null : $visit, 'session_resolved' => null === $store ] as $key => $value ) {
			( new ReflectionProperty( $plugin, $key ) )->setValue( $plugin, $value );
		}

		return new POW\Account\VisitDashboard( $plugin );
	}

	private function own(): string {
		return POW_PLUGIN_DIR . 'templates/account/dashboard.php';
	}

	public function test_inside_a_visit_the_dashboard_is_the_plugins_own(): void {
		self::assertSame( $this->own(), $this->dashboard( $this->visit() )->template( self::WOOCOMMERCE, 'myaccount/dashboard.php' ) );
		self::assertSame( $this->own(), $this->dashboard( $this->visit() )->template( '/srv/www/wp-content/themes/shop/woocommerce/myaccount/dashboard.php', 'myaccount/dashboard.php' ), 'A theme\'s copy is replaced too: it may carry the same logout link' );
	}

	public function test_outside_a_visit_the_dashboard_is_untouched(): void {
		self::assertSame( self::WOOCOMMERCE, $this->dashboard( null )->template( self::WOOCOMMERCE, 'myaccount/dashboard.php' ), 'The account holder\'s own login keeps WooCommerce\'s dashboard and its logout link' );
	}

	public function test_no_other_template_is_touched_in_a_visit(): void {
		$dashboard = $this->dashboard( $this->visit() );
		foreach ( [ 'myaccount/navigation.php', 'myaccount/my-account.php', 'myaccount/form-edit-account.php', 'cart/cart.php', 'dashboard.php', '' ] as $name ) {
			self::assertSame( '/located/' . $name, $dashboard->template( '/located/' . $name, $name ), $name . ' passes untouched' );
		}
		self::assertNull( $dashboard->template( null, 'myaccount/dashboard.php' ), 'Something that is not a path is passed on' );
		self::assertSame( self::WOOCOMMERCE, $dashboard->template( self::WOOCOMMERCE ), 'A call that does not name the template passes untouched' );
	}

	public function test_a_visit_that_cannot_be_proved_gets_the_plugins_dashboard(): void {
		self::assertSame( $this->own(), $this->dashboard( null, new VisitDashboardUnreachableStore() )->template( self::WOOCOMMERCE, 'myaccount/dashboard.php' ), 'An unanswerable lookup fails closed, like every other door' );

		$GLOBALS['pow_test_current_user_id'] = 0;
		self::assertSame( self::WOOCOMMERCE, $this->dashboard( null, new VisitDashboardUnreachableStore() )->template( self::WOOCOMMERCE, 'myaccount/dashboard.php' ), 'A guest is outside any visit without a lookup' );
	}

	public function test_a_template_that_cannot_be_read_leaves_woocommerces_in_place(): void {
		$GLOBALS['pow_test_filters']['pow_template_account/dashboard'] = static fn( string $file ): string => '/nowhere/dashboard.php';
		self::assertSame( self::WOOCOMMERCE, $this->dashboard( $this->visit() )->template( self::WOOCOMMERCE, 'myaccount/dashboard.php' ), 'WooCommerce renders nothing for a path that does not exist, so the swap never offers one' );

		$GLOBALS['pow_test_filters']['pow_template_account/dashboard'] = static fn( string $file ): string => __FILE__;
		self::assertSame( __FILE__, $this->dashboard( $this->visit() )->template( self::WOOCOMMERCE, 'myaccount/dashboard.php' ), 'The plugin\'s own template filter still chooses the file' );
	}

	/** Logout is a page that never opens in a visit; that is why the dashboard does not offer it either. */
	public function test_logout_never_opens_in_a_visit_and_the_menu_already_drops_it(): void {
		self::assertContains( 'customer-logout', POW\Account\VisitEndpoints::NEVER_OPEN );
		self::assertSame( [ 'dashboard' ], POW\Account\VisitEndpoints::listed( 'dashboard,customer-logout' ), 'A ticked logout is not in the list a visit reads' );
	}

	public function test_the_plugins_dashboard_greets_without_a_logout_link_and_keeps_the_dashboard_hooks(): void {
		$current_user = new WP_User( 20, 'Coca <Cola>' );
		ob_start();
		include $this->own();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Hello <strong>Coca &lt;Cola&gt;</strong>', $html, 'The greeting names the account, escaped' );
		self::assertStringNotContainsString( 'log out', strtolower( $html ) );
		self::assertStringNotContainsString( 'logout', $html );
		self::assertStringContainsString( 'href="https://shop.example.test/my-account/orders/"', $html, 'The rest of WooCommerce\'s dashboard text is kept' );
		self::assertSame( [ 'woocommerce_account_dashboard', 'woocommerce_before_my_account', 'woocommerce_after_my_account' ], $GLOBALS['pow_visit_dashboard_actions'] ?? [], 'Content other plugins add to the dashboard still shows' );

		$source = (string) file_get_contents( $this->own() );
		foreach ( [ 'wc_logout_url', 'wp_logout_url', 'customer-logout', 'Log out' ] as $needle ) {
			self::assertStringNotContainsString( $needle, $source, 'The template never builds a logout link: ' . $needle );
		}
	}

	public function test_the_swap_is_registered_last_on_the_filter_that_runs_every_render(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/Account/VisitDashboard.php' );
		self::assertStringContainsString( "add_filter( 'wc_get_template', [ \$this, 'template' ], PHP_INT_MAX, 2 );", $source );
		self::assertStringNotContainsString( "'woocommerce_locate_template'", $source, 'WooCommerce caches that lookup, so a per-visit answer there could reach another request' );
		self::assertStringNotContainsString( "'logout_url'", $source, 'Logout itself keeps working everywhere' );
	}
}
