<?php
/**
 * Browser form-packing for the cart return leg.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Cxml;

defined( 'ABSPATH' ) || exit;

/**
 * Encodes a PunchOutOrderMessage into the hidden form field the buyer's
 * browser POSTs to the BrowserFormPost URL (cXML Reference Guide §3 "Form
 * Packing"; scope §6.2).
 *
 * Two encodings, selected per partner:
 *
 * - `cxml-base64` (default): the whole document base64-encoded (RFC 2045).
 *   Survives transport untouched, so UTF-8 content (ZA product names) is
 *   safe and the XML declaration's encoding is honoured.
 * - `cxml-urlencoded`: the raw document, which per spec must then be pure
 *   us-ascii because no charset survives a form POST — every non-ASCII
 *   character becomes a numeric character reference. The server must NOT
 *   URL-encode the value (the browser does on submit); the renderer only
 *   HTML-escapes it into the attribute.
 *
 * Which one D365's basket wizard accepts is UNVERIFIED either way — hence
 * the per-partner switch (scope §6.2).
 *
 * Pure PHP, no WordPress or mbstring dependencies.
 */
final class FormPack {

	public const ENCODING_BASE64     = 'base64';
	public const ENCODING_URLENCODED = 'urlencoded';

	/**
	 * Build the hidden-field name/value pair for a POOM document.
	 *
	 * @param string $xml      The serialised PunchOutOrderMessage document.
	 * @param string $encoding self::ENCODING_* (unknown values fall back to base64).
	 * @return array{name: string, value: string}
	 */
	public static function field( string $xml, string $encoding ): array {
		if ( self::ENCODING_URLENCODED === $encoding ) {
			return [
				'name'  => 'cxml-urlencoded',
				'value' => self::to_ascii_entities( $xml ),
			];
		}

		return [
			'name'  => 'cxml-base64',
			'value' => base64_encode( $xml ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		];
	}

	/**
	 * Replace every non-ASCII character in a UTF-8 string with a numeric
	 * character reference, leaving the ASCII bytes untouched.
	 *
	 * Hand-rolled UTF-8 decoding rather than mb_encode_numericentity so the
	 * codec carries no mbstring requirement. Invalid sequences become
	 * U+FFFD rather than corrupting the document.
	 */
	public static function to_ascii_entities( string $utf8 ): string {
		$out = '';
		$len = strlen( $utf8 );

		for ( $i = 0; $i < $len; $i++ ) {
			$byte = ord( $utf8[ $i ] );

			if ( $byte < 0x80 ) {
				$out .= $utf8[ $i ];
				continue;
			}

			if ( 0xC0 === ( $byte & 0xE0 ) ) {
				$need      = 1;
				$codepoint = $byte & 0x1F;
			} elseif ( 0xE0 === ( $byte & 0xF0 ) ) {
				$need      = 2;
				$codepoint = $byte & 0x0F;
			} elseif ( 0xF0 === ( $byte & 0xF8 ) ) {
				$need      = 3;
				$codepoint = $byte & 0x07;
			} else {
				// Stray continuation or invalid lead byte.
				$out .= '&#65533;';
				continue;
			}

			for ( $j = 0; $j < $need; $j++ ) {
				$i++;

				if ( $i >= $len || 0x80 !== ( ord( $utf8[ $i ] ) & 0xC0 ) ) {
					$codepoint = 0xFFFD;
					$i--; // Re-examine the unexpected byte in the outer loop.
					break;
				}

				$codepoint = ( $codepoint << 6 ) | ( ord( $utf8[ $i ] ) & 0x3F );
			}

			$out .= '&#' . $codepoint . ';';
		}

		return $out;
	}
}
