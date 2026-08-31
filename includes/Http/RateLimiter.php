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
 * Fixed-window counter per (partner|unknown, IP) bucket for the pre-auth
 * /punchout/setup surface (scope §7). Transient-backed in production; the
 * storage callables are injectable so the window logic is unit-testable
 * without WordPress.
 *
 * A fixed one-minute window is deliberately simple: the goal is blunting
 * brute force and XML-parser abuse on a pre-auth endpoint, not fair
 * traffic shaping. A legitimate D365 retry (a handful of requests) never
 * approaches the threshold.
 */
final class RateLimiter {

	/** @var callable(string): int */
	private $get;

	/** @var callable(string, int): void */
	private $set;

	/**
	 * @param int           $per_minute Threshold (<= 0 disables limiting).
	 * @param callable|null $get        fn(string $key): int — current count.
	 * @param callable|null $set        fn(string $key, int $count): void — store with ~60s TTL.
	 */
	public function __construct(
		private int $per_minute,
		?callable $get = null,
		?callable $set = null,
	) {
		$this->get = $get ?? static fn( string $key ): int => (int) get_transient( $key );
		$this->set = $set ?? static function ( string $key, int $count ): void {
			set_transient( $key, $count, MINUTE_IN_SECONDS );
		};
	}

	/**
	 * Count a hit against a bucket; false when the bucket is over limit.
	 */
	public function allow( string $bucket ): bool {
		if ( $this->per_minute <= 0 ) {
			return true;
		}

		$key   = 'pow_rl_' . md5( $bucket );
		$count = ( $this->get )( $key );

		if ( $count >= $this->per_minute ) {
			return false;
		}

		( $this->set )( $key, $count + 1 );

		return true;
	}
}
