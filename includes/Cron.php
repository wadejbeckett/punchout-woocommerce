<?php
/**
 * Housekeeping: visit GC, log retention, quote retention.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW;

use POW\Audit\Log;
use POW\Orders\QuoteOrder;
use POW\Sessions\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Hourly GC (scope §7 "cron expires stragglers"):
 *
 * - open visits past expiry -> expired, their recorded logins destroyed and
 *   their own WooCommerce basket row deleted (including un-closed `ordered`
 *   rows — the buyer who closed the tab at the thank-you page, scope §5.4);
 * - audit-table retention trim;
 * - unconverted Punchout Quote orders past their retention cancelled
 *   (never deleted — QuoteOrder::expire()).
 *
 * No account is touched and no hook is fired. Buyers are not users of this
 * plugin: every buyer of a connection shops as that connection's own
 * customer account, so there is nothing dormant to deactivate. That makes
 * the visit sweep below the ONLY thing that ever closes an abandoned visit
 * — nobody logs out of a shared login — and the per-connection open-visit
 * cap (Store::MAX_OPEN_VISITS) depends on this job running.
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
}
