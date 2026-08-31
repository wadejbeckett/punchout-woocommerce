<?php
/**
 * Per-partner ALL CAPS toggle (client requirement 2026-08-31): mixed-case
 * cart -> uppercased POOM when on, untouched when off; never the stored
 * catalogue.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Cart\PoomMapper;
use POW\Partners\Partner;

final class AllCapsTransformTest extends TestCase {

	/** @return list<array<string, mixed>> */
	private function mixed_case_items(): array {
		return [
			[
				'quantity'         => 2,
				'supplier_part_id' => 'CcSku-001a',
				'aux_id'           => '123|0',
				'unit_price_cents' => 1050,
				'description'      => 'Blue Overall größe M',
				'short_name'       => 'Blue Overall',
				'uom'              => 'ea',
				'classification'   => '53102500',
			],
		];
	}

	public function test_toggle_defaults_off(): void {
		self::assertFalse( Partner::from_row( [ 'id' => 1, 'name' => 'X' ] )->allcaps_transform );
		self::assertTrue( Partner::from_row( [ 'id' => 1, 'name' => 'X', 'allcaps_transform' => '1' ] )->allcaps_transform );
	}

	public function test_uppercases_every_text_field(): void {
		$out = PoomMapper::uppercase_items( $this->mixed_case_items() )[0];

		self::assertSame( 'CCSKU-001A', $out['supplier_part_id'] );
		self::assertSame( 'BLUE OVERALL', $out['short_name'] );
		self::assertSame( 'EA', $out['uom'] );
		self::assertSame( '53102500', $out['classification'] );

		// Multibyte-aware where mbstring is available (ß has no single
		// uppercase char; mb_strtoupper expands it to SS).
		$expected = function_exists( 'mb_strtoupper' ) ? 'BLUE OVERALL GRÖSSE M' : strtoupper( 'Blue Overall größe M' );
		self::assertSame( $expected, $out['description'] );
	}

	public function test_non_text_fields_untouched(): void {
		$out = PoomMapper::uppercase_items( $this->mixed_case_items() )[0];

		self::assertSame( 2, $out['quantity'] );
		self::assertSame( 1050, $out['unit_price_cents'] );
		self::assertSame( '123|0', $out['aux_id'], 'the correlation key is ours, not display data' );
	}

	public function test_original_array_not_mutated(): void {
		$items = $this->mixed_case_items();
		PoomMapper::uppercase_items( $items );

		self::assertSame( 'CcSku-001a', $items[0]['supplier_part_id'], 'transform must copy, never mutate its input' );
	}
}
