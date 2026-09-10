<?php
/**
 * POST /punchout/return — cart -> POOM handoff and session close-out.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Http;

use POW\Support\Transport;

use POW\Audit\Log;
use POW\Addresses\DeliveryEstimate;
use POW\Addresses\QuoteAddress;
use POW\Addresses\ReturnConfirmation;
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
		private ?ReturnConfirmation $confirmation = null,
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
		Transport::require_https();
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

		if ( ! Transport::receiver_allowed( $session->browser_form_post_url ) ) {
			Transport::private_headers();
			$this->error_page( __( 'The saved BrowserFormPost requires a valid HTTPS URL. Start a new PunchOut session with a secure receiver.', 'punchout-woocommerce' ) );
			return;
		}

		$partner = $this->registry->find( $session->partner_id );

		if ( null === $partner ) {
			$this->error_page( __( 'This punchout session is no longer valid.', 'punchout-woocommerce' ) );
			return;
		}

		$notices = [];

		if ( 'cart' === $mode ) {
			// The optional constructor argument preserves old integrations, but absence never bypasses mandatory cart consent. Quote/rate/mapper callbacks run entirely before acquiring the winner mutex.
			if ( null === $this->confirmation ) { $this->review_page(); return; }
			try { $mapped = $this->confirmation->for_return( $session, $partner ); }
			catch ( \Throwable $error ) { $this->review_page(); return; }
			if ( $mapped instanceof \WP_Error ) { $this->review_page( $mapped ); return; }
			// Preparation can refresh protected company options. Build from that current row; the final confirmation guard still rejects any subsequent policy change before the sole winner transition.
			try { $partner = $this->registry->find( $session->partner_id ); }
			catch ( \Throwable $error ) { $this->review_page(); return; }
			if ( null === $partner || ! $partner->is_active() ) { $this->review_page(); return; }

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

		// The immutable mapped envelope goes to the optional Quote unchanged. Freight belongs only in this Builder copy, once, while the Quote consumes the native package rates separately.
		$wire_items = $mapped['items'];
		$delivery_args = [];
		if ( 'cart' === $mode ) {
			try {
				$freight = DeliveryEstimate::poom_line( $mapped['delivery'] );
				if ( null !== $freight ) { $wire_items[] = $freight; }
				$destination = $mapped['delivery_destination'];
				$delivery_args = [
					'ship_to' => null === $destination ? null : QuoteAddress::to_cxml( $destination ),
					'emit_ship_to' => $partner->emit_ship_to,
					'emit_delivery_code' => $partner->emit_delivery_code,
					'delivery_code' => $destination['code'] ?? '',
					'delivery_code_extrinsic_name' => $partner->delivery_code_extrinsic_name,
					'delivery_notes' => $mapped['delivery_notes'],
					'delivery_notes_policy' => $partner->delivery_notes_policy,
				];
			} catch ( \Throwable $error ) { $this->review_page(); return; }
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
				'items'               => $wire_items,
			] + $delivery_args
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
		$delivery_error = null;
		try {
			$this->registry->with_partner_lock( $partner->id, function () use ( $partner, $session, $mode, $user, $mapped, &$transitioned, &$delivery_error ) {
				$fresh_partner = $this->registry->find( $partner->id );
				$fresh = $this->sessions->find( $session->id );
				if ( ! $fresh_partner || ! $fresh_partner->is_active() || ! $fresh || ! Transport::receiver_allowed( $fresh->browser_form_post_url )
				|| $fresh->browser_form_post_url !== $session->browser_form_post_url
				|| $fresh->partner_id !== $partner->id || $fresh->user_id !== (int) $user->ID || ! $fresh->expires || $fresh->expires <= gmdate( 'Y-m-d H:i:s' ) || ! hash_equals( $fresh->wp_session_token, wp_get_session_token() ) || ! $this->sessions->login_valid_checked( $fresh ) ) { return; }
				$expected = 'cart' === $mode ? Session::ACTIVE : $session->status;
				if ( $fresh->status !== $expected ) { return; }
				$expected_guard = null;
				if ( 'cart' === $mode ) {
					$delivery_error = new \WP_Error( 'delivery_review_required', __( 'Delivery changed or could not be verified. Review it before returning the cart.', 'punchout-woocommerce' ) );
					$valid = $this->confirmation->validate_prepared_locked( $fresh, $fresh_partner, $mapped );
					if ( true !== $valid ) { if ( $valid instanceof \WP_Error ) { $delivery_error = $valid; } return; }
					$guard = $mapped['_guard'] ?? null;
					if ( ! is_array( $guard ) || ! array_key_exists( 'choice_json', $guard ) || ! array_key_exists( 'confirmation_json', $guard ) ) { return; }
					$expected_guard = [ 'user_id' => $fresh->user_id, 'wp_session_token' => $fresh->wp_session_token, 'delivery_choice' => $guard['choice_json'], 'delivery_confirmation' => $guard['confirmation_json'] ];
				}
				$transitioned = $this->sessions->transition( $fresh->id, $expected, 'cart' === $mode ? Session::RETURNED : Session::CLOSED, [], $expected_guard );
				if ( $transitioned && ! $this->sessions->destroy_login_checked( $fresh ) ) {
					$fenced = $this->registry->transition_status( $partner->id, Partner::STATUS_ACTIVE, [ 'status' => Partner::STATUS_DISABLED ] );
					$this->audit_best_effort( 'return_login_cleanup_failed', [ 'partner_id' => $partner->id, 'session_id' => $session->id, 'user_id' => $user->ID, 'result' => $fenced ? 'disabled' : 'fence_unconfirmed' ] );
				}
			} );
		} catch ( \Throwable $e ) {
			$this->audit_best_effort( 'return_boundary_failed', [ 'partner_id' => $partner->id, 'session_id' => $session->id, 'result' => 'error' ] );
		}

		if ( ! $transitioned ) {
			if ( $delivery_error instanceof \WP_Error ) { $this->review_page( $delivery_error ); return; }
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

	private function review_page( ?\WP_Error $error = null ): void {
		$this->error_page( $error?->get_error_message() ?? __( 'Review and confirm delivery before returning your cart.', 'punchout-woocommerce' ), 409, true );
	}

	private function error_page( string $message, int $status = 403, bool $review = false ): void {
		status_header( $status );
		header( 'Content-Type: text/html; charset=utf-8' );

		echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><title>';
		echo esc_html__( 'Punchout', 'punchout-woocommerce' );
		echo '</title></head><body style="font-family:sans-serif;max-width:36em;margin:4em auto;padding:0 1em"><p>';
		echo esc_html( $message );
		echo '</p>';
		if ( $review ) { echo '<p><a href="' . esc_url( Transport::supplier_url( home_url( '/punchout/confirm' ) ) ) . '">' . esc_html__( 'Review delivery again', 'punchout-woocommerce' ) . '</a></p>'; }
		echo '</body></html>';
	}
}
