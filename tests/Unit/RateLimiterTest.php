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

	private array $saved_globals = [];

	private function limiter( int $per_minute, int $window_seconds = 60 ): RateLimiter {
		return new RateLimiter(
			$per_minute,
			fn( string $key ): int => $this->store[ $key ] ?? 0,
			function ( string $key, int $count ): void {
				$this->store[ $key ] = $count;
			},
			$window_seconds
		);
	}

	protected function setUp(): void {
		$this->store = [];
		foreach ( [ 'pow_test_options', 'pow_test_transients', 'pow_test_transient_expirations' ] as $key ) {
			$this->saved_globals[ $key ] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null ];
			$GLOBALS[ $key ] = [];
		}
	}

	protected function tearDown(): void {
		foreach ( $this->saved_globals as $key => [ $existed, $value ] ) {
			if ( $existed ) {
				$GLOBALS[ $key ] = $value;
			} else {
				unset( $GLOBALS[ $key ] );
			}
		}
	}

	public function test_public_limit_preserves_positive_thresholds(): void {
		foreach ( [ 10, 30, 120, 5 ] as $fallback ) {
			foreach ( [ 1, 3, 9, 30 ] as $configured ) {
				self::assertSame( $configured, RateLimiter::public_limit( $configured, $fallback ) );
			}
		}
	}

	public function test_public_limit_defaults_only_nonpositive_thresholds(): void {
		foreach ( [ 10, 30, 120, 5 ] as $fallback ) {
			self::assertSame( $fallback, RateLimiter::public_limit( 0, $fallback ) );
			self::assertSame( $fallback, RateLimiter::public_limit( -5, $fallback ) );
		}
	}

	public function test_public_limit_rejects_zero_fallback_even_with_positive_configuration(): void {
		$this->expectException( \InvalidArgumentException::class );
		RateLimiter::public_limit( 3, 0 );
	}

	public function test_public_limit_rejects_negative_fallback(): void {
		$this->expectException( \InvalidArgumentException::class );
		RateLimiter::public_limit( 0, -1 );
	}

	public function test_window_length_namespaces_the_bucket(): void {
		$minute = $this->limiter( 5 );
		$hour   = $this->limiter( 5, 3600 );
		for ( $i = 0; $i < 5; $i++ ) {
			self::assertTrue( $minute->allow( 'acct|u7' ) );
		}
		self::assertFalse( $minute->allow( 'acct|u7' ) );
		self::assertTrue( $hour->allow( 'acct|u7' ) );
	}

	public function test_hourly_window_holds_five(): void {
		$hour = $this->limiter( 5, 3600 );
		for ( $i = 0; $i < 5; $i++ ) {
			self::assertTrue( $hour->allow( 'acct|i203.0.113.9' ) );
		}
		self::assertFalse( $hour->allow( 'acct|i203.0.113.9' ) );
	}

	public function test_default_setter_passes_each_window_ttl_and_rejection_does_not_write(): void {
		$GLOBALS['pow_test_transients'] = [];
		$GLOBALS['pow_test_transient_expirations'] = [];
		foreach ( [ 60, 3600 ] as $seconds ) {
			$limiter = new RateLimiter( 1, null, null, $seconds );
			self::assertTrue( $limiter->allow( 'shared' ) );
			$key = 'pow_rl_' . $seconds . '_' . md5( 'shared' );
			self::assertSame( 1, $GLOBALS['pow_test_transients'][ $key ] ?? null );
			self::assertSame( [ $seconds ], $GLOBALS['pow_test_transient_expirations'][ $key ] ?? null );
			self::assertFalse( $limiter->allow( 'shared' ) );
			self::assertSame( [ $seconds ], $GLOBALS['pow_test_transient_expirations'][ $key ] );
		}
	}

	public function test_public_settings_preserve_positive_values_and_secure_defaults(): void {
		$admin = ( new \ReflectionClass( \POW\Admin\Page::class ) )->newInstanceWithoutConstructor();
		self::assertSame( 120, ( new \POW\Settings() )->int( 'edge_rate_limit_per_min' ) );
		self::assertSame( 120, $admin->sanitize_settings( [] )['edge_rate_limit_per_min'] );
		foreach ( [ 1, 3, 9, 30 ] as $configured ) {
			$clean = $admin->sanitize_settings( [ 'rate_limit_per_min' => $configured, 'edge_rate_limit_per_min' => $configured ] );
			self::assertSame( $configured, $clean['rate_limit_per_min'] );
			self::assertSame( $configured, $clean['edge_rate_limit_per_min'] );
		}
		foreach ( [ 0, -5 ] as $configured ) {
			$clean = $admin->sanitize_settings( [ 'rate_limit_per_min' => $configured, 'edge_rate_limit_per_min' => $configured ] );
			self::assertSame( 0, $clean['rate_limit_per_min'] );
			self::assertSame( 0, $clean['edge_rate_limit_per_min'] );
		}
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
