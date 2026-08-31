<?php
/**
 * CIDR allowlist matching.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Support\Ip;

final class IpTest extends TestCase {

	public function test_bare_address(): void {
		self::assertTrue( Ip::in_cidr( '203.0.113.7', '203.0.113.7' ) );
		self::assertFalse( Ip::in_cidr( '203.0.113.8', '203.0.113.7' ) );
	}

	public function test_v4_prefixes(): void {
		self::assertTrue( Ip::in_cidr( '203.0.113.7', '203.0.113.0/24' ) );
		self::assertFalse( Ip::in_cidr( '203.0.114.7', '203.0.113.0/24' ) );
		self::assertTrue( Ip::in_cidr( '203.0.113.7', '203.0.113.7/32' ) );
		self::assertTrue( Ip::in_cidr( '8.8.8.8', '0.0.0.0/0' ) );
		// Non-octet-aligned prefix: /25 splits the last octet.
		self::assertTrue( Ip::in_cidr( '203.0.113.100', '203.0.113.0/25' ) );
		self::assertFalse( Ip::in_cidr( '203.0.113.200', '203.0.113.0/25' ) );
	}

	public function test_v6_prefixes(): void {
		self::assertTrue( Ip::in_cidr( '2001:db8::1', '2001:db8::/32' ) );
		self::assertFalse( Ip::in_cidr( '2001:db9::1', '2001:db8::/32' ) );
	}

	public function test_mixed_families_never_match(): void {
		self::assertFalse( Ip::in_cidr( '203.0.113.7', '2001:db8::/32' ) );
		self::assertFalse( Ip::in_cidr( '2001:db8::1', '203.0.113.0/24' ) );
	}

	public function test_malformed_input(): void {
		self::assertFalse( Ip::in_cidr( '203.0.113.7', 'garbage' ) );
		self::assertFalse( Ip::in_cidr( 'garbage', '203.0.113.0/24' ) );
		self::assertFalse( Ip::in_cidr( '203.0.113.7', '203.0.113.0/99' ) );
		self::assertFalse( Ip::in_cidr( '203.0.113.7', '' ) );
	}

	public function test_in_any(): void {
		self::assertTrue( Ip::in_any( '203.0.113.7', [ '198.51.100.0/24', '203.0.113.0/24' ] ) );
		self::assertFalse( Ip::in_any( '203.0.113.7', [ '198.51.100.0/24' ] ) );
		self::assertFalse( Ip::in_any( '203.0.113.7', [] ), 'empty allowlist matches nothing (caller treats [] as "no restriction")' );
	}
}
