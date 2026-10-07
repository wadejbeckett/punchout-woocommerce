<?php
/** Registers the plugin's WooCommerce e-mails and bridges the quote status to them. @package POW @license AGPL-3.0-or-later */
declare( strict_types = 1 );
namespace POW\Emails;

use POW\Orders\Status;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce only sends its transactional e-mails for a fixed list of status pairs, none of which
 * names a custom status (Orders\Status). So the plugin registers its own WC_Email, "PunchOut order
 * received", and fires it when an order enters the punchout-quote status: the bridge loads the
 * mailer (which instantiates the e-mail classes) and raises the notification action the e-mail
 * listens to, the same shape WooCommerce uses for its own emails.
 */
final class Notifications {
	public const ACTION = 'pow_quote_received_notification';

	public function register(): void {
		add_filter( 'woocommerce_email_classes', [ $this, 'add_emails' ] );
		add_action( 'woocommerce_order_status_' . Status::SLUG, [ $this, 'quote_received' ], 10, 2 );
	}

	/** @param array<string, object> $emails */
	public function add_emails( array $emails ): array {
		if ( class_exists( '\WC_Email' ) ) {
			$emails['POW_Quote_Received'] = new QuoteReceived();
		}
		return $emails;
	}

	/** An order has just become a Punchout Quote: hand it to the mailer. */
	public function quote_received( int $order_id, $order = null ): void {
		if ( ! function_exists( 'WC' ) || ! method_exists( WC(), 'mailer' ) ) { return; }
		WC()->mailer(); // Instantiates every registered e-mail, ours included, so its listener is bound.
		do_action( self::ACTION, $order_id, $order );
	}
}
