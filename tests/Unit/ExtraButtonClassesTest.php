<?php
/**
 * The "Extra button classes" setting: one plugin-wide list of the site's own
 * button classes, normalised on save and again on read, appended to the
 * visit's exit controls and the review page's primary actions before the
 * pow_button_classes filter, which keeps the last word.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Cart {
	// The unit suite has no nonce stub in this namespace; the cart suite has its own.
	if ( ! function_exists( __NAMESPACE__ . '\\wp_create_nonce' ) ) {
		function wp_create_nonce( string $action ): string { return 'nonce-' . $action; }
	}
}

namespace {
	use PHPUnit\Framework\TestCase;
	use POW\Cart\Surface;
	use POW\Sessions\Session;
	use POW\Settings;

	// WordPress core's class sanitiser, as the cart suite stubs it.
	if ( ! function_exists( 'sanitize_html_class' ) ) { function sanitize_html_class( string $class ): string { return preg_replace( '/[^A-Za-z0-9_-]/', '', $class ); } }
	// The Settings API calls register_settings() makes, recorded so the help text can be read. Never defined when WordPress is loaded.
	if ( ! function_exists( 'register_setting' ) ) { function register_setting( string $group, string $name, array $args = [] ): void {} }
	if ( ! function_exists( 'add_settings_section' ) ) { function add_settings_section( string $id, string $title, mixed $callback, string $page ): void {} }
	if ( ! function_exists( 'add_settings_field' ) ) { function add_settings_field( string $id, string $title, mixed $callback, string $page, string $section = 'default', array $args = [] ): void { $GLOBALS['pow_test_settings_fields'][ $id ] = $args; } }

	final class ExtraButtonClassesTest extends TestCase {
		private array $saved = [];

		protected function setUp(): void {
			foreach ( [ 'pow_test_options', 'pow_test_filters' ] as $key ) {
				$this->saved[ $key ] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null ];
				unset( $GLOBALS[ $key ] );
			}
			$GLOBALS['pow_test_filters'] = [];
			$GLOBALS['pow_test_options'] = [ 'pow_settings' => [ 'enabled' => 'yes' ] ];
		}

		protected function tearDown(): void {
			foreach ( $this->saved as $key => [ $exists, $value ] ) { if ( $exists ) { $GLOBALS[ $key ] = $value; } else { unset( $GLOBALS[ $key ] ); } }
		}

		private function extras( string $value ): void { $GLOBALS['pow_test_options']['pow_settings']['extra_button_classes'] = $value; }

		/** A Surface inside one live visit, on the real Settings reader. */
		private function surface( bool $visit = true ): Surface {
			$plugin = ( new ReflectionClass( POW\Plugin::class ) )->newInstanceWithoutConstructor();
			$session = $visit ? Session::from_row( [ 'id' => 81, 'partner_id' => 20, 'user_id' => 7, 'status' => Session::ACTIVE, 'wc_session_key' => 'pow_1a2b3c4d5e6f708192a3b4c5d6e7' ] ) : null;
			( new ReflectionProperty( POW\Plugin::class, 'current_session' ) )->setValue( $plugin, $session );
			( new ReflectionProperty( POW\Plugin::class, 'session_resolved' ) )->setValue( $plugin, true );
			return new Surface( $plugin, new POW\Partners\Registry( new POW\Partners\Secrets( str_repeat( 'f', 32 ) ) ) );
		}

		private static function button_class( string $html ): string {
			self::assertSame( 1, preg_match( '#<button type="submit" class="([^"]*)">#', $html, $m ), $html );
			return $m[1];
		}

		public function test_tokens_are_sanitised_deduplicated_and_never_a_plugin_or_hidden_class(): void {
			self::assertSame( [ 'site-btn', 'big', 'bx' ], Settings::button_class_tokens( 'site-btn  big site-btn pow-x checkout-button <b>x' ) );
			self::assertSame( [], Settings::button_class_tokens( null ) );
			self::assertSame( [], Settings::button_class_tokens( [ 'site-btn' ] ) );
			self::assertSame( [], Settings::button_class_tokens( '   ' ) );
			self::assertSame( [ 'a' ], Settings::button_class_tokens( "POW-return\tcheckout a\ncheckout-button" ) );
			self::assertCount( 20, Settings::button_class_tokens( implode( ' ', array_map( static fn( int $i ): string => 'c' . $i, range( 1, 30 ) ) ) ) );
		}

		/** One list: every class the visit stylesheet hides is refused as an extra class, so no extra class can hide the visit's exit or the review's buttons. */
		public function test_the_reserved_classes_are_exactly_the_ones_the_visit_stylesheet_hides(): void {
			$reserved = Settings::reserved_button_classes();
			self::assertSame( [ 'checkout-button', 'checkout', 'wc-block-mini-cart__footer-checkout' ], $reserved );
			self::assertCount( count( Settings::VISIT_HIDDEN_SELECTORS ), $reserved );
			foreach ( Settings::VISIT_HIDDEN_SELECTORS as $i => $selector ) { self::assertSame( '.' . $reserved[ $i ], substr( $selector, -strlen( '.' . $reserved[ $i ] ) ) ); }
			foreach ( $reserved as $class ) { self::assertSame( [ 'site-btn' ], Settings::button_class_tokens( $class . ' site-btn' ), $class ); }
			// The settings help names them from the same list.
			$saved = $GLOBALS['pow_test_settings_fields'] ?? null; $GLOBALS['pow_test_settings_fields'] = [];
			try {
				( new ReflectionClass( POW\Admin\Page::class ) )->newInstanceWithoutConstructor()->register_settings();
				$help = $GLOBALS['pow_test_settings_fields']['pow_extra_button_classes']['help'];
			} finally { if ( null === $saved ) { unset( $GLOBALS['pow_test_settings_fields'] ); } else { $GLOBALS['pow_test_settings_fields'] = $saved; } }
			foreach ( $reserved as $class ) { self::assertStringContainsString( $class, $help ); }
			self::assertStringContainsString( 'pow-', $help );
		}

		public function test_settings_read_sanitises_again(): void {
			$this->extras( 'site-btn pow-return-button "><script> site-btn' );
			self::assertSame( [ 'site-btn', 'script' ], ( new Settings() )->extra_button_classes() );
			self::assertSame( 'site-btn script', Surface::extra_button_classes() );
			$this->extras( '' );
			self::assertSame( [], ( new Settings() )->extra_button_classes() );
			self::assertSame( '', Surface::extra_button_classes() );
		}

		public function test_saving_the_settings_keeps_the_normalised_key(): void {
			$admin = ( new ReflectionClass( POW\Admin\Page::class ) )->newInstanceWithoutConstructor();
			( new ReflectionProperty( $admin, 'settings' ) )->setValue( $admin, new Settings() );
			self::assertSame( 'site-btn big', $admin->sanitize_settings( [ 'extra_button_classes' => ' site-btn big pow-x site-btn checkout ' ] )['extra_button_classes'] );
			self::assertSame( '', $admin->sanitize_settings( [] )['extra_button_classes'] );
			self::assertSame( '', $admin->sanitize_settings( [ 'extra_button_classes' => [ 'x' ] ] )['extra_button_classes'] );
		}

		public function test_exit_and_abandon_controls_carry_the_extras_before_the_filter(): void {
			$this->extras( 'site-btn big button' );
			$surface = $this->surface();
			self::assertSame( 'button alt wp-element-button pow-return-button site-btn big', self::button_class( $surface->markup() ) );
			self::assertSame( 'pow-abandon-button site-btn big button', self::button_class( $surface->abandon_markup() ) );
			// The filter sees the extras and keeps the last word.
			$seen = [];
			$GLOBALS['pow_test_filters']['pow_button_classes'] = static function ( array $classes, string $base ) use ( &$seen ): array { $seen[ $base ] = $classes; return array_values( array_diff( $classes, [ 'big' ] ) ); };
			self::assertSame( 'button alt wp-element-button pow-return-button site-btn', self::button_class( $surface->markup() ) );
			self::assertSame( 'pow-abandon-button site-btn button', self::button_class( $surface->abandon_markup() ) );
			self::assertSame( [ 'button', 'alt', 'wp-element-button', 'pow-return-button', 'site-btn', 'big' ], $seen['pow-return-button'] );
		}

		public function test_without_extras_the_controls_are_unchanged(): void {
			$surface = $this->surface();
			self::assertSame( 'button alt wp-element-button pow-return-button', self::button_class( $surface->markup() ) );
			self::assertSame( 'pow-abandon-button', self::button_class( $surface->abandon_markup() ) );
		}

		public function test_the_non_visit_checkout_link_gets_no_extras(): void {
			$this->extras( 'site-btn' );
			$classes = ( new ReflectionMethod( Surface::class, 'button_classes' ) )->invoke( $this->surface( false ), 'checkout-button' );
			self::assertSame( 'button alt wp-element-button checkout-button', $classes );
		}

		private function review( array $overrides = [] ): string {
			$view = [ 'choices' => [], 'selected_choice' => null, 'delivery_destination' => null, 'packages' => [], 'delivery' => [ 'status' => 'not_required', 'amount_cents' => null, 'emit' => false ], 'notes' => '', 'currency' => 'ZAR', 'merchandise_total_cents' => 12300, 'total_cents' => 12300, 'items' => [], 'skipped' => [], 'can_confirm' => true, 'requires_unknown_acknowledgement' => false, 'review_digest' => str_repeat( 'a', 64 ), 'error' => null ];
			$vars = array_replace( [ 'view' => $view, 'action_url' => 'https://shop.example.test/punchout/confirm', 'cart_url' => 'https://shop.example.test/cart/', 'nonce' => 'confirmation-nonce', 'return_nonce' => 'return-nonce', 'stylesheet_url' => 'https://shop.example.test/confirmation.css', 'shop_name' => 'Example shop', 'document' => false ], $overrides );
			ob_start();
			try { ( static function ( string $file, array $vars ): void { extract( $vars, EXTR_SKIP ); include $file; } )( dirname( __DIR__, 2 ) . '/templates/delivery-confirmation.php', $vars ); return (string) ob_get_clean(); }
			catch ( Throwable $error ) { ob_end_clean(); throw $error; }
		}

		public function test_review_primary_actions_carry_the_extras_and_back_does_not(): void {
			$this->extras( 'site-btn' );
			$html = $this->review();
			self::assertStringContainsString( 'class="button alt wp-element-button pow-confirmation__submit site-btn"', $html );
			self::assertStringContainsString( 'class="button wp-element-button pow-confirmation__secondary site-btn"', $html );
			self::assertStringContainsString( 'class="pow-confirmation__back" formnovalidate', $html );
			self::assertStringNotContainsString( 'pow-confirmation__back site-btn', $html );
			$add = $this->review( [ 'view' => [ 'choices' => [], 'selected_choice' => null, 'delivery_destination' => null, 'packages' => [], 'delivery' => [ 'status' => 'unknown', 'amount_cents' => null, 'emit' => false ], 'notes' => '', 'currency' => 'ZAR', 'merchandise_total_cents' => 12300, 'total_cents' => 12300, 'items' => [], 'skipped' => [], 'can_confirm' => false, 'requires_unknown_acknowledgement' => false, 'review_digest' => str_repeat( 'a', 64 ), 'error' => null ], 'add_address' => [ 'fields' => '', 'label' => '', 'nonce' => 'n', 'notice' => null, 'open' => false ] ] );
			self::assertStringContainsString( 'class="button wp-element-button pow-confirmation__secondary pow-confirmation__add-submit site-btn"', $add );
			self::assertStringContainsString( 'name="pow_address_refresh" value="1" class="pow-confirmation__back" formnovalidate', $add );
			$this->extras( '' );
			$html = $this->review();
			self::assertStringContainsString( 'class="button alt wp-element-button pow-confirmation__submit"', $html );
			self::assertStringContainsString( 'class="button wp-element-button pow-confirmation__secondary"', $html );
		}
	}
}
