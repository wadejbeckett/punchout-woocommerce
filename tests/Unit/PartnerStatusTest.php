<?php
/**
 * Partner status values and ownership (DESIGN §2).
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Partners\Partner;

final class PartnerStatusTest extends TestCase {

	public function test_pending_is_neither_active_nor_terminal(): void {
		$partner = Partner::from_row( [ 'id' => 7, 'name' => 'Example Buyer Company', 'status' => Partner::STATUS_PENDING ] );

		self::assertTrue( $partner->is_pending() );
		self::assertFalse( $partner->is_active(), 'a pending row must never authenticate a setup request' );
	}

	public function test_active_and_disabled(): void {
		self::assertTrue( Partner::from_row( [ 'id' => 1, 'name' => 'X', 'status' => Partner::STATUS_ACTIVE ] )->is_active() );
		self::assertFalse( Partner::from_row( [ 'id' => 1, 'name' => 'X', 'status' => Partner::STATUS_DISABLED ] )->is_active() );
	}

	public function test_owner_defaults_to_nobody(): void {
		$partner = Partner::from_row( [ 'id' => 1, 'name' => 'X' ] );

		self::assertSame( 0, $partner->owner_user_id );
		self::assertFalse( $partner->is_owned_by( 0 ), 'user 0 must never own an unowned row' );
		self::assertFalse( $partner->is_owned_by( 5 ) );
	}

	public function test_owner_binding(): void {
		$partner = Partner::from_row( [ 'id' => 1, 'name' => 'X', 'owner_user_id' => '5' ] );

		self::assertSame( 5, $partner->owner_user_id );
		self::assertTrue( $partner->is_owned_by( 5 ) );
		self::assertFalse( $partner->is_owned_by( 6 ) );
	}
}
