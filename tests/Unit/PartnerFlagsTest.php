<?php
/** The two per-connection permissions schema nine adds: buyer-added delivery addresses and the owner's own actions. */
declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Partners\Partner;

final class PartnerFlagsTest extends TestCase {
	public function test_a_row_from_before_schema_nine_reads_as_off(): void {
		$partner = Partner::from_row( [ 'id' => 5, 'owner_user_id' => 9, 'status' => 'active' ] );
		self::assertFalse( $partner->buyer_addresses );
		self::assertSame( '', $partner->owner_settings );
		self::assertFalse( $partner->owner_may( 'reset_connection' ) );
	}

	public function test_the_columns_read_back(): void {
		$partner = Partner::from_row( [ 'id' => 5, 'buyer_addresses' => '1', 'owner_settings' => 'reset_connection' ] );
		self::assertTrue( $partner->buyer_addresses );
		self::assertSame( 'reset_connection', $partner->owner_settings );
		self::assertFalse( Partner::from_row( [ 'id' => 5, 'buyer_addresses' => '0' ] )->buyer_addresses );
	}

	public function test_owner_settings_keep_only_known_actions_once(): void {
		self::assertSame( [ 'reset_connection' ], Partner::OWNER_ACTIONS );
		self::assertSame( 'reset_connection', Partner::normalise_owner_settings( ' reset_connection,bogus,reset_connection ' ) );
		foreach ( [ '', ',', 'bogus', 'RESET_CONNECTION', 'reset connection' ] as $raw ) { self::assertSame( '', Partner::normalise_owner_settings( $raw ), $raw ); }
		// A hand-edited row is normalised on the way out too.
		self::assertSame( 'reset_connection', Partner::from_row( [ 'id' => 5, 'owner_settings' => 'bogus, reset_connection' ] )->owner_settings );
	}

	public function test_owner_may_answers_only_for_a_granted_known_action(): void {
		$partner = Partner::from_row( [ 'id' => 5, 'owner_settings' => 'reset_connection' ] );
		self::assertTrue( $partner->owner_may( 'reset_connection' ) );
		foreach ( [ 'bogus', '', 'reset_connection,bogus' ] as $action ) { self::assertFalse( $partner->owner_may( $action ), $action ); }
		self::assertFalse( Partner::from_row( [ 'id' => 5 ] )->owner_may( 'reset_connection' ) );
	}
}
