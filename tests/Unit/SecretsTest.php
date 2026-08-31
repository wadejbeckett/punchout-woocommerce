<?php
/**
 * Secret sealing + dual-slot constant-time verification (scope §7).
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Partners\Secrets;

final class SecretsTest extends TestCase {

	private Secrets $secrets;

	protected function setUp(): void {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			self::markTestSkipped( 'ext-sodium not available' );
		}

		$this->secrets = new Secrets( str_repeat( 'k', 32 ) );
	}

	public function test_seal_open_round_trip(): void {
		$sealed = $this->secrets->seal( 'test-shared-secret' );

		self::assertNotSame( 'test-shared-secret', $sealed );
		self::assertSame( 'test-shared-secret', $this->secrets->open( $sealed ) );
	}

	public function test_seal_is_nonce_randomised(): void {
		self::assertNotSame( $this->secrets->seal( 's' ), $this->secrets->seal( 's' ) );
	}

	public function test_tampered_ciphertext_opens_to_null(): void {
		$sealed  = $this->secrets->seal( 'secret' );
		$raw     = base64_decode( $sealed, true );
		$raw[10] = ( "\x00" === $raw[10] ) ? "\x01" : "\x00";

		self::assertNull( $this->secrets->open( base64_encode( $raw ) ) );
		self::assertNull( $this->secrets->open( 'not-base64!!' ) );
		self::assertNull( $this->secrets->open( '' ) );
	}

	public function test_wrong_key_opens_to_null(): void {
		$other = new Secrets( str_repeat( 'x', 32 ) );

		self::assertNull( $other->open( $this->secrets->seal( 'secret' ) ) );
	}

	public function test_verify_reports_matching_slot(): void {
		$current  = $this->secrets->seal( 'new-secret' );
		$previous = $this->secrets->seal( 'old-secret' );

		self::assertSame( Secrets::SLOT_CURRENT, $this->secrets->verify( 'new-secret', $current, $previous ) );
		self::assertSame( Secrets::SLOT_PREVIOUS, $this->secrets->verify( 'old-secret', $current, $previous ) );
		self::assertNull( $this->secrets->verify( 'wrong', $current, $previous ) );
	}

	public function test_verify_edge_cases(): void {
		$current = $this->secrets->seal( 'secret' );

		self::assertNull( $this->secrets->verify( '', $current, null ), 'empty candidate must never match' );
		self::assertNull( $this->secrets->verify( 'secret', '', null ), 'unset slots must never match' );
		self::assertSame( Secrets::SLOT_CURRENT, $this->secrets->verify( 'secret', $current, null ) );
	}

	public function test_key_must_be_32_bytes(): void {
		$this->expectException( InvalidArgumentException::class );
		new Secrets( 'short' );
	}

	public function test_generated_material(): void {
		self::assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', Secrets::generate_secret() );
		self::assertSame( 32, strlen( (string) Secrets::decode_key( Secrets::generate_key() ) ) );
		self::assertNull( Secrets::decode_key( 'too-short' ) );
	}
}
