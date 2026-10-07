<?php
/**
 * PunchOut order received (HTML). Override: <theme>/woocommerce/emails/pow-quote-received.php
 *
 * Variables: $order, $email_heading, $additional_content, $sent_to_admin, $plain_text, $email,
 * $connection, $buyer_name, $buyer_identity, $delivery_code, $preferred_date, $delivery_notes, $edit_url.
 *
 * @package POW
 */
defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p><?php
printf(
	/* translators: 1: connection name, 2: buyer name or e-mail */
	esc_html__( 'A buyer at %1$s has returned a cart from their purchasing system. %2$s The order is waiting as a Punchout Quote until their approval comes through.', 'punchout-woocommerce' ),
	esc_html( '' !== $connection ? $connection : __( 'a punchout connection', 'punchout-woocommerce' ) ),
	esc_html( '' !== $buyer_name ? sprintf( /* translators: %s: buyer */ __( 'Bought by %s.', 'punchout-woocommerce' ), $buyer_name . ( '' !== $buyer_identity ? ' (' . $buyer_identity . ')' : '' ) ) : ( '' !== $buyer_identity ? sprintf( /* translators: %s: buyer e-mail */ __( 'Bought by %s.', 'punchout-woocommerce' ), $buyer_identity ) : '' ) )
);
?></p>

<?php do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email ); ?>

<h2><?php esc_html_e( 'Delivery', 'punchout-woocommerce' ); ?></h2>
<ul>
	<?php if ( '' !== $delivery_code ) : ?><li><?php esc_html_e( 'Delivery address code:', 'punchout-woocommerce' ); ?> <strong><?php echo esc_html( $delivery_code ); ?></strong></li><?php endif; ?>
	<?php if ( '' !== $preferred_date ) : ?><li><?php esc_html_e( 'Preferred delivery date:', 'punchout-woocommerce' ); ?> <strong><?php echo esc_html( $preferred_date ); ?></strong></li><?php endif; ?>
	<?php if ( '' !== $delivery_notes ) : ?><li><?php esc_html_e( 'Buyer notes:', 'punchout-woocommerce' ); ?> <?php echo esc_html( $delivery_notes ); ?></li><?php endif; ?>
</ul>

<?php do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email ); ?>
<?php do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email ); ?>

<p><a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Open the order', 'punchout-woocommerce' ); ?></a></p>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
do_action( 'woocommerce_email_footer', $email );
