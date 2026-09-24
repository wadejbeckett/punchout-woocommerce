<?php
/**
 * The connection's setup-XML download in WooCommerce My Account.
 *
 * A read-only surface for the account a connection is bound to: the
 * generated Dynamics setup XML, with a placeholder where the shared secret
 * goes. Nothing here creates, changes, rotates or deactivates anything —
 * that is administrator work — with one opt-in exception: when an
 * administrator ticks "Reset connection" for this connection, the account
 * holder can reset it here (Registration::reset_by_owner), and the new
 * secret is shown once, inside the account page, on the no-store response
 * to the reset and never stored. The screen, and that reset, do not exist
 * inside a punchout visit, because then the request is an employee shopping
 * as this account rather than the account holder reading its own
 * integration details.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Account;

use POW\Support\Transport;

use POW\Audit\Log;
use POW\Docs\Page as DocsPage;
use POW\Docs\Samples;
use POW\Http\Router;
use POW\Partners\Registration;
use POW\Partners\Registry;
use POW\Plugin;
use POW\Support\Templates;

defined( 'ABSPATH' ) || exit;

final class IntegrationTab {
	public const ENDPOINT = 'punchout-integration';
	public const NONCE = 'pow_account';
	public const RESET_NONCE = 'pow_account_reset';

	/** This request's own reset answer, which render() prints once; the new secret lives only here, for this one response. */
	private ?array $reset = null;

	public function __construct(
		private Plugin $plugin,
		private Registry $registry,
		private Log $audit,
		private ?Registration $registration = null,
	) {}

	/** Built on first use, so the plugin's wiring stays as it is. */
	private function registration(): Registration {
		return $this->registration ??= new Registration( $this->registry, new \POW\Sessions\Store(), $this->audit );
	}

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

	/**
	 * The account holder this screen is for, or 0.
	 *
	 * A live punchout visit answers 0. Inside a visit the signed-in account
	 * is the connection's own login carrying an employee of the buying
	 * company, not the person who manages the connection, so this screen —
	 * and the menu item that leads to it — must not exist for that request.
	 * RouteGuard already redirects the account area during a visit; this is
	 * the inner refusal, and it holds for the POST handler too.
	 *
	 * No role and no plugin user meta is consulted: the account is an
	 * ordinary customer, so anything asked about the user would answer
	 * "ordinary shopper" and refuse the very account this screen serves.
	 * Whether it manages a connection is answered by the registry below.
	 *
	 * An unanswerable visit lookup refuses as well: Sessions\Current's
	 * contract is that a throw is not "no visit".
	 */
	private function actor(): int {
		try {
			if ( ! $this->plugin->enabled() || ! is_user_logged_in() ) { return 0; }
			if ( null !== $this->plugin->current_session() ) { return 0; }
			$id = get_current_user_id();
			$user = $id > 0 ? get_userdata( $id ) : false;
			return $user && user_can( $user, 'read' ) ? $id : 0;
		} catch ( \Throwable $e ) { return 0; }
	}

	public function render(): void {
		Transport::require_https();
		if ( 0 === $this->actor() ) { return; }
		try {
			// The view never holds a secret: no sealed slot and no reveal store is read. The one exception is this request's own reset answer, printed once and then dropped.
			$reset = $this->reset;
			$this->reset = null;
			echo null !== $reset ? $this->reset_emission( $reset )['body'] : Templates::render( 'account/integration', $this->view_vars() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped template.
		} catch ( \Throwable $e ) {
			echo '<p class="woocommerce-error">' . esc_html__( 'Connection information is unavailable. Please try again later.', 'punchout-woocommerce' ) . '</p>';
		}
	}

	/**
	 * The POSTs this screen answers; no reveal store exists.
	 *
	 * The download answers directly: an XML attachment, or the notice. The reset sets its no-store headers and status here and is drawn inside the account page, theme and navigation included, by render() on this same request.
	 */
	public function handle_post(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! is_account_page() || ! is_wc_endpoint_url( self::ENDPOINT ) ) { return; }
		Transport::require_https();
		if ( 0 === $this->actor() ) { return; }
		$action = $_POST['pow_account_action'] ?? null;
		if ( 'download_setup_template' !== $action && 'reset_connection' !== $action ) { return; }
		// Never answer after output has made no-store headers impossible.
		if ( headers_sent() ) { return; }
		if ( 'reset_connection' === $action ) {
			// Not offered for this connection: no answer at all, exactly as before the option existed.
			$reset = $this->owner_reset_result();
			if ( null === $reset ) { return; }
			$this->private_headers();
			status_header( $reset['status'] );
			$this->reset = $reset;
			return;
		}
		$this->private_headers();
		$download = $this->template_download_result();
		if ( null === $download ) { return; }
		$emission = $this->download_emission( $download );
		status_header( $emission['status'] );
		foreach ( $emission['headers'] as $header ) { header( $header, true ); }
		echo $emission['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated XML attachment or escaped template.
		exit;
	}

	/** @return array{status:int,notice:array{text:string,type:string},filename:string,xml:string}|null */
	private function template_download_result(): ?array {
		if ( 'download_setup_template' !== ( $_POST['pow_account_action'] ?? null ) ) { return null; }
		$actor = $this->actor();
		$nonce = $_POST['_wpnonce'] ?? null;
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! is_account_page() || ! is_wc_endpoint_url( self::ENDPOINT ) || 0 === $actor || ! is_string( $nonce ) || ! wp_verify_nonce( wp_unslash( $nonce ), self::NONCE ) ) {
			return $this->download_result( 403, __( 'This setup template could not be authorised. Reload the integration page and try again.', 'punchout-woocommerce' ) );
		}
		try {
			$partner = $this->registry->find_by_owner( $actor );
			if ( null === $partner || ! $partner->is_owned_by( $actor ) || ! $partner->is_active() ) {
				return $this->download_result( 403, __( 'This connection cannot be managed from this account.', 'punchout-woocommerce' ) );
			}
			return $this->download_result( 200, '', Samples::setup_template( $partner, Router::setup_url() ), 'punchout-setup-' . $partner->id . '.xml' );
		} catch ( \DomainException $e ) {
			// A configuration gap on the connection, not a passing fault: reloading cannot change it.
			return $this->download_result( 409, self::template_not_ready_text() );
		} catch ( \Throwable $e ) {
			return $this->download_result( 503, __( 'The setup template is unavailable. Reload the integration page and try again.', 'punchout-woocommerce' ) );
		}
	}

	/**
	 * The account holder's opt-in reset, or null when this connection does
	 * not offer it to this request.
	 *
	 * Null (no answer, no write) unless the actor is the bound account of
	 * an active connection whose administrator granted `reset_connection`,
	 * outside a punchout visit. Registration::reset_by_owner checks all of
	 * that again under the partner lock. The secret goes only into the
	 * returned array for the one response; it is never logged or stored.
	 *
	 * @return array{status:int,notice:array{text:string,type:string},secret:string}|null
	 */
	private function owner_reset_result(): ?array {
		if ( 'reset_connection' !== ( $_POST['pow_account_action'] ?? null ) ) { return null; }
		$actor = $this->actor();
		if ( 0 === $actor ) { return null; }
		try {
			$partner = $this->registry->find_by_owner( $actor );
		} catch ( \Throwable $e ) { return null; }
		if ( null === $partner || ! $partner->is_owned_by( $actor ) || ! $partner->is_active() || ! $partner->owner_may( 'reset_connection' ) ) { return null; }
		$nonce = $_POST['_wpnonce'] ?? null;
		// Bound to the credential the form was drawn for: a replay after a reset (a reload of the answer page) no longer verifies.
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( wp_unslash( $nonce ), self::reset_action( $partner ) ) ) {
			return $this->reset_result( 403, __( 'This reset could not be authorised. Reload the integration page and try again.', 'punchout-woocommerce' ) );
		}
		if ( '1' !== ( $_POST['pow_confirm_reset'] ?? null ) ) {
			return $this->reset_result( 400, __( 'Tick the box to confirm the reset, then try again.', 'punchout-woocommerce' ) );
		}
		try {
			$secret = $this->registration()->reset_by_owner( $partner->id, $actor );
		} catch ( \Throwable $e ) { $secret = ''; }
		if ( '' !== $secret ) {
			return $this->reset_result( 200, __( 'Connection reset. The previous shared secret and every open punchout visit have been revoked. Copy the new shared secret now: it is shown only once. Paste it into your purchasing system in place of the old one.', 'punchout-woocommerce' ), $secret );
		}
		return $this->reset_result( 409, __( 'The reset did not complete and no new secret was issued. The connection may now be disabled; contact the store.', 'punchout-woocommerce' ) );
	}

	/**
	 * The reset form's nonce action, bound to the connection's current credential.
	 *
	 * A reset replaces the sealed secret, so the nonce minted before it no longer verifies and a resubmitted reset POST is refused instead of revoking the secret it just issued. The action is only ever hashed into the nonce; neither it nor the sealed value is sent.
	 */
	private static function reset_action( \POW\Partners\Partner $p ): string {
		return self::RESET_NONCE . '|' . $p->id . '|' . hash( 'sha256', $p->secret_current );
	}

	/** @return array{status:int,notice:array{text:string,type:string},secret:string} */
	private function reset_result( int $status, string $text, string $secret = '' ): array {
		return $this->result( $status, $text ) + [ 'secret' => $secret ];
	}

	/** The integration page with the reset's notice, and the new secret once on success. */
	private function reset_emission( array $reset ): array {
		return [
			'status' => $reset['status'],
			'body'   => Templates::render( 'account/integration', array_replace( $this->result_vars( $reset ), [ 'issued_secret' => (string) ( $reset['secret'] ?? '' ) ] ) ),
		];
	}

	/** What the download actually sends: the XML as an attachment on success, the page with the notice otherwise. */
	private function download_emission( array $download ): array {
		if ( 200 === $download['status'] ) {
			return [
				'status'  => 200,
				'headers' => [ 'Content-Type: application/xml; charset=UTF-8', 'Content-Disposition: attachment; filename="' . $download['filename'] . '"', 'X-Content-Type-Options: nosniff' ],
				'body'    => $download['xml'],
			];
		}
		return [ 'status' => $download['status'], 'headers' => [], 'body' => Templates::render( 'account/integration', $this->result_vars( $download ) ) ];
	}

	public static function template_not_ready_text(): string {
		return __( 'No setup template yet: the store still has to complete the From, To and Sender identities, the cXML version and the deployment mode on this connection.', 'punchout-woocommerce' );
	}

	/** @return array{status:int,notice:array{text:string,type:string},filename:string,xml:string} */
	private function download_result( int $status, string $text, string $xml = '', string $filename = '' ): array {
		return array_merge( $this->result( $status, $text ), [ 'filename' => $filename, 'xml' => $xml ] );
	}

	/** Report the download's own notice even when a subsequent view or permalink query fails. */
	private function result_vars( array $result ): array {
		try {
			$vars = $this->view_vars();
		} catch ( \Throwable $e ) {
			// This fallback deliberately performs no further lookups or helper calls.
			$vars = $this->empty_vars();
		}
		$vars['notice'] = $result['notice'];
		return $vars;
	}

	private function private_headers(): void {
		nocache_headers();
		header( 'Cache-Control: no-store, private, max-age=0', true );
		header( 'Referrer-Policy: no-referrer', true );
	}

	private function result( int $status, string $text ): array {
		return [ 'status' => $status, 'notice' => [ 'text' => $text, 'type' => $status >= 400 ? 'error' : 'success' ] ];
	}

	/** Safe view data only: a theme override never receives sealed registry slots or a secret. */
	private function view_vars(): array {
		$actor = $this->actor();
		if ( 0 === $actor ) { throw new \RuntimeException( 'Account access refused.' ); }
		$p = $this->registry->find_by_owner( $actor );
		if ( null !== $p && ! $p->is_owned_by( $actor ) ) { throw new \RuntimeException( 'Account access refused.' ); }
		$vars = $this->base_vars();
		$vars['state'] = null === $p ? 'none' : ( $p->is_pending() ? 'pending' : ( $p->is_active() ? 'active' : 'disabled' ) );
		if ( null !== $p ) {
			$vars['connection'] = [ 'name' => $p->name, 'from' => $p->from_domain . ' / ' . $p->from_identity, 'sender' => $p->sender_domain . ' / ' . $p->sender_identity, 'to' => $p->to_domain . ' / ' . $p->to_identity, 'deployment_mode' => $p->deployment_mode, 'cxml_version' => $p->cxml_version, 'return_encoding' => $p->return_encoding, 'visit_endpoints' => implode( ', ', $p->visit_endpoint_list() ) ];
			$vars['template_ready'] = $p->is_active() && $this->template_ready( $p );
			$vars['can_reset'] = $p->is_active() && $p->owner_may( 'reset_connection' );
			$vars['reset_nonce'] = $vars['can_reset'] ? wp_create_nonce( self::reset_action( $p ) ) : '';
			try { $vars['last_setup'] = $this->audit->last_success( $p->id ); } catch ( \Throwable $e ) { $vars['last_setup'] = __( 'Unavailable', 'punchout-woocommerce' ); }
		}
		return $vars;
	}

	/** Only a DomainException means "not configured"; any other failure leaves the button so the download can report it. */
	private function template_ready( \POW\Partners\Partner $p ): bool {
		try { Samples::setup_template( $p, Router::setup_url() ); return true; }
		catch ( \DomainException $e ) { return false; }
		catch ( \Throwable $e ) { return true; }
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
			'state' => 'unavailable', 'connection' => [], 'template_ready' => false, 'notice' => null,
			'setup_url' => '', 'last_setup' => null, 'docs_url' => '', 'action_url' => '', 'nonce' => '',
			'can_reset' => false, 'reset_nonce' => '', 'issued_secret' => '',
		];
	}
}
