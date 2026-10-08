<?php
declare( strict_types = 1 );

namespace pow_test_shape {
	function get_address_book( \WC_Customer $customer, string $type ) { return null; }
	function not_it( \WC_Customer $customer ) { return null; }
}
namespace pow_test_wrong {
	function get_address_book( int $user_id, string $type ) { return null; }
}

namespace {
use PHPUnit\Framework\TestCase;
use POW\Addresses\AccountBook;
use POW\Addresses\AccountCodes;
use POW\Addresses\DeliveryData;
use POW\Partners\Partner;

/** The account's saved addresses as a delivery source (0.4.15): the code map's rules and the provider's shape. Pure. */
final class AccountAddressesTest extends TestCase {
	private static function address( string $street ): array {
		return [ 'first_name' => 'Test', 'last_name' => 'Buyer', 'company' => 'Example Co', 'address_1' => $street, 'address_2' => '', 'city' => 'Johannesburg', 'state' => 'GP', 'postcode' => '2001', 'country' => 'ZA', 'phone' => '' ];
	}

	private static function partner( string $prefix = 'COKE' ): Partner {
		return Partner::from_row( [ 'id' => 1, 'owner_user_id' => 4, 'status' => 'active', 'delivery' => json_encode( [ 'delivery_code_prefix' => $prefix ] ) ] );
	}

	/* ---- the code map */

	public function test_new_keys_get_codes_in_sequence_and_the_map_is_marked_changed(): void {
		$fp1 = AccountCodes::fingerprint( self::address( '1 Demo Street' ) );
		$fp2 = AccountCodes::fingerprint( self::address( '2 Demo Street' ) );
		$result = AccountCodes::reconcile( [], [ 'a1' => $fp1, 'a2' => $fp2 ], 'COKE' );
		self::assertTrue( $result['changed'] );
		self::assertSame( [ 'a1' => 'COKE-001', 'a2' => 'COKE-002' ], $result['codes'] );
		self::assertSame( [ 'COKE-001' => [ 'key' => 'a1', 'retired' => false ], 'COKE-002' => [ 'key' => 'a2', 'retired' => false ] ], $result['map']['claims'] );
		$again = AccountCodes::reconcile( $result['map'], [ 'a1' => $fp1, 'a2' => $fp2 ], 'COKE' );
		self::assertFalse( $again['changed'] );
		self::assertSame( $result['codes'], $again['codes'] );
	}

	public function test_a_reused_key_with_a_different_address_gets_a_new_code_and_retires_the_old(): void {
		$fp1 = AccountCodes::fingerprint( self::address( '1 Demo Street' ) );
		$fp2 = AccountCodes::fingerprint( self::address( '2 Demo Street' ) );
		$fp3 = AccountCodes::fingerprint( self::address( '3 New Street' ) );
		$map = AccountCodes::reconcile( [], [ 'a1' => $fp1, 'a2' => $fp2 ], 'COKE' )['map'];
		// a2 deleted in the book, then a new address saved: the extension hands out a2 again.
		$result = AccountCodes::reconcile( $map, [ 'a1' => $fp1, 'a2' => $fp3 ], 'COKE' );
		self::assertTrue( $result['changed'] );
		self::assertSame( [ 'a1' => 'COKE-001', 'a2' => 'COKE-003' ], $result['codes'] );
		self::assertTrue( $result['map']['claims']['COKE-002']['retired'] );
		self::assertSame( [ 'key' => 'a2', 'retired' => false ], $result['map']['claims']['COKE-003'] );
		// The retired code is never handed out again, even for a fresh key.
		$later = AccountCodes::reconcile( $result['map'], [ 'a1' => $fp1, 'a2' => $fp3, 'a3' => $fp2 ], 'COKE' );
		self::assertSame( 'COKE-004', $later['codes']['a3'] );
	}

	public function test_an_empty_prefix_gives_empty_codes_but_still_tracks_the_fingerprint(): void {
		$fp1 = AccountCodes::fingerprint( self::address( '1 Demo Street' ) );
		$fp3 = AccountCodes::fingerprint( self::address( '3 New Street' ) );
		$first = AccountCodes::reconcile( [], [ 'a1' => $fp1 ], '' );
		self::assertSame( [ 'a1' => '' ], $first['codes'] );
		self::assertSame( [], $first['map']['claims'] );
		$changed = AccountCodes::reconcile( $first['map'], [ 'a1' => $fp3 ], '' );
		self::assertTrue( $changed['changed'] );
		self::assertSame( $fp3, $changed['map']['entries']['a1']['fingerprint'] );
	}

	public function test_keys_that_left_the_book_keep_their_claims(): void {
		$fp1 = AccountCodes::fingerprint( self::address( '1 Demo Street' ) );
		$fp2 = AccountCodes::fingerprint( self::address( '2 Demo Street' ) );
		$map = AccountCodes::reconcile( [], [ 'a1' => $fp1, 'a2' => $fp2 ], 'COKE' )['map'];
		$result = AccountCodes::reconcile( $map, [ 'a1' => $fp1 ], 'COKE' );
		self::assertFalse( $result['changed'] );
		self::assertSame( [ 'a1' => 'COKE-001' ], $result['codes'] );
		self::assertArrayHasKey( 'COKE-002', $result['map']['claims'] );
	}

	public function test_a_malformed_stored_map_is_replaced_whole_never_repaired(): void {
		self::assertSame( AccountCodes::empty(), AccountCodes::normalise( 'garbage' ) );
		self::assertSame( AccountCodes::empty(), AccountCodes::normalise( [ 'schema' => 1, 'entries' => [ 'a1' => [ 'code' => 'bad code', 'fingerprint' => str_repeat( 'a', 64 ) ] ], 'claims' => [] ] ) );
		self::assertSame( AccountCodes::empty(), AccountCodes::normalise( [ 'schema' => 2, 'entries' => [], 'claims' => [] ] ) );
		$good = [ 'schema' => 1, 'entries' => [ 'a1' => [ 'code' => 'X-001', 'fingerprint' => str_repeat( 'a', 64 ) ] ], 'claims' => [ 'X-001' => [ 'key' => 'a1', 'retired' => false ] ] ];
		self::assertSame( $good, AccountCodes::normalise( $good ) );
	}

	public function test_a_migration_carries_a_given_code_and_refuses_a_claimed_one(): void {
		$fp1 = AccountCodes::fingerprint( self::address( '1 Demo Street' ) );
		$fp2 = AccountCodes::fingerprint( self::address( '2 Demo Street' ) );
		$map = AccountCodes::assign( [], 'a1', 'buyer-007', $fp1 );
		self::assertSame( 'BUYER-007', $map['entries']['a1']['code'] );
		self::assertSame( [ 'key' => 'a1', 'retired' => false ], $map['claims']['BUYER-007'] );
		self::assertSame( 'address_code_taken', AccountCodes::assign( $map, 'a2', 'BUYER-007', $fp2 )->get_error_code() );
		$blank = AccountCodes::assign( $map, 'a2', '', $fp2 );
		self::assertSame( '', $blank['entries']['a2']['code'] );
		self::assertSame( 'address_codes_invalid', AccountCodes::assign( $map, 'a3', 'bad code', $fp2 )->get_error_code() );
		// Reassigning the same key keeps its claim; a different code retires the old.
		$same = AccountCodes::assign( $map, 'a1', 'BUYER-007', $fp1 );
		self::assertFalse( $same['claims']['BUYER-007']['retired'] );
		$moved = AccountCodes::assign( $map, 'a1', 'BUYER-008', $fp1 );
		self::assertTrue( $moved['claims']['BUYER-007']['retired'] );
	}

	/* ---- the provider's shape */

	public function test_the_api_is_found_by_shape_not_by_name_of_a_product(): void {
		self::assertNull( AccountBook::find_api( [ 'strlen', 'get_address_book_endpoint_url', 'some\\vendor\\other' ] ) );
		self::assertSame( 'pow_test_shape\\get_address_book', AccountBook::find_api( [ 'strlen', 'pow_test_shape\\not_it', 'pow_test_shape\\get_address_book' ] ) );
		// Same name, wrong first parameter: not the API.
		self::assertNull( AccountBook::find_api( [ 'pow_test_wrong\\get_address_book' ] ) );
	}

	public function test_the_default_address_comes_first_and_junk_keys_are_dropped(): void {
		$raw = [ 'a1' => [ 'x' => 1 ], 'a2' => [ 'x' => 2 ], 'bad key!' => [ 'x' => 3 ], 'a3' => 'not an array' ];
		self::assertSame( [ 'a2', 'a1' ], array_keys( AccountBook::ordered( $raw, 'a2' ) ) );
		self::assertSame( [ 'a1', 'a2' ], array_keys( AccountBook::ordered( $raw, null ) ) );
		self::assertSame( [ 'a1', 'a2' ], array_keys( AccountBook::ordered( $raw, 'missing' ) ) );
	}

	public function test_labels_prefer_the_nickname_then_the_company_then_street_and_city(): void {
		$canonical = self::address( '1 Demo Street' );
		self::assertSame( 'Head office', AccountBook::label_for( [ 'address_nickname' => ' Head office ' ], $canonical ) );
		self::assertSame( 'Example Co', AccountBook::label_for( [], $canonical ) );
		self::assertSame( '1 Demo Street, Johannesburg', AccountBook::label_for( [], array_replace( $canonical, [ 'company' => '' ] ) ) );
		self::assertSame( 'Saved address', AccountBook::label_for( [], array_fill_keys( array_keys( $canonical ), '' ) ) );
		self::assertSame( 190, mb_strlen( AccountBook::label_for( [ 'address_nickname' => str_repeat( 'é', 300 ) ], $canonical ) ) );
	}

	public function test_a_choice_has_the_fixed_shape_and_decodes_as_an_account_selection(): void {
		$entry = [ 'label' => 'Head office', 'address' => self::address( '1 Demo Street' ), 'fingerprint' => AccountCodes::fingerprint( self::address( '1 Demo Street' ) ) ];
		$choice = AccountBook::choice( self::partner(), 'a1', $entry, 'COKE-001' );
		self::assertSame( [ 'schema', 'partner_id', 'storage_user_id', 'provider', 'key', 'code', 'address', 'label', 'source', 'book_revision', 'entry_fingerprint' ], array_keys( $choice ) );
		self::assertSame( 'account', $choice['provider'] );
		self::assertSame( 'account_book', $choice['source'] );
		self::assertSame( 4, $choice['storage_user_id'] );
		self::assertNull( $choice['book_revision'] );
		self::assertSame( AccountBook::entry_fingerprint( 'a1', 'Head office', $entry['address'], 'COKE-001' ), $choice['entry_fingerprint'] );
		$decoded = DeliveryData::choice( json_encode( $choice ), 1 );
		self::assertSame( $choice, $decoded );
	}

	public function test_the_entry_fingerprint_moves_with_the_label_address_or_code(): void {
		$address = self::address( '1 Demo Street' );
		$base = AccountBook::entry_fingerprint( 'a1', 'Head office', $address, 'COKE-001' );
		self::assertNotSame( $base, AccountBook::entry_fingerprint( 'a1', 'Head office', $address, 'COKE-002' ) );
		self::assertNotSame( $base, AccountBook::entry_fingerprint( 'a1', 'Depot', $address, 'COKE-001' ) );
		self::assertNotSame( $base, AccountBook::entry_fingerprint( 'a1', 'Head office', self::address( '2 Demo Street' ), 'COKE-001' ) );
		self::assertNotSame( $base, AccountBook::entry_fingerprint( 'a2', 'Head office', $address, 'COKE-001' ) );
	}

	public function test_a_stored_account_choice_must_carry_a_fingerprint_and_a_canonical_code(): void {
		$entry = [ 'label' => 'Head office', 'address' => self::address( '1 Demo Street' ), 'fingerprint' => '' ];
		$choice = AccountBook::choice( self::partner(), 'a1', $entry, 'COKE-001' );
		foreach ( [ [ 'entry_fingerprint' => null ], [ 'book_revision' => 3 ], [ 'code' => 'bad code' ], [ 'source' => 'company_book' ] ] as $bad ) {
			try { DeliveryData::choice( json_encode( array_replace( $choice, $bad ) ), 1 ); self::fail( 'Accepted ' . json_encode( $bad ) ); }
			catch ( \DomainException $error ) { self::assertSame( 'Invalid stored delivery selection.', $error->getMessage() ); }
		}
		$blank_code = DeliveryData::choice( json_encode( array_replace( $choice, [ 'code' => '' ] ) ), 1 );
		self::assertSame( '', $blank_code['code'] );
	}
}

}
