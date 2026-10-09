<?php
/**
 * PunchOut order received (plain text). Override: <theme>/woocommerce/emails/plain/pow-quote-received.php
 *
 * @package POW
 */
defined( 'ABSPATH' ) || exit;

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";
printf(
	/* translators: 1: connection name, 2: buyer */
	esc_html__( 'A cart from a buyer at %1$s has been sent to the buyer’s purchasing system. %2$s The order is waiting here as a Punchout Quote.', 'punchout-woocommerce' ),
	esc_html( '' !== $connection ? $connection : __( 'a punchout connection', 'punchout-woocommerce' ) ),
	esc_html( '' !== $buyer_name || '' !== $buyer_identity ? sprintf( /* translators: %s: buyer */ __( 'Bought by %s.', 'punchout-woocommerce' ), trim( $buyer_name . ( '' !== $buyer_identity ? ' (' . $buyer_identity . ')' : '' ) ) ) : '' )
);
echo "\n\n";
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
echo "\n" . esc_html__( 'Delivery', 'punchout-woocommerce' ) . "\n";
if ( '' !== $delivery_code ) { echo esc_html__( 'Delivery address code:', 'punchout-woocommerce' ) . ' ' . esc_html( $delivery_code ) . "\n"; }
if ( '' !== $preferred_date ) { echo esc_html__( 'Preferred delivery date:', 'punchout-woocommerce' ) . ' ' . esc_html( $preferred_date ) . "\n"; }
if ( '' !== $delivery_notes ) { echo esc_html__( 'Buyer notes:', 'punchout-woocommerce' ) . ' ' . esc_html( $delivery_notes ) . "\n"; }
if ( ! empty( $delivery_estimate_note ) ) { echo esc_html( $delivery_estimate_note ) . "\n"; }
if ( ! empty( $attachment ) ) { echo esc_html__( 'Attachment:', 'punchout-woocommerce' ) . ' ' . esc_html( $attachment['name'] ) . ' (' . esc_html( $attachment['size'] ) . ') ' . esc_url( $attachment['url'] ) . "\n"; }
echo "\n";
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );
echo "\n" . esc_url( $edit_url ) . "\n\n";
if ( $additional_content ) { echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n"; }
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
