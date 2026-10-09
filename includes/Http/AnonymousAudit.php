<?php
/**
 * The audit-log budget for requests nobody has authenticated.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Anyone can call /punchout/setup, /punchout/start/{token} and /punchout/order, and each used to write audit rows
 * before (or without) proving who it was: a setup request kept up to 64 KB of its body for the log's whole
 * retention, and a start or order request wrote a row every time (security audit FILES-03 / AUTH-05). Since
 * 0.4.22:
 *
 * - Rows written for a request that has not authenticated count against a per-IP budget: PER_HOUR requests an
 *   hour from one address are logged, later ones are still answered but leave no row. One request uses one
 *   permit however many rows it writes. An authenticated setup (the sender's shared secret verified) and a
 *   redeemed StartPage login are never counted.
 * - An unauthenticated setup body is kept as an excerpt: the first EXCERPT_BYTES bytes (identities and the
 *   shared secret blanked first), and for a longer body its length and SHA-256. An authenticated body keeps the
 *   64 KB archive it always had.
 *
 * The budget is a database-backed fixed window (RateLimiter over RateLimitStore). A null limiter means no
 * budget, which is what a caller that constructs an endpoint without one gets.
 */
final class AnonymousAudit {

	/** Unauthenticated requests per IP per hour whose audit rows are written. */
	public const PER_HOUR = 60;

	/** Bytes of an unauthenticated setup body kept in the log. */
	public const EXCERPT_BYTES = 4096;

	/** @var array<string, bool> This request's answer per IP, so one request uses one permit. */
	private array $decided = [];

	public function __construct( private ?RateLimiter $limiter = null ) {}

	/** The production budget: PER_HOUR requests per IP in a one-hour window. */
	public static function hourly(): self {
		return new self( new RateLimiter( self::PER_HOUR, null, null, 3600 ) );
	}

	/** Whether this request from $ip may write its unauthenticated audit rows. Decided once per request. */
	public function allow( string $ip ): bool {
		if ( null === $this->limiter ) {
			return true;
		}

		return $this->decided[ $ip ] ??= $this->limiter->allow( 'anon-audit|' . $ip );
	}

	/** The request is over: forget its decisions (an endpoint object can serve more than one request). */
	public function done(): void {
		$this->decided = [];
	}

	/**
	 * What the log keeps of an unauthenticated setup body: $archive (already redacted) when it fits, else its first
	 * EXCERPT_BYTES bytes, cut on a character boundary, and a comment with the full body's length and SHA-256.
	 */
	public static function excerpt( string $archive, string $body ): string {
		if ( strlen( $archive ) <= self::EXCERPT_BYTES ) {
			return $archive;
		}

		return mb_strcut( $archive, 0, self::EXCERPT_BYTES, 'UTF-8' )
			. "\n<!-- pow: unauthenticated request, first " . self::EXCERPT_BYTES . ' bytes kept; ' . strlen( $body ) . ' bytes, sha256 ' . hash( 'sha256', $body ) . ' -->';
	}
}
