<?php
/**
 * The PunchOut payment method: a WooCommerce payment gateway (0.5.0).
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Checkout;

use POW\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * "Paying" with PunchOut sends the placed order to the buyer's purchasing system as a PunchOutOrderMessage and
 * keeps it in the shop as a Punchout Quote; nothing is charged. Where it shows is PaymentMethod's rule; what Submit
 * does is Submission's. Its settings (WooCommerce > Settings > Payments) are WooCommerce's own: on or off, the
 * title and description buyers see, and the place-order button's text; a heading there links to the plugin's own
 * tab, WooCommerce > Settings > PunchOut (0.5.3).
 *
 * Loaded only once WooCommerce has (its base class is WooCommerce's).
 */
final class Gateway extends \WC_Payment_Gateway {

	public function __construct( private PaymentMethod $method, private ?Submission $submission = null ) {
		$this->id                 = PaymentMethod::ID;
		$this->has_fields         = false;
		$this->supports           = [ 'products' ];
		$this->method_title       = __( 'PunchOut', 'punchout-woocommerce' );
		$this->method_description = __( 'Sends the order to the buyer’s purchasing system during a PunchOut visit, where it is the only payment method. Other shoppers never see it. Nothing is charged; the order waits as a Punchout Quote. Customer connections and the other PunchOut settings are under WooCommerce → Settings → PunchOut.', 'punchout-woocommerce' );
		$this->init_form_fields();
		$this->init_settings();
		// Settings saved without the switch (only a label, say) never switched the method off.
		$this->enabled           = 'no' === $this->get_option( 'enabled' ) ? 'no' : 'yes';
		$this->title             = Settings::resolve_label( $this->get_option( 'title' ), __( 'PunchOut', 'punchout-woocommerce' ) );
		$this->description       = (string) $this->get_option( 'description' );
		$this->order_button_text = PaymentMethod::place_order_label( $this->get_option( 'order_button_text' ) );
	}

	public function init_form_fields(): void {
		$this->form_fields = [
			'enabled'           => [
				'title'   => __( 'Enable/Disable', 'punchout-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Offer PunchOut during PunchOut visits', 'punchout-woocommerce' ),
				'default' => 'yes',
			],
			'title'             => [
				'title'       => __( 'Title', 'punchout-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'The name buyers see beside the method at checkout. Blank uses the default: “PunchOut”.', 'punchout-woocommerce' ),
				'default'     => __( 'PunchOut', 'punchout-woocommerce' ),
			],
			'description'       => [
				'title'       => __( 'Description', 'punchout-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Shown under the method at checkout. Blank shows nothing.', 'punchout-woocommerce' ),
				'default'     => __( 'Sends this order to your purchasing system.', 'punchout-woocommerce' ),
			],
			'order_button_text' => [
				'title'       => __( 'Place order button', 'punchout-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Text on checkout’s place-order button during a visit. Blank uses the default: “Place order”.', 'punchout-woocommerce' ),
				'default'     => '',
			],
			// A heading WooCommerce draws and never saves: where the connections and the rest of the settings live.
			'punchout_settings' => [
				'title'       => __( 'Connections and PunchOut settings', 'punchout-woocommerce' ),
				'type'        => 'title',
				'description' => sprintf(
					/* translators: %s: link to WooCommerce > Settings > PunchOut. */
					__( 'Customer connections and their activation, the log, the integration docs and the visit settings are under %s.', 'punchout-woocommerce' ),
					'<a href="' . esc_url( \POW\Admin\Page::url() ) . '">' . esc_html__( 'WooCommerce → Settings → PunchOut', 'punchout-woocommerce' ) . '</a>'
				),
			],
		];
	}

	/** Offered only while a live visit owns the request (and the method is switched on). */
	public function is_available() {
		return parent::is_available() && $this->method->live_visit();
	}

	/**
	 * Send the placed order to the visit's purchasing system (Submission). WooCommerce redirects the browser to the
	 * address this returns.
	 *
	 * @param int $order_id The order checkout just placed.
	 * @return array<string, string>
	 */
	public function process_payment( $order_id ) {
		if ( null === $this->submission ) {
			wc_add_notice( __( 'Your cart could not be sent to your purchasing system. Please try again.', 'punchout-woocommerce' ), 'error' );
			return [ 'result' => 'failure' ];
		}
		return $this->submission->submit( (int) $order_id );
	}
}
