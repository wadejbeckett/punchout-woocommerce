<?php
/**
 * Private company delivery editor. Embed outside any existing account/admin form.
 * Native field markup and the hidden-field closure are prepared by Fields after authorization.
 * @var int $partner_id
 * @var array $book
 * @var array|null $notice
 * @var array $draft
 * @var string $key
 * @var int $revision
 * @var string $form_fields Native Woo form field HTML.
 * @var Closure $hidden Escaped action/partner/key/type/revision nonce fields.
 * @var array|null $preview Server-derived normal Woo address only.
 * @var array|null $copy
 * @var int $copy_revision
 * @package POW
 * @license AGPL-3.0-or-later
 */
defined( 'ABSPATH' ) || exit;
$prefix = 'pow_address_' . $partner_id;
?>
<section class="pow-company-delivery-addresses" aria-labelledby="<?php echo esc_attr( $prefix . '_title' ); ?>">
	<h2 id="<?php echo esc_attr( $prefix . '_title' ); ?>"><?php esc_html_e( 'Company delivery addresses', 'punchout-woocommerce' ); ?></h2>
	<?php if ( is_array( $notice ) ) : ?>
		<div class="<?php echo 'error' === $notice['type'] ? 'woocommerce-error' : 'woocommerce-message'; ?>" role="alert"><?php echo esc_html( $notice['text'] ); ?></div>
	<?php endif; ?>
	<p><?php esc_html_e( 'Company owners and shop administrators manage this delivery book. Buyers select enabled entries in their own shopping sessions.', 'punchout-woocommerce' ); ?></p>
	<p><?php esc_html_e( 'Disabling an entry prevents new selection and return of still-active confirmations using it. Affected buyers must select an eligible address again. After an address, label or code changes, affected buyers must reconfirm the displayed values. Completed Quote snapshots remain unchanged.', 'punchout-woocommerce' ); ?></p>
	<p><?php esc_html_e( 'Changing or removing an issued code leaves its old claim retired permanently. A blank code preserves an existing code; for a new entry the company prefix generates a code when configured, otherwise the address stays uncoded.', 'punchout-woocommerce' ); ?></p>
	<?php if ( [] === $book['addresses'] ) : ?>
		<p><?php esc_html_e( 'No company delivery addresses have been added.', 'punchout-woocommerce' ); ?></p>
	<?php else : ?>
		<table class="woocommerce-table shop_table"><thead><tr>
			<?php foreach ( [ __( 'Label', 'punchout-woocommerce' ), __( 'Delivery address', 'punchout-woocommerce' ), __( 'Code', 'punchout-woocommerce' ), __( 'Buyer selection', 'punchout-woocommerce' ), __( 'Actions', 'punchout-woocommerce' ) ] as $heading ) : ?><th scope="col"><?php echo esc_html( $heading ); ?></th><?php endforeach; ?>
		</tr></thead><tbody>
		<?php foreach ( $book['addresses'] as $entry_key => $entry ) : ?>
			<tr><th scope="row"><?php echo esc_html( $entry['label'] ); ?></th><td>
				<?php foreach ( $entry['address'] as $value ) : ?><?php if ( '' !== $value ) : ?><span><?php echo esc_html( $value ); ?></span><br /><?php endif; ?><?php endforeach; ?>
			</td><td><code><?php echo esc_html( '' !== $entry['code'] ? $entry['code'] : __( 'Uncoded', 'punchout-woocommerce' ) ); ?></code></td>
			<td><?php echo esc_html( $entry['use_for_punchout'] ? __( 'Enabled', 'punchout-woocommerce' ) : __( 'Disabled', 'punchout-woocommerce' ) ); ?></td><td>
				<?php foreach ( [ 'edit' => __( 'Edit address', 'punchout-woocommerce' ), ( $entry['use_for_punchout'] ? 'disable' : 'enable' ) => ( $entry['use_for_punchout'] ? __( 'Disable address', 'punchout-woocommerce' ) : __( 'Enable for buyers', 'punchout-woocommerce' ) ), 'remove' => __( 'Remove address and retire its code', 'punchout-woocommerce' ) ] as $action => $label ) : ?>
					<form method="post" action=""><?php echo $hidden( $action, (string) $entry_key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped producer.
					?><button type="submit" class="button"><?php echo esc_html( $label ); ?></button></form>
				<?php endforeach; ?>
			</td></tr>
		<?php endforeach; ?>
		</tbody></table>
	<?php endif; ?>
	<h3><?php echo esc_html( '' === $key ? __( 'Add delivery address', 'punchout-woocommerce' ) : __( 'Edit delivery address', 'punchout-woocommerce' ) ); ?></h3>
	<p><?php esc_html_e( 'New addresses start disabled. Review the saved entry, then enable it separately for buyers.', 'punchout-woocommerce' ); ?></p>
	<form method="post" action="" class="woocommerce-address-fields">
		<?php echo $hidden( 'save', $key, '', $revision ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped producer. ?>
		<p><label for="<?php echo esc_attr( $prefix . '_label' ); ?>"><?php esc_html_e( 'Address label', 'punchout-woocommerce' ); ?></label><input type="text" id="<?php echo esc_attr( $prefix . '_label' ); ?>" name="pow_address_label" value="<?php echo esc_attr( $draft['label'] ); ?>" maxlength="190" required /></p>
		<p><label for="<?php echo esc_attr( $prefix . '_code' ); ?>"><?php esc_html_e( 'Delivery code (optional)', 'punchout-woocommerce' ); ?></label><input type="text" id="<?php echo esc_attr( $prefix . '_code' ); ?>" name="pow_address_code" value="<?php echo esc_attr( $draft['code'] ); ?>" maxlength="32" /></p>
		<?php echo $form_fields; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- native Woo form renderer. ?>
		<p><?php esc_html_e( 'After changing country, update its fields before saving. Your other input is retained.', 'punchout-woocommerce' ); ?></p>
		<button type="submit" name="pow_address_refresh" value="1" formnovalidate class="button"><?php esc_html_e( 'Update country fields', 'punchout-woocommerce' ); ?></button>
		<button type="submit" class="button"><?php esc_html_e( 'Save delivery address', 'punchout-woocommerce' ); ?></button>
	</form>
	<?php if ( '' !== $key ) : ?><form method="post" action=""><?php echo $hidden( 'new' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<button type="submit" class="button"><?php esc_html_e( 'Start a new address', 'punchout-woocommerce' ); ?></button></form><?php endif; ?>
	<h3><?php esc_html_e( 'Copy a normal WooCommerce address', 'punchout-woocommerce' ); ?></h3>
	<p><?php esc_html_e( 'Preview the company owner’s normal shipping or billing address, then explicitly confirm a new disabled copy. The normal Woo address stays unchanged. Billing email is excluded. Copies become independent company entries and do not stay linked to later Woo address changes.', 'punchout-woocommerce' ); ?></p>
	<?php foreach ( [ 'shipping' => __( 'Preview owner shipping address', 'punchout-woocommerce' ), 'billing' => __( 'Preview owner billing address', 'punchout-woocommerce' ) ] as $type => $label ) : ?>
		<form method="post" action=""><?php echo $hidden( 'preview', '', $type ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<button type="submit" class="button"><?php echo esc_html( $label ); ?></button></form>
	<?php endforeach; ?>
	<?php if ( is_array( $preview ) && is_array( $copy ) ) : ?>
		<h4><?php echo esc_html( $preview['label'] ); ?></h4>
		<p><?php foreach ( $preview['address'] as $value ) : ?><?php if ( '' !== $value ) : ?><span><?php echo esc_html( $value ); ?></span><br /><?php endif; ?><?php endforeach; ?></p>
		<form method="post" action="">
			<?php echo $hidden( 'copy', '', $copy['type'], $copy_revision ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<p><label for="<?php echo esc_attr( $prefix . '_copy_label' ); ?>"><?php esc_html_e( 'Copied address label', 'punchout-woocommerce' ); ?></label><input type="text" id="<?php echo esc_attr( $prefix . '_copy_label' ); ?>" name="pow_address_label" value="<?php echo esc_attr( $copy['label'] ?? $preview['label'] ); ?>" maxlength="190" required /></p>
			<p><label for="<?php echo esc_attr( $prefix . '_copy_code' ); ?>"><?php esc_html_e( 'Copied delivery code (optional)', 'punchout-woocommerce' ); ?></label><input type="text" id="<?php echo esc_attr( $prefix . '_copy_code' ); ?>" name="pow_address_code" value="<?php echo esc_attr( $copy['code'] ); ?>" maxlength="32" /></p>
			<p><label><input type="checkbox" name="pow_address_confirm" value="1" required /> <?php esc_html_e( 'I reviewed this normal Woo address and want a new disabled copy. Its current values will be read again when I confirm.', 'punchout-woocommerce' ); ?></label></p>
			<button type="submit" class="button"><?php esc_html_e( 'Confirm disabled copy', 'punchout-woocommerce' ); ?></button>
		</form>
	<?php elseif ( is_array( $copy ) ) : ?>
		<p><?php esc_html_e( 'Unsaved copy input — copy this before reloading the source preview:', 'punchout-woocommerce' ); ?></p>
		<pre><?php echo esc_html( (string) wp_json_encode( $copy, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) ); ?></pre>
	<?php endif; ?>
</section>
