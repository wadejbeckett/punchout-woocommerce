<?php
/**
 * The connection's setup-XML download in WooCommerce My Account.
 *
 * One read-only surface for the account a connection is bound to: the
 * generated Dynamics setup XML, with a placeholder where the shared secret
 * goes. Nothing here creates, changes, rotates or deactivates anything —
 * that is all administrator work now — and the screen does not exist inside
 * a punchout visit, because then the request is an employee shopping as
 * this account rather than the account holder reading its own integration
 * details.
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
use POW\Partners\Registry;
use POW\Plugin;
use POW\Support\Templates;

defined( 'ABSPATH' ) || exit;

final class IntegrationTab {
	public const ENDPOINT = 'punchout-integration';
	public const NONCE = 'pow_account';

	public function __construct(
		private Plugin $plugin,
		private Registry $registry,
		private Log $audit,
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
			// The view never holds a secret: no sealed slot and no reveal store is read.
			$vars = $this->view_vars();
			echo Templates::render( 'account/integration', $vars ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped template.
		} catch ( \Throwable $e ) {
			echo '<p class="woocommerce-error">' . esc_html__( 'Connection information is unavailable. Please try again later.', 'punchout-woocommerce' ) . '</p>';
		}
	}

	/** The one POST this screen answers, as a direct response; no reveal store exists. */
	public function handle_post(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! is_account_page() || ! is_wc_endpoint_url( self::ENDPOINT ) ) { return; }
		Transport::require_https();
		if ( 0 === $this->actor() ) { return; }
		if ( 'download_setup_template' !== ( $_POST['pow_account_action'] ?? null ) ) { return; }
		// Never answer after output has made no-store headers impossible.
		if ( headers_sent() ) { return; }
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
			$vars['connection'] = [ 'name' => $p->name, 'from' => $p->from_domain . ' / ' . $p->from_identity, 'sender' => $p->sender_domain . ' / ' . $p->sender_identity, 'to' => $p->to_domain . ' / ' . $p->to_identity, 'deployment_mode' => $p->deployment_mode, 'cxml_version' => $p->cxml_version, 'return_encoding' => $p->return_encoding ];
			$vars['template_ready'] = $p->is_active() && $this->template_ready( $p );
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
		];
	}
}
