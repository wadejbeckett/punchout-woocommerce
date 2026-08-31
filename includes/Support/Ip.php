<?php
/**
 * IP / CIDR matching.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Pure-PHP CIDR matcher for the per-partner IP allowlist (scope §4.1,
 * gotcha 7: buyer-side IP allowlisting is a real, undocumented blocker
 * class — ours is the mirror control). IPv4 and IPv6.
 */
final class Ip {

	/**
	 * True when $ip falls inside any of the given CIDR blocks. A bare
	 * address is treated as a /32 (v4) or /128 (v6).
	 *
	 * @param string       $ip    Remote address.
	 * @param list<string> $cidrs CIDR strings; an empty list matches nothing.
	 */
	public static function in_any( string $ip, array $cidrs ): bool {
		foreach ( $cidrs as $cidr ) {
			if ( self::in_cidr( $ip, $cidr ) ) {
				return true;
			}
		}

		return false;
	}

	public static function in_cidr( string $ip, string $cidr ): bool {
		$cidr = trim( $cidr );

		if ( '' === $cidr ) {
			return false;
		}

		if ( ! str_contains( $cidr, '/' ) ) {
			$packed_ip   = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$packed_cidr = @inet_pton( $cidr ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			return false !== $packed_ip && false !== $packed_cidr && $packed_ip === $packed_cidr;
		}

		[ $network, $bits ] = explode( '/', $cidr, 2 );

		if ( ! is_numeric( $bits ) ) {
			return false;
		}

		$bits          = (int) $bits;
		$packed_ip     = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$packed_net    = @inet_pton( trim( $network ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $packed_ip || false === $packed_net || strlen( $packed_ip ) !== strlen( $packed_net ) ) {
			return false;
		}

		$max_bits = strlen( $packed_ip ) * 8;

		if ( $bits < 0 || $bits > $max_bits ) {
			return false;
		}

		if ( 0 === $bits ) {
			return true;
		}

		$full_bytes = intdiv( $bits, 8 );
		$remainder  = $bits % 8;

		if ( $full_bytes > 0 && 0 !== substr_compare( $packed_ip, substr( $packed_net, 0, $full_bytes ), 0, $full_bytes ) ) {
			return false;
		}

		if ( 0 === $remainder ) {
			return true;
		}

		$mask = 0xFF << ( 8 - $remainder ) & 0xFF;

		return ( ord( $packed_ip[ $full_bytes ] ) & $mask ) === ( ord( $packed_net[ $full_bytes ] ) & $mask );
	}
}
