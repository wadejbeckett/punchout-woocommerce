<?php
/**
 * Setup rate limiting (scope §10: burst rejected, legitimate retry
 * unaffected).
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Http\RateLimiter;

final class RateLimiterTest extends TestCase {

	/** @var array<string, int> */
	private array $store = [];

	private function limiter( int $per_minute ): RateLimiter {
		return new RateLimiter(
			$per_minute,
			fn( string $key ): int => $this->store[ $key ] ?? 0,
			function ( string $key, int $count ): void {
				$this->store[ $key ] = $count;
			}
		);
	}

	protected function setUp(): void {
		$this->store = [];
	}

	public function test_burst_rejected_at_threshold(): void {
		$limiter = $this->limiter( 3 );

		self::assertTrue( $limiter->allow( 'p1|1.2.3.4' ) );
		self::assertTrue( $limiter->allow( 'p1|1.2.3.4' ) );
		self::assertTrue( $limiter->allow( 'p1|1.2.3.4' ) );
		self::assertFalse( $limiter->allow( 'p1|1.2.3.4' ), 'fourth request in the window must be rejected' );
	}

	public function test_buckets_are_independent(): void {
		$limiter = $this->limiter( 1 );

		self::assertTrue( $limiter->allow( 'p1|1.2.3.4' ) );
		self::assertFalse( $limiter->allow( 'p1|1.2.3.4' ) );
		// A different partner or IP is a different bucket: the legitimate
		// retry from elsewhere is unaffected by someone else's burst.
		self::assertTrue( $limiter->allow( 'p2|1.2.3.4' ) );
		self::assertTrue( $limiter->allow( 'p1|5.6.7.8' ) );
	}

	public function test_zero_disables_limiting(): void {
		$limiter = $this->limiter( 0 );

		for ( $i = 0; $i < 100; $i++ ) {
			self::assertTrue( $limiter->allow( 'p1|1.2.3.4' ) );
		}
	}
}
