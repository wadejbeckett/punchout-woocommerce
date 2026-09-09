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
use POW\Orders\QuoteOrder;
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
		private QuoteOrder $quotes,
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
					__( '"%s" could not be included (no SKU) and was left out of the returned cart.', 'punchout-woocommerce' ),
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

		// Prepare the complete response before consuming the session. The same mapped
		// snapshot supplies both the winning quote and this already-built document.
		$markup = $this->handoff_markup( $session, $partner, $poom_xml, $notices );

		if ( '' === trim( $markup ) ) {
			$this->error_page( __( 'The cart return could not be prepared. Please try again.', 'punchout-woocommerce' ), 500 );
			return;
		}

		// This conditional transition is the only winner selection. A losing request
		// never creates or attaches a quote, even if it read an earlier active snapshot.
		$transitioned = false;
		try {
			$this->registry->with_partner_lock( $partner->id, function () use ( $partner, $session, $mode, $user, &$transitioned ) {
				$fresh_partner = $this->registry->find( $partner->id );
				$fresh = $this->sessions->find( $session->id );
				if ( ! $fresh_partner || ! $fresh_partner->is_active() || ! $fresh || $fresh->partner_id !== $partner->id || $fresh->user_id !== (int) $user->ID || ! $fresh->expires || $fresh->expires <= gmdate( 'Y-m-d H:i:s' ) || ! hash_equals( $fresh->wp_session_token, wp_get_session_token() ) || ! $this->sessions->login_valid_checked( $fresh ) ) { return; }
				$expected = 'cart' === $mode ? Session::ACTIVE : $session->status;
				if ( $fresh->status !== $expected ) { return; }
				$transitioned = $this->sessions->transition( $fresh->id, $expected, 'cart' === $mode ? Session::RETURNED : Session::CLOSED );
				if ( $transitioned && ! $this->sessions->destroy_login_checked( $fresh ) ) {
					$fenced = $this->registry->transition_status( $partner->id, Partner::STATUS_ACTIVE, [ 'status' => Partner::STATUS_DISABLED ] );
					$this->audit_best_effort( 'return_login_cleanup_failed', [ 'partner_id' => $partner->id, 'session_id' => $session->id, 'user_id' => $user->ID, 'result' => $fenced ? 'disabled' : 'fence_unconfirmed' ] );
				}
			} );
		} catch ( \Throwable $e ) {
			$this->audit_best_effort( 'return_boundary_failed', [ 'partner_id' => $partner->id, 'session_id' => $session->id, 'result' => 'error' ] );
		}

		if ( ! $transitioned ) {
			$this->expired_page();
			return;
		}

		$quote_order_id = 0;

		if ( 'cart' === $mode ) {
			try {
				$quote_order_id = $this->quotes->create_for_session( $session, $partner, $mapped );

				if ( $quote_order_id > 0 ) {
					$this->quotes->attach_poom( $quote_order_id, $poom_xml );
				}
			} catch ( \Throwable $e ) {
				// Defence in depth for unexpected optional-service failures. Never expose
				// extension exception text, which can contain credentials or basket XML.
				$this->audit_best_effort( 'quote_return_failed', [ 'session_id' => $session->id, 'partner_id' => $partner->id, 'order_id' => $quote_order_id, 'result' => 'error' ] );
			}
		}

		$this->audit_best_effort(
			'cart' === $mode ? 'return_sent' : 'return_empty_sent',
			[
				'partner_id' => $partner->id,
				'session_id' => $session->id,
				'user_id'    => $user->ID,
				'order_id'   => $quote_order_id > 0 ? $quote_order_id : $session->order_id,
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

		$this->teardown( $user->ID, $session );

		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template output, escaped within.
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	private function audit_best_effort( string $event, array $context ): void {
		try {
			$this->audit->write_checked( $event, $context );
		} catch ( \Throwable $e ) {
			// Reporting cannot prevent a prepared, authorized handoff, including teardown.
		}
	}

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
				'heading' => __( 'Returning your cart…', 'punchout-woocommerce' ),
				'copy'    => __( 'Your cart is being sent back to your purchasing system. You should land back in your purchasing application within a few seconds. If you see a sign-in page instead, sign in and open the catalog again from your purchasing system, quoting the support reference below.', 'punchout-woocommerce' ),
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
		wp_clear_auth_cookie();

		if ( function_exists( 'WC' ) && null !== WC()->cart ) {
			WC()->cart->empty_cart( true );
		}

		$this->audit_best_effort(
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
