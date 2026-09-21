<?php
/**
 * Request-scoped punchout visit resolver.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Sessions;

defined( 'ABSPATH' ) || exit;

/**
 * Answers one question about the current request: which punchout visit, if
 * any, is it inside?
 *
 * The proof is the session row matching the pair (current user id, current
 * WP session token). The token is minted per visit at auto-login, so two
 * employees signed in as the same customer account carry two different
 * tokens and resolve to two different visits, each with its own basket —
 * and a cookie left over from a superseded visit matches no row at all.
 *
 * There is deliberately no role or user-meta test. The login is the
 * customer's own ordinary WooCommerce account, so any question about what
 * the *user* is would answer "ordinary shopper" and turn every guard that
 * asks this resolver off. The row is the whole proof.
 *
 * An unreachable store is not an answer: when the lookup cannot run, Store
 * throws and this resolver lets that propagate rather than reporting "no
 * visit", so a caller cannot mistake a broken query for a shopper outside
 * a visit. A caller that memoises must therefore cache the answer only
 * once a lookup has returned one (see Plugin::current_session()).
 */
final class Current {

	public function __construct( private Store $sessions ) {}

	/**
	 * The visit this request is inside, or null when it is inside none.
	 *
	 * `ordered` is in the default pair because a row can still hold that
	 * status: a visit that reached it must keep resolving until cron or a
	 * close-out ends it, or the request it belongs to would lose every
	 * guard mid-visit.
	 *
	 * @param string[] $statuses Statuses that still count as a live visit.
	 * @throws \RuntimeException When the session row cannot be looked up.
	 */
	public function visit( array $statuses = [ Session::ACTIVE, Session::ORDERED ] ): ?Session {
		if ( ! is_user_logged_in() ) {
			return null;
		}

		return $this->sessions->find_for_login( get_current_user_id(), wp_get_session_token(), $statuses );
	}

	/**
	 * The id of the visit this request is inside, 0 outside one.
	 *
	 * @throws \RuntimeException When the session row cannot be looked up.
	 */
	public function visit_id(): int {
		return $this->visit()?->id ?? 0;
	}
}
