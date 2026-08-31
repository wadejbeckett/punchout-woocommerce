<?php
/**
 * Housekeeping: session GC, buyer deactivation, log retention.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW;

use POW\Audit\Log;
use POW\Buyers\B2BKingBridge;
use POW\Sessions\Session;
use POW\Sessions\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Hourly GC (scope §7 "cron expires stragglers"):
 *
 * - open sessions past expiry -> expired, their recorded logins destroyed
 *   (including un-closed `ordered` rows — the buyer who closed the tab at
 *   the thank-you page, scope §5.4);
 * - buyers unseen for N days flagged inactive — flagged, never deleted,
 *   because they carry order attribution — their sessions torn down and
 *   the per-user B2BKing visibility transient cleared;
 * - audit-table retention trim.
 *
 * Runs through Action Scheduler when WooCommerce provides it (reliable,
 * observable in WC > Status > Scheduled Actions), falling back to WP-Cron.
 */
final class Cron {

	public const HOOK  = 'pow_gc';
	public const GROUP = 'punchout-woocommerce';

	public function __construct(
		private Store $sessions,
		private Log $audit,
		private Settings $settings,
		private B2BKingBridge $bridge,
	) {}

	public function register(): void {
		add_action( self::HOOK, [ $this, 'run' ] );
		add_action( 'init', [ $this, 'ensure_scheduled' ] );
	}

	public function ensure_scheduled(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! as_has_scheduled_action( self::HOOK, [], self::GROUP ) ) {
				as_schedule_recurring_action( time() + HOUR_IN_SECONDS, HOUR_IN_SECONDS, self::HOOK, [], self::GROUP );
			}

			return;
		}

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOOK );
		}
	}

	public function run(): void {
		$this->expire_sessions();
		$this->deactivate_stale_buyers();
		$this->audit->trim( $this->settings->int( 'log_retention_days' ) );
	}

	private function expire_sessions(): void {
		foreach ( $this->sessions->expired_open() as $session ) {
			if ( ! $this->sessions->transition( $session->id, $session->status, Session::EXPIRED ) ) {
				continue;
			}

			if ( '' !== $session->wp_session_token && $session->user_id > 0 ) {
				\WP_Session_Tokens::get_instance( $session->user_id )->destroy( $session->wp_session_token );
			}

			$this->audit->write(
				'session_expired',
				[
					'partner_id' => $session->partner_id,
					'session_id' => $session->id,
					'user_id'    => $session->user_id,
					'result'     => 'ttl',
				]
			);
		}
	}

	private function deactivate_stale_buyers(): void {
		$days = $this->settings->int( 'buyer_inactive_days' );

		if ( $days < 1 ) {
			return;
		}

		$cutoff = time() - $days * DAY_IN_SECONDS;

		$query = new \WP_User_Query(
			[
				'role'       => Installer::ROLE,
				'number'     => 100,
				'fields'     => 'ID',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query' => [
					[
						'key'     => '_pow_last_seen',
						'value'   => $cutoff,
						'compare' => '<',
						'type'    => 'NUMERIC',
					],
					[
						'key'     => '_pow_deactivated',
						'compare' => 'NOT EXISTS',
					],
				],
			]
		);

		foreach ( $query->get_results() as $user_id ) {
			$user_id = (int) $user_id;

			update_user_meta( $user_id, '_pow_deactivated', 1 );
			\WP_Session_Tokens::get_instance( $user_id )->destroy_all();
			$this->bridge->cleanup_transients( $user_id );

			$this->audit->write(
				'buyer_deactivated',
				[
					'user_id' => $user_id,
					'result'  => 'stale',
				]
			);
		}
	}
}
