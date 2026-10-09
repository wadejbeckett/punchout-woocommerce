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
 * With "Ask before leaving" on and a line in the cart (0.5.0), a dialog
 * follows the control: only its own form carries the answer
 * (pow_leave=confirmed) that leaves; "Keep shopping" closes it without a
 * script (formmethod="dialog"). The plugin's script
 * (assets/js/leave-confirmation.js) opens it when the control is pressed;
 * without the script the server sends the browser back with the dialog drawn
 * open. An override that drops the dialog leaves the buyer asked again and
 * again: keep it.
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
 * @var array{id: string, question: string, confirm: string, stay: string, open: bool}|null $leave
 *                          The leave-confirmation dialog, or null (leaves at once).
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

defined( 'ABSPATH' ) || exit;
?>
<?php $leave = $leave ?? null; ?>
<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="pow-abandon-form"<?php if ( $leave ) : ?> data-pow-leave="<?php echo esc_attr( $leave['id'] ); ?>"<?php endif; ?>>
	<input type="hidden" name="pow_mode" value="empty" />
	<input type="hidden" name="pow_nonce" value="<?php echo esc_attr( $nonce ); ?>" />
	<button type="submit" class="<?php echo esc_attr( $classes ); ?>">
		<?php echo esc_html( $label ); ?>
	</button>
</form>
<?php if ( $leave ) : ?>
<dialog id="<?php echo esc_attr( $leave['id'] ); ?>" class="pow-leave" aria-labelledby="<?php echo esc_attr( $leave['id'] ); ?>-question"<?php echo $leave['open'] ? ' open' : ''; ?>>
	<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="pow-leave__form">
		<input type="hidden" name="pow_mode" value="empty" />
		<input type="hidden" name="pow_nonce" value="<?php echo esc_attr( $nonce ); ?>" />
		<input type="hidden" name="pow_leave" value="confirmed" />
		<p class="pow-leave__question" id="<?php echo esc_attr( $leave['id'] ); ?>-question"><?php echo esc_html( $leave['question'] ); ?></p>
		<div class="pow-leave__actions">
			<button type="submit" class="pow-leave__confirm">
				<?php echo esc_html( $leave['confirm'] ); ?>
			</button>
			<button type="submit" formmethod="dialog" formnovalidate class="pow-leave__stay" autofocus>
				<?php echo esc_html( $leave['stay'] ); ?>
			</button>
		</div>
	</form>
</dialog>
<?php endif; ?>
