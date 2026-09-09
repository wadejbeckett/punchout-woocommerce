<?php
/**
 * The punchout-quote order status.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Registers "Punchout Quote" as a first-class WooCommerce order status.
 *
 * Everything here is a pure array transform plus one thin register();
 * the rules are what matter and they are unit-tested without WordPress.
 *
 * Three properties come free from WooCommerce core rather than from code
 * of ours, and are the reason the status behaves (verified against trunk,
 * 2026-09-08):
 *
 * - No stock movement: wc_maybe_reduce_stock_levels is bound only to
 *   woocommerce_payment_complete and the completed/processing/on-hold
 *   status hooks (wc-stock-functions.php:124-127), so
 *   woocommerce_order_status_punchout-quote reduces nothing.
 * - No email: WC_Emails::init_transactional_emails binds a fixed list of
 *   X_to_Y status pairs (class-wc-emails.php:91-147); no pair names a
 *   custom status, so neither entering nor leaving this one sends mail.
 * - Bulk action with no handler: both list tables service any mark_<slug>
 *   action gated on isset( wc_get_order_statuses()['wc-' . $slug] )
 *   (ListTable.php:1495; class-wc-admin-list-table-orders.php:495).
 */
final class Status {

	public const SLUG     = 'punchout-quote';
	public const INTERNAL = 'wc-' . self::SLUG;

	public static function label(): string {
		return _x( 'Punchout Quote', 'Order status', 'punchout-woocommerce' );
	}

	public function register(): void {
		add_action( 'init', [ self::class, 'register_post_status' ] );
		add_filter( 'wc_order_statuses', [ self::class, 'add_to_order_statuses' ] );
		add_filter( 'woocommerce_analytics_excluded_order_statuses', [ self::class, 'exclude_from_analytics' ] );
		add_action( 'admin_init', [ self::class, 'register_bulk_action' ] );
	}

	public static function register_post_status(): void {
		register_post_status( self::INTERNAL, self::post_status_args() );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function post_status_args(): array {
		return [
			'label'                     => self::label(),
			'public'                    => false,
			'exclude_from_search'       => true,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'internal'                  => true,
			/* translators: %s: order count */
			'label_count'               => _n_noop(
				'Punchout Quote <span class="count">(%s)</span>',
				'Punchout Quote <span class="count">(%s)</span>',
				'punchout-woocommerce'
			),
		];
	}

	/**
	 * @param array<string, string> $statuses wc- prefixed slug => label.
	 * @return array<string, string>
	 */
	public static function add_to_order_statuses( array $statuses ): array {
		$statuses[ self::INTERNAL ] = self::label();

		return $statuses;
	}

	/**
	 * Analytics revenue exclusion. The filter carries UNPREFIXED slugs
	 * (DataStore::get_excluded_report_order_statuses seeds it from the
	 * woocommerce_excluded_report_order_statuses option). Filtering rather
	 * than writing the option keeps the operator's own Analytics setting
	 * untouched and reverts cleanly when the plugin is deactivated.
	 *
	 * @param list<string> $statuses Excluded status slugs.
	 * @return list<string>
	 */
	public static function exclude_from_analytics( array $statuses ): array {
		return in_array( self::SLUG, $statuses, true ) ? $statuses : array_merge( $statuses, [ self::SLUG ] );
	}

	/**
	 * Hook the orders-list bulk action on whichever list table is live.
	 * wc_get_page_screen_id() returns woocommerce_page_wc-orders under
	 * HPOS and the bare post type under legacy storage, where the filter
	 * is WordPress's bulk_actions-edit-{post_type}.
	 */
	public static function register_bulk_action(): void {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? (string) wc_get_page_screen_id( 'shop-order' ) : '';
		$hook   = ( '' !== $screen && 'shop_order' !== $screen )
			? 'bulk_actions-' . $screen
			: 'bulk_actions-edit-shop_order';

		add_filter( $hook, [ self::class, 'add_bulk_action' ] );
	}

	/**
	 * @param array<string, string> $actions Bulk actions.
	 * @return array<string, string>
	 */
	public static function add_bulk_action( array $actions ): array {
		$actions[ 'mark_' . self::SLUG ] = __( 'Change status to Punchout Quote', 'punchout-woocommerce' );

		return $actions;
	}
}
