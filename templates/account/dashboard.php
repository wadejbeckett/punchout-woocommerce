<?php
/**
 * The My Account dashboard inside a punchout visit.
 *
 * WooCommerce's own myaccount/dashboard.php (template version 4.4.0) without
 * the "not you?" sentence and its logout link: in a visit, logging out ends
 * the visit and deletes its basket. Since 0.5.3 its second sentence names the
 * account pages the visit can open (the account menu's items, which in a visit
 * are the connection's ticked pages) instead of WooCommerce's orders,
 * addresses and password pages, which a visit opens only when ticked; with no
 * such page there is no sentence. The same dashboard hooks fire, so content
 * other plugins add to the dashboard still shows. Used only inside a visit
 * (Account\VisitDashboard); outside one, WooCommerce's or the theme's
 * dashboard renders as usual.
 *
 * Override by copying to {theme}/punchout-woocommerce/account/dashboard.php, or
 * with the pow_template_account/dashboard filter.
 *
 * @var WP_User|false $current_user The signed-in account, as WooCommerce passes it.
 * @package POW
 * @license AGPL-3.0-or-later
 */

defined( 'ABSPATH' ) || exit;

?>

<p>
	<?php
	printf(
		/* translators: %s: the account's display name */
		esc_html__( 'Hello %s', 'punchout-woocommerce' ),
		'<strong>' . esc_html( $current_user instanceof WP_User ? $current_user->display_name : '' ) . '</strong>'
	);
	?>
</p>

<?php
// The account pages this visit can open, as the account menu lists them (inside a visit the menu holds only the pages
// the connection ticked), without the dashboard itself and the pages that never open in a visit, logout among them.
$pow_open_pages = [];
foreach ( wc_get_account_menu_items() as $pow_endpoint => $pow_label ) {
	if ( in_array( (string) $pow_endpoint, array_merge( [ \POW\Account\VisitEndpoints::DASHBOARD ], \POW\Account\VisitEndpoints::NEVER_OPEN ), true ) ) {
		continue;
	}
	$pow_open_pages[] = '<a href="' . esc_url( wc_get_account_endpoint_url( (string) $pow_endpoint ) ) . '">' . esc_html( (string) $pow_label ) . '</a>';
}
?>

<?php if ( [] !== $pow_open_pages ) : ?>
<p>
	<?php
	printf(
		/* translators: %s: links to the account pages the visit can open, as a list. */
		esc_html__( 'From your account dashboard you can open %s.', 'punchout-woocommerce' ),
		wp_sprintf_l( '%l', $pow_open_pages ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each link escaped above.
	);
	?>
</p>
<?php endif; ?>

<?php
	/**
	 * My Account dashboard.
	 *
	 * @since 2.6.0
	 */
	do_action( 'woocommerce_account_dashboard' );

	/**
	 * Deprecated woocommerce_before_my_account action.
	 *
	 * @deprecated 2.6.0
	 */
	do_action( 'woocommerce_before_my_account' );

	/**
	 * Deprecated woocommerce_after_my_account action.
	 *
	 * @deprecated 2.6.0
	 */
	do_action( 'woocommerce_after_my_account' );
