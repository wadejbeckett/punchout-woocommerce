<?php
/**
 * Variable products in the returned cart: one ItemIn per chosen variation.
 *
 * The purchasing system keys the line on SupplierPartID (the variation's
 * own SKU) and shows the buyer the Description/ShortName; it never reads
 * SupplierPartAuxiliaryID. So the size and colour the buyer picked must be
 * in the text, or two sizes of one shirt arrive as two identical lines.
 * WooCommerce puts the attribute values in a variation's title only
 * sometimes — not with three or more attributes, not for a value the buyer
 * chose under "Any …" — so the mapper completes the name from the cart
 * line's own attributes, adding only values the title does not already
 * show.
 *
 * Runs the real PoomMapper over a stub cart and the real Builder; the
 * WooCommerce formatting helpers are the guarded stubs in wc-stubs.php
 * (the native suite VariationLineNative proves the same against real
 * WooCommerce).
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Cart\PoomMapper;
use POW\Cxml\Builder;
use POW\Partners\Partner;

final class PoomVariationLineTest extends TestCase {

	/** @var array<string, mixed> */
	private array $saved = [];

	protected function setUp(): void {
		if ( ! extension_loaded( 'dom' ) ) {
			self::markTestSkipped( 'ext-dom not available' );
		}
		foreach ( [ 'pow_test_wc', 'pow_test_attribute_terms' ] as $key ) {
			$this->saved[ $key ] = $GLOBALS[ $key ] ?? null;
		}
		$GLOBALS['pow_test_attribute_terms'] = [
			'pa_colour' => [ 'black' => 'Black', 'as-supplied' => 'AS SUPPLIED', 'red' => 'Red', 'navy' => 'Navy' ],
			'pa_size'   => [ 'as-supplied' => 'As Supplied' ],
		];
	}

	protected function tearDown(): void {
		foreach ( $this->saved as $key => $value ) {
			if ( null === $value ) {
				unset( $GLOBALS[ $key ] );
			} else {
				$GLOBALS[ $key ] = $value;
			}
		}
	}

	/**
	 * @param list<array<string, mixed>> $lines
	 * @return array{items: list<array<string, mixed>>, total_cents: int, currency: string, skipped: list<string>}
	 */
	private function map( array $lines, bool $allcaps = false ): array {
		$wc       = new POW_Test_WC();
		$wc->cart = new class( $lines ) {
			/** @param list<array<string, mixed>> $lines */
			public function __construct( private array $lines ) {}
			/** @return list<array<string, mixed>> */
			public function get_cart(): array { return $this->lines; }
		};
		$GLOBALS['pow_test_wc'] = $wc;

		$partner = Partner::from_row( [ 'id' => 7, 'name' => 'Example buyer', 'allcaps_transform' => $allcaps ? '1' : '0' ] );

		return ( new PoomMapper( new QuoteOrderTestSettings(), new QuoteOrderTestLogger() ) )->from_cart( $partner );
	}

	/** @param list<array<string, mixed>> $items */
	private function poom( array $items, int $total_cents ): DOMXPath {
		$xml = ( new Builder() )->poom(
			[
				'version'             => '1.2.008',
				'payload_id'          => 'poom-1@shop',
				'timestamp'           => '2026-10-06T09:00:00+00:00',
				'deployment_mode'     => 'test',
				'from'                => [ 'domain' => 'NetworkID', 'identity' => 'supplier' ],
				'to'                  => [ 'domain' => 'NetworkID', 'identity' => 'buyer' ],
				'sender'              => [ 'domain' => 'NetworkID', 'identity' => 'supplier' ],
				'buyer_cookie'        => 'cookie-1',
				'currency'            => 'ZAR',
				'total_cents'         => $total_cents,
				'supplier_order_info' => null,
				'items'               => $items,
			]
		);
		$doc = new DOMDocument();
		self::assertTrue( $doc->loadXML( $xml, LIBXML_NONET ) );
		return new DOMXPath( $doc );
	}

	/**
	 * @param array<string, string> $variation
	 * @return array<string, mixed>
	 */
	private static function line( int $parent, int $variation_id, string $title, string $sku, array $variation, float $quantity, float $line_total ): array {
		return [
			'data'         => new WC_Product( $variation_id, $title, $sku ),
			'product_id'   => $parent,
			'variation_id' => $variation_id,
			'variation'    => $variation,
			'quantity'     => $quantity,
			'line_total'   => $line_total,
		];
	}

	public function test_a_variation_whose_title_already_names_its_attributes_is_returned_unchanged(): void {
		// WooCommerce titles a two-attribute variation "<parent> - <values>".
		$title  = 'Ice Bucket - Black, As Supplied';
		$mapped = $this->map( [ self::line( 300, 301, $title, 'IB-1-Black-AsSupplied', [ 'attribute_pa_colour' => 'black', 'attribute_pa_size' => 'as-supplied' ], 2, 1200.00 ) ] );
		$x      = $this->poom( $mapped['items'], $mapped['total_cents'] );

		self::assertSame( 'IB-1-Black-AsSupplied', $x->evaluate( 'string(//ItemIn/ItemID/SupplierPartID)' ), 'the variation is keyed on its own SKU' );
		self::assertSame( '300|301', $x->evaluate( 'string(//ItemIn/ItemID/SupplierPartAuxiliaryID)' ), 'parent|variation correlation key' );
		self::assertSame( $title, $x->evaluate( 'string(//ItemIn/ItemDetail/Description/ShortName)' ) );
		self::assertSame( $title . $title, $x->evaluate( 'string(//ItemIn/ItemDetail/Description)' ), 'ShortName plus the description text, values not repeated' );
		self::assertSame( '600.00', $x->evaluate( 'string(//ItemIn/ItemDetail/UnitPrice/Money)' ) );
		self::assertSame( '2', $x->evaluate( 'string(//ItemIn/@quantity)' ) );
	}

	public function test_a_variation_titled_without_its_attributes_gets_them_appended(): void {
		// Three or more attributes: WooCommerce titles the variation with the parent name alone.
		$mapped = $this->map( [ self::line( 610, 611, 'Deluxe Cap', 'DC-1-R-M-C', [ 'attribute_pa_colour' => 'red', 'attribute_size' => 'M', 'attribute_fit' => 'Curved' ], 1, 80.00 ) ] );

		self::assertSame( 'Deluxe Cap - Red, M, Curved', $mapped['items'][0]['short_name'] );
		self::assertSame( 'Deluxe Cap - Red, M, Curved', $mapped['items'][0]['description'] );
		self::assertSame( '610|611', $mapped['items'][0]['aux_id'] );
	}

	public function test_a_value_chosen_under_any_is_added_to_the_values_the_title_already_shows(): void {
		// "Any size": WooCommerce's title shows only the fixed colour; the buyer's size lives on the cart line.
		$mapped = $this->map( [ self::line( 620, 621, 'Pullover - Navy', 'PO-1-NVY', [ 'attribute_pa_colour' => 'navy', 'attribute_size' => 'M' ], 3, 297.00 ) ] );

		self::assertSame( 'Pullover - Navy, M', $mapped['items'][0]['description'] );
		self::assertSame( 'Pullover - Navy, M', $mapped['items'][0]['short_name'] );
	}

	public function test_two_sizes_of_one_product_return_as_two_distinguishable_lines(): void {
		$mapped = $this->map(
			[
				self::line( 610, 611, 'Deluxe Cap', 'DC-1-R-M-C', [ 'attribute_pa_colour' => 'red', 'attribute_size' => 'M', 'attribute_fit' => 'Curved' ], 1, 80.00 ),
				self::line( 610, 612, 'Deluxe Cap', 'DC-1-R-L-C', [ 'attribute_pa_colour' => 'red', 'attribute_size' => 'L', 'attribute_fit' => 'Curved' ], 2, 160.00 ),
			],
			true
		);
		$x = $this->poom( $mapped['items'], $mapped['total_cents'] );

		self::assertSame( 2, $x->query( '//ItemIn' )->length );
		self::assertSame( 'DELUXE CAP - RED, M, CURVED', $x->evaluate( 'string(//ItemIn[1]/ItemDetail/Description/ShortName)' ), 'ALL CAPS reaches the completed name' );
		self::assertSame( 'DELUXE CAP - RED, L, CURVED', $x->evaluate( 'string(//ItemIn[2]/ItemDetail/Description/ShortName)' ) );
		self::assertSame( [ '610|611', '610|612' ], [ $x->evaluate( 'string(//ItemIn[1]//SupplierPartAuxiliaryID)' ), $x->evaluate( 'string(//ItemIn[2]//SupplierPartAuxiliaryID)' ) ] );
		self::assertSame( '240.00', $x->evaluate( 'string(//PunchOutOrderMessageHeader/Total/Money)' ) );
	}

	public function test_a_simple_product_line_is_untouched(): void {
		$mapped = $this->map(
			[
				[ 'data' => new WC_Product( 410, 'Trucker cap', 'CAP-410' ), 'product_id' => 410, 'variation_id' => 0, 'variation' => [], 'quantity' => 3, 'line_total' => 90.00 ],
				[ 'data' => new WC_Product( 420, 'Power bank', 'PB-420' ), 'product_id' => 420, 'variation_id' => 0, 'quantity' => 1, 'line_total' => 220.00 ],
			]
		);

		self::assertSame( [ 'Trucker cap', 'Power bank' ], array_column( $mapped['items'], 'description' ) );
		self::assertSame( [ 'Trucker cap', 'Power bank' ], array_column( $mapped['items'], 'short_name' ) );
		self::assertSame( [ '410|0', '420|0' ], array_column( $mapped['items'], 'aux_id' ) );
	}

	public function test_attribute_values_are_text_not_markup(): void {
		$mapped = $this->map( [ self::line( 630, 631, 'Banner', 'BN-1', [ 'attribute_finish' => 'Matte%20%26%20Hemmed', 'attribute_size' => '1 x 2m', 'attribute_pole' => '<none>' ], 1, 10.00 ) ] );
		$x      = $this->poom( $mapped['items'], $mapped['total_cents'] );

		self::assertSame( 'Banner - Matte & Hemmed, 1 x 2m, <none>', $x->evaluate( 'string(//ItemIn/ItemDetail/Description/ShortName)' ), 'escaped by the builder, decoded like WooCommerce does' );
	}
}
