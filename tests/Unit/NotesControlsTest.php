<?php
declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Addresses\Confirmation;

/**
 * Delivery notes carry only what cXML (XML 1.0) can (0.4.22). A pasted vertical tab or form feed used to pass
 * the review and then make every Submit fail in the builder.
 */
final class NotesControlsTest extends TestCase {
	/** The character set the cXML builder accepts for a text value. */
	private static function xml_safe( string $text ): bool {
		return 1 === preg_match( '/\A[\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]*\z/u', $text );
	}

	public function test_a_pasted_line_break_stays_a_line_break(): void {
		self::assertSame( "Gate\nbell\nside door", Confirmation::strip_controls( "Gate\x0Bbell\x0Cside door" ) );
	}

	public function test_other_c0_controls_are_removed_and_tab_line_feed_and_return_are_kept(): void {
		$controls = '';
		foreach ( array_merge( range( 0, 8 ), range( 0x0E, 0x1F ) ) as $code ) { $controls .= chr( $code ); }
		self::assertSame( "a\tb\nc\rd", Confirmation::strip_controls( "a\tb\nc\rd" . $controls ) );
		self::assertSame( 'Ring the bell', Confirmation::strip_controls( "Ring\x07 the\x1B bell\x00" ) );
	}

	public function test_the_two_xml_non_characters_go_and_ordinary_text_stays(): void {
		self::assertSame( 'Café — 2 × 🚚', Confirmation::strip_controls( "Caf\u{E9} \u{2014} 2 \u{D7} \u{1F69A}\u{FFFE}\u{FFFF}" ) );
	}

	public function test_whatever_comes_out_is_text_the_builder_accepts(): void {
		$all = '';
		for ( $code = 0; $code < 0x80; ++$code ) { $all .= chr( $code ); }
		$all .= "\u{FFFE}\u{FFFF}\u{FFFD}";
		$clean = Confirmation::strip_controls( $all );
		self::assertTrue( self::xml_safe( $clean ) );
		self::assertTrue( 1 === preg_match( '//u', $clean ), 'Valid UTF-8 out' );
		self::assertFalse( self::xml_safe( $all ), 'The input really was refusable' );
	}
}
