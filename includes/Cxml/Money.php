<?php
/**
 * The single float -> money boundary.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Cxml;

defined( 'ABSPATH' ) || exit;

/**
 * Cents-integer money handling for cXML documents.
 *
 * The POOM must quote exactly the numbers the buyer would have paid at
 * checkout, and the conversion from WooCommerce's float prices happens in
 * exactly one place with one rounding policy (scope §6.2, the cents
 * boundary flagged in cxml-protocol §6): half-up at the second decimal.
 *
 * Pure PHP, no WordPress dependencies — unit-tested without a WP install.
 */
final class Money {

	/**
	 * Convert an amount to integer cents, rounding half-up at cents.
	 *
	 * Floats are first pinned to four decimal places — beyond any real
	 * currency input, so no information is lost — which converts the binary
	 * float to a decimal string before the half-up rule is applied. That
	 * keeps the policy deterministic instead of at the mercy of binary
	 * representation (2.005 stored as 2.00499999… would otherwise round
	 * down or up depending on the path).
	 *
	 * @param float|string $amount Decimal amount ("12.34" preferred).
	 */
	public static function to_cents( float|string $amount ): int {
		if ( is_float( $amount ) ) {
			$amount = sprintf( '%.4F', $amount );
		}

		$amount = trim( $amount );

		if ( ! preg_match( '/^(-?)(\d+)(?:\.(\d+))?$/', $amount, $m ) ) {
			throw new \InvalidArgumentException( "Not a decimal amount: {$amount}" );
		}

		$sign     = ( '-' === $m[1] ) ? -1 : 1;
		$whole    = (int) $m[2];
		$fraction = $m[3] ?? '';
		$fraction = str_pad( $fraction, 3, '0' );

		$cents = ( (int) $fraction[0] ) * 10 + (int) $fraction[1];

		// Half-up on the third decimal digit (any further digits only make
		// the remainder larger, so the third digit alone decides >= 5).
		if ( (int) $fraction[2] >= 5 ) {
			$cents++;
		}

		return $sign * ( $whole * 100 + $cents );
	}

	/**
	 * Format integer cents as a cXML Money string ("1234.50").
	 */
	public static function format( int $cents ): string {
		$sign  = $cents < 0 ? '-' : '';
		$cents = abs( $cents );

		return sprintf( '%s%d.%02d', $sign, intdiv( $cents, 100 ), $cents % 100 );
	}
}
