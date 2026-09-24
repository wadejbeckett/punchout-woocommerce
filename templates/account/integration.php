<?php
/**
 * The connection's setup-XML download. Receives safe scalar view data, never a Partner object.
 *
 * Read-only unless the store lets the account holder reset: the store
 * manages the connection, so this screen offers no form but the download,
 * shows no secret and edits nothing. When an administrator ticks "Reset
 * connection" for the connection, a second, separate form offers the
 * reset, and the response to it shows the new secret once.
 *
 * @var string $state none|pending|active|disabled|unavailable
 * @var array<string,string> $connection Public connection details, including `visit_endpoints`: the My Account endpoints a visit may open, comma-separated, '' for none.
 * @var bool $template_ready Whether the connection's identities are complete enough to build the setup XML.
 * @var array|null $notice Escaped direct response notice.
 * @var string $setup_url
 * @var string|null $last_setup
 * @var string $action_url
 * @var string $docs_url
 * @var string $nonce
 * @var bool $can_reset Whether the store lets this account holder reset the connection here.
 * @var string $reset_nonce Nonce for the reset form.
 * @var string $issued_secret The new shared secret, only on the response to a successful reset; '' otherwise.
 * @package POW
 * @license AGPL-3.0-or-later
 */
defined( 'ABSPATH' ) || exit;
?>
<section class="pow-account-integration">
	<h2><?php esc_html_e( 'Punchout integration', 'punchout-woocommerce' ); ?></h2>
	<?php if ( is_array( $notice ) ) : ?>
		<div class="<?php echo 'error' === $notice['type'] ? 'woocommerce-error' : 'woocommerce-message'; ?>" role="alert"><?php echo esc_html( $notice['text'] ); ?></div>
	<?php endif; if ( '' !== ( $issued_secret ?? '' ) ) : // Same tag as the endif: the default markup stays byte-for-byte. ?>
		<div class="woocommerce-message" role="status"><p><strong><?php esc_html_e( 'New shared secret', 'punchout-woocommerce' ); ?></strong></p><p><code><?php echo esc_html( $issued_secret ); ?></code></p><p><?php esc_html_e( 'Copy it now. It is not shown again and is not in any email.', 'punchout-woocommerce' ); ?></p></div>
	<?php endif; ?>
	<?php if ( 'active' === $state ) : ?>
		<table class="woocommerce-table shop_table"><tbody>
			<?php foreach ( [ __( 'Status', 'punchout-woocommerce' ) => __( 'Active', 'punchout-woocommerce' ), __( 'Connection name', 'punchout-woocommerce' ) => $connection['name'] ?? '', __( 'Setup URL (test and production)', 'punchout-woocommerce' ) => $setup_url, __( 'Your identity (From)', 'punchout-woocommerce' ) => $connection['from'] ?? '', __( 'Your identity (Sender)', 'punchout-woocommerce' ) => $connection['sender'] ?? '', __( 'Supplier identity (To)', 'punchout-woocommerce' ) => $connection['to'] ?? '', __( 'Deployment mode', 'punchout-woocommerce' ) => $connection['deployment_mode'] ?? '', __( 'cXML version', 'punchout-woocommerce' ) => $connection['cxml_version'] ?? '', __( 'Return encoding', 'punchout-woocommerce' ) => $connection['return_encoding'] ?? '', __( 'My Account pages open during a visit', 'punchout-woocommerce' ) => '' !== ( $connection['visit_endpoints'] ?? '' ) ? $connection['visit_endpoints'] : __( 'None', 'punchout-woocommerce' ), __( 'Last successful setup (UTC)', 'punchout-woocommerce' ) => $last_setup ?? __( 'None yet', 'punchout-woocommerce' ) ] as $label => $value ) : ?>
				<tr><th scope="row"><?php echo esc_html( $label ); ?></th><td><code><?php echo esc_html( $value ); ?></code></td></tr>
			<?php endforeach; ?>
		</tbody></table>
		<p><?php esc_html_e( 'Connection identities are managed by the store. Contact the store if your purchasing system changes.', 'punchout-woocommerce' ); ?></p>
		<?php if ( ! empty( $template_ready ) ) : ?>
			<p><?php esc_html_e( 'Download the company Dynamics setup XML and paste it into your purchasing system, replacing the SharedSecret placeholder inside the pasted text with the credential we issued privately. Leave payloadID, timestamp, BuyerCookie and BrowserFormPost blank; the purchasing system fills them when a user punches out. Configure UserEmail separately in its extrinsics mapping. The download never contains your stored secret.', 'punchout-woocommerce' ); ?></p>
			<form method="post" action="<?php echo esc_url( $action_url ); ?>">
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" />
				<button type="submit" name="pow_account_action" value="download_setup_template" class="woocommerce-Button button"><?php esc_html_e( 'Download setup XML', 'punchout-woocommerce' ); ?></button>
			</form>
		<?php else : ?>
			<p><?php echo esc_html( \POW\Account\IntegrationTab::template_not_ready_text() ); ?></p>
		<?php endif; if ( ! empty( $can_reset ) ) : // Same tag as the endif: the default markup stays byte-for-byte. ?>
			<h3><?php esc_html_e( 'Reset connection', 'punchout-woocommerce' ); ?></h3>
			<p><?php esc_html_e( 'Resetting revokes the current shared secret and ends every open punchout visit of your company at once. Your purchasing system cannot punch in again until you paste the new secret, which is shown here only once.', 'punchout-woocommerce' ); ?></p>
			<form method="post" action="<?php echo esc_url( $action_url ); ?>">
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $reset_nonce ?? '' ); ?>" />
				<p><label><input type="checkbox" name="pow_confirm_reset" value="1" required /> <?php esc_html_e( 'I understand that open visits end and the old secret stops working now.', 'punchout-woocommerce' ); ?></label></p>
				<button type="submit" name="pow_account_action" value="reset_connection" class="woocommerce-Button button"><?php esc_html_e( 'Reset connection', 'punchout-woocommerce' ); ?></button>
			</form>
		<?php endif; ?>
	<?php elseif ( 'unavailable' === $state ) : ?>
		<p><?php esc_html_e( 'Connection information is unavailable. Reload this page before trying again.', 'punchout-woocommerce' ); ?></p>
	<?php else : ?>
		<p><?php esc_html_e( 'No active punchout connection is set up for this account. The store creates and activates company connections; contact it to arrange one.', 'punchout-woocommerce' ); ?></p>
	<?php endif; ?>
	<h3><?php esc_html_e( 'Company credentials and buyer identity', 'punchout-woocommerce' ); ?></h3>
	<p><?php esc_html_e( 'Company credentials authenticate the connection. Everyone your purchasing system authorises shops as this one store account, so no employee needs a website password or a separate store account.', 'punchout-woocommerce' ); ?></p>
	<p><?php esc_html_e( 'Supported identity fields, in priority order: UserEmail, UniqueUsername, UniqueName, then Contact/Email. Use an individual, nonempty, repeatable value. Changing a WooCommerce profile email does not configure the XML sent by your purchasing system.', 'punchout-woocommerce' ); ?></p>
	<p><?php esc_html_e( 'The identity your system sends is recorded on each visit and on the quote it produces. Without one, shopping still works, but the quote carries no buyer name.', 'punchout-woocommerce' ); ?></p>
	<?php if ( '' !== $docs_url ) : ?>
		<p><a href="<?php echo esc_url( $docs_url ); ?>"><?php esc_html_e( 'Integration documentation', 'punchout-woocommerce' ); ?></a></p>
	<?php else : ?>
		<p><?php esc_html_e( 'Contact the store for its integration documentation.', 'punchout-woocommerce' ); ?></p>
	<?php endif; ?>
	<p><a href="<?php echo esc_url( $action_url ); ?>"><?php esc_html_e( 'Reload integration details', 'punchout-woocommerce' ); ?></a></p>
</section>
