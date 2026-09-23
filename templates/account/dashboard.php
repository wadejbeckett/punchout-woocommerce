<?php
/**
 * The My Account dashboard inside a punchout visit.
 *
 * WooCommerce's own myaccount/dashboard.php (template version 4.4.0) without
 * the "not you?" sentence and its logout link: in a visit, logging out ends
 * the visit and deletes its basket. Everything else is WooCommerce's, in
 * WooCommerce's words, and the same dashboard hooks fire, so content other
 * plugins add to the dashboard still shows. Used only inside a visit
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

$allowed_html = [
	'a' => [
		'href' => [],
	],
];
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

<p>
	<?php
	/* translators: 1: Orders URL 2: Address URL 3: Account URL. */
	$dashboard_desc = __( 'From your account dashboard you can view your <a href="%1$s">recent orders</a>, manage your <a href="%2$s">billing address</a>, and <a href="%3$s">edit your password and account details</a>.', 'woocommerce' );
	if ( wc_shipping_enabled() ) {
		/* translators: 1: Orders URL 2: Addresses URL 3: Account URL. */
		$dashboard_desc = __( 'From your account dashboard you can view your <a href="%1$s">recent orders</a>, manage your <a href="%2$s">shipping and billing addresses</a>, and <a href="%3$s">edit your password and account details</a>.', 'woocommerce' );
	}
	printf(
		wp_kses( $dashboard_desc, $allowed_html ),
		esc_url( wc_get_endpoint_url( 'orders' ) ),
		esc_url( wc_get_endpoint_url( 'edit-address' ) ),
		esc_url( wc_get_endpoint_url( 'edit-account' ) )
	);
	?>
</p>

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
