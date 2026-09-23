<?php
/**
 * The My Account dashboard inside a punchout visit.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Account;

use POW\Plugin;
use POW\Support\Templates;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce's dashboard greets the account with "Hello Name (not Name?
 * Log out)". Inside a visit that link signs the connection's shared account
 * out of this browser, which ends the visit and deletes its basket, and the
 * buyer can only get back in with a new punchout from the purchasing system.
 * Logout is one of the pages that never open in a visit
 * (VisitEndpoints::NEVER_OPEN), so the account menu already leaves it out;
 * this takes the link out of the dashboard text as well.
 *
 * Inside a visit, and only there, WooCommerce's dashboard template is swapped
 * for the plugin's account/dashboard template: the same page, the same hooks,
 * without the logout sentence. It replaces whichever file was chosen before
 * it, a theme's copy included, so a theme restyles the visit's dashboard by
 * copying the plugin's template to punchout-woocommerce/account/dashboard.php.
 * Outside a visit, the account holder's own password login included, the
 * dashboard is untouched. A request whose visit cannot be proved gets the
 * plugin's copy, like every other door that fails closed.
 *
 * The swap uses WooCommerce's `wc_get_template` filter, which runs on every
 * render. WooCommerce caches the path its own template lookup finds, so a
 * per-request answer given at that earlier lookup could be served to the next
 * request, visit or not.
 */
final class VisitDashboard {

	/** The template WooCommerce renders for the account page with no endpoint. */
	public const WOOCOMMERCE_TEMPLATE = 'myaccount/dashboard.php';

	/** The plugin's copy, as Templates::locate() names it. */
	public const TEMPLATE = 'account/dashboard';

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		// Last, so a dashboard another plugin chose is replaced in a visit too.
		add_filter( 'wc_get_template', [ $this, 'template' ], PHP_INT_MAX, 2 );
	}

	/**
	 * The dashboard template to render: the plugin's copy inside a visit,
	 * otherwise what WooCommerce, the theme or another plugin chose. Any other
	 * template passes untouched, and so does the dashboard when the plugin's
	 * copy cannot be read.
	 *
	 * @param mixed $template      The path chosen so far.
	 * @param mixed $template_name The template WooCommerce was asked for.
	 */
	public function template( mixed $template, mixed $template_name = '' ): mixed {
		if ( self::WOOCOMMERCE_TEMPLATE !== $template_name || ! is_string( $template ) || ! $this->inside_visit() ) {
			return $template;
		}

		$own = Templates::locate( self::TEMPLATE );

		return is_readable( $own ) ? $own : $template;
	}

	/** Inside a visit, or unable to tell: the resolver throws rather than answer "no visit". */
	private function inside_visit(): bool {
		try {
			return null !== $this->plugin->current_session();
		} catch ( \Throwable $e ) {
			return true;
		}
	}
}
