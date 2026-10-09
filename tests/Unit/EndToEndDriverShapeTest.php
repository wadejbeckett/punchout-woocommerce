<?php
/**
 * The end-to-end HTTP driver under tests/E2E/ keeps its safety rails.
 *
 * Nothing in the ordinary suite runs the driver: it needs a disposable
 * WordPress/WooCommerce served on the loopback address. This is the standing
 * check that its pieces still parse, that the fixture script refuses anything
 * but an opted-in disposable local WP-CLI run, that the driver talks only to
 * 127.0.0.1, and that the exact-DTD helper it shells out to accepts the
 * plugin's own sample return and refuses a broken one.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace {
	if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) { require_once dirname( __DIR__ ) . '/bootstrap.php'; }

	use PHPUnit\Framework\TestCase;

	final class EndToEndDriverShapeTest extends TestCase {

		private function path( string $name ): string {
			return dirname( __DIR__ ) . '/E2E/' . $name;
		}

		private function source( string $name ): string {
			$path = $this->path( $name );
			self::assertTrue( is_file( $path ), $name . ' exists' );
			return (string) file_get_contents( $path );
		}

		public function test_the_php_pieces_parse(): void {
			foreach ( [ 'fixture.php', 'router.php', 'dtd.php' ] as $name ) {
				$tokens = token_get_all( $this->source( $name ), TOKEN_PARSE );
				self::assertGreaterThan( 1, count( $tokens ), $name . ' parses' );
			}
		}

		public function test_the_fixture_script_refuses_anything_but_a_disposable_local_cli_run(): void {
			$source = $this->source( 'fixture.php' );
			$guard  = substr( $source, 0, (int) strpos( $source, 'throw new RuntimeException' ) );
			foreach ( [ "defined( 'WP_CLI' )", "'disposable' !== getenv( 'POW_NATIVE_TESTS' )", "'local' !== wp_get_environment_type()", "current_user_can( 'manage_woocommerce' )" ] as $condition ) {
				self::assertStringContainsString( $condition, $guard, 'The fixture guard checks ' . $condition . ' before anything else' );
			}
		}

		public function test_the_fixture_script_creates_no_user_but_the_ordinary_bound_customer(): void {
			$source = $this->source( 'fixture.php' );
			self::assertStringContainsString( 'pow_native_bound_account(', $source, 'The bound account comes from the shared native fixture' );
			foreach ( [ 'wp_insert_user', 'wp_create_user', 'wp_update_user', 'wp_delete_user', 'add_role', 'set_role' ] as $call ) {
				self::assertStringNotContainsString( $call . '(', $source, 'The fixture script never calls ' . $call );
			}
		}

		public function test_the_driver_talks_only_to_the_loopback_address(): void {
			$source = $this->source( 'driver.py' );
			self::assertStringContainsString( "'127.0.0.1'", $source );
			self::assertStringContainsString( 'require_loopback(args.url)', $source, 'The driver checks its target before the first request' );
		}

		public function test_the_driver_never_prints_the_connection_secret(): void {
			$source = $this->source( 'driver.py' );
			self::assertSame( 0, preg_match( '/print\([^)]*secret/i', $source ), 'No print call names the secret' );
		}

		/** @return array{0:int,1:string} exit code and stdout of `php dtd.php` fed $xml on stdin. */
		private function dtd( string $xml ): array {
			$process = proc_open( [ PHP_BINARY, $this->path( 'dtd.php' ) ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
			self::assertTrue( is_resource( $process ), 'php dtd.php starts' );
			fwrite( $pipes[0], $xml );
			fclose( $pipes[0] );
			$out = (string) stream_get_contents( $pipes[1] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			return [ proc_close( $process ), $out ];
		}

		public function test_the_dtd_helper_accepts_the_plugins_own_sample_return(): void {
			if ( ! extension_loaded( 'dom' ) ) { self::markTestSkipped( 'ext-dom is required for the DTD helper.' ); }
			[ $code, $out ] = $this->dtd( \POW\Docs\Samples::poom() );
			self::assertSame( 0, $code, $out );
			self::assertSame( [ 'valid' => true, 'errors' => [] ], json_decode( $out, true ) );
		}

		public function test_the_dtd_helper_refuses_a_return_the_dtd_does_not_allow(): void {
			if ( ! extension_loaded( 'dom' ) ) { self::markTestSkipped( 'ext-dom is required for the DTD helper.' ); }
			$broken = preg_replace( '#<UnitOfMeasure>[^<]*</UnitOfMeasure>#', '', \POW\Docs\Samples::poom(), 1 );
			[ $code, $out ] = $this->dtd( (string) $broken );
			$result = json_decode( $out, true );
			self::assertSame( 1, $code );
			self::assertFalse( $result['valid'] );
			self::assertGreaterThan( 0, count( $result['errors'] ), 'The refusal names its errors' );
		}
	}
}
