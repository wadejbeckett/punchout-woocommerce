<?php
/**
 * Setup-endpoint rate limiting.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Transient-backed counter for a named bucket, with a fixed window duration per limiter and injectable storage. Each accepted hit refreshes the transient TTL; rejected hits do not write. This is a blunt anti-abuse counter, not atomic or precise traffic shaping.
 *
 * Window-specific keys separate minute and hourly consumers. Introducing the window namespace resets existing live counters once on deploy.
 */
final class RateLimiter {

	/** @var callable(string): int */
	private $get;

	/** @var callable(string, int): void */
	private $set;

	/**
	 * @param int           $per_minute Threshold per window (<= 0 disables limiting).
	 * @param callable|null $get        fn(string $key): int — current count.
	 * @param callable|null $set        fn(string $key, int $count): void — store with the window TTL.
	 * @param int           $window_seconds Fixed window duration in seconds (default 60).
	 */
	public function __construct(
		private int $per_minute,
		?callable $get = null,
		?callable $set = null,
		private int $window_seconds = 60,
	) {
		$this->get = $get ?? static fn( string $key ): int => (int) get_transient( $key );
		$this->set = $set ?? function ( string $key, int $count ): void {
			set_transient( $key, $count, $this->window_seconds );
		};
	}

	/** Public flows must stay bounded while preserving every positive configured threshold. */
	public static function public_limit( int $configured, int $fallback ): int {
		if ( $fallback <= 0 ) {
			throw new \InvalidArgumentException( 'Public rate-limit fallback must be positive' );
		}

		return $configured > 0 ? $configured : $fallback;
	}

	/**
	 * Count a hit against a bucket; false when the bucket is over limit.
	 */
	public function allow( string $bucket ): bool {
		if ( $this->per_minute <= 0 ) {
			return true;
		}

		$key   = 'pow_rl_' . $this->window_seconds . '_' . md5( $bucket );
		$count = ( $this->get )( $key );

		if ( $count >= $this->per_minute ) {
			return false;
		}

		( $this->set )( $key, $count + 1 );

		return true;
	}
}
