<?php
/**
 * One-time StartPage token issue/verify.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Sessions;

defined( 'ABSPATH' ) || exit;

/**
 * The StartPage URL is a bearer credential — "effectively a one time key"
 * (cXML Reference Guide §5.3.3) — treated like a password-reset link:
 * 256-bit random, single-use, short TTL, only its SHA-256 stored (scope §7).
 *
 * This class is pure (issue/verify format + hashing); atomic single-use
 * redemption is the Store's one-query status flip.
 */
final class Tokens {

	/**
	 * Base64url alphabet, 43 chars for 32 bytes without padding.
	 */
	private const TOKEN_REGEX = '/^[A-Za-z0-9_-]{43}$/';

	/**
	 * Issue a fresh token.
	 *
	 * @return array{token: string, hash: string} Plaintext token (goes into
	 *         the StartPage URL, never stored) and its SHA-256 hex (stored).
	 */
	public static function issue(): array {
		$token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		return [
			'token' => $token,
			'hash'  => self::hash( $token ),
		];
	}

	public static function hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	/**
	 * Cheap format sanity check before any database lookup, so junk URL
	 * probes never reach the sessions table.
	 */
	public static function looks_valid( string $token ): bool {
		return 1 === preg_match( self::TOKEN_REGEX, $token );
	}
}
