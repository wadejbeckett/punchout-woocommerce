<?php
/**
 * The opt-in native suites describe the confirmed model.
 *
 * `tests/Integration/` holds scripts that only run against a real
 * WordPress/WooCommerce under `POW_NATIVE_TESTS=disposable`, so nothing in
 * the ordinary suite executes them and a stale one rots unnoticed: it fails
 * on an undefined class an hour into a candidate run, or worse, it keeps
 * asserting behaviour the release removed. This suite is the standing check
 * on their shape. It proves what can be proved without a database — that no
 * script names a symbol the plugin no longer has, that every script still
 * parses, that the suites about two concurrent visits compare the per-visit
 * cart key rather than a user id, and that the lockdown and account drivers
 * cover the surfaces the model closes.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace {
	if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) { require_once dirname( __DIR__ ) . '/bootstrap.php'; }

	use PHPUnit\Framework\TestCase;

	final class NativeSuiteShapeTest extends TestCase {

		/**
		 * Symbols and fixtures no native script may name again.
		 *
		 * `_pow_partner_id` is deliberately absent: it is still the quote
		 * order's connection meta and still the legacy user-meta row the
		 * binding screen refuses an account for, so two suites name it on
		 * purpose.
		 */
		private const FORBIDDEN = [
			'Provisioner',
			'punchout_buyer',
			'Installer::ROLE',
			'with_identity_lock',
			'pow_buyer_provisioned',
			'pow_buyer_deactivated',
			'pow_buyer_identity',
			'_pow_identity',
			'_pow_ephemeral',
			'_pow_deactivated',
			'_pow_last_seen',
			'wp_pre_insert_user_data',
			'request_deactivation',
			'ExitPolicy',
			'PayExit',
			'punchout_and_checkout',
			'pow_closeout_copy',
			'closeout-button',
		];

		/** The suites that carry two concurrent visits of one bound account. */
		private const VISIT_SUITES = [
			'ConcurrencyNative.php',
			'AddressProviderNative.php',
			'DeliveryEstimateNative.php',
			'DeliveryStoreNative.php',
			'VisitLockdownNative.php',
		];

		private function directory(): string {
			return dirname( __DIR__ ) . '/Integration';
		}

		/** @return array<string,string> Native script path => its source. */
		private function scripts(): array {
			$files = glob( $this->directory() . '/*.php' ) ?: [];
			sort( $files );
			self::assertGreaterThan( 8, count( $files ), 'The native script scan found too few files to be trusted' );

			$sources = [];
			foreach ( $files as $file ) { $sources[ $file ] = (string) file_get_contents( $file ); }
			$driver = dirname( __DIR__ ) . '/Account/native.py';
			self::assertTrue( is_file( $driver ), 'The account HTTP driver is where the My Account checks live' );
			$sources[ $driver ] = (string) file_get_contents( $driver );

			return $sources;
		}

		private function script( string $name ): string {
			$path = $this->directory() . '/' . $name;
			self::assertTrue( is_file( $path ), $name . ' exists' );

			return (string) file_get_contents( $path );
		}

		public function test_no_native_suite_names_a_removed_symbol_or_seeds_a_buyer_account(): void {
			foreach ( $this->scripts() as $file => $source ) {
				foreach ( self::FORBIDDEN as $needle ) {
					self::assertStringNotContainsString( $needle, $source, basename( $file ) . ' still names ' . $needle );
				}
			}
		}

		/**
		 * A script nothing runs must at least compile. token_get_all() with
		 * TOKEN_PARSE raises a ParseError on invalid source, which is the only
		 * syntax check available without a shell.
		 */
		public function test_every_native_script_still_parses(): void {
			foreach ( $this->scripts() as $file => $source ) {
				if ( ! str_ends_with( $file, '.php' ) ) { continue; }
				try {
					token_get_all( $source, TOKEN_PARSE );
				} catch ( ParseError $error ) {
					self::fail( basename( $file ) . ' does not parse: ' . $error->getMessage() );
				}
			}
		}

		/**
		 * Two employees at one connection are one WordPress user, so a suite
		 * that proved isolation by comparing user ids proved nothing. The
		 * per-visit key is the only discriminator, and the basket it names has
		 * to be read through the plugin's own handler — core's would re-key the
		 * row to the shared account.
		 */
		public function test_the_visit_suites_prove_isolation_by_the_per_visit_key(): void {
			foreach ( self::VISIT_SUITES as $name ) {
				$source = $this->script( $name );
				self::assertStringContainsString( 'wc_session_key', $source, $name . ' names the per-visit cart key' );
				self::assertStringNotContainsString( 'new WC_Session_Handler', $source, $name . ' must not build core\'s handler for a visit' );
			}
		}

		/** The replacement for the buyer-provisioning races: visits, the cap and the per-identity supersede. */
		public function test_the_concurrency_suite_covers_visits_the_cap_and_supersede(): void {
			$source = $this->script( 'ConcurrencyNative.php' );

			foreach ( [ 'setup_visit_cap', 'setup_no_login', 'MAX_OPEN_VISITS', 'buyer_identity_hash', 'wp_session_token' ] as $needle ) {
				self::assertStringContainsString( $needle, $source, 'The concurrency suite covers ' . $needle );
			}
		}

		/** Confirmed model item 7, end to end, is one script's job. */
		public function test_the_lockdown_suite_covers_every_refused_surface(): void {
			$source = $this->script( 'VisitLockdownNative.php' );

			foreach ( [ '/wp/v2/users', '/wp/v2/application-passwords', 'guard_admin', 'pow_visit_locked', 'edit-account', 'checkout' ] as $needle ) {
				self::assertStringContainsString( $needle, $source, 'The lockdown suite covers ' . $needle );
			}
		}

		/** The My Account tab is a download and nothing else, and it does not exist inside a visit. */
		public function test_the_account_driver_drives_a_download_only_tab(): void {
			$driver = (string) file_get_contents( dirname( __DIR__ ) . '/Account/native.py' );

			// The driver still NAMES the removed copy, on purpose: it asserts the
			// application form and the rotation control are absent from the page.
			// What it may not do is post those actions.
			foreach ( [ "pow_account_action='submit'", "pow_account_action='rotate'", "pow_account_action='finish_rotation'", "pow_account_action='deactivate'", "step('clear_limits')", "step('expiry')" ] as $needle ) {
				self::assertStringNotContainsString( $needle, $driver, 'The account driver no longer drives ' . $needle );
			}
			foreach ( [ 'Request connection', 'Rotate secret' ] as $removed ) {
				self::assertStringContainsString( $removed . "' not in", $driver, 'The account driver proves ' . $removed . ' no longer renders' );
			}
			foreach ( [ 'download_setup_template', 'visit' ] as $needle ) {
				self::assertStringContainsString( $needle, $driver, 'The account driver checks ' . $needle );
			}
		}
	}
}
