<?php
/**
 * The plugin creates no users.
 *
 * One regression suite for everything the removal of provisioning has to
 * keep true: the Provisioner and the punchout_buyer role are gone, no
 * shipped file names them or the user meta they wrote, activation and
 * upgrade register no role, uninstall removes none, and the connection's
 * bound customer account is an ordinary WooCommerce login — it can sign in
 * with its password and ask for a reset, because it is the same account
 * that reads its own My Account screens.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace {
	if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) { require_once dirname( __DIR__ ) . '/bootstrap.php'; }

	use PHPUnit\Framework\TestCase;
	use POW\Installer;
	use POW\Plugin;
	use POW\Settings;

	final class ProvisioningRemovalTest extends TestCase {

		/**
		 * Needles no shipped source file may carry again — the class, the
		 * role, the two hooks, the filter over buyer identity and every
		 * user-meta key provisioning owned. `_pow_partner_id` is deliberately
		 * absent from this list: it is also the quote order's partner meta.
		 */
		private const FORBIDDEN = [
			'Provisioner',
			'punchout_buyer',
			'Installer::ROLE',
			'self::ROLE',
			'register_role',
			'add_role(',
			'remove_role(',
			'pow_buyer_provisioned',
			'pow_buyer_deactivated',
			// Quoted, so the removed filter name does not match the surviving
			// `_pow_buyer_identity` order meta key.
			"'pow_buyer_identity'",
			'_pow_last_seen',
			'_pow_ephemeral',
			'_pow_identity',
			'_pow_deactivated',
			'buyer_inactive_days',
			'deny_buyer_password_login',
			'deny_buyer_password_reset',
			'wp_insert_user',
			'wp_update_user',
			'wp_delete_user',
			'wp_create_user',
		];

		/** @return list<string> Every shipped PHP source file, including the uninstall routine. */
		private function sources(): array {
			$root  = dirname( __DIR__, 2 );
			$files = [ $root . '/uninstall.php' ];
			foreach ( [ '/includes', '/templates' ] as $dir ) {
				$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . $dir ) );
				foreach ( $iterator as $file ) {
					if ( $file->isFile() && 'php' === $file->getExtension() ) { $files[] = $file->getPathname(); }
				}
			}
			sort( $files );
			self::assertGreaterThan( 40, count( $files ), 'Source scan found too few files to be trusted' );
			return $files;
		}

		/** The source of one method, so a test can read what activation actually does. */
		private function body( string $class, string $method ): string {
			$reflection = new ReflectionMethod( $class, $method );
			$lines      = file( (string) $reflection->getFileName() ) ?: [];

			return implode( '', array_slice( $lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1 ) );
		}

		public function test_the_provisioner_and_the_buyer_role_no_longer_exist(): void {
			self::assertFalse( is_file( dirname( __DIR__, 2 ) . '/includes/Buyers/Provisioner.php' ), 'The provisioner file is gone' );
			self::assertFalse( class_exists( 'POW\\Buyers\\Provisioner' ), 'The provisioner class is not loadable' );
			self::assertFalse( defined( Installer::class . '::ROLE' ), 'The role constant is gone' );
			self::assertFalse( method_exists( Installer::class, 'register_role' ), 'The role registration is gone' );
			self::assertTrue( class_exists( 'POW\\Buyers\\Identity' ), 'Buyer identity survives as data' );
		}

		public function test_no_shipped_source_file_provisions_a_buyer(): void {
			foreach ( $this->sources() as $file ) {
				$source = (string) file_get_contents( $file );
				foreach ( self::FORBIDDEN as $needle ) {
					self::assertStringNotContainsString( $needle, $source, basename( $file ) . ' still names ' . $needle );
				}
			}
		}

		/**
		 * Activation and the schema upgrade are the only two places that ever
		 * created the role, and neither can be executed in a WordPress-free
		 * harness — so the proof is their own source: no role is named, and
		 * the function that would register one is not in the class at all.
		 */
		public function test_activation_and_upgrade_register_no_role(): void {
			foreach ( [ 'activate', 'deactivate', 'maybe_upgrade' ] as $method ) {
				$body = $this->body( Installer::class, $method );
				foreach ( [ 'role', 'ROLE' ] as $needle ) {
					self::assertStringNotContainsString( $needle, $body, 'Installer::' . $method . '() still names a ' . $needle );
				}
			}
		}

		/** Deleting the plugin removes its own rows; a user row, its roles and its meta are never the plugin's to touch. */
		public function test_uninstall_touches_no_user(): void {
			$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );
			foreach ( [ 'remove_role', 'delete_user_meta', 'wp_delete_user', 'delete_metadata', 'usermeta' ] as $needle ) {
				self::assertStringNotContainsString( $needle, $source, 'The uninstall routine still names ' . $needle );
			}
			self::assertStringContainsString( 'NO USER IS TOUCHED', $source );
		}

		/**
		 * The bound account is the same ordinary customer that signs in to My
		 * Account for its setup XML, so the password door stays open: there
		 * is no flag to key a denial on and nothing left to register.
		 */
		public function test_the_bound_account_keeps_password_login_and_reset(): void {
			foreach ( [ 'deny_buyer_password_login', 'deny_buyer_password_reset' ] as $method ) {
				self::assertFalse( method_exists( Plugin::class, $method ), 'The password denial ' . $method . '() is gone' );
			}
			foreach ( $this->sources() as $file ) {
				$source = (string) file_get_contents( $file );
				foreach ( [ "'allow_password_reset'", "'wp_authenticate_user'", "'retrieve_password_message'", 'pow_no_password_login' ] as $needle ) {
					self::assertStringNotContainsString( $needle, $source, basename( $file ) . ' still interferes with the password door: ' . $needle );
				}
			}
		}

		/** The dormant-buyer cron owned this setting; with the sweep gone the knob must go too, defaults and screen alike. */
		public function test_the_buyer_inactivity_setting_is_gone_from_defaults_and_the_screen(): void {
			$settings = new Settings();
			self::assertFalse( array_key_exists( 'buyer_inactive_days', $settings->all() ), 'The option defaults hold no buyer inactivity knob' );

			self::assertFalse( str_contains( $this->body( \POW\Admin\Page::class, 'sanitize_settings' ), 'buyer_inactive' ), 'The settings sanitiser holds no buyer inactivity knob' );
			self::assertFalse( str_contains( $this->body( \POW\Admin\Page::class, 'register_settings' ), 'buyer_inactive' ), 'The settings screen offers no buyer inactivity field' );
		}
	}
}
