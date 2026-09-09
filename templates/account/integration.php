<?php
/**
 * Ordinary owner's connection management. Receives safe scalar view data, never a Partner object.
 *
 * @var string $state none|pending|active|disabled|unavailable
 * @var array<string,string> $connection Public connection details.
 * @var bool $rotation_open Existing credential overlap.
 * @var array|null $notice Escaped direct response notice.
 * @var string $secret Plaintext only on the successful no-store rotation POST.
 * @var string $setup_url
 * @var string|null $last_setup
 * @var string $action_url
 * @var string $docs_url
 * @var string $nonce
 * @package POW
 * @license AGPL-3.0-or-later
 */
defined( 'ABSPATH' ) || exit;
?>
<section class="pow-account-integration">
	<h2><?php esc_html_e( 'Punchout integration', 'punchout-woocommerce' ); ?></h2>
	<?php if ( is_array( $notice ) ) : ?>
		<div class="<?php echo 'error' === $notice['type'] ? 'woocommerce-error' : 'woocommerce-message'; ?>" role="alert"><?php echo esc_html( $notice['text'] ); ?></div>
	<?php endif; ?>
	<?php if ( '' !== $secret ) : ?>
		<div class="woocommerce-message" role="alert">
			<strong><?php esc_html_e( 'Shared secret — copy it now:', 'punchout-woocommerce' ); ?></strong>
			<code><?php echo esc_html( $secret ); ?></code>
			<p><?php esc_html_e( 'This secret appears only in this response. Store it securely and update your purchasing system before finishing the rotation.', 'punchout-woocommerce' ); ?></p>
		</div>
	<?php endif; ?>
	<?php if ( 'none' === $state ) : ?>
		<p><?php esc_html_e( 'Request a company connection for your purchasing system. Ask its administrator for the connection details. The store reviews the company request before activation.', 'punchout-woocommerce' ); ?></p>
		<form method="post" action="<?php echo esc_url( $action_url ); ?>">
			<input type="hidden" name="pow_account_action" value="submit" />
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" />
			<?php foreach ( [ 'name' => __( 'Connection name', 'punchout-woocommerce' ), 'from_domain' => __( 'From domain', 'punchout-woocommerce' ), 'from_identity' => __( 'From identity', 'punchout-woocommerce' ), 'sender_domain' => __( 'Sender domain (blank uses From)', 'punchout-woocommerce' ), 'sender_identity' => __( 'Sender identity (blank uses From)', 'punchout-woocommerce' ) ] as $field => $label ) : ?>
				<p><label for="pow_<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $label ); ?></label>
				<input type="text" id="pow_<?php echo esc_attr( $field ); ?>" name="<?php echo esc_attr( 'name' === $field ? 'pow_name' : $field ); ?>" maxlength="190" <?php echo in_array( $field, [ 'name', 'from_domain', 'from_identity' ], true ) ? 'required' : ''; ?> /></p>
			<?php endforeach; ?>
			<p><label for="pow_deployment_mode"><?php esc_html_e( 'cXML deployment mode', 'punchout-woocommerce' ); ?></label>
			<select id="pow_deployment_mode" name="deployment_mode"><option value="test"><?php esc_html_e( 'Test', 'punchout-woocommerce' ); ?></option><option value="production"><?php esc_html_e( 'Production', 'punchout-woocommerce' ); ?></option></select></p>
			<p><label for="pow_notes"><?php esc_html_e( 'Notes (optional)', 'punchout-woocommerce' ); ?></label><textarea id="pow_notes" name="notes" rows="3"></textarea></p>
			<button type="submit" class="woocommerce-Button button"><?php esc_html_e( 'Request connection', 'punchout-woocommerce' ); ?></button>
		</form>
	<?php elseif ( 'pending' === $state ) : ?>
		<p><?php esc_html_e( 'Your company connection request is awaiting store approval. No buyer entry or employee approval is needed here.', 'punchout-woocommerce' ); ?></p>
	<?php elseif ( 'disabled' === $state ) : ?>
		<p><?php esc_html_e( 'Your company connection is deactivated. Contact the store to have it re-enabled.', 'punchout-woocommerce' ); ?></p>
	<?php elseif ( 'active' === $state ) : ?>
		<table class="woocommerce-table shop_table"><tbody>
			<?php foreach ( [ __( 'Status', 'punchout-woocommerce' ) => __( 'Active', 'punchout-woocommerce' ), __( 'Connection name', 'punchout-woocommerce' ) => $connection['name'] ?? '', __( 'Company exit policy', 'punchout-woocommerce' ) => $connection['exit_policy'] ?? '', __( 'Company permission', 'punchout-woocommerce' ) => $connection['effective_exit_policy'] ?? '', __( 'Setup URL (test and production)', 'punchout-woocommerce' ) => $setup_url, __( 'Your identity (From)', 'punchout-woocommerce' ) => $connection['from'] ?? '', __( 'Your identity (Sender)', 'punchout-woocommerce' ) => $connection['sender'] ?? '', __( 'Supplier identity (To)', 'punchout-woocommerce' ) => $connection['to'] ?? '', __( 'Deployment mode', 'punchout-woocommerce' ) => $connection['deployment_mode'] ?? '', __( 'cXML version', 'punchout-woocommerce' ) => $connection['cxml_version'] ?? '', __( 'Return encoding', 'punchout-woocommerce' ) => $connection['return_encoding'] ?? '', __( 'Last successful setup (UTC)', 'punchout-woocommerce' ) => $last_setup ?? __( 'None yet', 'punchout-woocommerce' ) ] as $label => $value ) : ?>
				<tr><th scope="row"><?php echo esc_html( $label ); ?></th><td><code><?php echo esc_html( $value ); ?></code></td></tr>
			<?php endforeach; ?>
		</tbody></table>
		<p><?php esc_html_e( 'Connection identities and purchasing permissions are managed by the store. Contact the store if your purchasing system changes.', 'punchout-woocommerce' ); ?></p>
		<form method="post" action="<?php echo esc_url( $action_url ); ?>">
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" />
			<?php if ( $rotation_open ) : ?>
				<p><?php esc_html_e( 'A rotation is open. Both secrets work until you finish it. Confirm that the new secret is working in your purchasing system first.', 'punchout-woocommerce' ); ?></p>
				<button type="submit" name="pow_account_action" value="finish_rotation" class="woocommerce-Button button"><?php esc_html_e( 'Finish rotation', 'punchout-woocommerce' ); ?></button>
			<?php else : ?>
				<button type="submit" name="pow_account_action" value="rotate" class="woocommerce-Button button"><?php esc_html_e( 'Rotate secret', 'punchout-woocommerce' ); ?></button>
			<?php endif; ?>
		</form>
		<form method="post" action="<?php echo esc_url( $action_url ); ?>">
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" />
			<p><?php esc_html_e( 'Deactivating immediately blocks the connection and ends its open shopping sessions. The store is notified. Contact the store to restore access.', 'punchout-woocommerce' ); ?></p>
			<button type="submit" name="pow_account_action" value="deactivate" class="woocommerce-Button button"><?php esc_html_e( 'Deactivate connection now', 'punchout-woocommerce' ); ?></button>
		</form>
	<?php else : ?>
		<p><?php esc_html_e( 'Connection information is unavailable. Reload this page before making another change.', 'punchout-woocommerce' ); ?></p>
	<?php endif; ?>
	<h3><?php esc_html_e( 'Company credentials and individual buyers', 'punchout-woocommerce' ); ?></h3>
	<p><?php esc_html_e( 'Company credentials authenticate the connection. Your purchasing system authorises its buyers and supplies a stable identifier for each person. Buyers are recognised or created automatically; no separate website password, manual employee entry or second store approval is required.', 'punchout-woocommerce' ); ?></p>
	<p><?php esc_html_e( 'Supported identity fields, in priority order: UserEmail, UniqueUsername, UniqueName, then Contact/Email. Use an individual, nonempty, repeatable value. Changing a WooCommerce profile email does not configure the XML sent by your purchasing system.', 'punchout-woocommerce' ); ?></p>
	<p><?php esc_html_e( 'Without a usable identity, shopping can continue with a temporary buyer account, but returning-buyer recognition cannot be promised. Ask your purchasing-system administrator to correct the identity mapping.', 'punchout-woocommerce' ); ?></p>
	<?php if ( '' !== $docs_url ) : ?>
		<p><a href="<?php echo esc_url( $docs_url ); ?>"><?php esc_html_e( 'Integration documentation', 'punchout-woocommerce' ); ?></a></p>
	<?php else : ?>
		<p><?php esc_html_e( 'Contact the store for its integration documentation.', 'punchout-woocommerce' ); ?></p>
	<?php endif; ?>
	<p><a href="<?php echo esc_url( $action_url ); ?>"><?php esc_html_e( 'Reload integration details', 'punchout-woocommerce' ); ?></a></p>
</section>
