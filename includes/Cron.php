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
use POW\Orders\QuoteOrder;
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
 *   pow_buyer_deactivated fired for site glue to clean up after;
 * - audit-table retention trim;
 * - unconverted Punchout Quote orders past their retention cancelled
 *   (never deleted — QuoteOrder::expire()).
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
		private QuoteOrder $quotes,
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
		$this->quotes->expire();
	}

	private function expire_sessions(): void {
		foreach ( $this->sessions->expired_open() as $session ) {
			$registry = Plugin::instance()->registry();
			if ( ! $registry || ! $this->sessions->expire_and_destroy( $session, $registry ) ) { continue; }

			$this->audit->write_checked(
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

			$registry = Plugin::instance()->registry();
			$partner_id = (int) get_user_meta( $user_id, '_pow_partner_id', true );
			if ( ! $registry || $partner_id <= 0 ) { continue; }
			try {
				$clean = $registry->with_partner_lock( $partner_id, function () use ( $user_id, $partner_id ) {
					update_user_meta( $user_id, '_pow_deactivated', 1 );
					if ( ! get_user_meta( $user_id, '_pow_deactivated', true ) ) { return false; }
					$after = 0;
					$ok = true;
					while ( $rows = $this->sessions->revocation_batch( $partner_id, $after ) ) {
						foreach ( $rows as $row ) {
							if ( $row->id <= $after ) { return false; }
							$after = $row->id;
							if ( $row->user_id === $user_id ) { $ok = $this->sessions->expire_locked( $row ) && $ok; }
						}
					}
					return $ok;
				} );
			} catch ( \Throwable $e ) { $clean = false; }
			if ( ! $clean ) { continue; }

			/**
			 * Fires when a dormant punchout buyer is deactivated, so site
			 * glue can undo whatever pow_buyer_provisioned set up (group
			 * membership, cached visibility, etc.).
			 *
			 * @param int $user_id Deactivated buyer user ID.
			 */
			do_action( 'pow_buyer_deactivated', $user_id );

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
