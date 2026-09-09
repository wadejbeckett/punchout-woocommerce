<?php
/**
 * Ordinary company-owner integration management in WooCommerce My Account.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Account;

use POW\Audit\Log;
use POW\Docs\Page as DocsPage;
use POW\Http\RateLimiter;
use POW\Http\Router;
use POW\Installer;
use POW\Partners\{Registration, Registry};
use POW\Plugin;
use POW\Support\{Ip, Templates};

defined( 'ABSPATH' ) || exit;

final class IntegrationTab {
	public const ENDPOINT = 'punchout-integration';
	public const NONCE = 'pow_account';

	public function __construct(
		private Plugin $plugin,
		private Registry $registry,
		private Registration $registration,
		private Log $audit,
		private RateLimiter $limiter,
	) {}

	/** Endpoint registration stays unconditional; permission checks guard each surface. */
	public function register(): void {
		add_action( 'init', [ $this, 'add_endpoint' ] );
		add_filter( 'query_vars', [ $this, 'add_query_var' ] );
		add_filter( 'woocommerce_get_query_vars', [ $this, 'add_wc_query_var' ] );
		add_filter( 'woocommerce_account_menu_items', [ $this, 'add_menu_item' ] );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', [ $this, 'render' ] );
		add_action( 'template_redirect', [ $this, 'handle_post' ] );
	}

	public function add_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_PAGES );
	}

	/** @param list<string> $vars @return list<string> */
	public function add_query_var( array $vars ): array {
		if ( ! in_array( self::ENDPOINT, $vars, true ) ) { $vars[] = self::ENDPOINT; }
		return $vars;
	}

	/** Woo's endpoint conditional uses this map, independently of WordPress rewrite query vars. */
	public function add_wc_query_var( array $vars ): array {
		$vars[self::ENDPOINT] = self::ENDPOINT;
		return $vars;
	}

	/** @param array<string,string> $items @return array<string,string> */
	public function add_menu_item( array $items ): array {
		if ( 0 === $this->actor() ) { return $items; }
		$logout = $items['customer-logout'] ?? null;
		unset( $items['customer-logout'] );
		$items[self::ENDPOINT] = __( 'Punchout integration', 'punchout-woocommerce' );
		if ( null !== $logout ) { $items['customer-logout'] = $logout; }
		return $items;
	}

	/** Authorisation is independent of the redirect policy for shopping sessions. */
	private function actor(): int {
		try {
			if ( ! $this->plugin->enabled() || ! is_user_logged_in() ) { return 0; }
			$id = get_current_user_id();
			$user = $id > 0 ? get_userdata( $id ) : false;
			return $user && user_can( $user, 'read' ) && ! in_array( Installer::ROLE, (array) $user->roles, true ) && ! get_user_meta( $id, '_pow_partner_id', true ) ? $id : 0;
		} catch ( \Throwable $e ) { return 0; }
	}

	public function render(): void {
		if ( 0 === $this->actor() ) { return; }
		try {
			// GET views have no secret source. Neither slots nor reveal storage are read for display.
			$vars = $this->view_vars();
			echo Templates::render( 'account/integration', $vars ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped template.
		} catch ( \Throwable $e ) {
			echo '<p class="woocommerce-error">' . esc_html__( 'Connection information is unavailable. Please try again later.', 'punchout-woocommerce' ) . '</p>';
		}
	}

	/** All action results are direct POST responses; no secret or notice reveal store exists. */
	public function handle_post(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! is_account_page() || ! is_wc_endpoint_url( self::ENDPOINT ) || 0 === $this->actor() ) { return; }
		// Never issue a credential after output has made no-store headers impossible.
		if ( headers_sent() ) { return; }
		$this->private_headers();
		$result = $this->post_result();
		if ( null === $result ) { return; }
		status_header( $result['status'] );
		echo Templates::render( 'account/integration', $this->result_vars( $result ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped template.
		exit;
	}

	/** Keep a confirmed secret available even when subsequent view or permalink queries fail. */
	private function result_vars( array $result ): array {
		try {
			$vars = $this->view_vars();
		} catch ( \Throwable $e ) {
			// This fallback deliberately performs no further lookups or helper calls.
			$vars = $this->empty_vars();
		}
		$vars['notice'] = $result['notice'];
		$vars['secret'] = $result['secret'];
		return $vars;
	}

	private function private_headers(): void {
		nocache_headers();
		header( 'Cache-Control: no-store, private, max-age=0', true );
		header( 'Referrer-Policy: no-referrer', true );
	}

	/** @return array{status:int,notice:array{text:string,type:string},secret:string}|null */
	private function post_result(): ?array {
		$actor = $this->actor();
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! is_account_page() || ! is_wc_endpoint_url( self::ENDPOINT ) || 0 === $actor ) { return null; }
		$action = $_POST['pow_account_action'] ?? null;
		$nonce = $_POST['_wpnonce'] ?? null;
		if ( ! is_string( $action ) || ! in_array( $action, [ 'submit', 'rotate', 'finish_rotation', 'deactivate' ], true ) || ! is_string( $nonce ) || ! wp_verify_nonce( wp_unslash( $nonce ), self::NONCE ) ) {
			return $this->result( 403, __( 'This request could not be authorised. Reload the integration page and try again.', 'punchout-woocommerce' ) );
		}
		try {
			// Both buckets cover all actions. Respect the injected bounded hourly threshold.
			$user_allowed = $this->limiter->allow( 'account|user|' . $actor );
			$ip_allowed = $this->limiter->allow( 'account|ip|' . Ip::client() );
			if ( ! $user_allowed || ! $ip_allowed ) { return $this->result( 429, __( 'Too many connection requests. Please try again later.', 'punchout-woocommerce' ) ); }
			if ( 'submit' === $action ) {
				$fields = $this->posted_fields();
				if ( null === $fields ) { return $this->result( 400, __( 'Enter text in the connection fields and try again.', 'punchout-woocommerce' ) ); }
				$id = $this->registration->submit( $actor, $fields );
				return is_wp_error( $id ) ? $this->result( 400, $id->get_error_message() ) : $this->result( 200, __( 'Your connection request has been submitted for review.', 'punchout-woocommerce' ) );
			}
			$partner = $this->registry->find_by_owner( $actor );
			if ( null === $partner || ! $partner->is_owned_by( $actor ) ) { return $this->refused(); }
			if ( 'deactivate' === $action ) {
				// Service rechecks the actual ordinary owner, fences immediately and drains all sessions.
				return $this->registration->request_deactivation( $partner->id, $actor )
					? $this->result( 200, __( 'The connection is deactivated and its open sessions have ended.', 'punchout-woocommerce' ) )
					: $this->result( 503, __( 'Deactivation could not be fully confirmed. Contact the store before using this connection again.', 'punchout-woocommerce' ) );
			}
			return $this->rotation_result( $partner->id, $actor, $action );
		} catch ( \Throwable $e ) {
			return $this->result( 503, __( 'The connection change could not be confirmed. Reload the integration page before trying again.', 'punchout-woocommerce' ) );
		}
	}

	/** Preserve a confirmed write even if lock release or later diagnostics fail. */
	private function rotation_result( int $id, int $actor, string $action ): array {
		$confirmed = null;
		try {
			$result = $this->registry->with_partner_lock( $id, function () use ( $id, $actor, $action, &$confirmed ): array {
				$p = $this->registry->find( $id );
				if ( $this->actor() !== $actor || null === $p || ! $p->is_owned_by( $actor ) || ! $p->is_active() ) { return $this->refused(); }
				$open = '' !== $p->secret_previous;
				if ( ( 'rotate' === $action && $open ) || ( 'finish_rotation' === $action && ! $open ) ) {
					return $this->result( 409, __( 'The rotation state has changed. Reload the integration page before continuing.', 'punchout-woocommerce' ) );
				}
				if ( 'rotate' === $action ) {
					$secret = $this->registry->rotate( $id );
					if ( null === $secret || '' === $secret ) { return $this->result( 503, __( 'Secret rotation could not be confirmed.', 'punchout-woocommerce' ) ); }
					$confirmed = $this->result( 200, __( 'Secret rotated. The previous secret remains valid until you finish the rotation.', 'punchout-woocommerce' ), $secret );
				} else {
					if ( ! $this->registry->close_rotation( $id ) ) { return $this->result( 503, __( 'Finishing the rotation could not be confirmed.', 'punchout-woocommerce' ) ); }
					$confirmed = $this->result( 200, __( 'Rotation finished. The previous secret is no longer accepted.', 'punchout-woocommerce' ) );
				}
				return $confirmed;
			} );
		} catch ( \Throwable $e ) {
			$result = $confirmed ?? $this->result( 503, __( 'The connection change could not be confirmed.', 'punchout-woocommerce' ) );
		}
		if ( null !== $confirmed ) {
			global $wpdb;
			try {
				$previous = $wpdb->suppress_errors( true );
				try {
					$this->audit->write_checked( 'rotate' === $action ? 'secret_rotated' : 'rotation_closed', [ 'partner_id' => $id, 'user_id' => $actor, 'result' => 'ok' ] );
				} finally { $wpdb->suppress_errors( $previous ); }
			} catch ( \Throwable $e ) { /* Diagnostics cannot erase a confirmed credential result. */ }
		}
		return $result;
	}

	/** Reject structured inputs; never forward owner, status, identity entitlement or secret controls. */
	private function posted_fields(): ?array {
		$fields = [];
		foreach ( [ 'name', 'from_domain', 'from_identity', 'sender_domain', 'sender_identity', 'deployment_mode', 'notes' ] as $key ) {
			// `name` is a public WP query var: posting it changes the main page query to a 404.
			$posted_key = 'name' === $key && array_key_exists( 'pow_name', $_POST ) ? 'pow_name' : $key;
			if ( ! array_key_exists( $posted_key, $_POST ) ) { continue; }
			if ( ! is_string( $_POST[$posted_key] ) ) { return null; }
			$value = wp_unslash( $_POST[$posted_key] );
			$value = 'notes' === $key ? trim( wp_strip_all_tags( $value ) ) : sanitize_text_field( $value );
			// Registration defaults only absent Sender values, so omit optional blank fields.
			if ( '' === $value && in_array( $key, [ 'sender_domain', 'sender_identity' ], true ) ) { continue; }
			$fields[$key] = $value;
		}
		return $fields;
	}

	private function result( int $status, string $text, string $secret = '' ): array {
		return [ 'status' => $status, 'notice' => [ 'text' => $text, 'type' => $status >= 400 ? 'error' : 'success' ], 'secret' => $secret ];
	}

	private function refused(): array {
		return $this->result( 403, __( 'This connection cannot be managed from this account.', 'punchout-woocommerce' ) );
	}

	/** Safe view data only: a theme override never receives sealed registry slots. */
	private function view_vars(): array {
		$actor = $this->actor();
		if ( 0 === $actor ) { throw new \RuntimeException( 'Account access refused.' ); }
		$p = $this->registry->find_by_owner( $actor );
		if ( null !== $p && ! $p->is_owned_by( $actor ) ) { throw new \RuntimeException( 'Account access refused.' ); }
		$vars = $this->base_vars();
		$vars['state'] = null === $p ? 'none' : ( $p->is_pending() ? 'pending' : ( $p->is_active() ? 'active' : 'disabled' ) );
		if ( null !== $p ) {
			$vars['connection'] = [ 'name' => $p->name, 'from' => $p->from_domain . ' / ' . $p->from_identity, 'sender' => $p->sender_domain . ' / ' . $p->sender_identity, 'to' => $p->to_domain . ' / ' . $p->to_identity, 'deployment_mode' => $p->deployment_mode, 'cxml_version' => $p->cxml_version, 'return_encoding' => $p->return_encoding ];
			$vars['connection']['exit_policy'] = \POW\Checkout\ExitPolicy::labels()[ $p->exit_policy ];
			$vars['connection']['effective_exit_policy'] = \POW\Checkout\ExitPolicy::labels()[ \POW\Checkout\ExitPolicy::resolve( $this->plugin->settings()->exit_policy(), $p->exit_policy, 'inherit' ) ];
			$vars['rotation_open'] = '' !== $p->secret_previous;
			try { $vars['last_setup'] = $this->audit->last_success( $p->id ); } catch ( \Throwable $e ) { $vars['last_setup'] = __( 'Unavailable', 'punchout-woocommerce' ); }
		}
		return $vars;
	}

	private function base_vars(): array {
		$docs_url = '';
		// Use the store's published shortcode page instead of inventing a docs permalink.
		foreach ( get_posts( [ 'post_type' => 'page', 'post_status' => 'publish', 's' => DocsPage::SHORTCODE, 'numberposts' => -1 ] ) as $page ) {
			if ( has_shortcode( $page->post_content, DocsPage::SHORTCODE ) ) { $docs_url = (string) get_permalink( $page->ID ); break; }
		}
		return array_replace( $this->empty_vars(), [
			'state' => 'none',
			'setup_url' => Router::setup_url(), 'last_setup' => null, 'docs_url' => $docs_url,
			'action_url' => wc_get_endpoint_url( self::ENDPOINT, '', wc_get_page_permalink( 'myaccount' ) ), 'nonce' => wp_create_nonce( self::NONCE ),
		] );
	}

	private function empty_vars(): array {
		return [
			'state' => 'unavailable', 'connection' => [], 'rotation_open' => false, 'notice' => null, 'secret' => '',
			'setup_url' => '', 'last_setup' => null, 'docs_url' => '', 'action_url' => '', 'nonce' => '',
		];
	}
}
