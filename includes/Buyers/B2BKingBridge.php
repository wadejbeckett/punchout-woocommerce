<?php
/**
 * Optional B2BKing integration.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Buyers;

use POW\Logger;
use POW\Partners\Partner;

defined( 'ABSPATH' ) || exit;

/**
 * Attaches punchout buyers to a B2BKing company account (subaccount) and
 * customer group, when a partner row is configured with either and B2BKing
 * is active. Everything degrades gracefully to plain Woo when it is not —
 * B2BKing is an integration, not a dependency.
 *
 * Group assignment goes through the loaded helper class's
 * update_user_group() — it cascades to subaccounts and fires
 * b2bking_user_group_updated; raw meta writes strand subaccounts on the
 * wrong price list. Installs differ on the class name (Core+Pro loads
 * B2bking_Globalhelper; the free build B2bking_Globalhelpercore), so both
 * are tried, with the b2bking_group_key_name-filtered meta key as the
 * last-resort fallback (scope §5.2).
 */
final class B2BKingBridge {

	public function __construct( private Logger $logger ) {}

	/**
	 * Apply the partner's B2BKing configuration to a buyer user.
	 */
	public function attach( int $user_id, Partner $partner ): void {
		if ( $partner->b2bking_company_user_id > 0 ) {
			$this->attach_subaccount( $user_id, $partner->b2bking_company_user_id );
		}

		if ( $partner->b2bking_group_id > 0 ) {
			$this->assign_group( $user_id, $partner->b2bking_group_id );
		}
	}

	/**
	 * Hygiene for departed buyers: B2BKing caches a visibility transient
	 * per user with a one-year TTL; per-buyer provisioning multiplies it
	 * (scope §5.1 cost list), so cron clears it on deactivation.
	 */
	public function cleanup_transients( int $user_id ): void {
		delete_transient( 'b2bking_user_' . $user_id . '_ajax_visibility' );
	}

	private function attach_subaccount( int $user_id, int $company_user_id ): void {
		update_user_meta( $user_id, 'b2bking_account_type', 'subaccount' );
		update_user_meta( $user_id, 'b2bking_account_parent', (string) $company_user_id );

		// The parent's subaccount list drives B2BKing's own group cascade;
		// append (never rewrite) so admin-managed subaccounts survive.
		$list = (string) get_user_meta( $company_user_id, 'b2bking_subaccounts_list', true );
		$ids  = array_filter( explode( ',', $list ) );

		if ( ! in_array( (string) $user_id, $ids, true ) ) {
			$ids[] = (string) $user_id;
			update_user_meta( $company_user_id, 'b2bking_subaccounts_list', implode( ',', $ids ) );
		}
	}

	private function assign_group( int $user_id, int $group_id ): void {
		$current = $this->current_group( $user_id );

		if ( $current === (string) $group_id ) {
			return;
		}

		foreach ( [ 'B2bking_Globalhelper', 'B2bking_Globalhelpercore' ] as $helper ) {
			if ( class_exists( $helper ) && method_exists( $helper, 'update_user_group' ) ) {
				$helper::update_user_group( $user_id, (string) $group_id );
				return;
			}
		}

		if ( $this->b2bking_present() ) {
			// B2BKing is active but its helper is unrecognisable — write
			// the filtered meta key directly and say so loudly, because a
			// raw write does not cascade to subaccounts.
			$meta_key = (string) apply_filters( 'b2bking_group_key_name', 'b2bking_customergroup' );
			update_user_meta( $user_id, $meta_key, (string) $group_id );
			$this->logger->warning(
				'B2BKing group set via raw meta fallback (helper class not found); subaccount cascade did not run',
				[ 'user_id' => $user_id, 'group' => $group_id ]
			);
			return;
		}

		$this->logger->warning(
			'Partner configures a B2BKing group but B2BKing is not active; skipped',
			[ 'user_id' => $user_id, 'group' => $group_id ]
		);
	}

	private function current_group( int $user_id ): string {
		$meta_key = (string) apply_filters( 'b2bking_group_key_name', 'b2bking_customergroup' );

		return (string) get_user_meta( $user_id, $meta_key, true );
	}

	private function b2bking_present(): bool {
		return class_exists( 'B2bking' ) || defined( 'B2BKING_DIR' ) || function_exists( 'b2bking_run' );
	}
}
