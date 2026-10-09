<?php
/**
 * PHP built-in server router for the disposable fixture.
 *
 *   POW_E2E_ORIGIN=http://127.0.0.1:<port> php -S 127.0.0.1:<port> -t <WordPress root> tests/E2E/router.php
 *
 * The driver starts this itself. The site answers on the loopback origin it is
 * served on (WP_HOME and WP_SITEURL for these requests only; the database's
 * own home URL is left alone), static files are served as they are, a request
 * for a PHP file inside the root runs that file, and everything else runs
 * WordPress's index.php, as a rewrite-less web server would.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

$pow_e2e_origin = (string) getenv( 'POW_E2E_ORIGIN' );
if ( 1 !== preg_match( '#\Ahttp://127\.0\.0\.1:\d{2,5}\z#', $pow_e2e_origin ) ) {
	http_response_code( 500 );
	echo 'POW_E2E_ORIGIN must be http://127.0.0.1:<port>.';
	return true;
}

$pow_e2e_root = rtrim( (string) realpath( (string) $_SERVER['DOCUMENT_ROOT'] ), '/' );
$pow_e2e_path = rawurldecode( (string) parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH ) );
$pow_e2e_file = realpath( $pow_e2e_root . $pow_e2e_path );
if ( false !== $pow_e2e_file && ! str_starts_with( $pow_e2e_file, $pow_e2e_root . '/' ) && $pow_e2e_file !== $pow_e2e_root ) { $pow_e2e_file = false; }
if ( false !== $pow_e2e_file && is_dir( $pow_e2e_file ) ) { $pow_e2e_file = is_file( $pow_e2e_file . '/index.php' ) ? $pow_e2e_file . '/index.php' : false; }

if ( false !== $pow_e2e_file && ! str_ends_with( $pow_e2e_file, '.php' ) ) {
	return false; // A static file: the built-in server sends it.
}

define( 'WP_HOME', $pow_e2e_origin );
define( 'WP_SITEURL', $pow_e2e_origin );

$pow_e2e_script = false !== $pow_e2e_file ? $pow_e2e_file : $pow_e2e_root . '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $pow_e2e_script;
$_SERVER['SCRIPT_NAME']     = substr( $pow_e2e_script, strlen( $pow_e2e_root ) );
$_SERVER['PHP_SELF']        = $_SERVER['SCRIPT_NAME'];
chdir( dirname( $pow_e2e_script ) );
require $pow_e2e_script;
return true;
