<?php
/**
 * The mid-session "return without a cart" control.
 *
 * Rendered only inside an active punchout session, via
 * [punchout_abandon_button] or pow_abandon_button(). Posts an empty
 * PunchOutOrderMessage — the cXML cancel semantic — so the buyer's
 * procurement application knows the session ended with no items.
 *
 * A real POST carrying the pow_return nonce, so the control is a submit
 * button and never a link; every authorisation check (login, role, nonce,
 * session ownership and status) happens in the /punchout/return endpoint.
 *
 * Override by copying to {theme}/punchout-woocommerce/abandon-button.php,
 * or via the pow_template_abandon-button filter.
 *
 * Available variables:
 *
 * @var string $action_url Form action (the /punchout/return endpoint).
 * @var string $nonce      Nonce value for the pow_return action.
 * @var string $label      Button label (pow_abandon_button_label filter).
 * @var string $classes    Button class attribute (pow_button_classes filter).
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

defined( 'ABSPATH' ) || exit;
?>
<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="pow-abandon-form">
	<input type="hidden" name="pow_mode" value="empty" />
	<input type="hidden" name="pow_nonce" value="<?php echo esc_attr( $nonce ); ?>" />
	<button type="submit" class="<?php echo esc_attr( $classes ); ?>">
		<?php echo esc_html( $label ); ?>
	</button>
</form>
