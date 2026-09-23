<?php
/**
 * Pure delivery-code normalization, allocation and claim preservation.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Addresses\Codes;

final class DeliveryCodeTest extends TestCase {

	public function test_generation_starts_at_one_with_three_digits(): void {
		self::assertSame( 'BUYER-001', Codes::next( [], 'BUYER' ) );
		self::assertSame( 'BUYER-002', Codes::next( [ 'BUYER-001' ], 'BUYER' ) );
	}

	public function test_all_issued_claims_prevent_recycling_gaps(): void {
		// The caller includes retired claims too; the missing 002 is never reused.
		self::assertSame( 'BUYER-004', Codes::next( [ 'BUYER-003', 'BUYER-001' ], 'BUYER' ) );
	}

	public function test_manual_numeric_claims_advance_the_same_sequence(): void {
		self::assertSame( 'BUYER-028', Codes::next( [ 'BUYER-027', 'BUYER-MANUAL', 'OTHER-9999999' ], 'BUYER' ) );
	}

	public function test_switching_back_to_an_old_prefix_keeps_its_claims(): void {
		$issued = [ 'OLD-001', 'OLD-009' ];
		self::assertSame( 'NEW-001', Codes::next( $issued, 'NEW' ) );
		$issued[] = 'NEW-001';
		self::assertSame( 'OLD-010', Codes::next( $issued, 'OLD' ) );
		self::assertSame( 'NEW-002', Codes::next( $issued, 'NEW' ) );
	}

	public function test_sequence_matching_uses_the_exact_prefix(): void {
		$issued = [ 'BUYER-X-9999999', 'XBUYER-9999999', 'BUYER-001-NOTE', 'BUYER-12', 'BUYER-10000000' ];
		self::assertSame( 'BUYER-001', Codes::next( $issued, 'BUYER' ) );
		self::assertSame( 'BUYER-X-001', Codes::next( [ 'BUYER-9999999' ], 'BUYER-X' ) );
	}

	public function test_suffix_grows_without_wrapping_or_truncation(): void {
		self::assertSame( 'BUYER-1000', Codes::next( [ 'BUYER-999' ], 'BUYER' ) );
		self::assertSame( 'BUYER-1000000', Codes::next( [ 'BUYER-999999' ], 'BUYER' ) );
		self::assertSame( 'BUYER-9999999', Codes::next( [ 'BUYER-9999998' ], 'BUYER' ) );
	}

	public function test_largest_prefix_and_suffix_fit_the_code_bound(): void {
		$prefix = 'ABCDEFGHIJKLMNOPQRSTUVWX';
		self::assertSame( 'ABCDEFGHIJKLMNOPQRSTUVWX-9999999', Codes::next( [ 'ABCDEFGHIJKLMNOPQRSTUVWX-9999998' ], $prefix ) );
		self::assertSame( 32, strlen( Codes::next( [ 'ABCDEFGHIJKLMNOPQRSTUVWX-9999998' ], $prefix ) ) );
	}

	public function test_exhausted_sequence_refuses_even_when_lower_gaps_exist(): void {
		$this->expectException( OverflowException::class );
		Codes::next( [ 'BUYER-9999999' ], 'BUYER' );
	}

	public function test_exhaustion_also_refuses_at_the_maximum_prefix_length(): void {
		$this->expectException( OverflowException::class );
		Codes::next( [ 'ABCDEFGHIJKLMNOPQRSTUVWX-9999999' ], 'ABCDEFGHIJKLMNOPQRSTUVWX' );
	}

	public function test_blank_prefix_disables_generation(): void {
		self::assertSame( '', Codes::next( [], '' ) );
		self::assertSame( '', Codes::next( [ 'BUYER-9999999' ], '   ' ) );
		self::assertSame( '', Codes::sanitise_prefix( '   ' ) );
		self::assertSame( '', Codes::sanitise( '' ) );
		self::assertSame( '', Codes::sanitise( '   ' ) );
	}

	public function test_ascii_case_and_surrounding_spaces_normalize_consistently(): void {
		self::assertSame( 'BUYER_A-09', Codes::sanitise( ' buyer_a-09 ' ) );
		self::assertSame( 'BUYER_A', Codes::sanitise_prefix( ' buyer_a ' ) );
		self::assertSame( 'BUYER_A-002', Codes::next( [ 'BUYER_A-001' ], ' buyer_a ' ) );
		self::assertSame( 'BUYER_A-09', Codes::sanitise( Codes::sanitise( ' buyer_a-09 ' ) ) );
	}

	public function test_valid_code_separators_are_preserved(): void {
		self::assertSame( 'A__B--C_01', Codes::sanitise( 'a__b--c_01' ) );
		self::assertSame( 'BUYER--001', Codes::next( [], 'BUYER-' ) );
		self::assertSame( '001', Codes::sanitise( '001' ) );
	}

	public function test_exact_length_manual_codes_and_prefixes_are_accepted(): void {
		self::assertSame( 'ABCDEFGHIJKLMNOPQRSTUVWXYZ012345', Codes::sanitise( 'abcdefghijklmnopqrstuvwxyz012345' ) );
		self::assertSame( 'ABCDEFGHIJKLMNOPQRSTUVWX', Codes::sanitise_prefix( 'abcdefghijklmnopqrstuvwx' ) );
	}

	public function test_overlength_values_cannot_alias_a_truncated_code_or_prefix(): void {
		$this->assert_invalid( static fn() => Codes::sanitise( 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456' ) );
		$this->assert_invalid( static fn() => Codes::sanitise_prefix( 'ABCDEFGHIJKLMNOPQRSTUVWXY' ) );
		$this->assert_invalid( static fn() => Codes::next( [ 'ABCDEFGHIJKLMNOPQRSTUVWX-001' ], 'ABCDEFGHIJKLMNOPQRSTUVWXY' ) );
	}

	public function test_punctuation_and_internal_spaces_are_rejected_not_stripped(): void {
		foreach ( [ 'BUYER 001', 'BUYER/001', 'BUYER.001', 'BUYER<001>', 'BUYER"001', 'BUYER&001', 'BUYER\\001' ] as $bad ) {
			$this->assert_invalid( static fn() => Codes::sanitise( $bad ) );
			$this->assert_invalid( static fn() => Codes::sanitise_prefix( $bad ) );
			$this->assert_invalid( static fn() => Codes::next( [ 'BUYER-001' ], $bad ) );
		}
	}

	public function test_controls_and_non_ascii_are_rejected_without_transliteration(): void {
		foreach ( [ "BUYER-001\n", "\tBUYER", "BUYER\r", "BUYER\0", "BUYER\x7f", 'BÜYER', 'ＢＵＹＥＲ', "BUYER\xc2\xa0", "BUYER\xff" ] as $bad ) {
			$this->assert_invalid( static fn() => Codes::sanitise( $bad ) );
			$this->assert_invalid( static fn() => Codes::sanitise_prefix( $bad ) );
		}
	}

	public function test_noncanonical_issued_claims_refuse_instead_of_being_ignored(): void {
		foreach ( [ 'buyer-001', ' BUYER-001 ', '', '   ', 'BUYER/001', "BUYER-001\n", 'BÜYER-001', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456' ] as $bad ) {
			$this->assert_invalid( static fn() => Codes::next( [ $bad ], 'BUYER' ) );
		}
	}

	public function test_malformed_claims_in_another_prefix_still_refuse(): void {
		$this->assert_invalid( static fn() => Codes::next( [ 'BUYER-001', 'other-001' ], 'BUYER' ) );
		$this->assert_invalid( static fn() => Codes::next( [ 'other-001' ], '' ) );
	}

	public function test_issued_input_requires_a_list_of_code_strings(): void {
		foreach ( [ [ 1 ], [ false ], [ null ], [ [] ], [ new stdClass() ], [ 'BUYER-001' => [ 'retired' => true ] ], [ 2 => 'BUYER-001' ] ] as $bad ) {
			$this->assert_invalid( static fn() => Codes::next( $bad, 'BUYER' ) );
		}
	}

	public function test_claim_order_and_duplicates_do_not_change_allocation_or_input(): void {
		$issued = [ 'BUYER-009', 'BUYER-001', 'BUYER-009' ];
		self::assertSame( 'BUYER-010', Codes::next( $issued, 'BUYER' ) );
		self::assertSame( 'BUYER-010', Codes::next( array_reverse( $issued ), 'BUYER' ) );
		self::assertSame( [ 'BUYER-009', 'BUYER-001', 'BUYER-009' ], $issued );
	}

	private function assert_invalid( callable $operation ): void {
		try {
			$operation();
		} catch ( InvalidArgumentException $e ) {
			// Counted as an assertion, so PHPUnit does not report the test as risky.
			self::assertInstanceOf( InvalidArgumentException::class, $e );
			return;
		}
		self::fail( 'Malformed code input must be refused, not converted into a usable identifier.' );
	}
}
