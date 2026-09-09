<?php
/**
 * The cXML status codes the setup endpoint emits are constants, and the
 * reference map documents exactly those codes — no more, no fewer.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Http\SetupEndpoint;

final class StatusReferenceTest extends TestCase {

	public function test_every_status_constant_is_documented(): void {
		$codes = [
			SetupEndpoint::STATUS_OK,
			SetupEndpoint::STATUS_AUTH_FAILED,
			SetupEndpoint::STATUS_INVALID,
			SetupEndpoint::STATUS_DUPLICATE,
			SetupEndpoint::STATUS_UNSUPPORTED,
			SetupEndpoint::STATUS_INTERNAL,
			SetupEndpoint::STATUS_RATE_LIMITED,
		];

		self::assertSame( [ 200, 401, 406, 409, 450, 500, 550 ], $codes );
		self::assertSame( $codes, array_keys( SetupEndpoint::STATUS_REASONS ) );

		foreach ( SetupEndpoint::STATUS_REASONS as $code => $reason ) {
			self::assertNotSame( '', $reason, "Status {$code} has no reason text" );
		}
	}

	public function test_source_carries_no_bare_status_literals(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/Http/SetupEndpoint.php' );
		$body   = (string) substr( $source, (int) strpos( $source, 'private function handle_inner' ) );

		foreach ( [ '401', '406', '409', '450', '500', '550' ] as $literal ) {
			self::assertStringNotContainsString( ", {$literal}, ", $body );
			self::assertStringNotContainsString( "( {$literal},", $body );
		}
	}
}
