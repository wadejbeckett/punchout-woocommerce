<?php
/**
 * Replay semantics (scope §7/§10: pending-state replay returns the stored
 * response; post-redemption duplicate -> 409).
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Sessions\ReplayPolicy;
use POW\Sessions\Session;

final class ReplayPolicyTest extends TestCase {

	private const HASH_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	private const HASH_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

	public function test_no_existing_row_is_new(): void {
		self::assertSame( ReplayPolicy::DECISION_NEW, ReplayPolicy::decide( null, null, self::HASH_A ) );
	}

	public function test_pending_identical_body_is_legitimate_retry(): void {
		self::assertSame(
			ReplayPolicy::DECISION_REPLAY,
			ReplayPolicy::decide( Session::PENDING, self::HASH_A, self::HASH_A )
		);
	}

	public function test_pending_different_body_is_conflict(): void {
		self::assertSame(
			ReplayPolicy::DECISION_CONFLICT,
			ReplayPolicy::decide( Session::PENDING, self::HASH_A, self::HASH_B )
		);
	}

	public function test_any_duplicate_after_redemption_is_conflict(): void {
		foreach ( [ Session::ACTIVE, Session::RETURNED, Session::ORDERED, Session::CLOSED, Session::EXPIRED ] as $status ) {
			self::assertSame(
				ReplayPolicy::DECISION_CONFLICT,
				ReplayPolicy::decide( $status, self::HASH_A, self::HASH_A ),
				"identical body in {$status} must conflict (a replayed response would be a dead one-time URL)"
			);
		}
	}
}
