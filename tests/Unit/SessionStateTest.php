<?php
/**
 * Session state machine (scope §4.2).
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Sessions\Session;

final class SessionStateTest extends TestCase {

	public function test_allowed_transitions(): void {
		self::assertTrue( Session::can_transition( Session::PENDING, Session::ACTIVE ) );
		self::assertTrue( Session::can_transition( Session::PENDING, Session::EXPIRED ) );
		self::assertTrue( Session::can_transition( Session::ACTIVE, Session::RETURNED ) );
		self::assertTrue( Session::can_transition( Session::ACTIVE, Session::ORDERED ) );
		self::assertTrue( Session::can_transition( Session::ACTIVE, Session::CLOSED ) );
		self::assertTrue( Session::can_transition( Session::ACTIVE, Session::EXPIRED ) );
		self::assertTrue( Session::can_transition( Session::ORDERED, Session::CLOSED ) );
		self::assertTrue( Session::can_transition( Session::ORDERED, Session::EXPIRED ) );
	}

	public function test_forbidden_transitions(): void {
		self::assertFalse( Session::can_transition( Session::PENDING, Session::RETURNED ) );
		self::assertFalse( Session::can_transition( Session::PENDING, Session::ORDERED ) );
		self::assertFalse( Session::can_transition( Session::RETURNED, Session::CLOSED ) );
		self::assertFalse( Session::can_transition( Session::RETURNED, Session::ACTIVE ) );
		self::assertFalse( Session::can_transition( Session::CLOSED, Session::ACTIVE ) );
		self::assertFalse( Session::can_transition( Session::EXPIRED, Session::ACTIVE ) );
		self::assertFalse( Session::can_transition( Session::ORDERED, Session::RETURNED ) );
	}

	public function test_terminal_states(): void {
		$row = [ 'id' => 1, 'status' => Session::RETURNED ];
		self::assertTrue( Session::from_row( $row )->is_terminal() );

		$row['status'] = Session::ACTIVE;
		self::assertFalse( Session::from_row( $row )->is_terminal() );

		// `ordered` is not terminal: the close-out (-> closed) or cron
		// (-> expired) still owns the last word.
		$row['status'] = Session::ORDERED;
		self::assertFalse( Session::from_row( $row )->is_terminal() );
	}
}
