<?php
/**
 * Stored delivery selections must not turn corrupt or foreign data into a usable destination.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */
declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Sessions\Session;
use POW\Addresses\DeliveryData;

final class DeliverySnapshotTest extends TestCase {

	private function choice(): array {
		return [
			'schema' => 1, 'partner_id' => 7, 'storage_user_id' => 23,
			'provider' => 'native', 'key' => 'address-a', 'code' => 'BUYER-001',
			'address' => [ 'first_name' => 'Jane', 'last_name' => 'Buyer', 'company' => 'Example & Co', 'address_1' => '12 Main Road', 'address_2' => '', 'city' => 'Cape Town', 'state' => 'WC', 'postcode' => '8001', 'country' => 'ZA', 'phone' => '' ],
			'label' => 'Receiving', 'source' => 'company_book', 'book_revision' => 2,
			'entry_fingerprint' => str_repeat( 'a', 64 ),
		];
	}

	private function session( mixed $choice ): Session {
		return Session::from_row( [ 'id' => 11, 'partner_id' => 7, 'user_id' => 31, 'delivery_choice' => $choice ] );
	}

	private function rejected( mixed $raw ): void {
		try {
			$this->session( $raw )->delivery_choice();
		} catch ( DomainException $e ) {
			self::assertSame( 'Invalid stored delivery selection.', $e->getMessage() );
			return;
		}
		self::fail( 'Malformed or foreign stored selection must require reselection.' );
	}

	public function test_old_rows_have_no_selected_destination(): void {
		self::assertNull( Session::from_row( [ 'partner_id' => 7 ] )->delivery_choice() );
		self::assertNull( $this->session( null )->delivery_choice() );
	}

	public function test_complete_snapshot_retains_literal_values_without_normalizing_master_data(): void {
		$choice = $this->choice();
		$choice['address']['address_2'] = 'L’étage / Bay "A"';
		$session = $this->session( json_encode( $choice ) );
		self::assertSame( $choice, $session->delivery_choice() );
		self::assertSame( 'L’étage / Bay "A"', $session->delivery_choice()['address']['address_2'] );
	}

	public function test_present_invalid_json_is_not_absence(): void {
		foreach ( [ '', 'null', '[]', '{}', 'false', '42', '"address"', '{', "{\xff}", [] ] as $raw ) {
			$this->rejected( $raw );
		}
	}

	public function test_foreign_or_coerced_company_and_owner_identifiers_refuse(): void {
		foreach ( [ [ 'partner_id', 8 ], [ 'partner_id', '7' ], [ 'storage_user_id', 0 ], [ 'storage_user_id', '23' ], [ 'schema', 2 ] ] as [ $field, $value ] ) {
			$choice = $this->choice(); $choice[ $field ] = $value;
			$this->rejected( json_encode( $choice ) );
		}
	}

	public function test_missing_fields_or_nested_non_string_address_values_refuse(): void {
		$choice = $this->choice(); unset( $choice['address']['country'] );
		$this->rejected( json_encode( $choice ) );
		$choice = $this->choice(); $choice['address']['city'] = [ 'Cape Town' ];
		$this->rejected( json_encode( $choice ) );
		$choice = $this->choice(); unset( $choice['storage_user_id'] );
		$this->rejected( json_encode( $choice ) );
	}

	public function test_old_full_book_snapshot_can_be_shown_but_has_no_current_fingerprint(): void {
		$choice = $this->choice(); unset( $choice['book_revision'], $choice['entry_fingerprint'] );
		$decoded = $this->session( json_encode( $choice ) )->delivery_choice();
		self::assertSame( 'address-a', $decoded['key'] );
		self::assertNull( $decoded['entry_fingerprint'] );
		self::assertNull( $decoded['book_revision'] );
	}

	public function test_uncoded_session_candidate_does_not_gain_a_master_fingerprint(): void {
		$choice = $this->choice();
		$choice['provider'] = 'inbound'; $choice['source'] = 'ship_to'; $choice['key'] = 'inbound';
		$choice['code'] = ''; $choice['book_revision'] = null; $choice['entry_fingerprint'] = null;
		self::assertSame( $choice, $this->session( json_encode( $choice ) )->delivery_choice() );
		$choice['entry_fingerprint'] = str_repeat( 'a', 64 );
		$this->rejected( json_encode( $choice ) );
	}

	public function test_oversized_json_and_malformed_fingerprint_refuse_before_use(): void {
		$choice = $this->choice(); $choice['entry_fingerprint'] = 'not-a-digest';
		$this->rejected( json_encode( $choice ) );
		$choice = $this->choice(); $choice['label'] = str_repeat( 'A', 66000 );
		$this->rejected( json_encode( $choice ) );
	}

	private function confirmation(): array {
		return [
			'schema' => 1, 'session_id' => 11, 'buyer_user_id' => 31,
			'choice_hash' => hash( 'sha256', 'null' ), 'cart_fingerprint' => str_repeat( 'b', 64 ), 'policy_fingerprint' => str_repeat( 'c', 64 ),
			'delivery' => [ 'status' => 'not_required', 'amount_cents' => null, 'currency' => 'ZAR', 'code' => '', 'emit' => false, 'rates' => [], 'freight' => [ 'supplier_part_id' => 'DELIVERY', 'uom' => 'EA', 'classification_domain' => 'supplier', 'classification' => 'freight' ] ],
			'notes' => 'Leave at receiving.', 'confirmed_at' => 1788973200,
		];
	}

	private function confirmed_session( array $confirmation, ?array $choice = null ): Session {
		return Session::from_row( [ 'id' => 11, 'partner_id' => 7, 'user_id' => 31, 'delivery_choice' => null === $choice ? null : json_encode( $choice ), 'delivery_confirmation' => json_encode( $confirmation ) ] );
	}

	private function confirmation_rejected( array $confirmation, ?array $choice = null ): void {
		try { $this->confirmed_session( $confirmation, $choice )->delivery_confirmation(); }
		catch ( DomainException $e ) { self::assertSame( 'Invalid stored delivery confirmation.', $e->getMessage() ); return; }
		self::fail( 'Invalid confirmation cannot authorize a return.' );
	}

	public function test_old_rows_are_never_confirmed_and_virtual_confirmation_needs_no_destination(): void {
		self::assertNull( Session::from_row( [ 'id' => 11 ] )->delivery_confirmation() );
		self::assertSame( $this->confirmation(), $this->confirmed_session( $this->confirmation() )->delivery_confirmation() );
	}

	public function test_confirmation_is_bound_to_its_buyer_session_and_selected_choice(): void {
		foreach ( [ [ 'session_id', 12 ], [ 'buyer_user_id', 32 ], [ 'buyer_user_id', '31' ], [ 'choice_hash', str_repeat( '0', 64 ) ], [ 'cart_fingerprint', '' ], [ 'policy_fingerprint', 'unbound' ], [ 'confirmed_at', 0 ] ] as [ $field, $value ] ) {
			$data = $this->confirmation(); $data[ $field ] = $value; $this->confirmation_rejected( $data );
		}
		$this->confirmation_rejected( $this->confirmation(), $this->choice() );
	}

	public function test_confirmation_cannot_invent_a_free_or_negative_shipping_charge(): void {
		$data = $this->confirmation(); $data['delivery']['status'] = 'quoted'; $data['delivery']['amount_cents'] = 0;
		$this->confirmation_rejected( $data );
		$data['delivery']['rates'] = [ [ 'package_key' => '0', 'rate_id' => 'flat_rate:4', 'method_id' => 'flat_rate', 'instance_id' => 4, 'label' => 'Road', 'amount_cents' => -10, 'taxes' => [] ] ];
		$this->confirmation_rejected( $data );
	}

	public function test_real_shipping_rates_retain_exact_package_cents_and_quoted_zero(): void {
		$choice = $this->choice(); $data = $this->confirmation(); $data['choice_hash'] = DeliveryData::fingerprint( $choice );
		$data['delivery']['status'] = 'quoted'; $data['delivery']['amount_cents'] = 3500; $data['delivery']['emit'] = true;
		$data['delivery']['rates'] = [ [ 'package_key' => '0', 'rate_id' => 'flat_rate:4', 'method_id' => 'flat_rate', 'instance_id' => 4, 'label' => 'Road', 'amount_cents' => 3500, 'taxes' => [ 1 => '5.25' ] ] ];
		self::assertSame( 3500, $this->confirmed_session( $data, $choice )->delivery_confirmation()['delivery']['amount_cents'] );
		$data['delivery']['amount_cents'] = 3501; $this->confirmation_rejected( $data, $choice );
		$data['delivery']['amount_cents'] = 0; $data['delivery']['rates'][0]['amount_cents'] = 0; $data['delivery']['rates'][0]['taxes'] = [];
		self::assertSame( 0, $this->confirmed_session( $data, $choice )->delivery_confirmation()['delivery']['amount_cents'] );
		$data['delivery']['rates'][] = $data['delivery']['rates'][0]; $this->confirmation_rejected( $data, $choice );
	}

	public function test_unknown_delivery_stays_null_and_cannot_be_emitted(): void {
		$choice = $this->choice(); $data = $this->confirmation(); $data['choice_hash'] = DeliveryData::fingerprint( $choice ); $data['delivery']['status'] = 'unknown';
		self::assertNull( $this->confirmed_session( $data, $choice )->delivery_confirmation()['delivery']['amount_cents'] );
		$data['delivery']['emit'] = true; $this->confirmation_rejected( $data, $choice );
		$data['delivery']['emit'] = false; $data['delivery']['amount_cents'] = 0; $this->confirmation_rejected( $data, $choice );
	}

	public function test_note_limit_counts_unicode_characters_and_refuses_arrays(): void {
		$data = $this->confirmation(); $data['notes'] = str_repeat( 'é', 2000 );
		self::assertSame( $data['notes'], $this->confirmed_session( $data )->delivery_confirmation()['notes'] );
		$data['notes'] .= 'é'; $this->confirmation_rejected( $data );
		$data['notes'] = [ 'bad' ]; $this->confirmation_rejected( $data );
	}

	public function test_fingerprints_ignore_object_key_order_but_retain_list_order(): void {
		self::assertSame( '43258cff783fe7036d8a43033f830adfc60ec037382473548ac742b888292777', DeliveryData::fingerprint( [ 'b' => 2, 'a' => 1 ] ) );
		self::assertNotSame( DeliveryData::fingerprint( [ 'rates' => [ 'first', 'second' ] ] ), DeliveryData::fingerprint( [ 'rates' => [ 'second', 'first' ] ] ) );
	}

	public function test_deeply_nested_fingerprint_input_is_bounded(): void {
		$deep = [ 'value' => 'leaf' ];
		for ( $i = 0; $i < 30; ++$i ) { $deep = [ 'nested' => $deep ]; }
		$this->expectException( DomainException::class );
		DeliveryData::fingerprint( $deep );
	}

	public function test_recursive_fingerprint_input_refuses_before_exhausting_memory(): void {
		$recursive = []; $recursive['self'] = &$recursive;
		$this->expectException( DomainException::class );
		DeliveryData::fingerprint( $recursive );
	}
}
