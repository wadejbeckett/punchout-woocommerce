<?php
/**
 * The state page and the leave-confirmation are configured by the site (0.5.0). Only button labels are
 * settings: the state page's button, and the confirmation's two buttons. Each resolves like every other button
 * label: a non-blank saved value wins, its filter runs last, and a blank result keeps the neutral default. The page
 * itself is chosen in the settings (none by default: the plain pages are used), and the confirmation is off by default.
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

	final class StateSettingsTest extends TestCase {
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

		private function settings( array $saved = [] ): Settings {
			$GLOBALS['pow_test_options'][ Settings::OPTION_KEY ] = $saved;
			return new Settings();
		}

		public function test_the_defaults_are_neutral_and_the_confirmation_is_off(): void {
			$settings = $this->settings();
			self::assertSame( 'Continue to your purchasing system', $settings->state_button_label() );
			self::assertSame( 'Return without sending', $settings->leave_confirm_label() );
			self::assertSame( 'Keep shopping', $settings->leave_stay_label() );
			self::assertFalse( $settings->leave_confirmation() );
			self::assertSame( 0, $settings->int( 'state_page_id' ), 'No page is chosen: the plain pages are used' );
		}

		public function test_a_saved_label_wins_and_a_blank_one_keeps_the_default(): void {
			$settings = $this->settings( [ 'state_button_label' => ' Continue ', 'leave_confirm_label' => 'Leave', 'leave_stay_label' => '   ', 'leave_confirmation' => 'yes' ] );
			self::assertSame( 'Continue', $settings->state_button_label() );
			self::assertSame( 'Leave', $settings->leave_confirm_label() );
			self::assertSame( 'Keep shopping', $settings->leave_stay_label() );
			self::assertTrue( $settings->leave_confirmation() );
		}

		public function test_each_label_filter_runs_last_and_a_blank_result_keeps_the_default(): void {
			$GLOBALS['pow_test_filters'] = [
				Settings::STATE_BUTTON_FILTER  => static fn( string $label ): string => $label . '!',
				Settings::LEAVE_CONFIRM_FILTER => static fn(): string => ' ',
				Settings::LEAVE_STAY_FILTER    => static fn( string $label ): string => strtoupper( $label ),
			];
			$settings = $this->settings( [ 'state_button_label' => 'Continue' ] );
			self::assertSame( 'Continue!', $settings->state_button_label() );
			self::assertSame( 'Return without sending', $settings->leave_confirm_label(), 'A button is never drawn empty' );
			self::assertSame( 'KEEP SHOPPING', $settings->leave_stay_label() );
			self::assertSame( [ 'punchout_state_button_label', 'punchout_leave_confirm_label', 'punchout_leave_stay_label' ], [ Settings::STATE_BUTTON_FILTER, Settings::LEAVE_CONFIRM_FILTER, Settings::LEAVE_STAY_FILTER ] );
		}

		public function test_the_settings_screen_offers_and_saves_them(): void {
			$admin = ( new ReflectionClass( POW\Admin\Page::class ) )->newInstanceWithoutConstructor();
			( new ReflectionProperty( $admin, 'settings' ) )->setValue( $admin, new Settings() );
			$saved = $admin->sanitize_settings( [ 'state_page_id' => '42', 'state_button_label' => ' <b>Continue</b> ', 'leave_confirmation' => 'yes', 'leave_confirm_label' => 'Leave', 'leave_stay_label' => 'Stay' ] );
			self::assertSame( [ 42, 'Continue', 'yes', 'Leave', 'Stay' ], [ $saved['state_page_id'], $saved['state_button_label'], $saved['leave_confirmation'], $saved['leave_confirm_label'], $saved['leave_stay_label'] ] );
			$empty = $admin->sanitize_settings( [ 'state_page_id' => '-3', 'leave_confirmation' => 'on' ] );
			self::assertSame( [ 0, '', 'no', '', '' ], [ $empty['state_page_id'], $empty['state_button_label'], $empty['leave_confirmation'], $empty['leave_confirm_label'], $empty['leave_stay_label'] ] );

			$GLOBALS['pow_test_settings_fields'] = [];
			$admin->register_settings();
			$fields = $GLOBALS['pow_test_settings_fields'];
			self::assertSame( 'page', $fields['pow_state_page_id']['type'] ?? null );
			self::assertSame( 'None: the plain pages (default)', $fields['pow_state_page_id']['options']['none'] ?? null, 'Not the landing page\'s "Shop page" default' );
			self::assertStringContainsString( '[punchout_state]', $fields['pow_state_page_id']['help'] );
			self::assertSame( 'checkbox', $fields['pow_leave_confirmation']['type'] ?? null );
			self::assertStringContainsString( 'question', $fields['pow_leave_confirmation']['help'], 'The question is the shortcode attribute, not a setting' );
			foreach ( [ 'pow_state_button_label' => '“Continue to your purchasing system”', 'pow_leave_confirm_label' => '“Return without sending”', 'pow_leave_stay_label' => '“Keep shopping”' ] as $id => $default ) {
				self::assertSame( 'text', $fields[ $id ]['type'] ?? null, $id );
				self::assertStringContainsString( $default, $fields[ $id ]['help'], 'The help names the default' );
			}
		}
	}
}
