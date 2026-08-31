<?php
/**
 * The "send to purchasing system" cart exit button.
 *
 * Rendered only inside an active punchout session — an ADDITIVE surface
 * next to (never instead of) the stock WooCommerce checkout button.
 *
 * Override by copying to {theme}/punchout-woocommerce/return-button.php,
 * or via the pow_template_return-button filter.
 *
 * Available variables:
 *
 * @var string $action_url Form action (the /punchout/return endpoint).
 * @var string $nonce      Nonce value for the pow_return action.
 * @var string $label      Button label (pow_return_button_label filter).
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

defined( 'ABSPATH' ) || exit;
?>
<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="pow-return-form">
	<input type="hidden" name="pow_mode" value="cart" />
	<input type="hidden" name="pow_nonce" value="<?php echo esc_attr( $nonce ); ?>" />
	<button type="submit" class="button alt wp-element-button pow-return-button">
		<?php echo esc_html( $label ); ?>
	</button>
</form>
