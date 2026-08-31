<?php
/**
 * POST /punchout/return — cart -> POOM handoff and session close-out.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Http;

use POW\Audit\Log;
use POW\Cart\PoomMapper;
use POW\Cxml\Builder;
use POW\Cxml\FormPack;
use POW\Installer;
use POW\Partners\Partner;
use POW\Partners\Registry;
use POW\Sessions\Session;
use POW\Sessions\Store;
use POW\Support\Templates;

defined( 'ABSPATH' ) || exit;

/**
 * The RFQ exit and the empty-POOM close-out (scope §3/§5.4/§6.2).
 *
 * - mode=cart: build the POOM from WC()->cart, render the auto-submitting
 *   handoff form targeting the stored BrowserFormPost URL, transition the
 *   session active -> returned.
 * - mode=empty: empty POOM (the cXML cancel semantic). Accepted for the
 *   buyer's own session in `active` ("return without ordering") and in
 *   `ordered` (the pay-path close-out, carrying SupplierOrderInfo with the
 *   Woo order reference).
 *
 * Both paths then tear the session down: the recorded WP session token is
 * destroyed (this login only), auth cookies cleared, cart emptied — the
 * cXML guide's destroy-cookies-after-POOM rule, because the commercial
 * risk is negotiated pricing leaking from an abandoned login (gotcha 8).
 *
 * Also reachable as wc-ajax action `pow_return`.
 */
final class ReturnEndpoint {

	public function __construct(
		private Store $sessions,
		private Registry $registry,
		private PoomMapper $mapper,
		private Builder $builder,
		private Log $audit,
	) {}

	/**
	 * Register the wc-ajax alias (front-end, nocache, no admin bootstrap).
	 */
	public function register(): void {
		add_action( 'wc_ajax_pow_return', [ $this, 'handle_ajax' ] );
	}

	public function handle_ajax(): void {
		$this->handle();
		exit;
	}

	public function handle(): void {
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			$this->error_page( __( 'Invalid request.', 'punchout-woocommerce' ) );
			return;
		}

		$nonce = isset( $_POST['pow_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['pow_nonce'] ) ) : '';

		if ( ! is_user_logged_in() || ! wp_verify_nonce( $nonce, 'pow_return' ) ) {
			$this->expired_page();
			return;
		}

		$user = wp_get_current_user();

		if ( ! in_array( Installer::ROLE, (array) $user->roles, true ) ) {
			$this->error_page( __( 'This action is only available inside a punchout session.', 'punchout-woocommerce' ) );
			return;
		}

		$mode = ( isset( $_POST['pow_mode'] ) && 'empty' === $_POST['pow_mode'] ) ? 'empty' : 'cart';

		// The session must belong to THIS login (user id + exact WP session
		// token). mode=empty additionally accepts the buyer's own `ordered`
		// session — the pay-path close-out (scope §3/§9.7).
		$statuses = 'empty' === $mode ? [ Session::ACTIVE, Session::ORDERED ] : [ Session::ACTIVE ];
		$session  = $this->sessions->find_for_login( $user->ID, wp_get_session_token(), $statuses );

		if ( null === $session ) {
			$this->expired_page();
			return;
		}

		$partner = $this->registry->find( $session->partner_id );

		if ( null === $partner ) {
			$this->error_page( __( 'This punchout session is no longer valid.', 'punchout-woocommerce' ) );
			return;
		}

		$notices = [];

		if ( 'cart' === $mode ) {
			$mapped = $this->mapper->from_cart( $partner );

			foreach ( $mapped['skipped'] as $name ) {
				$notices[] = sprintf(
					/* translators: %s: product name */
					__( '"%s" could not be included (no SKU) and was left out of the returned basket.', 'punchout-woocommerce' ),
					$name
				);
			}
		} else {
			$mapped = [
				'items'       => [],
				'total_cents' => 0,
				'currency'    => get_woocommerce_currency(),
			];
		}

		$supplier_order_info = null;

		if ( 'empty' === $mode && $session->order_id > 0 && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $session->order_id );

			if ( $order ) {
				$date                = $order->get_date_created();
				$supplier_order_info = [
					'order_id'   => (string) $order->get_order_number(),
					'order_date' => $date ? $date->format( 'Y-m-d\TH:i:sP' ) : '',
				];
			}
		}

		$poom_xml = $this->builder->poom(
			[
				'version'             => $partner->cxml_version,
				'payload_id'          => Builder::payload_id( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'localhost' ),
				'timestamp'           => Builder::timestamp(),
				'deployment_mode'     => $session->deployment_mode,
				// From/To reversed from the setup request — we are the
				// originator of this document. Sender is identity-only by
				// construction (Builder writes no SharedSecret, scope §6.2).
				'from'                => [ 'domain' => $partner->to_domain, 'identity' => $partner->to_identity ],
				'to'                  => [ 'domain' => $partner->from_domain, 'identity' => $partner->from_identity ],
				'sender'              => [ 'domain' => $partner->to_domain, 'identity' => $partner->to_identity ],
				'user_agent'          => 'PunchOut for WooCommerce/' . \POW\VERSION,
				'buyer_cookie'        => $session->buyer_cookie,
				'operation_allowed'   => 'create',
				'currency'            => $mapped['currency'],
				'total_cents'         => $mapped['total_cents'],
				'supplier_order_info' => $supplier_order_info,
				'items'               => $mapped['items'],
			]
		);

		// Transition BEFORE rendering: if the guarded update loses a race
		// (double-submit), the second request lands on the expired page
		// instead of sending a second POOM.
		$transitioned = 'cart' === $mode
			? $this->sessions->transition( $session->id, Session::ACTIVE, Session::RETURNED )
			: $this->sessions->transition( $session->id, $session->status, Session::CLOSED );

		if ( ! $transitioned ) {
			$this->expired_page();
			return;
		}

		$this->audit->write(
			'cart' === $mode ? 'return_sent' : 'return_empty_sent',
			[
				'partner_id' => $partner->id,
				'session_id' => $session->id,
				'user_id'    => $user->ID,
				'order_id'   => $session->order_id,
				'direction'  => 'out',
				'result'     => 'ok',
				'detail'     => [
					'lines'    => count( $mapped['items'] ),
					'total'    => $mapped['total_cents'],
					'encoding' => $partner->return_encoding,
				],
				'xml'        => $poom_xml,
			]
		);

		$markup = $this->handoff_markup( $session, $partner, $poom_xml, $notices );

		$this->teardown( $user->ID, $session );

		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template output, escaped within.
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	private function handoff_markup( Session $session, Partner $partner, string $poom_xml, array $notices ): string {
		$field = FormPack::field(
			$poom_xml,
			'urlencoded' === $partner->return_encoding ? FormPack::ENCODING_URLENCODED : FormPack::ENCODING_BASE64
		);

		/**
		 * Filter the buyer-facing copy on the handoff page.
		 *
		 * @param array<string, string> $copy    heading/copy/button strings.
		 * @param Session               $session The closing session.
		 */
		$copy = apply_filters(
			'pow_handoff_copy',
			[
				'heading' => __( 'Returning your basket…', 'punchout-woocommerce' ),
				'copy'    => __( 'Your basket is being sent back to your purchasing system. You should land back in your purchasing application within a few seconds. If you see a sign-in page instead, sign in and open the catalog again from your purchasing system, quoting the support reference below.', 'punchout-woocommerce' ),
				'button'  => __( 'Continue to your purchasing system', 'punchout-woocommerce' ),
			],
			$session
		);

		return Templates::render(
			'handoff',
			[
				'action_url'  => $session->browser_form_post_url,
				'field_name'  => $field['name'],
				'field_value' => $field['value'],
				'reference'   => 'POW-' . $session->id,
				'notices'     => $notices,
				'heading'     => (string) ( $copy['heading'] ?? '' ),
				'copy'        => (string) ( $copy['copy'] ?? '' ),
				'button'      => (string) ( $copy['button'] ?? '' ),
			]
		);
	}

	/**
	 * Destroy exactly this login, clear cookies, empty the cart.
	 */
	private function teardown( int $user_id, Session $session ): void {
		if ( '' !== $session->wp_session_token ) {
			\WP_Session_Tokens::get_instance( $user_id )->destroy( $session->wp_session_token );
		}

		wp_clear_auth_cookie();

		if ( function_exists( 'WC' ) && null !== WC()->cart ) {
			WC()->cart->empty_cart( true );
		}

		$this->audit->write(
			'session_closed',
			[
				'partner_id' => $session->partner_id,
				'session_id' => $session->id,
				'user_id'    => $user_id,
				'result'     => 'ok',
			]
		);
	}

	/**
	 * Expired-session page (scope §9.3): no cart contents, re-punchout copy.
	 */
	private function expired_page(): void {
		$this->error_page(
			__( 'Your punchout session has expired. Please return to your purchasing system and open the catalog again.', 'punchout-woocommerce' ),
			403
		);
	}

	private function error_page( string $message, int $status = 403 ): void {
		status_header( $status );
		header( 'Content-Type: text/html; charset=utf-8' );

		echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><title>';
		echo esc_html__( 'Punchout', 'punchout-woocommerce' );
		echo '</title></head><body style="font-family:sans-serif;max-width:36em;margin:4em auto;padding:0 1em"><p>';
		echo esc_html( $message );
		echo '</p></body></html>';
	}
}
