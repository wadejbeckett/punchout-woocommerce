<?php
/**
 * The pay-path close-out button ("return to your purchasing system").
 *
 * Rendered on the order-received page for punchout orders. Posts an empty
 * PunchOutOrderMessage — the cXML cancel semantic that tells the buyer's
 * procurement application no items are coming back — carrying the paid
 * order's reference in SupplierOrderInfo.
 *
 * Override by copying to {theme}/punchout-woocommerce/closeout-button.php,
 * or via the pow_template_closeout-button filter.
 *
 * Available variables:
 *
 * @var string $action_url Form action (the /punchout/return endpoint).
 * @var string $nonce      Nonce value for the pow_return action.
 * @var string $label      Button label.
 * @var string $copy       Explanatory copy shown above the button.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="pow-closeout">
	<p><?php echo esc_html( $copy ); ?></p>
	<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="pow-closeout-form">
		<input type="hidden" name="pow_mode" value="empty" />
		<input type="hidden" name="pow_nonce" value="<?php echo esc_attr( $nonce ); ?>" />
		<button type="submit" class="button wp-element-button pow-closeout-button">
			<?php echo esc_html( $label ); ?>
		</button>
	</form>
</section>
