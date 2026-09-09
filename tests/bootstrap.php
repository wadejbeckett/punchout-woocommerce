<?php
/**
 * Test bootstrap: no WordPress, no WooCommerce.
 *
 * The units under test are the pure layers — the cXML codec, tokens,
 * replay policy, state machine, secrets, IP matching, rate-limit window,
 * ALL-CAPS transform, documentation model. ABSPATH is defined only to
 * satisfy the file guards, and Support/wp-stubs.php supplies the one
 * WordPress function those layers do call.
 *
 * The one exception is quote-order creation, which cannot be proved
 * without an order object: Support/wc-stubs.php records what was set on a
 * stub WC_Order. It proves our rules, not WooCommerce's.
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

// Under WordPress these stubs define nothing.
require_once __DIR__ . '/Support/wp-stubs.php';

// Native endpoint I/O and parser-entry recorders; dormant outside their test.
require_once __DIR__ . '/Support/setup-io-stubs.php';

// Under WooCommerce these stubs define nothing. Loaded after wp-stubs so
// the WordPress surface is claimed there and this file stays WooCommerce.
require_once __DIR__ . '/Support/wc-stubs.php';

require_once dirname( __DIR__ ) . '/includes/Autoloader.php';

\POW\Autoloader::register( 'POW', dirname( __DIR__ ) . '/includes' );

// The service doubles two quote suites share. They extend plugin classes,
// so they load after the autoloader — and from here rather than from a
// test file, because the standalone runner requires each test file only
// when it reaches it.
require_once __DIR__ . '/Support/quote-doubles.php';
