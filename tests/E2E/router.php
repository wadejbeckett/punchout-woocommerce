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

/*
 * Test harness only, as hooks WordPress builds before it loads ($wp_filter, WP_Hook::build_preinitialized_hooks()):
 * - POW_E2E_MAIL_LOG (set by the driver for the server): each WooCommerce e-mail the site sends, or tries to send
 *   while the fixture blocks mail, is recorded there as one JSON line {"id": <e-mail id>, "order": <order id>}.
 * - A request carrying X-Pow-E2E-Currency: <ISO code> is priced in that currency, as a currency switcher would do.
 * - A request carrying X-Pow-E2E-Pause: <1-10> waits that many seconds once checkout has created its order, so a
 *   second Submit can overtake it (two racing Submits, in a fixed order).
 */
$pow_e2e_mail_log = (string) getenv( 'POW_E2E_MAIL_LOG' );
if ( '' !== $pow_e2e_mail_log ) {
	$GLOBALS['wp_filter']['woocommerce_email_sent'][10][] = [
		'function'      => static function ( $sent, $id, $email = null ) use ( $pow_e2e_mail_log ): void {
			$object = is_object( $email ) && isset( $email->object ) ? $email->object : null;
			$entry  = [ 'id' => (string) $id, 'order' => $object instanceof WC_Order ? $object->get_id() : 0 ];
			file_put_contents( $pow_e2e_mail_log, json_encode( $entry ) . "\n", FILE_APPEND | LOCK_EX );
		},
		'accepted_args' => 3,
	];
}
$pow_e2e_currency = (string) ( $_SERVER['HTTP_X_POW_E2E_CURRENCY'] ?? '' );
if ( 1 === preg_match( '/\A[A-Z]{3}\z/', $pow_e2e_currency ) ) {
	$GLOBALS['wp_filter']['woocommerce_currency'][ PHP_INT_MAX ][] = [ 'function' => static fn(): string => $pow_e2e_currency, 'accepted_args' => 1 ];
}
$pow_e2e_pause = (string) ( $_SERVER['HTTP_X_POW_E2E_PAUSE'] ?? '' );
if ( 1 === preg_match( '/\A(?:[1-9]|10)\z/', $pow_e2e_pause ) ) {
	$GLOBALS['wp_filter']['woocommerce_checkout_order_processed'][0][] = [ 'function' => static function () use ( $pow_e2e_pause ): void { sleep( (int) $pow_e2e_pause ); }, 'accepted_args' => 0 ];
}

$pow_e2e_script = false !== $pow_e2e_file ? $pow_e2e_file : $pow_e2e_root . '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $pow_e2e_script;
$_SERVER['SCRIPT_NAME']     = substr( $pow_e2e_script, strlen( $pow_e2e_root ) );
$_SERVER['PHP_SELF']        = $_SERVER['SCRIPT_NAME'];
chdir( dirname( $pow_e2e_script ) );
require $pow_e2e_script;
return true;
