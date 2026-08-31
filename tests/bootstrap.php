<?php
/**
 * Test bootstrap: no WordPress, no WooCommerce.
 *
 * The units under test are the pure layers — the cXML codec, tokens,
 * replay policy, state machine, secrets, IP matching, rate-limit window,
 * ALL-CAPS transform. They call no WP functions; ABSPATH is defined only
 * to satisfy the file guards.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

// Under real PHPUnit this shim defines nothing.
require_once __DIR__ . '/Support/testcase-shim.php';

require_once dirname( __DIR__ ) . '/includes/Autoloader.php';

\POW\Autoloader::register( 'POW', dirname( __DIR__ ) . '/includes' );
