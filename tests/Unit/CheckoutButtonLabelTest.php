<?php
/**
 * The cart's way to checkout in a visit (0.5.0) is labelled by the site: "Checkout button label", a plugin setting
 * with a neutral default ("Proceed to checkout"), resolved like every other button label: a non-blank saved value
 * wins, the filter runs last, and a blank result keeps the default.
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

	final class CheckoutButtonLabelTest extends TestCase {
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

		private function label( array $saved ): string {
			$GLOBALS['pow_test_options'][ Settings::OPTION_KEY ] = $saved;
			return ( new Settings() )->checkout_button_label();
		}

		public function test_the_default_is_neutral(): void {
			self::assertSame( 'Proceed to checkout', $this->label( [] ) );
			self::assertSame( 'Proceed to checkout', $this->label( [ 'checkout_button_label' => '   ' ] ) );
		}

		public function test_a_saved_label_wins(): void {
			self::assertSame( 'Review', $this->label( [ 'checkout_button_label' => ' Review ' ] ) );
		}

		public function test_the_filter_runs_last_and_a_blank_result_keeps_the_default(): void {
			$GLOBALS['pow_test_filters'][ Settings::CHECKOUT_BUTTON_FILTER ] = static fn( string $label ): string => $label . ' order';
			self::assertSame( 'Review order', $this->label( [ 'checkout_button_label' => 'Review' ] ) );
			$GLOBALS['pow_test_filters'][ Settings::CHECKOUT_BUTTON_FILTER ] = static fn(): string => '';
			self::assertSame( 'Proceed to checkout', $this->label( [ 'checkout_button_label' => 'Review' ] ), 'A button is never drawn empty' );
			self::assertSame( 'punchout_checkout_button_label', Settings::CHECKOUT_BUTTON_FILTER );
		}

		public function test_the_settings_screen_offers_and_saves_it(): void {
			$admin = ( new ReflectionClass( POW\Admin\Page::class ) )->newInstanceWithoutConstructor();
			( new ReflectionProperty( $admin, 'settings' ) )->setValue( $admin, new Settings() );
			self::assertSame( 'Review', $admin->sanitize_settings( [ 'checkout_button_label' => ' <b>Review</b> ' ] )['checkout_button_label'] );
			self::assertSame( '', $admin->sanitize_settings( [] )['checkout_button_label'] );
			$GLOBALS['pow_test_settings_fields'] = [];
			$admin->register_settings();
			self::assertSame( 'text', $GLOBALS['pow_test_settings_fields']['pow_checkout_button_label']['type'] ?? null );
			self::assertStringContainsString( '“Proceed to checkout”', $GLOBALS['pow_test_settings_fields']['pow_checkout_button_label']['help'], 'The help names the default' );
		}
	}
}
