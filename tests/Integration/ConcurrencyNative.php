<?php
/**
 * Native concurrency regression: many visits, one bound account.
 *
 * Run only against an opted-in disposable local WordPress/WooCommerce database:
 * POW_NATIVE_TESTS=disposable POW_NATIVE_CONCURRENCY_MODE=suite POW_NATIVE_WP_CLI=/absolute/path/to/wp-cli.phar POW_NATIVE_WP_PATH=/absolute/path/to/wordpress POW_NATIVE_WP_USER=<fixture-admin> POW_NATIVE_FIXTURE_ROOT=/absolute/private/temp/path wp --user=<fixture-admin> eval-file tests/Integration/ConcurrencyNative.php
 *
 * The suite spawns independent WP-CLI processes, because everything it proves
 * is about two requests that overlap: a connection has ONE bound customer
 * account, so two employees shopping at the same moment are the same WordPress
 * user with two visits, two StartPage tokens, two `wc_session_key`s and two
 * baskets. Nothing but a real second process can show that the second visit
 * does not overwrite the first — a single-process fixture shares the object
 * cache, the static lock map and the request's own session handler.
 *
 * Its claim barrier makes a duplicate request land in the window between the
 * winner's claim insert and its commit. Its transient-read barrier makes the
 * unfixed limiter expose every concurrent get-before-set reader. The assertions
 * are on real rows, real login tokens, real basket rows and real MariaDB
 * options, not doubles.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'disposable' !== getenv( 'POW_NATIVE_TESTS' ) || 'local' !== wp_get_environment_type() || ! current_user_can( 'manage_woocommerce' ) || ! function_exists( 'WC' ) ) {
	throw new RuntimeException( 'Requires opted-in disposable local WordPress/WooCommerce CLI and a capable fixture administrator.' );
}

require_once dirname( __DIR__ ) . '/Support/setup-io-stubs.php';
require_once dirname( __DIR__ ) . '/Support/native-visits.php';

use POW\Buyers\Identity;
use POW\Cart\NativeSessionGuard;
use POW\Cart\SessionKey;
use POW\Cxml\Builder;
use POW\Cxml\Parser;
use POW\Cxml\SetupMessage;
use POW\Http\RateLimiter;
use POW\Http\SetupEndpoint;
use POW\Http\StartEndpoint;
use POW\Partners\Partner;
use POW\Sessions\Session;
use POW\Sessions\Store;

/**
 * Records that a duplicate request saw the winner's in-flight claim, from inside
 * the read that saw it.
 *
 * Two records, for two readers. The option is what the coordinator checks
 * afterwards; the marker file is what releases the stalled winner, and it is a
 * file rather than a second option deliberately — the winner is stalled inside
 * the `query` filter of its own GET_LOCK, and a nested database read there would
 * run against the wpdb state of the statement it is standing in the middle of.
 */
final class PowNativeObservedStore extends Store {
	public function find_by_payload( int $partner_id, string $payload_id ): ?Session {
		$session = parent::find_by_payload( $partner_id, $payload_id );
		$prefix = (string) ( $GLOBALS['pow_native_waiter_prefix'] ?? '' );
		if ( '' !== $prefix && $session && Session::PENDING === $session->status && 0 === $session->user_id && null === $session->response_xml ) {
			add_option( $prefix . (string) getenv( 'POW_NATIVE_WORKER_ID' ), '1', '', false );
			$directory = (string) getenv( 'POW_NATIVE_RUN_DIRECTORY' );
			if ( '' !== $directory && is_dir( $directory ) ) { touch( $directory . '/claim-observed' ); }
		}
		return $session;
	}
}

function pow_native_write_private( string $path, string $contents ): void {
	if ( false === file_put_contents( $path, $contents, LOCK_EX ) || ! chmod( $path, 0600 ) ) {
		throw new RuntimeException( 'Private native fixture write failed.' );
	}
}

function pow_native_json( string $path ): array {
	$value = json_decode( (string) file_get_contents( $path ), true, 32, JSON_THROW_ON_ERROR );
	if ( ! is_array( $value ) ) {
		throw new RuntimeException( 'Invalid native fixture JSON.' );
	}
	return $value;
}

function pow_native_count_files( string $directory, string $prefix ): int {
	$files = glob( $directory . '/' . $prefix . '*' );
	return is_array( $files ) ? count( $files ) : 0;
}

function pow_native_wait( callable $ready, float $seconds, string $message ): void {
	$deadline = microtime( true ) + $seconds;
	do {
		if ( $ready() ) {
			return;
		}
		usleep( 10000 );
	} while ( microtime( true ) < $deadline );
	throw new RuntimeException( $message );
}

/** The same lock name the registry computes, so a barrier can watch one connection's mutex. */
function pow_native_partner_lock_key( int $partner_id ): string {
	return 'pow_partner_' . substr( hash( 'sha256', DB_NAME . '|' . \POW\Installer::partners_table() . '|' . $partner_id ), 0, 52 );
}

function pow_native_setup_message( Partner $partner, string $payload_id, string $identity, string $shared_secret = 'fixture-secret-not-verified-here' ): SetupMessage {
	return new SetupMessage(
		kind: SetupMessage::KIND_SETUP,
		payload_id: $payload_id,
		timestamp: gmdate( 'c' ),
		version: $partner->cxml_version,
		lang: 'en-ZA',
		deployment_mode: 'test',
		from_domain: $partner->from_domain,
		from_identity: $partner->from_identity,
		to_domain: $partner->to_domain,
		to_identity: $partner->to_identity,
		sender_domain: $partner->sender_domain,
		sender_identity: $partner->sender_identity,
		shared_secret: $shared_secret,
		user_agent: 'POW native fixture',
		buyer_cookie: 'native-cookie-' . $payload_id,
		browser_form_post: 'https://buyer.example.test/return',
		extrinsics: [ 'UserEmail' => $identity ]
	);
}

/** An unidentified buyer is legal: the identity extrinsic is simply omitted. */
function pow_native_setup_body( Partner $partner, string $secret, string $payload_id, string $identity ): string {
	$xml = static fn( string $value ): string => htmlspecialchars( $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	$extrinsic = '' !== $identity ? '<Extrinsic name="UserEmail">' . $xml( $identity ) . '</Extrinsic>' : '';
	return '<cXML version="' . $xml( $partner->cxml_version ) . '" payloadID="' . $xml( $payload_id ) . '"><Header><From><Credential domain="' . $xml( $partner->from_domain ) . '"><Identity>' . $xml( $partner->from_identity ) . '</Identity></Credential></From><To><Credential domain="' . $xml( $partner->to_domain ) . '"><Identity>' . $xml( $partner->to_identity ) . '</Identity></Credential></To><Sender><Credential domain="' . $xml( $partner->sender_domain ) . '"><Identity>' . $xml( $partner->sender_identity ) . '</Identity><SharedSecret>' . $xml( $secret ) . '</SharedSecret></Credential><UserAgent>POW native fixture</UserAgent></Sender></Header><Request deploymentMode="test"><PunchOutSetupRequest operation="create"><BuyerCookie>native-cookie</BuyerCookie>' . $extrinsic . '<BrowserFormPost><URL>https://buyer.example.test/return</URL></BrowserFormPost></PunchOutSetupRequest></Request></cXML>';
}

function pow_native_endpoint(): SetupEndpoint {
	$plugin = \POW\Plugin::instance();
	$registry = $plugin->registry();
	$sessions = isset( $GLOBALS['pow_native_waiter_prefix'] ) ? new PowNativeObservedStore() : $plugin->sessions();
	$audit = $plugin->audit();
	if ( ! $registry || ! $sessions || ! $audit ) {
		throw new RuntimeException( 'Plugin services unavailable.' );
	}
	return new SetupEndpoint( $registry, $sessions, new Parser(), new Builder(), new RateLimiter( 0 ), $audit, new RateLimiter( 0 ) );
}

/** @return array{status:int,hash:string,start:string,token:string} */
function pow_native_invoke_setup( string $body, string $ip = '203.0.113.40' ): array {
	$server = $_SERVER;
	$_SERVER['REMOTE_ADDR'] = $ip;
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_SERVER['CONTENT_TYPE'] = 'text/xml';
	$GLOBALS['pow_test_setup_io'] = [ 'body' => $body, 'reads' => 0, 'parses' => 0, 'headers' => [] ];
	ob_start();
	try {
		pow_native_endpoint()->handle();
		$response = (string) ob_get_contents();
	} finally {
		ob_end_clean();
		$_SERVER = $server;
		unset( $GLOBALS['pow_test_setup_io'] );
	}
	$document = new DOMDocument();
	if ( ! $document->loadXML( $response ) ) {
		throw new RuntimeException( 'Setup response was not XML.' );
	}
	$status = $document->getElementsByTagName( 'Status' )->item( 0 );
	$start = (string) ( new DOMXPath( $document ) )->evaluate( 'string(//StartPage/URL)' );
	$path = '' !== $start ? (string) wp_parse_url( $start, PHP_URL_PATH ) : '';
	return [
		'status' => $status ? (int) $status->getAttribute( 'code' ) : 0,
		'hash'   => hash( 'sha256', $response ),
		'start'  => $start,
		'token'  => '' !== $path ? basename( $path ) : '',
	];
}

/**
 * Redeem one StartPage token in this process and hand back the activated visit.
 *
 * The redeem is what mints the visit's WP session token and its own
 * `wc_session_key`, so every assertion about two baskets has to go through it.
 * The endpoint signs the request in as the bound account; the caller's own
 * actor and cookies are restored, because a coordinator that quietly became
 * the buyer would make every later capability check meaningless.
 */
function pow_native_redeem( string $token ): ?Session {
	$plugin = \POW\Plugin::instance();
	$sessions = $plugin->sessions();
	$actor = get_current_user_id();
	$cookies = $_COOKIE;
	$server = $_SERVER;
	$_SERVER['REQUEST_METHOD'] = 'GET';
	ob_start();
	try {
		( new StartEndpoint( $sessions, $plugin->registry(), $plugin->settings(), $plugin->audit() ) )->handle( $token );
	} finally {
		ob_end_clean();
		pow_native_leave_visit( $actor );
		$_SERVER = $server;
		$_COOKIE = $cookies;
	}
	$row = $sessions->find_by_token_hash( \POW\Sessions\Tokens::hash( $token ) );
	return $row;
}

/** One authenticated setup plus its redeem: the whole way a visit comes into being. */
function pow_native_open_visit_over_the_wire( Partner $partner, string $secret, string $payload_id, string $identity, string $ip = '203.0.113.40' ): array {
	$response = pow_native_invoke_setup( pow_native_setup_body( $partner, $secret, $payload_id, $identity ), $ip );
	if ( 200 !== $response['status'] || '' === $response['token'] ) {
		return [ 'status' => $response['status'], 'session' => 0, 'token' => '', 'key' => '' ];
	}
	$visit = pow_native_redeem( $response['token'] );
	return [
		'status'  => $response['status'],
		'session' => $visit?->id ?? 0,
		'token'   => $visit?->wp_session_token ?? '',
		'key'     => (string) ( $visit?->wc_session_key ?? '' ),
	];
}

function pow_native_spawn( string $mode, int $count, string $run_directory, array $extra = [] ): array {
	$wp_cli = (string) getenv( 'POW_NATIVE_WP_CLI' );
	$wp_path = (string) getenv( 'POW_NATIVE_WP_PATH' );
	$wp_user = (string) getenv( 'POW_NATIVE_WP_USER' );
	if ( '' === $wp_cli || '' === $wp_path || '' === $wp_user || ! is_file( $wp_cli ) || ! is_dir( $wp_path ) ) {
		throw new RuntimeException( 'Native subprocess paths are required.' );
	}
	$processes = [];
	for ( $worker = 1; $worker <= $count; ++$worker ) {
		$result = $run_directory . '/' . $mode . '-result-' . $worker . '.json';
		$environment = array_merge( getenv(), $extra, [ 'POW_NATIVE_CONCURRENCY_MODE' => $mode, 'POW_NATIVE_WORKER_ID' => (string) $worker, 'POW_NATIVE_RESULT' => $result ] );
		$command = [ PHP_BINARY, $wp_cli, '--path=' . $wp_path, '--user=' . $wp_user, 'eval-file', __FILE__ ];
		$spec = [ 0 => [ 'file', '/dev/null', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ];
		$process = proc_open( $command, $spec, $pipes, null, $environment );
		if ( ! is_resource( $process ) ) {
			throw new RuntimeException( 'Could not start native worker.' );
		}
		$processes[] = [ $process, $pipes, $result ];
	}
	return $processes;
}

function pow_native_finish( array $processes ): array {
	$results = [];
	foreach ( $processes as [ $process, $pipes, $result ] ) {
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit = proc_close( $process );
		if ( 0 !== $exit || ! is_file( $result ) ) {
			throw new RuntimeException( 'Native worker failed: ' . trim( $stdout . ' ' . $stderr ) );
		}
		$results[] = pow_native_json( $result );
	}
	return $results;
}

/**
 * Two requests with the same payload, one of them landing in the claim window.
 *
 * Worker 1 stalls when it acquires the connection lock for the SECOND time —
 * that is the commit, and by then its claim row is inserted and the first lock
 * released, which is exactly the state a retrying purchasing system finds.
 * Worker 2 waits for that marker, so the duplicate is guaranteed to read an
 * uncommitted claim rather than to race for it.
 */
function pow_native_setup_worker(): void {
	$fixture = pow_native_json( (string) getenv( 'POW_NATIVE_SETUP_FIXTURE' ) );
	$worker = (int) getenv( 'POW_NATIVE_WORKER_ID' );
	$directory = (string) getenv( 'POW_NATIVE_RUN_DIRECTORY' );
	$GLOBALS['pow_native_waiter_prefix'] = $fixture['waiter_prefix'];
	if ( 1 === $worker ) {
		$acquisitions = 0;
		$key = $fixture['lock_key'];
		add_filter(
			'query',
			static function ( string $sql ) use ( &$acquisitions, $key, $directory ): string {
				if ( ! str_contains( $sql, 'GET_LOCK(' ) || ! str_contains( $sql, $key ) ) { return $sql; }
				++$acquisitions;
				if ( 2 !== $acquisitions ) { return $sql; }
				// Claim inserted, connection lock released, commit not yet begun. The
				// duplicate is let in here and releases this one from inside the read
				// that saw the claim, so the window is real and neither waits for the
				// other to finish.
				touch( $directory . '/claim-window' );
				pow_native_wait( static fn(): bool => is_file( $directory . '/claim-observed' ), 10.0, 'Duplicate never observed the claim.' );
				return $sql;
			},
			PHP_INT_MAX
		);
	} else {
		pow_native_wait( static fn(): bool => is_file( $directory . '/claim-window' ), 20.0, 'Claim window barrier timed out.' );
	}
	$response = pow_native_invoke_setup( $fixture['body'] );
	pow_native_write_private( (string) getenv( 'POW_NATIVE_RESULT' ), wp_json_encode( $response ) );
}

/** One employee punching in: a setup and its redeem, overlapping every other worker's. */
function pow_native_visit_worker(): void {
	$fixture = pow_native_json( (string) getenv( 'POW_NATIVE_SETUP_FIXTURE' ) );
	$directory = (string) getenv( 'POW_NATIVE_RUN_DIRECTORY' );
	$worker = (int) getenv( 'POW_NATIVE_WORKER_ID' );
	$registry = \POW\Plugin::instance()->registry();
	$partner = $registry->find( (int) $fixture['partner_id'] ) ?? throw new RuntimeException( 'Native connection unavailable.' );
	touch( $directory . '/visit-ready-' . $worker );
	pow_native_wait( static fn(): bool => is_file( $directory . '/visit-start' ), 20.0, 'Visit start barrier timed out.' );
	$result = pow_native_open_visit_over_the_wire( $partner, $fixture['secret'], $fixture['payloads'][ $worker - 1 ], $fixture['identities'][ $worker - 1 ] );
	// Fill the visit's own basket: a superseded visit must lose its row, and an
	// empty row would make that assertion pass for the wrong reason.
	$visit = $result['session'] > 0 ? pow_native_visit_store()->find( (int) $result['session'] ) : null;
	if ( $visit ) {
		$handler = pow_native_visit_handler( $visit );
		WC()->session = $handler;
		$handler->set( 'cart', [ 'visit-' . $worker => [ 'product_id' => 0, 'quantity' => 1 ] ] );
		$result['basket'] = $handler->save_checked();
	}
	pow_native_write_private( (string) getenv( 'POW_NATIVE_RESULT' ), wp_json_encode( $result ) );
}

/** Two redeems for one connection at the same moment: one shared `session_tokens` row, two surviving logins. */
function pow_native_redeem_worker(): void {
	$fixture = pow_native_json( (string) getenv( 'POW_NATIVE_SETUP_FIXTURE' ) );
	$directory = (string) getenv( 'POW_NATIVE_RUN_DIRECTORY' );
	$worker = (int) getenv( 'POW_NATIVE_WORKER_ID' );
	touch( $directory . '/redeem-ready-' . $worker );
	pow_native_wait( static fn(): bool => is_file( $directory . '/redeem-start' ), 20.0, 'Redeem start barrier timed out.' );
	$visit = pow_native_redeem( $fixture['tokens'][ $worker - 1 ] );
	pow_native_write_private(
		(string) getenv( 'POW_NATIVE_RESULT' ),
		wp_json_encode( [ 'session' => $visit?->id ?? 0, 'token' => $visit?->wp_session_token ?? '', 'key' => (string) ( $visit?->wc_session_key ?? '' ) ] )
	);
}

/**
 * Two baskets under one login, written in an interleaved order.
 *
 * Each worker only ever names its own visit key. What has to hold is that a
 * commit on one key never reaches the other row, in either direction, and that
 * emptying one basket leaves the other's lines alone.
 */
function pow_native_cart_worker(): void {
	$fixture = pow_native_json( (string) getenv( 'POW_NATIVE_SETUP_FIXTURE' ) );
	$directory = (string) getenv( 'POW_NATIVE_RUN_DIRECTORY' );
	$worker = (int) getenv( 'POW_NATIVE_WORKER_ID' );
	$sessions = pow_native_visit_store();
	$visit = $sessions->find( (int) $fixture['sessions'][ $worker - 1 ] ) ?? throw new RuntimeException( 'Native visit row unavailable.' );
	$key = SessionKey::for_session( $visit );
	$other = (string) $fixture['keys'][ 2 === $worker ? 0 : 1 ];
	$line = 'line-' . $worker;
	$handler = pow_native_visit_handler( $visit );
	WC()->session = $handler;
	$handler->set( 'cart', [ $line => [ 'product_id' => 0, 'quantity' => $worker ] ] );
	$stored = $handler->save_checked();
	touch( $directory . '/cart-set-' . $worker );
	pow_native_wait( static fn(): bool => 2 === pow_native_count_files( $directory, 'cart-set-' ), 20.0, 'Cart write barrier timed out.' );
	$own = pow_native_cart_value( $key, 'cart' );
	$emptied = true;
	if ( 1 === $worker ) {
		$handler->set( 'cart', [] );
		$emptied = $handler->save_checked();
	}
	touch( $directory . '/cart-done-' . $worker );
	pow_native_wait( static fn(): bool => 2 === pow_native_count_files( $directory, 'cart-done-' ), 20.0, 'Cart teardown barrier timed out.' );
	pow_native_write_private(
		(string) getenv( 'POW_NATIVE_RESULT' ),
		wp_json_encode(
			[
				'stored'      => $stored,
				'emptied'     => $emptied,
				'own_lines'   => is_array( $own ) ? array_keys( $own ) : [],
				'final_own'   => array_keys( (array) pow_native_cart_value( $key, 'cart' ) ),
				'final_other' => array_keys( (array) pow_native_cart_value( $other, 'cart' ) ),
			]
		)
	);
}

/**
 * A basket staged, a colleague's redeem, then the commit.
 *
 * The commit fingerprint may only cover metadata that AUTHORISES this login.
 * A colleague redeeming a StartPage token rewrites the account's shared
 * `session_tokens` row between this worker's stage and its commit, and WooCommerce
 * itself rewrites `wc_last_active` on the `wp` action, so a fingerprint over
 * every usermeta row silently lost carts. The commit must survive both.
 */
function pow_native_stage_commit_worker(): void {
	$fixture = pow_native_json( (string) getenv( 'POW_NATIVE_SETUP_FIXTURE' ) );
	$directory = (string) getenv( 'POW_NATIVE_RUN_DIRECTORY' );
	$sessions = pow_native_visit_store();
	$visit = $sessions->find( (int) $fixture['session'] ) ?? throw new RuntimeException( 'Native visit row unavailable.' );
	$handler = pow_native_visit_handler( $visit );
	WC()->session = $handler;
	$handler->set( 'cart', [ 'staged-line' => [ 'product_id' => 0, 'quantity' => 1 ] ] );
	$guard = NativeSessionGuard::registered();
	$prepared = $guard->prepare( $visit );
	update_user_meta( $visit->user_id, 'wc_last_active', (string) time() );
	touch( $directory . '/staged' );
	pow_native_wait( static fn(): bool => is_file( $directory . '/colleague-redeemed' ), 20.0, 'Colleague redeem barrier timed out.' );
	$committed = $guard->save( $handler, $prepared );
	pow_native_write_private(
		(string) getenv( 'POW_NATIVE_RESULT' ),
		wp_json_encode( [ 'committed' => $committed, 'lines' => array_keys( (array) pow_native_cart_value( SessionKey::for_session( $visit ), 'cart' ) ) ] )
	);
}

function pow_native_rate_worker(): void {
	$directory = (string) getenv( 'POW_NATIVE_RUN_DIRECTORY' );
	$worker = (string) getenv( 'POW_NATIVE_WORKER_ID' );
	$bucket = (string) getenv( 'POW_NATIVE_RATE_BUCKET' );
	$key = 'pow_rl_60_' . md5( $bucket );
	touch( $directory . '/rate-ready-' . $worker );
	pow_native_wait( static fn(): bool => is_file( $directory . '/rate-start' ), 20.0, 'Rate start barrier timed out.' );
	add_filter( 'pre_transient_' . $key, static function ( $pre ) use ( $directory, $worker ) {
		touch( $directory . '/rate-read-' . $worker );
		pow_native_wait( static fn(): bool => is_file( $directory . '/rate-release-read' ), 3.0, 'Transient read barrier timed out.' );
		return $pre;
	} );
	$allowed = ( new RateLimiter( 10 ) )->allow( $bucket );
	pow_native_write_private( (string) getenv( 'POW_NATIVE_RESULT' ), wp_json_encode( [ 'allowed' => $allowed ] ) );
}

function pow_native_partner_lock_worker(): void {
	$plugin = \POW\Plugin::instance();
	$registry = $plugin->registry();
	$directory = (string) getenv( 'POW_NATIVE_RUN_DIRECTORY' );
	$partner_id = (int) getenv( 'POW_NATIVE_LOCK_PARTNER' );
	if ( ! $registry || $partner_id <= 0 ) { throw new RuntimeException( 'Partner lock worker fixture unavailable.' ); }
	$registry->with_partner_lock( $partner_id, static function () use ( $directory ): void {
		touch( $directory . '/partner-lock-ready' );
		pow_native_wait( static fn(): bool => is_file( $directory . '/partner-lock-release' ), 12.0, 'Partner lock release barrier timed out.' );
	} );
	pow_native_write_private( (string) getenv( 'POW_NATIVE_RESULT' ), wp_json_encode( [ 'released' => true ] ) );
}

$mode = (string) getenv( 'POW_NATIVE_CONCURRENCY_MODE' );
$workers = [
	'setup-worker'        => 'pow_native_setup_worker',
	'visit-worker'        => 'pow_native_visit_worker',
	'redeem-worker'       => 'pow_native_redeem_worker',
	'cart-worker'         => 'pow_native_cart_worker',
	'stage-commit-worker' => 'pow_native_stage_commit_worker',
	'rate-worker'         => 'pow_native_rate_worker',
	'partner-lock-worker' => 'pow_native_partner_lock_worker',
];
if ( isset( $workers[ $mode ] ) ) {
	$workers[ $mode ]();
	return;
}
if ( 'suite' !== $mode ) {
	throw new RuntimeException( 'POW_NATIVE_CONCURRENCY_MODE=suite is required.' );
}

$passed = 0;
$failed = 0;
$check = static function ( bool $condition, string $message ) use ( &$passed, &$failed ): void {
	if ( $condition ) {
		++$passed;
		echo 'PASS ' . $message . "\n";
		return;
	}
	++$failed;
	echo 'FAIL ' . $message . "\n";
};

$fixture_root = rtrim( (string) getenv( 'POW_NATIVE_FIXTURE_ROOT' ), '/' );
if ( '' === $fixture_root || ! is_dir( $fixture_root ) || ! is_writable( $fixture_root ) ) {
	throw new RuntimeException( 'A private writable POW_NATIVE_FIXTURE_ROOT is required.' );
}
$run = strtolower( wp_generate_password( 12, false, false ) );
$run_directory = $fixture_root . '/pow-concurrency-' . $run;
if ( ! mkdir( $run_directory, 0700 ) || ! chmod( $run_directory, 0700 ) ) {
	throw new RuntimeException( 'Could not create native run directory.' );
}

$plugin = \POW\Plugin::instance();
$registry = $plugin->registry();
$sessions = $plugin->sessions();
$audit = $plugin->audit();
if ( ! $registry || ! $sessions || ! $audit ) {
	throw new RuntimeException( 'Plugin services unavailable.' );
}
global $wpdb;
$admin = get_current_user_id();

/** One ordinary WooCommerce customer account, and the connection whose buyers all punch in as it. */
$account = pow_native_bound_account( 'native-race' );
$secret = wp_generate_password( 40, false, false );
$connection = static function ( string $label, int $owner, string $secret ) use ( $registry, $run ): Partner {
	$suffix = $label . '-' . $run;
	$id = $registry->insert(
		[
			'name' => 'Native Race ' . $suffix,
			'status' => Partner::STATUS_ACTIVE,
			'owner_user_id' => $owner,
			'from_domain' => 'NetworkID',
			'from_identity' => 'buyer-' . $suffix,
			'sender_domain' => 'NetworkID',
			'sender_identity' => 'sender-' . $suffix,
			'to_domain' => 'NetworkID',
			'to_identity' => 'supplier-' . $suffix,
			'cxml_version' => '1.2.008',
			'deployment_mode' => 'test',
			'return_encoding' => 'base64',
			'session_ttl' => 14400,
			'token_ttl' => 300,
		],
		$secret
	);
	$partner = $id > 0 ? $registry->find( $id ) : null;
	if ( ! $partner ) { throw new RuntimeException( 'Could not create the native connection.' ); }
	return $partner;
};
$partner = $connection( 'bound', $account, $secret );
$partner_id = $partner->id;

/* ---------------------------------------------------------------------------
 * Many visits on one account
 * ------------------------------------------------------------------------ */

$identities = [];
$payloads = [];
for ( $worker = 1; $worker <= 4; ++$worker ) {
	$identities[] = 'employee-' . $worker . '-' . $run . '@example.test';
	$payloads[] = 'visit-' . $worker . '-' . $run;
}
$visit_fixture = $run_directory . '/visits.json';
pow_native_write_private( $visit_fixture, wp_json_encode( [ 'partner_id' => $partner_id, 'secret' => $secret, 'identities' => $identities, 'payloads' => $payloads ] ) );
$visit_processes = pow_native_spawn( 'visit-worker', 4, $run_directory, [ 'POW_NATIVE_SETUP_FIXTURE' => $visit_fixture, 'POW_NATIVE_RUN_DIRECTORY' => $run_directory ] );
pow_native_wait( static fn(): bool => 4 === pow_native_count_files( $run_directory, 'visit-ready-' ), 20.0, 'Visit workers not ready.' );
touch( $run_directory . '/visit-start' );
$visit_results = pow_native_finish( $visit_processes );
$visit_rows = [];
foreach ( $visit_results as $result ) {
	$row = $result['session'] > 0 ? pow_native_visit_row( (int) $result['session'] ) : null;
	if ( $row ) { $visit_rows[ (int) $result['session'] ] = $row; }
}
$statuses = array_column( $visit_results, 'status' );
$tokens = array_filter( array_column( $visit_results, 'token' ) );
$keys = array_filter( array_column( $visit_results, 'key' ) );
$check( [ 200, 200, 200, 200 ] === $statuses && 4 === count( $visit_rows ), 'four simultaneous setups for one connection open four visits' );
$check( 4 === count( array_unique( $tokens ) ) && 4 === count( array_unique( $keys ) ), 'each concurrent visit receives its own login token and its own cart key' );
$check(
	4 === count( array_filter( $keys, static fn( string $key ): bool => SessionKey::is_visit_key( $key ) ) )
	&& [ $account ] === array_values( array_unique( array_map( 'intval', array_column( $visit_rows, 'user_id' ) ) ) ),
	'every visit carries a per-visit cart key and the one bound account'
);
$check(
	[ Session::ACTIVE ] === array_values( array_unique( array_column( $visit_rows, 'status' ) ) )
	&& 4 === count( array_filter( $visit_results, static fn( array $r ): bool => WP_Session_Tokens::get_instance( $account )->verify( $r['token'] ) ) ),
	'a colleague punching in supersedes nobody: four logins stay live'
);
$identity_hashes = [];
foreach ( $identities as $index => $identity ) {
	$identity_hashes[ $identity ] = ( Identity::from_message( pow_native_setup_message( $partner, $payloads[ $index ], $identity ) ) )->hash( $partner_id );
}
$stored_hashes = array_column( $visit_rows, 'buyer_identity_hash' );
$check( 4 === count( array_intersect( $stored_hashes, array_values( $identity_hashes ) ) ), 'each visit records the buyer identity hash its request named' );

/* ---------------------------------------------------------------------------
 * A returning buyer supersedes its own earlier visit and nobody else's
 * ------------------------------------------------------------------------ */

$superseded = $visit_results[0];
$survivors = array_slice( $visit_results, 1 );
$repeat = pow_native_open_visit_over_the_wire( $partner, $secret, 'repeat-' . $run, $identities[0] );
$old = pow_native_visit_row( (int) $superseded['session'] );
$check( 200 === $repeat['status'] && $repeat['session'] > 0 && $repeat['key'] !== $superseded['key'], 'the same buyer punching in again opens a fresh visit with a fresh cart key' );
$check( Session::EXPIRED === ( $old['status'] ?? '' ) && ! WP_Session_Tokens::get_instance( $account )->verify( $superseded['token'] ), 'the returning buyer supersedes its own earlier visit and destroys that login only' );
$check( true === ( $superseded['basket'] ?? false ) && null === pow_native_cart_row( $superseded['key'] ), 'superseding a visit removes the basket row it had persisted' );
$live = array_filter( $survivors, static fn( array $r ): bool => Session::ACTIVE === ( pow_native_visit_row( (int) $r['session'] )['status'] ?? '' ) && WP_Session_Tokens::get_instance( $account )->verify( $r['token'] ) );
$check( 3 === count( $live ), 'one punch-in leaves every other employee visit untouched' );

/* ---------------------------------------------------------------------------
 * Two baskets, interleaved, under one login
 * ------------------------------------------------------------------------ */

$cart_visits = array_values( array_slice( $survivors, 0, 2 ) );
$cart_fixture = $run_directory . '/carts.json';
pow_native_write_private( $cart_fixture, wp_json_encode( [ 'sessions' => array_column( $cart_visits, 'session' ), 'keys' => array_column( $cart_visits, 'key' ) ] ) );
$cart_processes = pow_native_spawn( 'cart-worker', 2, $run_directory, [ 'POW_NATIVE_SETUP_FIXTURE' => $cart_fixture, 'POW_NATIVE_RUN_DIRECTORY' => $run_directory ] );
$cart_results = pow_native_finish( $cart_processes );
$check( true === $cart_results[0]['stored'] && true === $cart_results[1]['stored'], 'both concurrent visits persist their own basket' );
$check( [ 'line-1' ] === $cart_results[0]['own_lines'] && [ 'line-2' ] === $cart_results[1]['own_lines'], 'neither basket row ever holds the other visit lines' );
$check( true === $cart_results[0]['emptied'] && [] === $cart_results[0]['final_own'] && [ 'line-2' ] === $cart_results[0]['final_other'], 'emptying one visit basket leaves the colleague basket whole' );
$check( [ 'line-2' ] === $cart_results[1]['final_own'] && [] === $cart_results[1]['final_other'], 'each visit reads the same two rows the same way' );

/* ---------------------------------------------------------------------------
 * A colleague's redeem between one visit's stage and its commit
 * ------------------------------------------------------------------------ */

$stage_visit = (int) $cart_visits[1]['session'];
$colleague = pow_native_invoke_setup( pow_native_setup_body( $partner, $secret, 'colleague-' . $run, 'colleague-' . $run . '@example.test' ) );
$stage_fixture = $run_directory . '/stage.json';
pow_native_write_private( $stage_fixture, wp_json_encode( [ 'session' => $stage_visit ] ) );
$stage_processes = pow_native_spawn( 'stage-commit-worker', 1, $run_directory, [ 'POW_NATIVE_SETUP_FIXTURE' => $stage_fixture, 'POW_NATIVE_RUN_DIRECTORY' => $run_directory ] );
pow_native_wait( static fn(): bool => is_file( $run_directory . '/staged' ), 20.0, 'Stage barrier timed out.' );
$colleague_visit = 200 === $colleague['status'] ? pow_native_redeem( $colleague['token'] ) : null;
touch( $run_directory . '/colleague-redeemed' );
$stage_results = pow_native_finish( $stage_processes );
$check( null !== $colleague_visit && SessionKey::is_visit_key( (string) $colleague_visit->wc_session_key ), 'a colleague redeems a StartPage token while another visit holds a staged basket' );
$check( true === $stage_results[0]['committed'] && [ 'staged-line' ] === $stage_results[0]['lines'], 'a colleague redeem and WooCommerce own activity meta do not refuse a staged commit' );

/* ---------------------------------------------------------------------------
 * Two simultaneous redeems share one session_tokens row
 * ------------------------------------------------------------------------ */

$pair = [];
foreach ( [ 'pair-a-' . $run, 'pair-b-' . $run ] as $index => $payload ) {
	$response = pow_native_invoke_setup( pow_native_setup_body( $partner, $secret, $payload, 'pair-' . $index . '-' . $run . '@example.test' ) );
	if ( 200 !== $response['status'] ) { throw new RuntimeException( 'Simultaneous redeem fixture setup failed.' ); }
	$pair[] = $response['token'];
}
$redeem_fixture = $run_directory . '/redeems.json';
pow_native_write_private( $redeem_fixture, wp_json_encode( [ 'tokens' => $pair ] ) );
$redeem_processes = pow_native_spawn( 'redeem-worker', 2, $run_directory, [ 'POW_NATIVE_SETUP_FIXTURE' => $redeem_fixture, 'POW_NATIVE_RUN_DIRECTORY' => $run_directory ] );
pow_native_wait( static fn(): bool => 2 === pow_native_count_files( $run_directory, 'redeem-ready-' ), 20.0, 'Redeem workers not ready.' );
touch( $run_directory . '/redeem-start' );
$redeem_results = pow_native_finish( $redeem_processes );
$redeem_tokens = array_column( $redeem_results, 'token' );
$redeem_keys = array_column( $redeem_results, 'key' );
$check( 2 === count( array_unique( $redeem_tokens ) ) && 2 === count( array_unique( $redeem_keys ) ), 'two simultaneous redeems mint two login tokens and two cart keys' );
// The workers wrote the account's session_tokens row in their own processes; this process cached that meta earlier, so read it afresh.
clean_user_cache( $account );
$check(
	2 === count( array_filter( $redeem_tokens, static fn( string $token ): bool => '' !== $token && WP_Session_Tokens::get_instance( $account )->verify( $token ) ) ),
	'a read-modify-write of one shared session_tokens row under the connection lock loses no login'
);

/* ---------------------------------------------------------------------------
 * Same payload twice: one visit, one response, no second claim
 * ------------------------------------------------------------------------ */

$payload = 'replay-' . $run;
$setup_fixture = $run_directory . '/setup.json';
$waiter_prefix = 'pow_native_waiter_' . $run . '_';
pow_native_write_private(
	$setup_fixture,
	wp_json_encode(
		[
			'body' => pow_native_setup_body( $partner, $secret, $payload, 'replay-' . $run . '@example.test' ),
			'waiter_prefix' => $waiter_prefix,
			'lock_key' => pow_native_partner_lock_key( $partner_id ),
		]
	)
);
$setup_processes = pow_native_spawn( 'setup-worker', 2, $run_directory, [ 'POW_NATIVE_SETUP_FIXTURE' => $setup_fixture, 'POW_NATIVE_RUN_DIRECTORY' => $run_directory ] );
$setup_results = pow_native_finish( $setup_processes );
$setup_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . \POW\Installer::sessions_table() . ' WHERE partner_id = %d AND payload_id = %s', $partner_id, $payload ), ARRAY_A );
$waiter_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $waiter_prefix ) . '%' ) );
$check( 200 === $setup_results[0]['status'] && 200 === $setup_results[1]['status'], 'two concurrent authenticated setup requests both receive success' );
$check( $setup_results[0]['hash'] === $setup_results[1]['hash'], 'the duplicate receives the winner response byte-for-byte' );
$check( 1 === count( $setup_rows ) && (int) $setup_rows[0]['user_id'] === $account && '' !== (string) $setup_rows[0]['response_xml'], 'one complete visit row wins, on the bound account' );
$check( $waiter_count >= 1, 'a duplicate observes the persisted in-flight claim in the window between the claim and its commit' );

/* ---------------------------------------------------------------------------
 * The open-visit cap
 * ------------------------------------------------------------------------ */

$filler = [];
while ( $sessions->count_open_for_partner( $partner_id ) < Store::MAX_OPEN_VISITS ) {
	$id = $sessions->create(
		[
			'partner_id' => $partner_id,
			'user_id' => $account,
			'status' => Session::ACTIVE,
			'payload_id' => 'cap-' . count( $filler ) . '-' . $run,
			'body_hash' => hash( 'sha256', 'cap-' . count( $filler ) . '-' . $run ),
			'one_time_token_hash' => hash( 'sha256', random_bytes( 32 ) ),
			'expires' => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
		]
	);
	if ( $id <= 0 ) { throw new RuntimeException( 'Cap fixture row failed.' ); }
	$filler[] = $id;
}
// One expired-but-open visit on top of a full connection: the sweep must reach
// it before the count, and reaching it must not make room.
$expired_filler = $sessions->create(
	[
		'partner_id' => $partner_id,
		'user_id' => $account,
		'status' => Session::ACTIVE,
		'payload_id' => 'cap-expired-' . $run,
		'body_hash' => hash( 'sha256', 'cap-expired-' . $run ),
		'one_time_token_hash' => hash( 'sha256', random_bytes( 32 ) ),
		'expires' => gmdate( 'Y-m-d H:i:s', time() - 120 ),
	]
);
if ( $expired_filler <= 0 ) { throw new RuntimeException( 'Cap sweep fixture row failed.' ); }
$capped_payload = 'over-cap-' . $run;
$capped = pow_native_invoke_setup( pow_native_setup_body( $partner, $secret, $capped_payload, 'over-cap-' . $run . '@example.test' ), '203.0.113.44' );
$cap_audits = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . \POW\Installer::log_table() . " WHERE partner_id = %d AND payload_id = %s AND event = 'setup_visit_cap' AND result = 'cap'", $partner_id, $capped_payload ) );
$capped_rows = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . \POW\Installer::sessions_table() . ' WHERE partner_id = %d AND payload_id = %s', $partner_id, $capped_payload ) );
$check( Store::MAX_OPEN_VISITS === $sessions->count_open_for_partner( $partner_id ), 'the connection sits exactly on its open-visit cap after the sweep' );
$check( 500 === $capped['status'] && 1 === $cap_audits && 0 === $capped_rows, 'an over-cap setup is refused with cXML 500, a setup_visit_cap audit row and no claim' );
$check( Session::EXPIRED === ( pow_native_visit_row( $expired_filler )['status'] ?? '' ), 'the cap sweeps this connection expired-but-open visits before it counts' );
if ( ! $sessions->expire_and_destroy( $sessions->find( (int) array_pop( $filler ) ), $registry ) ) {
	throw new RuntimeException( 'Cap fixture row could not be released.' );
}
$under_cap = pow_native_invoke_setup( pow_native_setup_body( $partner, $secret, 'under-cap-' . $run, 'under-cap-' . $run . '@example.test' ), '203.0.113.44' );
$check( 200 === $under_cap['status'], 'one closed visit makes room for the next punch-in' );

/* ---------------------------------------------------------------------------
 * A connection with no usable login
 * ------------------------------------------------------------------------ */

foreach ( [ 'unbound' => 0, 'privileged' => $admin ] as $label => $owner ) {
	$secret_for = wp_generate_password( 40, false, false );
	$refused = $connection( $label, $owner, $secret_for );
	$refused_payload = $label . '-setup-' . $run;
	$response = pow_native_invoke_setup( pow_native_setup_body( $refused, $secret_for, $refused_payload, $label . '-' . $run . '@example.test' ), '203.0.113.45' );
	$no_login = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . \POW\Installer::log_table() . " WHERE partner_id = %d AND payload_id = %s AND event = 'setup_no_login' AND result = 'no_login'", $refused->id, $refused_payload ) );
	$rows = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . \POW\Installer::sessions_table() . ' WHERE partner_id = %d', $refused->id ) );
	$identities_logged = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GROUP_CONCAT(detail) FROM ' . \POW\Installer::log_table() . ' WHERE partner_id = %d', $refused->id ) );
	$check( 500 === $response['status'] && 1 === $no_login && 0 === $rows, 'a connection with no usable login is refused with cXML 500, setup_no_login and no claim row (' . $label . ')' );
	$check( ! str_contains( $identities_logged, $label . '-' . $run . '@example.test' ), 'the refusal records no buyer e-mail (' . $label . ')' );
}

/* ---------------------------------------------------------------------------
 * The claim row a second request must never be able to take
 * ------------------------------------------------------------------------ */

$claim_method = new ReflectionMethod( SetupEndpoint::class, 'claim_setup' );
$auth_claim_payload = 'auth-claim-' . $run;
$auth_claim_hash = hash( 'sha256', 'auth-claim-body-' . $run );
$auth_claim_token = hash( 'sha256', 'auth-claim-token-' . $run );
$auth_claim_id = $sessions->create( [ 'partner_id' => $partner_id, 'user_id' => 0, 'status' => Session::PENDING, 'payload_id' => $auth_claim_payload, 'body_hash' => $auth_claim_hash, 'one_time_token_hash' => $auth_claim_token, 'expires' => gmdate( 'Y-m-d H:i:s', time() + 300 ), 'response_xml' => null ] );
$claim_identity = Identity::from_message( pow_native_setup_message( $partner, $auth_claim_payload, 'claim-auth-' . $run . '@example.test' ) );
$auth_failed = false;
try {
	$claim_method->invoke( pow_native_endpoint(), $partner, pow_native_setup_message( $partner, $auth_claim_payload, 'claim-auth-' . $run . '@example.test', 'wrong-secret' ), $claim_identity, '203.0.113.42', $auth_claim_payload, $auth_claim_hash, hash( 'sha256', random_bytes( 16 ) ), gmdate( 'Y-m-d H:i:s', time() + 300 ) );
} catch ( Throwable $error ) {
	$auth_failed = true;
}
$auth_claim_after = $sessions->find( $auth_claim_id );
$auth_claim_token_after = $wpdb->get_var( $wpdb->prepare( 'SELECT one_time_token_hash FROM ' . \POW\Installer::sessions_table() . ' WHERE id = %d', $auth_claim_id ) );
$check( $auth_failed && $auth_claim_after && 0 === $auth_claim_after->user_id && null === $auth_claim_after->response_xml && $auth_claim_token === $auth_claim_token_after, 'a second request authentication failure before insert cannot delete the existing in-flight claim' );
$wrong_token_claim = Session::from_row( [ 'id' => $auth_claim_id, 'partner_id' => $partner_id, 'user_id' => 0, 'status' => Session::PENDING, 'payload_id' => $auth_claim_payload, 'body_hash' => $auth_claim_hash, 'one_time_token_hash' => hash( 'sha256', 'wrong-token-' . $run ) ] );
$check( ! $sessions->complete_setup_claim( $wrong_token_claim, $account, '<fixture/>' ) && ! $sessions->abandon_setup_claim( $wrong_token_claim ) && null !== $sessions->find( $auth_claim_id ), 'a mismatched claim token cannot commit or delete the pending row' );

$lock_claim_payload = 'lock-claim-' . $run;
$lock_claim_hash = hash( 'sha256', 'lock-claim-body-' . $run );
$lock_claim_token = hash( 'sha256', 'lock-claim-token-' . $run );
$lock_claim_id = $sessions->create( [ 'partner_id' => $partner_id, 'user_id' => 0, 'status' => Session::PENDING, 'payload_id' => $lock_claim_payload, 'body_hash' => $lock_claim_hash, 'one_time_token_hash' => $lock_claim_token, 'expires' => gmdate( 'Y-m-d H:i:s', time() + 300 ), 'response_xml' => null ] );
$lock_processes = pow_native_spawn( 'partner-lock-worker', 1, $run_directory, [ 'POW_NATIVE_RUN_DIRECTORY' => $run_directory, 'POW_NATIVE_LOCK_PARTNER' => (string) $partner_id ] );
pow_native_wait( static fn(): bool => is_file( $run_directory . '/partner-lock-ready' ), 10.0, 'Partner lock worker did not acquire the lock.' );
$lock_failed = false;
try {
	$claim_method->invoke( pow_native_endpoint(), $partner, pow_native_setup_message( $partner, $lock_claim_payload, 'claim-lock-' . $run . '@example.test', $secret ), Identity::from_message( pow_native_setup_message( $partner, $lock_claim_payload, 'claim-lock-' . $run . '@example.test' ) ), '203.0.113.43', $lock_claim_payload, $lock_claim_hash, hash( 'sha256', random_bytes( 16 ) ), gmdate( 'Y-m-d H:i:s', time() + 300 ) );
} catch ( Throwable $error ) {
	$lock_failed = true;
} finally {
	touch( $run_directory . '/partner-lock-release' );
}
$lock_results = pow_native_finish( $lock_processes );
$lock_claim_after = $sessions->find( $lock_claim_id );
$lock_claim_token_after = $wpdb->get_var( $wpdb->prepare( 'SELECT one_time_token_hash FROM ' . \POW\Installer::sessions_table() . ' WHERE id = %d', $lock_claim_id ) );
$check( $lock_failed && true === ( $lock_results[0]['released'] ?? false ) && $lock_claim_after && 0 === $lock_claim_after->user_id && null === $lock_claim_after->response_xml && $lock_claim_token === $lock_claim_token_after, 'a second request lock timeout before insert cannot delete the existing in-flight claim' );

/* ---------------------------------------------------------------------------
 * Atomic rate budgets — independent of buyers, unchanged
 * ------------------------------------------------------------------------ */

$rate_bucket = 'native-rate|' . $run;
$rate_processes = pow_native_spawn( 'rate-worker', 20, $run_directory, [ 'POW_NATIVE_RUN_DIRECTORY' => $run_directory, 'POW_NATIVE_RATE_BUCKET' => $rate_bucket ] );
pow_native_wait( static fn(): bool => 20 === pow_native_count_files( $run_directory, 'rate-ready-' ), 15.0, 'Rate workers did not reach the start barrier.' );
touch( $run_directory . '/rate-start' );
$read_deadline = microtime( true ) + 1.0;
while ( pow_native_count_files( $run_directory, 'rate-read-' ) < 20 && microtime( true ) < $read_deadline ) {
	usleep( 10000 );
}
touch( $run_directory . '/rate-release-read' );
$rate_results = pow_native_finish( $rate_processes );
$permits = count( array_filter( $rate_results, static fn( array $result ): bool => true === $result['allowed'] ) );
$check( 10 === $permits, 'twenty simultaneous attempts receive exactly ten healthy permits and no over-admission' );
$check( ( new RateLimiter( 10 ) )->allow( 'native-independent|' . $run ), 'another bucket remains independent' );

$window_bucket = 'native-window|' . $run;
$window_limiter = new RateLimiter( 2, null, null, 3 );
$first_window = $window_limiter->allow( $window_bucket );
$window_key = 'pow_rl_3_' . md5( $window_bucket );
$timeout_option = '_transient_timeout_' . $window_key;
$first_timeout = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $timeout_option ) );
sleep( 2 );
$second_window = $window_limiter->allow( $window_bucket );
$second_timeout = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $timeout_option ) );
sleep( max( 1, $first_timeout - time() + 1 ) );
$next_window = $window_limiter->allow( $window_bucket );
$check( $first_window && $second_window && $first_timeout > 0 && $first_timeout === $second_timeout && $next_window, 'accepted hits preserve the original fixed-window expiry and the next window resets' );

$value_option = '_transient_' . $window_key;
$had_value = null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $value_option ) );
$had_timeout = null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $timeout_option ) );
$wpdb->update( $wpdb->options, [ 'option_value' => (string) ( time() - 1 ) ], [ 'option_name' => $timeout_option ] );
$previous_external_cache = wp_using_ext_object_cache();
wp_using_ext_object_cache( true );
delete_expired_transients();
$core_skipped = 2 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name IN (%s, %s)", $value_option, $timeout_option ) );
( new RateLimiter( 1 ) )->allow( 'native-cleanup-trigger|' . $run );
wp_using_ext_object_cache( $previous_external_cache );
$cleaned = 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name IN (%s, %s)", $value_option, $timeout_option ) );
$check( $had_value && $had_timeout && $core_skipped && $cleaned, 'bounded native cleanup removes expired database counters when persistent object cache makes core skip them' );

$options_table = $wpdb->options;
$previous_errors = $wpdb->suppress_errors( true );
$wpdb->options = $wpdb->prefix . 'pow_missing_options_' . $run;
$storage_failure = ( new RateLimiter( 1 ) )->allow( 'native-storage-failure|' . $run );
$wpdb->options = $options_table;
$wpdb->suppress_errors( $previous_errors );
$check( ! $storage_failure, 'database storage failure refuses the protected request' );

pow_native_leave_visit( $admin );
echo 'Passed: ' . $passed . ' Failed: ' . $failed . " Skipped: 0\n";
echo 'Fixture: ' . $run_directory . "\n";
if ( $failed > 0 ) {
	WP_CLI::halt( 1 );
}
