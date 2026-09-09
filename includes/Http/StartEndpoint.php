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
use POW\Support\Ip;

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
		$session = null;
		$logged_in = false;
		try {
			$located = $this->sessions->find_by_token_hash( Tokens::hash( $token ) );
			if ( $located ) {
				$this->registry->with_partner_lock( $located->partner_id, function () use ( $token, $located, &$session, &$logged_in ) {
					$pending = $this->sessions->find_by_token_hash( Tokens::hash( $token ) );
					$partner = $this->registry->find( $located->partner_id );
					if ( ! $pending || $pending->partner_id !== $located->partner_id || $pending->status !== \POW\Sessions\Session::PENDING || ! $pending->expires || $pending->expires <= gmdate( 'Y-m-d H:i:s' ) || ! $partner || ! $partner->is_active() ) { return; }
					$user = get_userdata( $pending->user_id );
					if ( ! $user || ! in_array( Installer::ROLE, (array) $user->roles, true ) || (int) get_user_meta( $user->ID, '_pow_partner_id', true ) !== $partner->id || get_user_meta( $user->ID, '_pow_deactivated', true ) ) { return; }
					$session = $this->sessions->redeem_token( Tokens::hash( $token ) );
					if ( ! $session ) { return; }
					try {
						$expiration = time() + $partner->session_ttl;
						$wp_token = wp_generate_password( 43, false, false );
						if ( ! $this->sessions->bind_login( $session->id, $wp_token, gmdate( 'Y-m-d H:i:s', $expiration ) ) ) { throw new \RuntimeException( 'Login binding failed.' ); }
						$session = $this->sessions->find( $session->id );
						$info = apply_filters( 'attach_session_information', [], $user->ID );
						$info['expiration'] = $expiration;
						$info['login'] = time();
						if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) { $info['ip'] = $_SERVER['REMOTE_ADDR']; }
						if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) { $info['ua'] = wp_unslash( $_SERVER['HTTP_USER_AGENT'] ); }
						wp_cache_delete( $user->ID, 'user_meta' );
						\WP_Session_Tokens::get_instance( $user->ID )->update( $wp_token, $info );
						if ( ! $this->sessions->login_valid_checked( $session ) ) { throw new \RuntimeException( 'Login persistence failed.' ); }
						$ttl_filter = static fn(): int => $partner->session_ttl;
						add_filter( 'auth_cookie_expiration', $ttl_filter, 999 );
						try {
							wp_set_current_user( $user->ID );
							wp_set_auth_cookie( $user->ID, false, '', $wp_token );
							$logged_in = true;
						} finally { remove_filter( 'auth_cookie_expiration', $ttl_filter, 999 ); }
					} catch ( \Throwable $e ) {
						if ( ! $this->sessions->expire_locked( $session ) ) {
							$this->registry->transition_status( $partner->id, \POW\Partners\Partner::STATUS_ACTIVE, [ 'status' => \POW\Partners\Partner::STATUS_DISABLED ] );
						}
						wp_clear_auth_cookie();
						wp_set_current_user( 0 );
					}
				} );
			}
		} catch ( \Throwable $e ) { /* No unconfirmed token is exposed; recorded references support recovery. */ }
		try {
			$this->audit->write_checked( $logged_in ? 'token_redeem' : 'token_reject', [ 'partner_id' => $session?->partner_id ?? 0, 'session_id' => $session?->id ?? 0, 'user_id' => $session?->user_id ?? 0, 'result' => $logged_in ? 'ok' : '403', 'ip' => $this->client_ip() ] );
		} catch ( \Throwable $e ) { /* Diagnostics cannot undo confirmed login. */ }
		if ( ! $logged_in || ! $session ) { $this->deny(); return; }
		update_user_meta( $session->user_id, '_pow_last_seen', time() );

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
		return Ip::client();
	}
}
