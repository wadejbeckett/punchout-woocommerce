<?php
/**
 * Raw-path router for the punchout endpoints.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Http;

use POW\Support\Transport;

defined( 'ABSPATH' ) || exit;

/**
 * parse_request-level matcher for /punchout/* (scope §3).
 *
 * No rewrite rules, no canonical processing, no theme bootstrap: the
 * handler matches the raw request path and exits. WP core cannot 301 a
 * POST (redirect_canonical returns early for non-GET/HEAD), and running
 * before template_redirect keeps every canonical plugin out of the path.
 * Anything else under /punchout/ falls through to WP and 404s.
 *
 * Every matched route sends no-store, noindex and nosniff headers before its
 * handler runs — punchout responses are price-bearing and must never be
 * cached, indexed or sniffed as another type (scope §7, L4).
 */
final class Router {

	/**
	 * One supplier URL serves test and production connections. The cXML
	 * deploymentMode selects the connection context, not a second route.
	 * Use the front-end home URL so subdirectory installations stay valid.
	 */
	public static function setup_url(): string {
		return Transport::supplier_url( home_url( '/punchout/setup' ) );
	}

	public function __construct(
		private SetupEndpoint $setup,
		private StartEndpoint $start,
		private ReturnEndpoint $return_endpoint,
		private ?\POW\Checkout\Handoff $handoff = null,
	) {}

	public function register(): void {
		add_action( 'parse_request', [ $this, 'route' ], 0 );
	}

	public function route(): void {
		$path = $this->request_path();

		if ( ! str_starts_with( $path, '/punchout/' ) ) {
			return;
		}

		if ( ! Transport::request_allowed() ) {
			if ( in_array( $path, [ '/punchout/setup', '/punchout/order' ], true ) ) {
				$this->setup->transport_denied();
				exit;
			}
			Transport::require_https();
		}

		if ( '/punchout/setup' === $path ) {
			$this->harden_headers();
			$this->setup->handle();
			exit;
		}

		if ( str_starts_with( $path, '/punchout/start/' ) ) {
			// Every method reaches the endpoint: it refuses a malformed token (plain 403, same as an expired one, no
			// detail an attacker can learn from, scope §9.2) before any lookup, answers GET and HEAD with the page
			// that posts itself back, and redeems only on POST (0.4.23).
			$this->harden_headers();
			$this->start->handle( substr( $path, strlen( '/punchout/start/' ) ) );
			exit;
		}

		// The old review route (retired in 0.5.0) falls through to WordPress and 404s like any other unknown path
		// under /punchout/. A cart leaves through WooCommerce's checkout and /punchout/handoff.

		if ( '/punchout/return' === $path ) {
			$this->harden_headers();
			$this->return_endpoint->handle();
			exit;
		}

		if ( '/punchout/handoff' === $path && null !== $this->handoff ) {
			// 0.5.0: the page checkout's Submit redirects to. Theme-free; served once to the browser and visit that
			// won the return, and it adds no-referrer, because the address carries the order key.
			$this->harden_headers();
			$this->handoff->handle();
			exit;
		}

		if ( '/punchout/sent' === $path && null !== $this->handoff ) {
			// 0.5.0: the Sent state, where a Submit after the visit's return is sent. Theme-free, no form.
			$this->harden_headers();
			$this->handoff->sent();
			exit;
		}

		if ( '/punchout/order' === $path ) {
			// Priced option O2, not core (scope §3): answer cXML 450
			// inside HTTP 200 so the buyer-side batch marks it cleanly.
			$this->harden_headers();
			$this->setup->not_implemented();
			exit;
		}

		// Everything else under /punchout/ falls through to WP (404).
	}

	/**
	 * The request path relative to the WP home path, trailing slash
	 * stripped, so both slash variants and subdirectory installs match.
	 */
	private function request_path(): string {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw path match only.
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		$home_path = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );

		if ( '' !== $home_path && str_starts_with( $path, $home_path ) ) {
			$path = substr( $path, strlen( $home_path ) );
		}

		return '/' . trim( $path, '/' );
	}

	/**
	 * The headers every matched route sends after nocache_headers(): no caching, no indexing and (0.4.22) no MIME
	 * sniffing, so a browser never reinterprets a punchout response as another type.
	 *
	 * @return list<string>
	 */
	public static function security_headers(): array {
		return [ 'Cache-Control: private, no-store', 'X-Robots-Tag: noindex, nofollow', 'X-Content-Type-Options: nosniff' ];
	}

	private function harden_headers(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		nocache_headers();
		foreach ( self::security_headers() as $header ) {
			header( $header );
		}
	}
}
