<?php
/**
 * Raw-path router for the punchout endpoints.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Http;

use POW\Sessions\Tokens;

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
 * Every matched route sends no-store + noindex headers before its handler
 * runs — punchout responses are price-bearing and must never be cached or
 * indexed (scope §7, L4).
 */
final class Router {

	public function __construct(
		private SetupEndpoint $setup,
		private StartEndpoint $start,
		private ReturnEndpoint $return_endpoint,
	) {}

	public function register(): void {
		add_action( 'parse_request', [ $this, 'route' ], 0 );
	}

	public function route(): void {
		$path = $this->request_path();

		if ( ! str_starts_with( $path, '/punchout/' ) ) {
			return;
		}

		if ( '/punchout/setup' === $path ) {
			$this->harden_headers();
			$this->setup->handle();
			exit;
		}

		if ( str_starts_with( $path, '/punchout/start/' ) ) {
			$token = substr( $path, strlen( '/punchout/start/' ) );

			if ( Tokens::looks_valid( $token ) ) {
				$this->harden_headers();
				$this->start->handle( $token );
				exit;
			}

			// Malformed token: plain 403, same as an expired one — no
			// detail an attacker can learn from (scope §9.2). Junk that
			// does not even look like a token still gets the same page.
			$this->harden_headers();
			$this->start->deny();
			exit;
		}

		if ( '/punchout/return' === $path ) {
			$this->harden_headers();
			$this->return_endpoint->handle();
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

	private function harden_headers(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		nocache_headers();
		header( 'Cache-Control: private, no-store' );
		header( 'X-Robots-Tag: noindex, nofollow' );
	}
}
