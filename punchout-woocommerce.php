<?php
/**
 * Plugin Name:       PunchOut for WooCommerce
 * Plugin URI:        https://github.com/wadejbeckett/punchout-woocommerce
 * Description:       Makes a WooCommerce store a cXML PunchOut supplier site for enterprise procurement buyers (Microsoft Dynamics 365 F&O/SCM first). Multi-tenant trading-partner registry, one-time StartPage login, and an additive "send for approval" cart exit that returns the basket as a PunchOutOrderMessage. The normal WooCommerce checkout is untouched.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            Noiz
 * License:           AGPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/agpl-3.0.html
 * Text Domain:       punchout-woocommerce
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:   9.9
 *
 * PunchOut for WooCommerce — cXML PunchOut supplier plugin.
 * Copyright (C) 2026 Noiz.
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or (at your
 * option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU Affero General Public License
 * for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * @package POW
 */

declare( strict_types = 1 );

namespace POW;

defined( 'ABSPATH' ) || exit;

const VERSION     = '0.1.0';
const PLUGIN_FILE = __FILE__;

define( 'POW_PLUGIN_FILE', __FILE__ );
define( 'POW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'POW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once __DIR__ . '/includes/Autoloader.php';

Autoloader::register( __NAMESPACE__, __DIR__ . '/includes' );

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage.
 *
 * All order access in this plugin goes through wc_get_order() and the CRUD
 * meta methods, never through get_posts/postmeta, so HPOS is safe.
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

register_activation_hook( __FILE__, [ Installer::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Installer::class, 'deactivate' ] );

/**
 * Boot once all plugins are loaded so WooCommerce, B2BKing (optional) and
 * the Action Scheduler library WooCommerce bundles are present before wiring.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		Plugin::instance()->boot();
	},
	20
);

/**
 * Convenience accessor for the container.
 */
function pow(): Plugin {
	return Plugin::instance();
}

/**
 * True when the current request belongs to an active punchout session.
 *
 * Template- and theme-level code (builder render logics, section swaps) may
 * call this; it is presentation-gating only — the RouteGuard is the access
 * control.
 */
function pow_is_punchout(): bool {
	return null !== Plugin::instance()->current_session();
}

/**
 * Render (or return) the "send to purchasing system" button for the current
 * punchout session. Safe to call anywhere in a theme; outputs nothing when
 * the visitor is not in an active punchout session.
 *
 * Also available as the [punchout_return_button] shortcode.
 *
 * @param bool $display True to echo, false to return the markup.
 */
function pow_return_button( bool $display = true ): string {
	$markup = Plugin::instance()->return_button_markup();

	if ( $display ) {
		echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template output, escaped within.
	}

	return $markup;
}
