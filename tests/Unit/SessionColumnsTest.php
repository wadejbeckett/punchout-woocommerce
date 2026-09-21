<?php
/**
 * The schema-seven session columns: the per-visit WooCommerce key and the
 * buyer identity carried as data on the row.
 *
 * A visit is identified by its own `wc_session_key`, never by `user_id` —
 * many visits of one customer account share that id. Buyer identity is
 * optional by design (a purchasing system may send neither name nor
 * e-mail), so every column here must read back as null when the row omits
 * it and when the row stores it empty.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Sessions\Session;

final class SessionColumnsTest extends TestCase {

	private const KEY = 'pow_0123456789abcdef0123456789ab';

	public function test_from_row_maps_the_per_visit_key_and_buyer_identity(): void {
		$session = Session::from_row(
			[
				'id'                  => 9,
				'partner_id'          => 3,
				'status'              => Session::ACTIVE,
				'user_id'             => 42,
				'wc_session_key'      => self::KEY,
				'buyer_identity'      => 'buyer@example.com',
				'buyer_name'          => 'Jane Buyer',
				'buyer_identity_hash' => str_repeat( 'b', 64 ),
			]
		);

		self::assertSame( self::KEY, $session->wc_session_key );
		self::assertSame( 'buyer@example.com', $session->buyer_identity );
		self::assertSame( 'Jane Buyer', $session->buyer_name );
		self::assertSame( str_repeat( 'b', 64 ), $session->buyer_identity_hash );

		// WooCommerce stores session_key as char(32); a longer key would be
		// truncated on write and never match on readback.
		self::assertSame( 32, strlen( (string) $session->wc_session_key ) );
	}

	public function test_empty_columns_read_back_as_absent(): void {
		$session = Session::from_row(
			[
				'id'                  => 9,
				'status'              => Session::ACTIVE,
				'wc_session_key'      => '',
				'buyer_identity'      => '',
				'buyer_name'          => '',
				'buyer_identity_hash' => '',
			]
		);

		self::assertNull( $session->wc_session_key );
		self::assertNull( $session->buyer_identity );
		self::assertNull( $session->buyer_name );
		self::assertNull( $session->buyer_identity_hash );
	}

	public function test_row_without_the_new_columns_still_constructs(): void {
		$session = Session::from_row( [ 'id' => 1, 'status' => Session::PENDING ] );

		self::assertSame(
			[ null, null, null, null ],
			[ $session->wc_session_key, $session->buyer_identity, $session->buyer_name, $session->buyer_identity_hash ]
		);
		self::assertFalse( $session->is_terminal() );
	}

	public function test_an_anonymous_visit_keeps_its_key_without_any_identity(): void {
		$session = Session::from_row( [ 'id' => 4, 'status' => Session::ACTIVE, 'wc_session_key' => self::KEY ] );

		self::assertSame( self::KEY, $session->wc_session_key );
		self::assertNull( $session->buyer_identity );
		self::assertNull( $session->buyer_name );
	}
}
