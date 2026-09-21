<?php
/** Master-switch boot registration regressions. @package POW */
declare( strict_types = 1 );

final class PluginBootTest extends PHPUnit\Framework\TestCase {
	public function test_master_off_boot_registers_complete_cart_exit_for_ordinary_checkout_only(): void {
		$fixture = dirname( __DIR__ ) . '/fixtures/plugin-boot-master-off.php';
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $fixture );
		exec( $command, $output, $status );
		self::assertSame( 0, $status, implode( "\n", $output ) );
		$result = json_decode( implode( "\n", $output ), true, 512, JSON_THROW_ON_ERROR );
		self::assertTrue( $result['registered'] );
		self::assertSame( 1, substr_count( $result['ordinary'], 'href="https://shop.example.test/checkout/"' ) );
		self::assertStringNotContainsString( 'pow-return-form', $result['ordinary'] );
		// Behaviour change (single-login mode): nothing about the *account*
		// blocks checkout any more, only a live visit does. A signed-in
		// account with no visit is an ordinary shopper, so it keeps the
		// native checkout link and gets byte-for-byte the ordinary control.
		self::assertSame( 1, substr_count( $result['signed_in_no_visit'], 'href="https://shop.example.test/checkout/"' ) );
		self::assertStringNotContainsString( 'pow-return-form', $result['signed_in_no_visit'] );
		self::assertSame( $result['ordinary'], $result['signed_in_no_visit'] );
		self::assertFalse( $result['return_shortcode_registered'] );
		self::assertFalse( $result['router_registered'] );
		// The bound account is an ordinary customer login: nothing in boot()
		// may stand between it and its own password, whatever the master
		// switch says. The teardown that used to share that comment block
		// survives on its own.
		foreach ( [ 'allow_password_reset', 'wp_authenticate_user' ] as $filter ) {
			self::assertFalse( in_array( $filter, $result['filters'], true ), 'boot() must not touch the password door: ' . $filter );
		}
		self::assertTrue( in_array( 'update_option_' . POW\Settings::OPTION_KEY, $result['actions'], true ), 'The master-switch teardown is still registered' );
		self::assertSame( [], $result['provisioning_classes'], 'boot() must construct no provisioner and no pay exit' );
	}
}
