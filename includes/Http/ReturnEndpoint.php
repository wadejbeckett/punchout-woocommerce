<?php
/**
 * POST /punchout/return — Cancel and return: the empty PunchOutOrderMessage and the visit's close-out.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Http;

use POW\Support\Transport;

use POW\Audit\Log;
use POW\Checkout\Handoff;
use POW\Cart\LeaveConfirmation;
use POW\Cart\SessionKey;
use POW\Cxml\Builder;
use POW\Cxml\FormPack;
use POW\Partners\Partner;
use POW\Partners\Registry;
use POW\Http\StatePage;
use POW\Sessions\HandoffReplay;
use POW\Sessions\Session;
use POW\Sessions\Store;
use POW\Support\Templates;

defined( 'ABSPATH' ) || exit;

/**
 * The empty-POOM close-out (scope §3/§5.4/§6.2): "return without a cart", the abandon control.
 *
 * Since 0.5.0 a cart leaves through WooCommerce's classic checkout (Checkout\Submission) and the GET handoff
 * (Checkout\Handoff). This endpoint keeps the other way out: an empty PunchOutOrderMessage (the cXML cancel
 * semantic) for the buyer's own `active` visit. There is no paid close-out and no SupplierOrderInfo: a PunchOut
 * order is never payable, so no order of ours exists for a message to reference.
 *
 * The close-out tears the visit down: the recorded WP session token is
 * destroyed (this login only), auth cookies cleared, cart emptied — the
 * cXML guide's destroy-cookies-after-POOM rule, because the commercial
 * risk is negotiated pricing leaking from an abandoned login (gotcha 8).
 *
 * "This login only" is load-bearing, not a nicety. The account is shared:
 * every buyer of the connection punches in as it, so the account holds one
 * WP_Session_Tokens entry per live visit and teardown destroys exactly the
 * one this row recorded. A colleague shopping in the next room keeps her
 * login, her cookie and her own basket.
 *
 * A repeated request for a visit that has already closed (a double click:
 * the browser shows only the answer to the last request, and the first one
 * won) gets the winner's page again instead of the expired page (0.4.22,
 * HandoffReplay, replay()).
 *
 * Also reachable as wc-ajax action `pow_return`.
 */
final class ReturnEndpoint {

	/** The one mode left: the empty message. Kept as the form's value so an older layout's control still works. */
	private const MODE = 'empty';

	public function __construct(
		private Store $sessions,
		private Registry $registry,
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
		Transport::require_https();
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			$this->error_page( __( 'Invalid request.', 'punchout-woocommerce' ) );
			return;
		}

		$nonce = isset( $_POST['pow_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['pow_nonce'] ) ) : '';

		// A request whose visit has already closed has lost its login (the
		// winner destroyed it), so it fails here; the same form from the same
		// browser gets the winner's page again.
		if ( ! is_user_logged_in() || ! wp_verify_nonce( $nonce, 'pow_return' ) ) {
			if ( $this->replay( $nonce ) ) { return; }
			$this->expired_page();
			return;
		}

		$user = wp_get_current_user();

		// The visit must be THIS login's: the account is shared, so the user
		// id names nobody and the exact WP session token is what selects one
		// of its open visits. An ordinary password login of the same account
		// has no matching token and therefore no visit, which is the whole
		// authorisation — there is no role or capability to consult. Only
		// `active` is admitted: the abandon control acts on a live visit.
		$session = $this->sessions->find_for_login( $user->ID, wp_get_session_token(), [ Session::ACTIVE ] );

		if ( null === $session ) {
			if ( $this->replay( $nonce ) ) { return; }
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

		// 0.5.0: "Ask before leaving". A cancel without the answer, while the cart holds a line, goes back to its
		// page with the question open; nothing is sent and the visit stays open.
		$settings = new \POW\Settings();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the pow_return nonce was verified above.
		if ( LeaveConfirmation::must_ask( $settings, LeaveConfirmation::cart_items(), is_string( $_POST[ LeaveConfirmation::FIELD ] ?? null ) ? sanitize_text_field( wp_unslash( $_POST[ LeaveConfirmation::FIELD ] ) ) : null ) ) {
			wp_safe_redirect( LeaveConfirmation::ask_url( wp_get_referer(), $settings->landing_url() ), 303 );
			return;
		}

		try {
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
					'currency'            => get_woocommerce_currency(),
					'total_cents'         => 0,
					// Never a reference: a PunchOut order is never payable, so no order of ours exists for a POOM
					// to name. The Builder keeps the ability to carry one for the 1.2.071 dialect.
					'supplier_order_info' => null,
					'items'               => [],
				]
			);

			// Prepare the complete response before consuming the session.
			$markup = self::handoff_markup( $session, $partner, $poom_xml, [], StatePage::NOT_SENT );
		} catch ( \Throwable $error ) {
			$this->audit_best_effort( 'return_build_failed', [ 'partner_id' => $partner->id, 'session_id' => $session->id, 'result' => 'error' ] );
			$this->error_page( __( 'The cart return could not be prepared. Please try again.', 'punchout-woocommerce' ), 500 );
			return;
		}

		if ( '' === trim( $markup ) ) {
			$this->error_page( __( 'The cart return could not be prepared. Please try again.', 'punchout-woocommerce' ), 500 );
			return;
		}

		// This conditional transition is the only winner selection.
		$transitioned = false;
		$login_token  = (string) wp_get_session_token();
		$kept         = false;
		try {
			$this->registry->with_partner_lock( $partner->id, function () use ( $partner, $session, $user, $nonce, $markup, $login_token, &$transitioned, &$kept ) {
				$fresh_partner = $this->registry->find( $partner->id );
				$fresh = $this->sessions->find( $session->id );
				// Every predicate stays, but read the identity ones correctly:
				// $fresh->user_id === $user->ID proves only that the row still
				// names the shared account, which every colleague's row does
				// too. What proves the visit is hash_equals on the WP session
				// token, and what proves the basket is the per-visit key —
				// re-read here so a row re-keyed between the snapshot and the
				// mutex cannot be closed under the old basket.
				if ( ! $fresh_partner || ! $fresh_partner->is_active() || ! $fresh || ! Transport::receiver_allowed( $fresh->browser_form_post_url )
				|| $fresh->browser_form_post_url !== $session->browser_form_post_url
				|| $fresh->partner_id !== $partner->id || $fresh->user_id !== (int) $user->ID || ! $fresh->expires || $fresh->expires <= gmdate( 'Y-m-d H:i:s' ) || ! hash_equals( $fresh->wp_session_token, wp_get_session_token() )
				|| ! SessionKey::is_visit_key( (string) $fresh->wc_session_key ) || ! hash_equals( (string) $fresh->wc_session_key, (string) $session->wc_session_key )
				|| ! $this->sessions->login_valid_checked( $fresh ) ) { return; }
				// Start from the snapshot's own status, which the lookup admitted only as `active`; the re-read
				// still has to find it there.
				$expected = $session->status;
				if ( $fresh->status !== $expected ) { return; }
				// Kept before the transition, under the lock, so any request that sees this visit closed finds the
				// page to repeat; dropped again below when this request does not win.
				$kept = HandoffReplay::remember( $login_token, $nonce, $fresh->id, self::MODE, $markup );
				$transitioned = $this->sessions->transition( $fresh->id, $expected, Session::CLOSED );
				if ( $transitioned && ! $this->sessions->destroy_login_checked( $fresh ) ) {
					$fenced = $this->registry->transition_status( $partner->id, Partner::STATUS_ACTIVE, [ 'status' => Partner::STATUS_DISABLED ] );
					$this->audit_best_effort( 'return_login_cleanup_failed', [ 'partner_id' => $partner->id, 'session_id' => $session->id, 'user_id' => $user->ID, 'result' => $fenced ? 'disabled' : 'fence_unconfirmed' ] );
				}
			} );
		} catch ( \Throwable $e ) {
			$this->audit_best_effort( 'return_boundary_failed', [ 'partner_id' => $partner->id, 'session_id' => $session->id, 'result' => 'error' ] );
		}

		if ( ! $transitioned && $kept ) { HandoffReplay::forget( $login_token ); }

		if ( ! $transitioned ) {
			// The other click of a double submit won while this one waited for the lock.
			if ( $this->replay( $nonce ) ) { return; }
			$this->expired_page();
			return;
		}

		$this->audit_best_effort(
			'return_empty_sent',
			[
				'partner_id' => $partner->id,
				'session_id' => $session->id,
				'user_id'    => $user->ID,
				'order_id'   => $session->order_id,
				'direction'  => 'out',
				'result'     => 'ok',
				'detail'     => [
					'lines'    => 0,
					'total'    => 0,
					'encoding' => $partner->return_encoding,
				],
				'xml'        => $poom_xml,
			]
		);

		$this->teardown( $user->ID, $session );

		self::html_headers( 200 );
		Handoff::send_page_headers( $markup, $session->browser_form_post_url );
		echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template output, escaped within.
	}

	/**
	 * Answer a repeated cancel for a visit that has already closed with that same page (0.4.22). Only when this
	 * browser presents the login token and the return nonce the winner kept its page under, and the visit row it
	 * names has left `active` that way with that login. Builds nothing and changes nothing but this browser's
	 * cookies. True when the page was sent.
	 */
	public function replay( string $nonce ): bool {
		try {
			$login_token = (string) wp_get_session_token();
			$kept = HandoffReplay::find( $login_token, $nonce );
			if ( null === $kept || self::MODE !== $kept['mode'] ) { return false; }
			$visit = $this->sessions->find( $kept['session_id'] );
			if ( null === $visit || Session::CLOSED !== $visit->status || '' === $visit->wp_session_token || ! hash_equals( $visit->wp_session_token, $login_token ) ) { return false; }
		} catch ( \Throwable $error ) {
			return false;
		}

		wp_clear_auth_cookie();
		Handoff::forget_marker();
		$this->audit_best_effort( 'return_replayed', [ 'partner_id' => $visit->partner_id, 'session_id' => $visit->id, 'user_id' => $visit->user_id, 'direction' => 'out', 'result' => 'ok' ] );
		self::html_headers( 200 );
		Handoff::send_page_headers( $kept['markup'], $visit->browser_form_post_url );
		echo $kept['markup']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the winner's rendered template, escaped within.
		return true;
	}

	/** Status, HTML content type and (0.4.22) no MIME sniffing: also for the wc-ajax alias, which the router never sees. */
	private static function html_headers( int $status ): void {
		status_header( $status );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
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

	/**
	 * The theme-free page that posts the cXML to the visit's BrowserFormPost (handoff template, pow_handoff_copy).
	 * The checkout path keeps it for its GET handoff (0.5.0, Checkout\Submission); Cancel and return draws it with
	 * the empty message.
	 *
	 * It carries the state classes (0.5.0): Sending for a cart, Not sent for the empty message of Cancel and return.
	 * Its fallback button, pressed only when the page cannot post by itself, is the "State page button label".
	 *
	 * @param list<string> $notices Per-line notices (lines left out, for example).
	 * @param string       $state   StatePage::SENDING (a cart) or StatePage::NOT_SENT (the empty cancel message).
	 */
	public static function handoff_markup( Session $session, Partner $partner, string $poom_xml, array $notices, string $state = StatePage::SENDING ): string {
		$field = FormPack::field(
			$poom_xml,
			'urlencoded' === $partner->return_encoding ? FormPack::ENCODING_URLENCODED : FormPack::ENCODING_BASE64
		);
		$state = StatePage::NOT_SENT === $state ? StatePage::NOT_SENT : StatePage::SENDING;

		/**
		 * Filter the buyer-facing copy on the handoff page.
		 *
		 * @param array<string, string> $copy    heading/copy/button strings.
		 * @param Session               $session The closing session.
		 * @param string                $state   'sending' (a cart) or 'not-sent' (Cancel and return), 0.5.0.
		 */
		$copy = apply_filters(
			'pow_handoff_copy',
			[
				'heading' => StatePage::NOT_SENT === $state ? __( 'Not sent', 'punchout-woocommerce' ) : __( 'Sending your cart…', 'punchout-woocommerce' ),
				'copy'    => StatePage::NOT_SENT === $state
					? __( 'Your cart was not sent. Taking you back to your purchasing system.', 'punchout-woocommerce' )
					: __( 'Your cart is being sent back to your purchasing system. You should land back in your purchasing application within a few seconds. If you see a sign-in page instead, sign in and open the catalog again from your purchasing system, quoting the support reference below.', 'punchout-woocommerce' ),
				'button'  => ( new \POW\Settings() )->state_button_label(),
			],
			$session,
			$state
		);

		return Templates::render(
			'handoff',
			[
				'state'       => $state,
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
	 * Destroy exactly this visit: its login, its cookies, its basket.
	 *
	 * Each of the three is per-visit and none may reach a colleague.
	 * destroy_login_checked() (called under the mutex above, before this)
	 * destroys only the WP_Session_Tokens entry this row recorded;
	 * wp_clear_auth_cookie() clears only this browser's cookie; and
	 * empty_cart( true ) acts on the WooCommerce session this request is
	 * bound to, which is this visit's own wc_session_key row — not the shared
	 * account's numeric shopper row and not another visit's.
	 */
	private function teardown( int $user_id, Session $session ): void {
		wp_clear_auth_cookie();
		// 0.5.3: nothing was sent from this visit, so an earlier visit's sent marker must not answer for it.
		Handoff::forget_marker();

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
	 * Expired-session page (scope §9.3): no cart contents, re-punchout copy. With a state page chosen (0.5.0), the
	 * browser goes to its Session ended state instead, drawn inside the theme.
	 */
	private function expired_page(): void {
		$ended = StatePage::link( StatePage::ENDED );
		if ( null !== $ended ) {
			wp_safe_redirect( $ended, 303 );
			return;
		}
		$this->error_page(
			__( 'Your punchout session has expired. Please return to your purchasing system and open the catalog again.', 'punchout-woocommerce' ),
			403
		);
	}

	private function error_page( string $message, int $status = 403 ): void {
		self::html_headers( $status );

		echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><title>';
		echo esc_html__( 'Punchout', 'punchout-woocommerce' );
		echo '</title></head><body style="font-family:sans-serif;max-width:36em;margin:4em auto;padding:0 1em"><p>';
		echo esc_html( $message );
		echo '</p></body></html>';
	}
}
