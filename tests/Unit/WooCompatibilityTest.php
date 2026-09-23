<?php
/**
 * The declared WooCommerce compatibility, against the one thing that decides it.
 *
 * One basket per punchout visit is a bypass of `WC_Session_Handler`'s own
 * keying, and `Docs\SelfTest::cart_handler_faults()` pins the core session
 * methods it stands on — four of which must still be private or protected.
 * On a store whose handler is shaped differently the handler throws and every
 * visit is refused with a 409, so a `WC requires at least` below the version
 * that shape was verified against is not a compatibility claim, it is a
 * promise the plugin breaks once per buyer request.
 *
 * The suite cannot run WooCommerce, so what it can hold is the two
 * statements an operator reads before installing: the plugin header and the
 * readme's installation step must name one minimum, and that minimum may not
 * be older than the version the header says was actually tested.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Docs\SelfTest;

final class WooCompatibilityTest extends TestCase {

	/** One header field of the main plugin file. */
	private function header( string $field ): string {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/punchout-woocommerce.php' );
		self::assertSame( 1, preg_match( '/^ \* ' . preg_quote( $field, '/' ) . ':\s*(\S+)$/m', $source, $found ), $field . ' must be declared once in the plugin header.' );

		return $found[1];
	}

	public function test_the_declared_minimum_is_not_older_than_the_version_verified(): void {
		$minimum = $this->header( 'WC requires at least' );
		$tested  = $this->header( 'WC tested up to' );

		self::assertTrue(
			version_compare( $minimum, $tested, '>=' ),
			'WC requires at least ' . $minimum . ' promises a store the session bypass was never verified on (tested up to ' . $tested . '); a visit there is refused with a 409 on every request.'
		);
	}

	public function test_the_readme_installation_step_names_the_same_minimum(): void {
		$minimum = $this->header( 'WC requires at least' );
		$readme  = (string) file_get_contents( dirname( __DIR__, 2 ) . '/readme.txt' );

		self::assertSame( 1, preg_match( '/WooCommerce ([0-9][0-9.]*)\+ must be active/', $readme, $found ), 'The installation step must state the WooCommerce minimum.' );
		self::assertSame( $minimum, $found[1], 'The readme tells the operator a different WooCommerce minimum than the header does.' );
	}

	/**
	 * Why the minimum is version-fragile at all: the bypass depends on core
	 * methods staying non-public. A handler without them is a fault, which is
	 * what the 409 is raised from — so the declared minimum has to track the
	 * version this shape was read from, not the oldest WooCommerce that boots.
	 */
	public function test_a_handler_without_the_bypassed_methods_is_a_fault(): void {
		$faults = SelfTest::cart_handler_faults( POW_Test_Shapeless_Session_Handler::class );

		foreach ( [ 'init_hooks', 'init_session', 'init_session_from_request', 'migrate_guest_session_to_user_session' ] as $method ) {
			self::assertNotSame(
				[],
				array_values( array_filter( $faults, static fn( string $fault ): bool => str_contains( $fault, $method ) ) ),
				$method . ' missing from the core handler must read as a fault, or nothing refuses the visit.'
			);
		}
	}
}

/** A session handler shaped like the WooCommerce releases that predate the bypass: none of the four methods it needs. */
final class POW_Test_Shapeless_Session_Handler { // phpcs:ignore PEAR.NamingConventions.ValidClassName.StartWithCapital
	public function init(): void {}
	public function init_session_cookie(): void {}
	public function has_session(): bool { return false; }
	public function set_customer_session_cookie( bool $set ): void {}
	public function maybe_set_customer_session_cookie(): void {}
	public function set_session_expiration(): void {}
	public function generate_customer_id(): string { return ''; }
	public function get_customer_unique_id(): string { return ''; }
	public function get_session_cookie(): bool { return false; }
	public function get_session_data(): array { return []; }
	public function save_data( string $old = '' ): void {}
	public function destroy_session(): void {}
	public function forget_session(): void {}
	public function get_session( string $customer_id, mixed $default_value = false ): mixed { return $default_value; }
	public function delete_session( string $customer_id ): void {}
	public function update_session_timestamp( string $customer_id, int $timestamp ): void {}
}
