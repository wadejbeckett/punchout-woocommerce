<?php
/** The "PunchOut order received" e-mail to the store. @package POW @license AGPL-3.0-or-later */
declare( strict_types = 1 );
namespace POW\Emails;

use POW\Orders\QuoteOrder;

defined( 'ABSPATH' ) || exit;

/**
 * Sent to the store when a buyer's cart returns and its Punchout Quote order is created.
 * Configured like any WooCommerce e-mail (WooCommerce → Settings → Emails → PunchOut order
 * received): enabled, recipients (comma-separated; the store's admin address when empty),
 * subject, heading, type. Templates: templates/emails/pow-quote-received.php and
 * templates/emails/plain/pow-quote-received.php, overridable in the theme under
 * woocommerce/emails/ like WooCommerce's own.
 */
final class QuoteReceived extends \WC_Email {
	public function __construct() {
		$this->id             = 'pow_quote_received';
		$this->title          = __( 'PunchOut order received', 'punchout-woocommerce' );
		$this->description    = __( 'Sent to the store when a punchout buyer returns a cart and its Punchout Quote order is created. WooCommerce\'s own "New order" e-mail does not cover this status.', 'punchout-woocommerce' );
		$this->template_html  = 'emails/pow-quote-received.php';
		$this->template_plain = 'emails/plain/pow-quote-received.php';
		$this->template_base  = dirname( POW_PLUGIN_FILE ) . '/templates/';
		$this->placeholders   = [ '{order_number}' => '', '{connection}' => '', '{buyer}' => '' ];
		add_action( Notifications::ACTION, [ $this, 'trigger' ], 10, 2 );
		parent::__construct();
		$this->recipient = $this->get_option( 'recipient', (string) get_option( 'admin_email' ) );
	}

	public function get_default_subject(): string {
		return __( '[{site_title}]: PunchOut order #{order_number} from {connection}', 'punchout-woocommerce' );
	}

	public function get_default_heading(): string {
		return __( 'PunchOut order #{order_number}', 'punchout-woocommerce' );
	}

	/** @param int|string $order_id */
	public function trigger( $order_id, $order = false ): void {
		$this->setup_locale();
		if ( ! $order instanceof \WC_Order ) { $order = wc_get_order( (int) $order_id ); }
		if ( $order instanceof \WC_Order ) {
			$this->object                         = $order;
			$this->placeholders['{order_number}'] = $order->get_order_number();
			$this->placeholders['{connection}']   = (string) $order->get_meta( QuoteOrder::META_PARTNER_NAME );
			$this->placeholders['{buyer}']        = (string) $order->get_meta( QuoteOrder::META_BUYER_NAME );
		}
		if ( $this->is_enabled() && $this->get_recipient() && $this->object instanceof \WC_Order ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
		$this->restore_locale();
	}

	/** Comma-separated recipients, validated; the admin address when the setting is empty. */
	public function get_recipient(): string {
		return implode( ', ', Recipients::resolve( (string) $this->recipient, (string) get_option( 'admin_email' ) ) );
	}

	/** @return array<string, mixed> */
	private function template_args( bool $plain ): array {
		$order = $this->object;
		return [
			'order'              => $order,
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => true,
			'plain_text'         => $plain,
			'email'              => $this,
			'connection'         => (string) $order->get_meta( QuoteOrder::META_PARTNER_NAME ),
			'buyer_name'         => (string) $order->get_meta( QuoteOrder::META_BUYER_NAME ),
			'buyer_identity'     => (string) $order->get_meta( QuoteOrder::META_BUYER_IDENTITY ),
			'delivery_code'      => (string) $order->get_meta( QuoteOrder::META_DELIVERY_CODE ),
			'preferred_date'     => (string) $order->get_meta( QuoteOrder::META_PREFERRED_DELIVERY_DATE ),
			'delivery_notes'     => (string) $order->get_customer_note(),
			'edit_url'           => $order->get_edit_order_url(),
		];
	}

	public function get_content_html(): string {
		return wc_get_template_html( $this->template_html, $this->template_args( false ), '', $this->template_base );
	}

	public function get_content_plain(): string {
		return wc_get_template_html( $this->template_plain, $this->template_args( true ), '', $this->template_base );
	}

	public function init_form_fields(): void {
		parent::init_form_fields();
		$this->form_fields = [
			'enabled'    => [ 'title' => __( 'Enable/Disable', 'punchout-woocommerce' ), 'type' => 'checkbox', 'label' => __( 'Enable this email notification', 'punchout-woocommerce' ), 'default' => 'yes' ],
			'recipient'  => [ 'title' => __( 'Recipient(s)', 'punchout-woocommerce' ), 'type' => 'text', 'description' => sprintf( /* translators: %s: admin e-mail */ __( 'Comma-separated e-mail addresses. Defaults to %s.', 'punchout-woocommerce' ), '<code>' . esc_html( (string) get_option( 'admin_email' ) ) . '</code>' ), 'placeholder' => '', 'default' => '', 'desc_tip' => true ],
		] + $this->form_fields;
		unset( $this->form_fields['enabled_duplicate'] );
	}
}
