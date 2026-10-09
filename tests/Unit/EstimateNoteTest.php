<?php
declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Addresses\EstimateNote;

require_once dirname( __DIR__, 2 ) . '/includes/Settings.php';
require_once dirname( __DIR__, 2 ) . '/includes/Addresses/EstimateNote.php';

/** The delivery-estimate note that travels with every estimate (0.4.19). */
final class EstimateNoteTest extends TestCase {
	public function test_the_note_goes_first_and_the_buyer_notes_follow(): void {
		self::assertSame( "Estimate only.\nLeave at reception.", EstimateNote::compose( 'Estimate only.', 'Leave at reception.' ) );
		self::assertSame( 'Estimate only.', EstimateNote::compose( ' Estimate only. ', '' ) );
		self::assertSame( 'Leave at reception.', EstimateNote::compose( '', 'Leave at reception.' ) );
		self::assertSame( '', EstimateNote::compose( '', '' ) );
	}

	public function test_the_address_leads_and_only_the_buyer_notes_are_shortened(): void {
		self::assertSame( "Deliver to: 1 Demo Street\nEstimate only.\nLeave at reception.", EstimateNote::compose( 'Estimate only.', 'Leave at reception.', 'Deliver to: 1 Demo Street' ) );
		$capped = EstimateNote::compose( 'Estimate only.', str_repeat( 'n', 3000 ), 'Deliver to: 1 Demo Street', 2000 );
		self::assertSame( 2000, mb_strlen( $capped ) );
		self::assertTrue( str_starts_with( $capped, "Deliver to: 1 Demo Street\nEstimate only.\n" ) );
	}

	public function test_the_freight_description_carries_the_note_within_the_wire_limit(): void {
		self::assertSame( 'Courier (1-2 working days) — Estimate only.', EstimateNote::line_description( 'Courier (1-2 working days)', 'Estimate only.' ) );
		self::assertSame( 'Courier', EstimateNote::line_description( 'Courier', '' ) );
		$long = EstimateNote::line_description( 'Courier', str_repeat( 'x', 300 ), 50 );
		self::assertSame( 50, mb_strlen( $long ) );
		self::assertTrue( str_ends_with( $long, '…' ) );
	}
}
