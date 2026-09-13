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
 * Database-backed atomic counter for a named bucket, with a fixed window duration per limiter and injectable storage for deterministic unit boundaries.
 *
 * Window-specific keys separate minute and hourly consumers. Introducing the window namespace resets existing live counters once on deploy.
 */
final class RateLimiter {

	/** @var callable(string): int */
	private $get;

	/** @var callable(string, int): void */
	private $set;

	/** @var callable(string, int, int): bool */
	private $consume;

	/**
	 * @param int           $per_minute Threshold per window (<= 0 disables limiting).
	 * @param callable|null $get        fn(string $key): int — current count.
	 * @param callable|null $set        fn(string $key, int $count): void — store with the window TTL.
	 * @param int           $window_seconds Fixed window duration in seconds (default 60).
	 * @param callable|null $consume         Atomic fn(string $key, int $limit, int $window): bool. Production defaults to RateLimitStore; tests may inject a deterministic boundary.
	 */
	public function __construct(
		private int $per_minute,
		?callable $get = null,
		?callable $set = null,
		private int $window_seconds = 60,
		?callable $consume = null,
	) {
		if ( null !== $consume ) {
			$this->consume = $consume;
			$this->get = static fn(): int => 0;
			$this->set = static function (): void {};
			return;
		}
		if ( null === $get && null === $set ) {
			global $wpdb;
			if ( isset( $wpdb ) && class_exists( '\\wpdb', false ) && $wpdb instanceof \wpdb ) {
				$store = new RateLimitStore();
				$this->consume = [ $store, 'consume' ];
				$this->get = static fn(): int => 0;
				$this->set = static function (): void {};
				return;
			}
			$this->consume = static fn(): bool => false;
			$this->get = static fn(): int => 0;
			$this->set = static function (): void {};
			return;
		}
		if ( null === $get || null === $set ) {
			throw new \InvalidArgumentException( 'Both rate-limit storage callbacks are required.' );
		}
		$this->get = $get;
		$this->set = $set;
		$this->consume = function ( string $key, int $limit ): bool {
			$count = ( $this->get )( $key );
			if ( $count >= $limit ) {
				return false;
			}
			return false !== ( $this->set )( $key, $count + 1 );
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

		if ( $this->window_seconds <= 0 ) {
			return false;
		}
		$key = 'pow_rl_' . $this->window_seconds . '_' . md5( $bucket );
		try {
			return true === ( $this->consume )( $key, $this->per_minute, $this->window_seconds );
		} catch ( \Throwable $error ) {
			return false;
		}
	}
}
