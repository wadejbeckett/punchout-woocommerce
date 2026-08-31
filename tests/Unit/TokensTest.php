<?php
/**
 * One-time StartPage token format and hashing.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Sessions\Tokens;

final class TokensTest extends TestCase {

	public function test_issue_produces_urlsafe_256_bit_token(): void {
		$issued = Tokens::issue();

		self::assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', $issued['token'] );
		self::assertSame( hash( 'sha256', $issued['token'] ), $issued['hash'] );
	}

	public function test_tokens_are_unique(): void {
		self::assertNotSame( Tokens::issue()['token'], Tokens::issue()['token'] );
	}

	public function test_looks_valid(): void {
		self::assertTrue( Tokens::looks_valid( Tokens::issue()['token'] ) );
		self::assertFalse( Tokens::looks_valid( '' ) );
		self::assertFalse( Tokens::looks_valid( 'short' ) );
		self::assertFalse( Tokens::looks_valid( str_repeat( 'a', 42 ) . '+' ) );
		self::assertFalse( Tokens::looks_valid( str_repeat( 'a', 44 ) ) );
		self::assertFalse( Tokens::looks_valid( str_repeat( 'a', 42 ) . '/' ) );
	}
}
