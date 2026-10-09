<?php
/**
 * Submit at checkout: the placed order goes to the buyer's purchasing system (0.5.0).
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Checkout;

use POW\Audit\Log;
use POW\Cart\SessionKey;
use POW\Cxml\Builder;
use POW\Http\ReturnEndpoint;
use POW\Orders\QuoteOrder;
use POW\Orders\Status;
use POW\Partners\Partner;
use POW\Partners\Registry;
use POW\Plugin;
use POW\Sessions\Session;
use POW\Sessions\Store;
use POW\Settings;
use POW\Support\Transport;

defined( 'ABSPATH' ) || exit;

/**
 * The PunchOut payment method's process_payment(), in this order:
 *
 * 1. Build the PunchOutOrderMessage from the placed order (OrderMessage, unchanged builder) and the handoff page
 *    that posts it, then stamp the order's provenance (visit, connection, buyer, cookie, cXML copy). Nothing is
 *    consumed yet: a failure here answers "failure" with a plain message, the order stays pending and the visit open.
 * 2. Under the connection lock, re-prove the visit (active, this login, this basket, unexpired, same receiver),
 *    keep the handoff page for this browser (Handoff), then make this visit the single return winner, linking the
 *    order, and end its login. The browser keeps its cookie until the handoff clears it.
 * 3. After the win, each step on its own: the audit row with the cXML, the empty cart, then the Punchout Quote
 *    status, last, which is what sends the store its one "PunchOut order received" e-mail.
 *
 * The answer is "success" with the handoff address, where the browser is sent next.
 *
 * Not yet here (later 0.5.0 tickets): refusing a SKU-less line, a wrong currency or a note cXML cannot carry before
 * an order exists; the loser of a double Submit trashing its own order; releasing stock and coupon holds;
 * zero-total carts; the Sent and Not sent pages.
 */
final class Submission {

	public function __construct(
		private Plugin $plugin,
		private Registry $registry,
		private Store $sessions,
		private Builder $builder,
		private Log $audit,
		private Settings $settings,
	) {}

	/**
	 * @return array{result: string, redirect?: string}
	 */
	public function submit( int $order_id ): array {
		try {
			$order = $order_id > 0 ? wc_get_order( $order_id ) : null;
			$visit = $this->plugin->current_session();
			$partner = null !== $visit ? $this->registry->find( $visit->partner_id ) : null;
		} catch ( \Throwable $error ) {
			return $this->refuse( self::ended() );
		}
		if ( ! $order instanceof \WC_Order || null === $visit || Session::ACTIVE !== $visit->status || null === $partner || ! $partner->is_active() || ! Transport::receiver_allowed( $visit->browser_form_post_url ) ) {
			return $this->refuse( self::ended() );
		}

		// 1. Build first. Nothing is consumed until the message and its page exist.
		try {
			[ $args, $skipped ] = OrderMessage::builder_args( $order, $partner, $visit, (string) $this->settings->get( 'default_unspsc', '' ), (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
			$poom_xml = $this->builder->poom( $args );
			$notices  = array_map( static fn( string $name ): string => sprintf( /* translators: %s: product name */ __( '"%s" could not be included (no SKU) and was left out of the returned cart.', 'punchout-woocommerce' ), $name ), $skipped );
			$markup   = ReturnEndpoint::handoff_markup( $visit, $partner, $poom_xml, $notices );
			if ( '' === trim( $markup ) ) { throw new \RuntimeException( 'Empty handoff page.' ); }
			$this->stamp( $order, $visit, $partner, $poom_xml );
		} catch ( \Throwable $error ) {
			$this->audit_best_effort( 'return_build_failed', [ 'partner_id' => $partner->id, 'session_id' => $visit->id, 'order_id' => $order_id, 'result' => 'error' ] );
			return $this->refuse( __( 'Your order could not be prepared for your purchasing system because some of its text cannot be sent (for example a special character in an address or the notes). Check the details and submit again.', 'punchout-woocommerce' ) );
		}

		// 2. Keep the page for this browser, then win the return, under the connection lock.
		$login_token = (string) wp_get_session_token();
		$order_key   = (string) $order->get_order_key();
		$user_id     = (int) get_current_user_id();
		$kept        = false;
		$won         = false;
		try {
			$this->registry->with_partner_lock( $partner->id, function () use ( $partner, $visit, $order_id, $order_key, $login_token, $user_id, $markup, &$kept, &$won ): void {
				$fresh_partner = $this->registry->find( $partner->id );
				$fresh         = $this->sessions->find( $visit->id );
				if ( null === $fresh_partner || ! $fresh_partner->is_active() || null === $fresh || Session::ACTIVE !== $fresh->status
				|| $fresh->partner_id !== $partner->id || $fresh->user_id !== $user_id || '' === $login_token || ! hash_equals( $fresh->wp_session_token, $login_token )
				|| $fresh->browser_form_post_url !== $visit->browser_form_post_url || ! Transport::receiver_allowed( $fresh->browser_form_post_url )
				|| ! $fresh->expires || $fresh->expires <= gmdate( 'Y-m-d H:i:s' )
				|| ! SessionKey::is_visit_key( (string) $fresh->wc_session_key ) || ! hash_equals( (string) $fresh->wc_session_key, (string) $visit->wc_session_key )
				|| ! $this->sessions->login_valid_checked( $fresh ) ) {
					return;
				}
				$kept = Handoff::remember( $login_token, $order_key, $fresh->id, $order_id, $markup );
				if ( ! $kept ) { return; }
				$won = $this->sessions->transition( $fresh->id, Session::ACTIVE, Session::RETURNED, [ 'order_id' => $order_id ] );
				if ( $won && ! $this->sessions->destroy_login_checked( $fresh ) ) {
					$fenced = $this->registry->transition_status( $partner->id, Partner::STATUS_ACTIVE, [ 'status' => Partner::STATUS_DISABLED ] );
					$this->audit_best_effort( 'return_login_cleanup_failed', [ 'partner_id' => $partner->id, 'session_id' => $fresh->id, 'user_id' => $user_id, 'result' => $fenced ? 'disabled' : 'fence_unconfirmed' ] );
				}
			} );
		} catch ( \Throwable $error ) {
			$this->audit_best_effort( 'return_boundary_failed', [ 'partner_id' => $partner->id, 'session_id' => $visit->id, 'order_id' => $order_id, 'result' => 'error' ] );
		}

		if ( ! $won ) {
			if ( $kept ) { Handoff::forget( $login_token, $order_key ); }
			return $this->refuse( self::ended() );
		}

		// 3. After the win: each step on its own, none can stop the handoff.
		$this->audit_best_effort(
			'return_sent',
			[
				'partner_id' => $partner->id,
				'session_id' => $visit->id,
				'user_id'    => $user_id,
				'order_id'   => $order_id,
				'direction'  => 'out',
				'result'     => 'ok',
				'detail'     => [ 'lines' => count( $args['items'] ), 'total' => $args['total_cents'], 'encoding' => $partner->return_encoding, 'via' => 'checkout' ],
				'xml'        => $poom_xml,
			]
		);
		try {
			if ( function_exists( 'WC' ) && null !== WC()->cart ) { WC()->cart->empty_cart(); }
		} catch ( \Throwable $error ) {
			// The visit has returned; its basket row is removed with it.
		}
		try {
			$order->update_status(
				Status::SLUG,
				sprintf(
					/* translators: 1: visit id, 2: connection name */
					__( 'Sent to the buyer’s purchasing system from punchout session #%1$d (%2$s).', 'punchout-woocommerce' ),
					$visit->id,
					$partner->name
				)
			);
		} catch ( \Throwable $error ) {
			$this->audit_best_effort( 'quote_status_failed', [ 'partner_id' => $partner->id, 'session_id' => $visit->id, 'order_id' => $order_id, 'result' => 'error' ] );
		}

		return [ 'result' => 'success', 'redirect' => Handoff::url( $order_id, $order_key ) ];
	}

	/** The provenance every PunchOut order carries (the same keys old Quotes carry), and the cXML copy. */
	private function stamp( \WC_Order $order, Session $visit, Partner $partner, string $poom_xml ): void {
		foreach (
			[
				QuoteOrder::META_SESSION_ID     => (string) $visit->id,
				QuoteOrder::META_PARTNER_ID     => (string) $partner->id,
				QuoteOrder::META_PARTNER_NAME   => $partner->name,
				QuoteOrder::META_BUYER_IDENTITY => (string) $visit->buyer_identity,
				QuoteOrder::META_BUYER_NAME     => (string) $visit->buyer_name,
				QuoteOrder::META_BUYER_COOKIE   => $visit->buyer_cookie,
				QuoteOrder::META_POOM_XML       => $poom_xml,
			] as $key => $value
		) {
			$order->update_meta_data( $key, $value );
		}
		$order->save();
	}

	/** @return array{result: string} */
	private function refuse( string $message ): array {
		if ( function_exists( 'wc_add_notice' ) ) { wc_add_notice( $message, 'error' ); }
		return [ 'result' => 'failure' ];
	}

	private static function ended(): string {
		return __( 'This catalog session has ended or could not be confirmed, so the order was not sent. Open the catalog again from your purchasing system.', 'punchout-woocommerce' );
	}

	private function audit_best_effort( string $event, array $context ): void {
		try {
			$this->audit->write_checked( $event, $context );
		} catch ( \Throwable $error ) {
			// Reporting never decides a Submit.
		}
	}
}
