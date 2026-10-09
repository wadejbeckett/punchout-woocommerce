<?php
declare( strict_types = 1 );

namespace {
	// The Settings API calls register_settings() makes, recorded so the fields can be read. Never defined when WordPress is loaded.
	if ( ! function_exists( 'register_setting' ) ) { function register_setting( string $group, string $name, array $args = [] ): void {} }
	if ( ! function_exists( 'add_settings_section' ) ) { function add_settings_section( string $id, string $title, mixed $callback, string $page ): void {} }
	if ( ! function_exists( 'add_settings_field' ) ) { function add_settings_field( string $id, string $title, mixed $callback, string $page, string $section = 'default', array $args = [] ): void { $GLOBALS['pow_test_settings_fields'][ $id ] = $args; } }

	use PHPUnit\Framework\TestCase;
	use POW\Addresses\ReviewLabels;
	use POW\Settings;

	/**
	 * The review page's words are the site's (0.4.23): the Submit button, the heading over the item lines and the
	 * total's label are settings with neutral defaults, resolved like the exit-control labels (ButtonLabelTest): a
	 * non-blank saved value wins, anything else falls back, and the filter runs last.
	 */
	final class ReviewLabelsTest extends TestCase {
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

		/** @param array<string, mixed> $saved */
		private function settings( array $saved ): Settings {
			$GLOBALS['pow_test_options'][ Settings::OPTION_KEY ] = $saved;
			return new Settings();
		}

		public function test_the_defaults_are_neutral(): void {
			self::assertSame( [ 'submit' => 'Submit', 'items' => 'Items', 'total' => 'Total' ], ReviewLabels::resolve() );
			self::assertSame( ReviewLabels::resolve(), ReviewLabels::resolve( $this->settings( [] ) ) );
			foreach ( ReviewLabels::resolve() as $label ) { self::assertStringNotContainsString( 'approv', strtolower( $label ) ); }
		}

		public function test_saved_labels_win(): void {
			$labels = ReviewLabels::resolve( $this->settings( [ ReviewLabels::SUBMIT => 'Send to purchasing', ReviewLabels::ITEMS => 'Items on order', ReviewLabels::TOTAL => 'Order total' ] ) );
			self::assertSame( [ 'submit' => 'Send to purchasing', 'items' => 'Items on order', 'total' => 'Order total' ], $labels );
		}

		public function test_blank_or_unusable_settings_fall_back_to_the_defaults(): void {
			$labels = ReviewLabels::resolve( $this->settings( [ ReviewLabels::SUBMIT => '   ', ReviewLabels::ITEMS => [ 'x' ], ReviewLabels::TOTAL => '' ] ) );
			self::assertSame( [ 'submit' => 'Submit', 'items' => 'Items', 'total' => 'Total' ], $labels );
			self::assertSame( 'Items on order', ReviewLabels::resolve( $this->settings( [ ReviewLabels::ITEMS => "  Items on order\n" ] ) )['items'], 'Trimmed' );
		}

		public function test_the_filter_runs_last_and_a_blank_filter_result_keeps_the_default(): void {
			$GLOBALS['pow_test_filters'][ ReviewLabels::FILTERS[ ReviewLabels::SUBMIT ] ] = static fn( string $label ): string => $label . ' cart';
			$GLOBALS['pow_test_filters'][ ReviewLabels::FILTERS[ ReviewLabels::TOTAL ] ] = static fn(): string => '';
			$labels = ReviewLabels::resolve( $this->settings( [ ReviewLabels::SUBMIT => 'Submit', ReviewLabels::TOTAL => 'Order total' ] ) );
			self::assertSame( 'Submit cart', $labels['submit'] );
			self::assertSame( 'Total', $labels['total'], 'A button or label is never drawn empty' );
			self::assertSame( [ 'punchout_review_submit_label', 'punchout_review_items_heading', 'punchout_review_total_label' ], array_values( ReviewLabels::FILTERS ) );
		}

		/** What a template is handed is completed key by key, so an override fed a partial or foreign array still has words. */
		public function test_a_partial_or_foreign_label_set_is_completed(): void {
			self::assertSame( [ 'submit' => 'Submit', 'items' => 'Items on order', 'total' => 'Total' ], ReviewLabels::complete( [ 'items' => 'Items on order', 'total' => '  ' ] ) );
			self::assertSame( ReviewLabels::resolve(), ReviewLabels::complete( null ) );
			self::assertSame( ReviewLabels::resolve(), ReviewLabels::complete( 'Submit' ) );
		}

		public function test_saved_values_are_plain_bounded_text(): void {
			self::assertSame( 'Submit', ReviewLabels::sanitise( '  <b>Submit</b> ' ) );
			self::assertSame( '', ReviewLabels::sanitise( [ 'x' ] ) );
			self::assertSame( ReviewLabels::MAX_LENGTH, mb_strlen( ReviewLabels::sanitise( str_repeat( 'é', 300 ) ) ) );
		}

		public function test_the_settings_screen_offers_and_saves_the_three_labels(): void {
			$admin = ( new ReflectionClass( POW\Admin\Page::class ) )->newInstanceWithoutConstructor();
			( new ReflectionProperty( $admin, 'settings' ) )->setValue( $admin, new Settings() );
			$clean = $admin->sanitize_settings( [ ReviewLabels::SUBMIT => ' <em>Submit</em> ', ReviewLabels::ITEMS => 'Items on order', ReviewLabels::TOTAL => 'Total' ] );
			self::assertSame( 'Submit', $clean[ ReviewLabels::SUBMIT ] );
			self::assertSame( 'Items on order', $clean[ ReviewLabels::ITEMS ] );
			self::assertSame( 'Total', $clean[ ReviewLabels::TOTAL ] );
			foreach ( [ ReviewLabels::SUBMIT, ReviewLabels::ITEMS, ReviewLabels::TOTAL ] as $key ) { self::assertSame( '', $admin->sanitize_settings( [] )[ $key ], $key ); }

			$GLOBALS['pow_test_settings_fields'] = [];
			$admin->register_settings();
			foreach ( [ ReviewLabels::SUBMIT => 'Submit', ReviewLabels::ITEMS => 'Items', ReviewLabels::TOTAL => 'Total' ] as $key => $default ) {
				self::assertArrayHasKey( 'pow_' . $key, $GLOBALS['pow_test_settings_fields'], $key );
				self::assertSame( 'text', $GLOBALS['pow_test_settings_fields'][ 'pow_' . $key ]['type'] );
				self::assertStringContainsString( '“' . $default . '”', $GLOBALS['pow_test_settings_fields'][ 'pow_' . $key ]['help'], 'The help names the default' );
			}
		}
	}
}
