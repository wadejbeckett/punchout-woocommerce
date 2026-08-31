<?php
/**
 * Form packing: both encodings + us-ascii entity escaping (scope §10).
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Cxml\FormPack;

final class FormPackTest extends TestCase {

	public function test_base64_round_trips_utf8(): void {
		$xml   = '<?xml version="1.0" encoding="UTF-8"?><cXML><Description>Café Zoë — R100</Description></cXML>';
		$field = FormPack::field( $xml, FormPack::ENCODING_BASE64 );

		self::assertSame( 'cxml-base64', $field['name'] );
		self::assertSame( $xml, base64_decode( $field['value'], true ) );
	}

	public function test_unknown_encoding_falls_back_to_base64(): void {
		self::assertSame( 'cxml-base64', FormPack::field( '<cXML/>', 'bogus' )['name'] );
	}

	public function test_urlencoded_is_pure_ascii_with_numeric_entities(): void {
		$field = FormPack::field( '<D>Café – ZAR</D>', FormPack::ENCODING_URLENCODED );

		self::assertSame( 'cxml-urlencoded', $field['name'] );
		self::assertSame( '<D>Caf&#233; &#8211; ZAR</D>', $field['value'] );
		self::assertMatchesRegularExpression( '/^[\x00-\x7F]*$/', $field['value'] );
	}

	public function test_urlencoded_value_is_not_url_encoded(): void {
		// The spec is explicit: the server must NOT URL-encode the value —
		// the browser does that on submit.
		$field = FormPack::field( '<A b="1 &amp; 2"/>', FormPack::ENCODING_URLENCODED );

		self::assertStringContainsString( '<A b="1 &amp; 2"/>', $field['value'] );
	}

	public function test_four_byte_sequences(): void {
		// U+1F600 (4-byte sequence) must map to one numeric reference.
		self::assertSame( '&#128512;', FormPack::to_ascii_entities( "\u{1F600}" ) );
	}

	public function test_invalid_bytes_become_replacement_character(): void {
		self::assertSame( '&#65533;x', FormPack::to_ascii_entities( "\x80x" ) );
	}
}
