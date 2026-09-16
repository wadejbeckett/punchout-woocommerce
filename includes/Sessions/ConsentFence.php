<?php
/** Remove buyer delivery consent, or fence the exact active login when removal cannot be verified. @package POW @license AGPL-3.0-or-later */
declare( strict_types = 1 );
namespace POW\Sessions;
use POW\Partners\{Partner, Registry};
defined( 'ABSPATH' ) || exit;

/**
 * The single recovery path after a cart mutation or an unacknowledged consent save.
 * Caller holds the partner mutex. True means consent was verifiably removed. False means it was not, and this
 * module has already done the only safe thing: expire the exact ACTIVE login, or if that CAS lost too, disable the
 * company. A paid winner or replacement login is never expired or fenced.
 */
final class ConsentFence {
	public function __construct( private Store $sessions, private Registry $registry ) {}

	public function clear_locked( Session $session ): bool {
		try { if ( $this->sessions->invalidate_delivery( $session->id, $session->user_id, $session->wp_session_token ) ) { return true; } }
		catch ( \Throwable $error ) {}
		// A false clear may itself have committed. Fence the exact active login before any continuation; never expire a replacement login or a completed winner.
		try {
			$fresh = $this->same_active_login( $session );
			if ( ! $fresh ) { return false; }
			if ( $this->sessions->expire_active_login_locked( $fresh ) ) { return false; }
			// A paid winner or replacement login can beat recovery even while this mutex is held. A failed CAS/readback never authorizes a company fence without another exact ACTIVE read.
			$fresh = $this->same_active_login( $session );
			if ( ! $fresh ) { return false; }
			$this->registry->transition_status( $fresh->partner_id, Partner::STATUS_ACTIVE, [ 'status' => Partner::STATUS_DISABLED ] );
		} catch ( \Throwable $error ) { /* Unreadable state cannot authorize disabling a potentially completed winner. */ }
		return false;
	}

	private function same_active_login( Session $session ): ?Session {
		$fresh = $this->sessions->find( $session->id );
		if ( ! $fresh || Session::ACTIVE !== $fresh->status || $fresh->partner_id !== $session->partner_id || $fresh->user_id !== $session->user_id || $fresh->wp_session_token !== $session->wp_session_token ) { return null; }
		return $fresh;
	}
}
