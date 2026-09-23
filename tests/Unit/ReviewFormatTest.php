<?php
/** Review-page presentation: store money format, line totals, province and country names, site logo. */
declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Addresses\ReviewFormat;

final class ReviewFormatTest extends TestCase {
	public function test_money_uses_the_store_price_format_and_strips_markup(): void {
		$text = ReviewFormat::money( 161986, 'ZAR' );
		self::assertSame( "R\u{00A0}1 619,86", $text );
		self::assertStringNotContainsString( '<', $text );
	}
	public function test_money_forces_two_decimals_to_match_the_cxml_cents(): void {
		unset( $GLOBALS['pow_test_wc_price_args'] );
		ReviewFormat::money( 5, 'ZAR' );
		self::assertSame( 2, $GLOBALS['pow_test_wc_price_args']['decimals'] ?? null );
		self::assertSame( 'ZAR', $GLOBALS['pow_test_wc_price_args']['currency'] ?? null );
	}
	public function test_money_follows_store_separators(): void {
		$GLOBALS['pow_test_price_thousand_sep'] = ''; $GLOBALS['pow_test_price_format'] = '%1$s%2$s';
		try { self::assertSame( 'R1619,86', ReviewFormat::money( 161986, 'ZAR' ) ); }
		finally { unset( $GLOBALS['pow_test_price_thousand_sep'], $GLOBALS['pow_test_price_format'] ); }
	}
	public function test_money_falls_back_to_the_cxml_amount_when_the_formatter_yields_nothing(): void {
		$GLOBALS['pow_test_price_format'] = '';
		try { self::assertSame( 'ZAR 1619.86', ReviewFormat::money( 161986, 'ZAR' ) ); }
		finally { unset( $GLOBALS['pow_test_price_format'] ); }
	}
	public function test_line_total_uses_the_confirmation_rounding(): void {
		$items = [ [ 'unit_price_cents' => 53995, 'quantity' => 3 ], [ 'unit_price_cents' => 333, 'quantity' => '1.5' ], [ 'unit_price_cents' => 1, 'quantity' => 1 ] ];
		self::assertSame( 161985, ReviewFormat::line_total_cents( $items[0] ) );
		self::assertSame( 500, ReviewFormat::line_total_cents( $items[1] ) );
		$sum = 0; $map_sum = 0;
		foreach ( $items as $line ) { $sum += ReviewFormat::line_total_cents( $line ); $map_sum += (int) round( $line['unit_price_cents'] * (float) $line['quantity'] ); }
		self::assertSame( $map_sum, $sum );
	}
	private static function with_countries( ?object $countries, callable $run ): void {
		$before = $GLOBALS['pow_test_wc'] ?? null;
		$wc = new class() extends POW_Test_WC { public mixed $countries = null; };
		$wc->countries = $countries; $GLOBALS['pow_test_wc'] = $wc;
		try { $run(); } finally { if ( null === $before ) { unset( $GLOBALS['pow_test_wc'] ); } else { $GLOBALS['pow_test_wc'] = $before; } }
	}
	private static function countries_double( mixed $states = [ 'GP' => 'Gauteng' ] ): object {
		return new class( $states ) {
			public function __construct( private mixed $states ) {}
			public function get_states( string $country ): mixed { return 'ZA' === $country ? $this->states : false; }
			public function get_countries(): array { return [ 'ZA' => 'South Africa', 'AX' => '&#197;land Islands' ]; }
		};
	}
	public function test_address_lines_show_province_and_country_names(): void {
		self::with_countries( self::countries_double(), static function (): void {
			$address = [ 'first_name' => 'Thys', 'address_1' => '1 Depot Road', 'address_2' => '', 'city' => 'Pretoria', 'state' => 'GP', 'postcode' => '0157', 'country' => 'ZA', 'phone' => '0123456789' ];
			self::assertSame( [ 'Thys', '1 Depot Road', 'Pretoria', 'Gauteng', '0157', 'South Africa' ], ReviewFormat::address_lines( $address ) );
			self::assertSame( [ 'Mariehamn', 'Åland Islands' ], ReviewFormat::address_lines( [ 'city' => 'Mariehamn', 'country' => 'AX' ] ) );
		} );
	}
	public function test_address_lines_fall_back_to_codes(): void {
		$address = [ 'city' => 'Pretoria', 'state' => 'GP', 'country' => 'ZA', 'phone' => '012' ];
		$before = $GLOBALS['pow_test_wc'] ?? null; unset( $GLOBALS['pow_test_wc'] );
		try { self::assertSame( [ 'Pretoria', 'GP', 'ZA' ], ReviewFormat::address_lines( $address ) ); }
		finally { if ( null !== $before ) { $GLOBALS['pow_test_wc'] = $before; } }
		self::with_countries( null, static fn() => self::assertSame( [ 'Pretoria', 'GP', 'ZA' ], ReviewFormat::address_lines( $address ) ) );
		self::with_countries( self::countries_double( false ), static fn() => self::assertSame( [ 'Pretoria', 'GP', 'South Africa' ], ReviewFormat::address_lines( $address ) ) );
		self::with_countries( self::countries_double(), static fn() => self::assertSame( [ 'XX', 'ZZ' ], ReviewFormat::address_lines( [ 'state' => 'XX', 'country' => 'ZZ' ] ) ) );
	}
	public function test_logo_html_is_the_native_custom_logo_or_empty(): void {
		unset( $GLOBALS['pow_test_custom_logo'] );
		self::assertSame( '', ReviewFormat::logo_html() );
		$logo = '<a href="https://shop.example.test/" class="custom-logo-link" rel="home"><img src="https://shop.example.test/logo.png" class="custom-logo" alt="Example shop"></a>';
		$GLOBALS['pow_test_custom_logo'] = $logo;
		try { self::assertSame( $logo, ReviewFormat::logo_html() ); } finally { unset( $GLOBALS['pow_test_custom_logo'] ); }
	}
}
