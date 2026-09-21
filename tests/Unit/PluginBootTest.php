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
		// Nothing about the *account* blocks checkout any more: only a live
		// visit does. An account with no visit is an ordinary shopper and
		// gets the ordinary control, whatever role it happens to carry.
		self::assertSame( $result['ordinary'], $result['orphan'] );
		self::assertFalse( $result['return_shortcode_registered'] );
		self::assertFalse( $result['router_registered'] );
	}
}
