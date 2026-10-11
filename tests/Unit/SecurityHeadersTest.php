<?php
declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Http\Router;

/**
 * Every plugin response says "nosniff" (0.4.22, security audit IO-03): the routed endpoints, the cancel
 * close-out (also reached as a wc-ajax action the router never sees) and the transport refusals. The return
 * endpoint's header is also checked on a real response in CancelAndReturnTest.
 */
final class SecurityHeadersTest extends TestCase {
	private static function source( string $file ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/' . $file );
	}

	public function test_every_routed_punchout_response_is_private_unindexed_and_not_sniffed(): void {
		self::assertSame( [ 'Cache-Control: private, no-store', 'X-Robots-Tag: noindex, nofollow', 'X-Content-Type-Options: nosniff' ], Router::security_headers() );
		self::assertStringContainsString( 'foreach ( self::security_headers() as $header )', self::source( 'Http/Router.php' ) );
	}

	public function test_the_close_out_and_the_transport_refusal_send_nosniff_themselves(): void {
		foreach ( [ 'Http/ReturnEndpoint.php', 'Support/Transport.php' ] as $file ) {
			self::assertStringContainsString( "header( 'X-Content-Type-Options: nosniff'", self::source( $file ), $file );
		}
	}

}
