<?php
/** Master-switch boot registration regressions. @package POW */
declare( strict_types = 1 );

final class PluginBootTest extends PHPUnit\Framework\TestCase {
	/** One real Plugin::boot() with the master switch off, as the fixture reports it. */
	private function booted(): array {
		$fixture = dirname( __DIR__ ) . '/fixtures/plugin-boot-master-off.php';
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $fixture );
		exec( $command, $output, $status );
		self::assertSame( 0, $status, implode( "\n", $output ) );

		return json_decode( implode( "\n", $output ), true, 512, JSON_THROW_ON_ERROR );
	}

	public function test_master_off_boot_registers_complete_cart_exit_for_ordinary_checkout_only(): void {
		$result = $this->booted();
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

	/**
	 * Basket isolation must have exactly the same lifetime as visit resolution.
	 *
	 * Flipping the master switch off sweeps the open visits, but the sweep can
	 * come back incomplete — a contended connection lock, an unconfirmed
	 * expire — and every surviving visit is then booted with enabled() false.
	 * NativeSessionGuard keeps resolving that visit and keeps binding its own
	 * `pow_` basket row, so if Cart\Guard sat behind the gate the visit would
	 * run with WooCommerce's shared-account persistent cart fully live: every
	 * cart mutation would overwrite the bound account's own saved basket, and
	 * an emptied visit would merge a colleague's lines back in on its next
	 * hydration. The resolver and the filters that consume it are registered
	 * together or not at all.
	 */
	public function test_master_off_boot_keeps_cart_isolation_for_a_visit_that_survived_the_sweep(): void {
		$result = $this->booted();
		self::assertTrue( in_array( 'woocommerce_session_handler', $result['filters'], true ), 'The per-visit session handler is chosen whatever the switch says' );
		foreach ( [ 'woocommerce_persistent_cart_enabled', 'get_user_metadata', 'delete_user_metadata' ] as $filter ) {
			self::assertTrue( in_array( $filter, $result['filters'], true ), 'Basket isolation is gated on the master switch: ' . $filter );
		}
		self::assertTrue( in_array( 'woocommerce_add_to_cart_validation', $result['filters'], true ), 'A surviving visit still refuses products outside its range' );
		self::assertTrue( in_array( 'wp_loaded', $result['actions'], true ), 'A surviving create-visit still gets its one-shot seed' );
	}

	/**
	 * A busy connection must not strand every connection after it.
	 *
	 * on_settings_updated() sweeps each connection inside its own named MySQL
	 * lock, and with_partner_lock() throws when that lock cannot be taken — a
	 * setup request for one connection is enough. With a single try around the
	 * whole loop, that throw ends the sweep: every connection after the busy
	 * one keeps its visits active and logged in for the rest of their TTL, and
	 * only one audit row records that anything went wrong.
	 *
	 * This is a structural check. The behaviour needs a real database: Registry
	 * is final and the lock is GET_LOCK, so the contention it turns on cannot
	 * be staged in this process.
	 */
	public function test_the_disable_sweep_isolates_each_connection_from_the_next(): void {
		$method = new ReflectionMethod( POW\Plugin::class, 'on_settings_updated' );
		$lines = (array) file( (string) $method->getFileName() );
		$body = implode( '', array_slice( $lines, $method->getStartLine(), $method->getEndLine() - $method->getStartLine() ) );
		$loop = strpos( $body, 'foreach (' );
		$tail = strpos( $body, '$ok = $clean && $ok;' );
		self::assertTrue( false !== $loop && false !== $tail && $loop < $tail, 'The sweep still iterates the connections' );
		$iteration = substr( $body, $loop, $tail - $loop );
		self::assertStringContainsString( 'with_partner_lock', $iteration );
		self::assertStringContainsString( 'try {', $iteration, 'Each connection is swept inside its own try' );
		self::assertStringContainsString( 'catch (', $iteration, 'A contended connection lock is caught per connection, not once for all of them' );
	}
}
