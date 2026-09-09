<?php
/**
 * Pure delivery-code normalization and allocation from all issued claims.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Addresses;

defined( 'ABSPATH' ) || exit;

final class Codes {

	/**
	 * Allocate above the highest 3–7 digit suffix for this exact prefix, never filling gaps. Manual numeric claims share that sequence; other manual identifiers remain reserved but do not advance it.
	 *
	 * The caller supplies a list of canonical codes from ALL claims, including retired ones (array_keys of the book's claims map). Do not pass only enabled addresses. This helper neither reserves nor persists its result; the book must call it and save the claim under its existing partner mutex.
	 *
	 * An empty normalized prefix disables generation and returns ''. Malformed claims still refuse in that case. Noncanonical persisted codes are rejected rather than repaired or skipped, since either could hide a collision.
	 *
	 * @param string[] $issued Every issued code, including retired claims.
	 * @param string   $prefix Prefix to normalize, at most 24 ASCII characters.
	 * @throws \InvalidArgumentException For malformed input or noncanonical claims.
	 * @throws \OverflowException When the current prefix has reached 9999999; lower gaps are not recycled.
	 */
	public static function next( array $issued, string $prefix ): string {
		$prefix = self::sanitise_prefix( $prefix );
		if ( ! array_is_list( $issued ) ) {
			throw new \InvalidArgumentException( 'Issued codes must be a list of canonical strings.' );
		}

		$highest = 0;
		$stem    = $prefix . '-';
		foreach ( $issued as $code ) {
			if ( ! is_string( $code ) || '' === $code || self::sanitise( $code ) !== $code ) {
				throw new \InvalidArgumentException( 'Issued codes must be nonempty canonical strings.' );
			}
			if ( '' !== $prefix && str_starts_with( $code, $stem ) ) {
				$suffix = substr( $code, strlen( $stem ) );
				if ( 1 === preg_match( '/\A[0-9]{3,7}\z/', $suffix ) ) {
					$highest = max( $highest, (int) $suffix );
				}
			}
		}

		if ( '' === $prefix ) {
			return '';
		}
		if ( 9999999 === $highest ) {
			throw new \OverflowException( 'Delivery-code sequence exhausted for this prefix.' );
		}

		return $stem . str_pad( (string) ( $highest + 1 ), 3, '0', STR_PAD_LEFT );
	}

	/**
	 * Normalize a manual code to uppercase ASCII, at most 32 characters. Empty remains empty; preserving an existing code on blank input belongs to the book mutation, not this helper.
	 *
	 * @throws \InvalidArgumentException For forbidden characters or excessive length.
	 */
	public static function sanitise( string $code ): string {
		return self::normalise( $code, 32 );
	}

	/**
	 * Normalize a generation prefix to uppercase ASCII, at most 24 characters. Blank disables generation; a trailing hyphen is preserved, not merged with the generated separator.
	 *
	 * @throws \InvalidArgumentException For forbidden characters or excessive length.
	 */
	public static function sanitise_prefix( string $prefix ): string {
		return self::normalise( $prefix, 24 );
	}

	/**
	 * Only surrounding ASCII spaces and letter case normalize. Reject controls, non-ASCII and other punctuation rather than stripping, transliterating or truncating one identifier into another. Explicit ASCII translation avoids locale or extension dependencies.
	 */
	private static function normalise( string $value, int $limit ): string {
		$value = trim( $value, ' ' );
		if ( strlen( $value ) > $limit || 1 !== preg_match( '/\A[A-Za-z0-9_-]*\z/', $value ) ) {
			throw new \InvalidArgumentException( 'Delivery codes require bounded ASCII letters, digits, underscores or hyphens.' );
		}

		return strtr( $value, 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ' );
	}
}
