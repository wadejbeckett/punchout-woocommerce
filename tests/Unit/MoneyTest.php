<?php
/**
 * The float -> money boundary (scope §10: money rounding at the
 * float->cents boundary).
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Cxml\Money;

final class MoneyTest extends TestCase {

	public function test_string_amounts(): void {
		self::assertSame( 1234, Money::to_cents( '12.34' ) );
		self::assertSame( 123400, Money::to_cents( '1234' ) );
		self::assertSame( 10, Money::to_cents( '0.1' ) );
		self::assertSame( 0, Money::to_cents( '0' ) );
	}

	public function test_half_up_at_cents(): void {
		self::assertSame( 201, Money::to_cents( '2.005' ) );
		self::assertSame( 200, Money::to_cents( '2.0049' ) );
		self::assertSame( 201, Money::to_cents( '2.0050' ) );
		self::assertSame( 100, Money::to_cents( '0.999' ) );
	}

	public function test_float_amounts_are_pinned_before_rounding(): void {
		// 2.005 is 2.00499999999999989… in binary; the %.4F pin makes the
		// half-up rule deterministic instead of representation-dependent.
		self::assertSame( 201, Money::to_cents( 2.005 ) );
		self::assertSame( 1234, Money::to_cents( 12.34 ) );
		self::assertSame( 10999, Money::to_cents( 109.99 ) );
	}

	public function test_negative_amounts(): void {
		self::assertSame( -600, Money::to_cents( '-5.999' ) );
		self::assertSame( -1201, Money::to_cents( '-12.01' ) );
	}

	public function test_format(): void {
		self::assertSame( '12.34', Money::format( 1234 ) );
		self::assertSame( '0.05', Money::format( 5 ) );
		self::assertSame( '0.00', Money::format( 0 ) );
		self::assertSame( '-12.01', Money::format( -1201 ) );
		self::assertSame( '1234.00', Money::format( 123400 ) );
	}

	public function test_round_trip(): void {
		self::assertSame( '2.01', Money::format( Money::to_cents( '2.005' ) ) );
	}

	public function test_invalid_amount_throws(): void {
		$this->expectException( InvalidArgumentException::class );
		Money::to_cents( 'R100,00' );
	}
}
