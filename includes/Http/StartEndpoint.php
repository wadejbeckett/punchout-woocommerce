<?php
/**
 * GET /punchout/start/{token} — one-time auto-login.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Http;

use POW\Audit\Log;
use POW\Installer;
use POW\Partners\Registry;
use POW\Sessions\Store;
use POW\Sessions\Tokens;
use POW\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Redeems the one-time StartPage token and logs the buyer's browser in
 * (scope §3/§5).
 *
 * The token IS the credential: 256-bit random, single-use (atomic status
 * flip), short TTL, stored hashed. The login request NEVER renders
 * content — cookies and nonces are only valid on the next request, so the
 * handler always 302s (wp-implementation M8).
 */
final class StartEndpoint {

	public function __construct(
		private Store $sessions,
		private Registry $registry,
		private Settings $settings,
		private Log $audit,
	) {}

	public function handle( string $token ): void {
		$session = $this->sessions->redeem_token( Tokens::hash( $token ) );

		if ( null === $session ) {
			$this->audit->write(
				'token_reject',
				[
					'direction' => 'in',
					'result'    => '403',
					'ip'        => $this->client_ip(),
				]
			);
			$this->deny();
			return;
		}

		$partner = $this->registry->find( $session->partner_id );
		$user    = get_user_by( 'id', $session->user_id );

		if ( null === $partner || false === $user || ! in_array( Installer::ROLE, (array) $user->roles, true ) ) {
			// A redeemed token pointing at a missing/ineligible user is an
			// operator error (deleted user, deleted partner) — same
			// detail-free page either way.
			$this->deny();
			return;
		}

		$session_ttl = $partner->session_ttl;

		// Punchout logins get the partner's session TTL (~4h), not WP's
		// two-day default. The filter is added just-in-time so it scopes
		// to exactly this wp_set_auth_cookie() call.
		add_filter(
			'auth_cookie_expiration',
			static fn (): int => $session_ttl,
			999
		);

		wp_set_current_user( $user->ID );

		// Create the WP session token explicitly and record it, so
		// teardown at either exit destroys exactly THIS login and no other
		// (gotcha 8; scope §4.2 wp_session_token).
		$manager  = \WP_Session_Tokens::get_instance( $user->ID );
		$wp_token = $manager->create( time() + $session_ttl );

		wp_set_auth_cookie( $user->ID, false, '', $wp_token );

		$this->sessions->update(
			$session->id,
			[
				'wp_session_token' => $wp_token,
				'expires'          => gmdate( 'Y-m-d H:i:s', time() + $session_ttl ),
			]
		);

		update_user_meta( $user->ID, '_pow_last_seen', time() );

		$this->audit->write(
			'token_redeem',
			[
				'partner_id' => $partner->id,
				'session_id' => $session->id,
				'user_id'    => $user->ID,
				'direction'  => 'in',
				'result'     => 'ok',
				'ip'         => $this->client_ip(),
			]
		);

		$target = $this->redirect_target( $session->selected_item );

		/**
		 * Filter the post-login redirect target for a punchout session.
		 *
		 * @param string                $target     Destination URL.
		 * @param \POW\Sessions\Session $session    The activated session.
		 */
		$target = (string) apply_filters( 'pow_start_redirect', $target, $session );

		wp_safe_redirect( $target, 302 );
	}

	/**
	 * Plain 403 page, deliberately detail-free, with the buyer-facing
	 * guidance copy (scope §9.2).
	 */
	public function deny(): void {
		status_header( 403 );
		header( 'Content-Type: text/html; charset=utf-8' );

		$message = __(
			'This catalog link has expired. Please return to your purchasing system and open the catalog again.',
			'punchout-woocommerce'
		);

		/**
		 * Filter the expired/invalid StartPage token message.
		 *
		 * @param string $message Buyer-facing copy.
		 */
		$message = (string) apply_filters( 'pow_expired_token_message', $message );

		echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><title>';
		echo esc_html__( 'Link expired', 'punchout-woocommerce' );
		echo '</title></head><body style="font-family:sans-serif;max-width:36em;margin:4em auto;padding:0 1em"><p>';
		echo esc_html( $message );
		echo '</p></body></html>';
	}

	/**
	 * SelectedItem deep-link when the setup carried one (scope §3),
	 * otherwise the configured landing page.
	 */
	private function redirect_target( ?string $selected_item_json ): string {
		if ( null !== $selected_item_json && function_exists( 'wc_get_product_id_by_sku' ) ) {
			$selected = json_decode( $selected_item_json, true );

			if ( is_array( $selected ) ) {
				$product_id = 0;

				// Our own SupplierPartAuxiliaryID scheme is
				// "{product_id}|{variation_id}" — exact when present.
				if ( ! empty( $selected['aux_id'] ) && preg_match( '/^(\d+)\|/', (string) $selected['aux_id'], $m ) ) {
					$product_id = (int) $m[1];
				}

				if ( 0 === $product_id && ! empty( $selected['supplier_part_id'] ) ) {
					$product_id = (int) wc_get_product_id_by_sku( (string) $selected['supplier_part_id'] );
				}

				if ( $product_id > 0 ) {
					$permalink = get_permalink( $product_id );

					if ( is_string( $permalink ) && '' !== $permalink ) {
						return $permalink;
					}
				}
			}
		}

		return $this->settings->landing_url();
	}

	private function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';

		return (string) apply_filters( 'pow_client_ip', $ip );
	}
}
