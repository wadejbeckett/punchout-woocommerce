<?php
/**
 * Native setup-replay and rate-limit concurrency regression.
 *
 * Run only against an opted-in disposable local WordPress/WooCommerce database:
 * POW_NATIVE_TESTS=disposable POW_NATIVE_CONCURRENCY_MODE=suite POW_NATIVE_WP_CLI=/absolute/path/to/wp-cli.phar POW_NATIVE_WP_PATH=/absolute/path/to/wordpress POW_NATIVE_WP_USER=<fixture-admin> POW_NATIVE_FIXTURE_ROOT=/absolute/private/temp/path wp --user=<fixture-admin> eval-file tests/Integration/ConcurrencyNative.php
 *
 * The suite spawns independent WP-CLI processes. Its setup hook barrier makes the unfixed endpoint run both provisioning callbacks before either final duplicate decision. Its transient-read barrier makes the unfixed limiter expose every concurrent get-before-set reader. The assertions are on real users, plugin rows, hook effects and MariaDB options, not doubles.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'disposable' !== getenv( 'POW_NATIVE_TESTS' ) || 'local' !== wp_get_environment_type() || ! current_user_can( 'manage_woocommerce' ) || ! function_exists( 'WC' ) ) {
	throw new RuntimeException( 'Requires opted-in disposable local WordPress/WooCommerce CLI and a capable fixture administrator.' );
}

require_once dirname( __DIR__ ) . '/Support/setup-io-stubs.php';

use POW\Buyers\Provisioner;
use POW\Cxml\Builder;
use POW\Cxml\Parser;
use POW\Cxml\SetupMessage;
use POW\Http\RateLimiter;
use POW\Http\SetupEndpoint;
use POW\Partners\Partner;
use POW\Sessions\Store;

final class PowNativeObservedStore extends Store {
	public function find_by_payload( int $partner_id, string $payload_id ): ?POW\Sessions\Session {
		$session = parent::find_by_payload( $partner_id, $payload_id );
		$prefix = (string) ( $GLOBALS['pow_native_waiter_prefix'] ?? '' );
		if ( '' !== $prefix && $session && POW\Sessions\Session::PENDING === $session->status && 0 === $session->user_id && null === $session->response_xml ) {
			add_option( $prefix . (string) getenv( 'POW_NATIVE_WORKER_ID' ), '1', '', false );
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

function pow_native_setup_message( Partner $partner, string $payload_id, string $identity, string $shared_secret = 'fixture-secret-not-used-by-provisioner' ): SetupMessage {
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

function pow_native_setup_body( Partner $partner, string $secret, string $payload_id, string $identity ): string {
	$xml = static fn( string $value ): string => htmlspecialchars( $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	return '<cXML version="' . $xml( $partner->cxml_version ) . '" payloadID="' . $xml( $payload_id ) . '"><Header><From><Credential domain="' . $xml( $partner->from_domain ) . '"><Identity>' . $xml( $partner->from_identity ) . '</Identity></Credential></From><To><Credential domain="' . $xml( $partner->to_domain ) . '"><Identity>' . $xml( $partner->to_identity ) . '</Identity></Credential></To><Sender><Credential domain="' . $xml( $partner->sender_domain ) . '"><Identity>' . $xml( $partner->sender_identity ) . '</Identity><SharedSecret>' . $xml( $secret ) . '</SharedSecret></Credential><UserAgent>POW native fixture</UserAgent></Sender></Header><Request deploymentMode="test"><PunchOutSetupRequest operation="create"><BuyerCookie>native-cookie</BuyerCookie><Extrinsic name="UserEmail">' . $xml( $identity ) . '</Extrinsic><BrowserFormPost><URL>https://buyer.example.test/return</URL></BrowserFormPost></PunchOutSetupRequest></Request></cXML>';
}

function pow_native_endpoint(): SetupEndpoint {
	$plugin = \POW\Plugin::instance();
	$registry = $plugin->registry();
	$sessions = isset( $GLOBALS['pow_native_waiter_prefix'] ) ? new PowNativeObservedStore() : $plugin->sessions();
	$audit = $plugin->audit();
	if ( ! $registry || ! $sessions || ! $audit ) {
		throw new RuntimeException( 'Plugin services unavailable.' );
	}
	return new SetupEndpoint( $registry, $sessions, new Provisioner( $sessions, $audit, $plugin->logger() ), new Parser(), new Builder(), new RateLimiter( 0 ), $audit, new RateLimiter( 0 ) );
}

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
	return [ 'status' => $status ? (int) $status->getAttribute( 'code' ) : 0, 'hash' => hash( 'sha256', $response ) ];
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

function pow_native_setup_worker(): void {
	global $wpdb;
	$fixture = pow_native_json( (string) getenv( 'POW_NATIVE_SETUP_FIXTURE' ) );
	$worker = (string) getenv( 'POW_NATIVE_WORKER_ID' );
	$GLOBALS['pow_native_waiter_prefix'] = $fixture['waiter_prefix'];
	$hook_option = $fixture['hook_prefix'] . $worker;
	add_action( 'pow_buyer_provisioned', static function () use ( $wpdb, $hook_option, $fixture ): void {
		add_option( $hook_option, '1', '', false );
		$deadline = microtime( true ) + 1.0;
		do {
			$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $fixture['waiter_prefix'] ) . '%' ) );
			if ( $count >= 1 ) {
				break;
			}
			usleep( 10000 );
		} while ( microtime( true ) < $deadline );
	}, 1 );
	pow_native_write_private( (string) getenv( 'POW_NATIVE_RESULT' ), wp_json_encode( pow_native_invoke_setup( $fixture['body'] ) ) );
}

function pow_native_new_buyer_worker(): void {
	$fixture = pow_native_json( (string) getenv( 'POW_NATIVE_SETUP_FIXTURE' ) );
	$directory = (string) getenv( 'POW_NATIVE_RUN_DIRECTORY' );
	$worker = (string) getenv( 'POW_NATIVE_WORKER_ID' );
	touch( $directory . '/buyer-ready-' . $worker );
	pow_native_wait( static fn(): bool => is_file( $directory . '/buyer-start' ), 20.0, 'Buyer start barrier timed out.' );
	// The native filter runs after WordPress's non-atomic username lookup.
	// An unfixed pair reaches it twice before either INSERT. A serialized pair
	// reaches it once; the first proceeds after the bounded observation window.
	add_filter( 'wp_pre_insert_user_data', static function ( array $data, bool $update ) use ( $directory, $worker ): array {
		if ( ! $update ) {
			touch( $directory . '/buyer-insert-' . $worker );
			$deadline = microtime( true ) + 2.0;
			while ( pow_native_count_files( $directory, 'buyer-insert-' ) < 2 && microtime( true ) < $deadline ) { usleep( 10000 ); }
		}
		return $data;
	}, 10, 2 );
	$hook = [];
	add_action( 'pow_buyer_provisioned', static function ( int $user_id, Partner $partner, bool $created ) use ( &$hook ): void {
		$hook[] = [ 'user_id' => $user_id, 'created' => $created ];
	}, 10, 3 );
	$response = pow_native_invoke_setup( $fixture['bodies'][ (int) $worker - 1 ] );
	pow_native_write_private( (string) getenv( 'POW_NATIVE_RESULT' ), wp_json_encode( [ 'response' => $response, 'hook' => $hook ] ) );
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

function pow_native_buyer_lock_worker(): void {
	$plugin = \POW\Plugin::instance();
	$provisioner = new Provisioner( $plugin->sessions(), $plugin->audit(), $plugin->logger() );
	$directory = (string) getenv( 'POW_NATIVE_RUN_DIRECTORY' );
	$lock = new ReflectionMethod( Provisioner::class, 'with_identity_lock' );
	$lock->invoke( $provisioner, (int) getenv( 'POW_NATIVE_LOCK_PARTNER' ), (string) getenv( 'POW_NATIVE_LOCK_IDENTITY' ), static function () use ( $directory ): int {
		touch( $directory . '/buyer-lock-ready' );
		pow_native_wait( static fn(): bool => is_file( $directory . '/buyer-lock-release' ), 15.0, 'Buyer lock release barrier timed out.' );
		return 0;
	} );
	pow_native_write_private( (string) getenv( 'POW_NATIVE_RESULT' ), wp_json_encode( [ 'released' => true ] ) );
}

$mode = (string) getenv( 'POW_NATIVE_CONCURRENCY_MODE' );
if ( 'setup-worker' === $mode ) {
	pow_native_setup_worker();
	return;
}
if ( 'new-buyer-worker' === $mode ) {
	pow_native_new_buyer_worker();
	return;
}
if ( 'rate-worker' === $mode ) {
	pow_native_rate_worker();
	return;
}
if ( 'partner-lock-worker' === $mode ) {
	pow_native_partner_lock_worker();
	return;
}
if ( 'buyer-lock-worker' === $mode ) {
	pow_native_buyer_lock_worker();
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
$secret = wp_generate_password( 40, false, false );
$partner_id = $registry->insert(
	[
		'name' => 'Native Race ' . $run,
		'status' => Partner::STATUS_ACTIVE,
		'from_domain' => 'NetworkID',
		'from_identity' => 'buyer-' . $run,
		'sender_domain' => 'NetworkID',
		'sender_identity' => 'sender-' . $run,
		'to_domain' => 'NetworkID',
		'to_identity' => 'supplier-' . $run,
		'cxml_version' => '1.2.008',
		'deployment_mode' => 'test',
		'return_encoding' => 'base64',
		'mode' => Partner::MODE_REQUISITION_ONLY,
		'session_ttl' => 14400,
		'token_ttl' => 30,
	],
	$secret
);
$partner = $registry->find( $partner_id );
if ( ! $partner ) {
	throw new RuntimeException( 'Could not create native partner.' );
}

$payload = 'race-' . $run . '@example.test';
$identity = 'race-' . $run . '@example.test';
$preprovisioned = ( new Provisioner( $sessions, $audit, $plugin->logger() ) )->provision( $partner, pow_native_setup_message( $partner, 'preprovision-' . $run, $identity ) );
if ( $preprovisioned <= 0 ) {
	throw new RuntimeException( 'Could not preprovision the concurrent setup buyer.' );
}
$setup_fixture = $run_directory . '/setup.json';
$hook_prefix = 'pow_native_hook_' . $run . '_';
$waiter_prefix = 'pow_native_waiter_' . $run . '_';
pow_native_write_private( $setup_fixture, wp_json_encode( [ 'body' => pow_native_setup_body( $partner, $secret, $payload, $identity ), 'hook_prefix' => $hook_prefix, 'waiter_prefix' => $waiter_prefix ] ) );
$setup_processes = pow_native_spawn( 'setup-worker', 2, $run_directory, [ 'POW_NATIVE_SETUP_FIXTURE' => $setup_fixture ] );
$setup_results = pow_native_finish( $setup_processes );
global $wpdb;
$setup_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . \POW\Installer::sessions_table() . ' WHERE partner_id = %d AND payload_id = %s', $partner_id, $payload ), ARRAY_A );
$buyer_ids = get_users( [ 'fields' => 'ids', 'meta_key' => '_pow_partner_id', 'meta_value' => (string) $partner_id ] );
$hook_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $hook_prefix ) . '%' ) );
$waiter_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $waiter_prefix ) . '%' ) );
$reuse_audits = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . \POW\Installer::log_table() . " WHERE partner_id = %d AND user_id = %d AND event = 'buyer_provisioned' AND result = 'reused'", $partner_id, $preprovisioned ) );
$check( 200 === $setup_results[0]['status'] && 200 === $setup_results[1]['status'], 'two concurrent authenticated setup requests both receive success' );
$check( $setup_results[0]['hash'] === $setup_results[1]['hash'], 'the duplicate receives the winner response byte-for-byte' );
$check( 1 === count( $setup_rows ) && (int) $setup_rows[0]['user_id'] > 0 && '' !== (string) $setup_rows[0]['response_xml'], 'one complete native session row wins' );
$check( 1 === count( $buyer_ids ), 'one company-scoped buyer account is provisioned' );
$check( 1 === $hook_count, 'the provisioning side-effect hook runs once across concurrent duplicates' );
$check( $waiter_count >= 1, 'a duplicate observes the persisted in-flight claim while the winner is inside the unlocked provisioning hook' );
$check( 1 === $reuse_audits, 'the concurrent winner records one accurate buyer-reuse audit' );

$new_identity = 'new-buyer-' . $run . '@example.test';
$new_payloads = [ 'new-first-' . $run, 'new-second-' . $run ];
$new_fixture = $run_directory . '/new-buyer.json';
pow_native_write_private( $new_fixture, wp_json_encode( [ 'bodies' => [ pow_native_setup_body( $partner, $secret, $new_payloads[0], $new_identity ), pow_native_setup_body( $partner, $secret, $new_payloads[1], strtoupper( $new_identity ) ) ] ] ) );
$new_processes = pow_native_spawn( 'new-buyer-worker', 2, $run_directory, [ 'POW_NATIVE_SETUP_FIXTURE' => $new_fixture, 'POW_NATIVE_RUN_DIRECTORY' => $run_directory ] );
pow_native_wait( static fn(): bool => 2 === pow_native_count_files( $run_directory, 'buyer-ready-' ), 15.0, 'New buyer workers not ready.' );
touch( $run_directory . '/buyer-start' );
$new_results = pow_native_finish( $new_processes );
$new_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, status, response_xml FROM ' . \POW\Installer::sessions_table() . ' WHERE partner_id = %d AND payload_id IN (%s, %s)', $partner_id, ...$new_payloads ), ARRAY_A );
$new_users = get_users( [ 'fields' => 'ids', 'meta_query' => [ [ 'key' => '_pow_partner_id', 'value' => (string) $partner_id ], [ 'key' => '_pow_identity', 'value' => $new_identity ] ] ] );
$new_hooks = array_merge( $new_results[0]['hook'], $new_results[1]['hook'] );
$check( 200 === $new_results[0]['response']['status'] && 200 === $new_results[1]['response']['status'], 'distinct concurrent payloads for a new buyer both finish successfully' );
$check( 1 === count( $new_users ) && 1 === pow_native_count_files( $run_directory, 'buyer-insert-' ), 'distinct payloads with normalized buyer identity enter native user INSERT only once' );
$check( 2 === count( $new_hooks ) && 1 === count( array_filter( $new_hooks, static fn( array $hook ): bool => $hook['created'] ) ) && 1 === count( array_unique( array_column( $new_hooks, 'user_id' ) ) ), 'new and reused callbacks identify the same single buyer' );
$open_new_rows = array_filter( $new_rows, static fn( array $row ): bool => in_array( $row['status'], [ POW\Sessions\Session::PENDING, POW\Sessions\Session::ACTIVE ], true ) );
$check( 2 === count( $new_rows ) && 1 === count( array_unique( array_column( $new_rows, 'user_id' ) ) ) && 1 === count( $open_new_rows ), 'latest-wins leaves only one open session across distinct concurrent payloads' );

$locked_identity = 'locked-buyer-' . $run . '@example.test';
$locked_payload = 'locked-buyer-' . $run;
$locked_body = pow_native_setup_body( $partner, $secret, $locked_payload, $locked_identity );
$buyer_lock_processes = pow_native_spawn( 'buyer-lock-worker', 1, $run_directory, [ 'POW_NATIVE_RUN_DIRECTORY' => $run_directory, 'POW_NATIVE_LOCK_PARTNER' => (string) $partner_id, 'POW_NATIVE_LOCK_IDENTITY' => $locked_identity ] );
pow_native_wait( static fn(): bool => is_file( $run_directory . '/buyer-lock-ready' ), 10.0, 'Buyer lock worker did not acquire the lock.' );
try {
	$independent_setup = pow_native_invoke_setup( pow_native_setup_body( $partner, $secret, 'independent-' . $run, 'independent-' . $run . '@example.test' ) );
	$lock_timeout_setup = pow_native_invoke_setup( $locked_body );
	$locked_users = get_users( [ 'fields' => 'ids', 'meta_query' => [ [ 'key' => '_pow_partner_id', 'value' => (string) $partner_id ], [ 'key' => '_pow_identity', 'value' => $locked_identity ] ] ] );
	$locked_claim = $sessions->find_by_payload( $partner_id, $locked_payload );
} finally {
	touch( $run_directory . '/buyer-lock-release' );
}
$buyer_lock_results = pow_native_finish( $buyer_lock_processes );
$unlocked_retry = pow_native_invoke_setup( $locked_body );
$check( 200 === $independent_setup['status'], 'a held buyer lock does not block another identity in the same company' );
$check( 500 === $lock_timeout_setup['status'] && [] === $locked_users && null === $locked_claim, 'buyer lock timeout creates no user and releases only its own setup claim' );
$check( $buyer_lock_results[0]['released'] && 200 === $unlocked_retry['status'], 'a setup denied by the buyer lock succeeds on retry after release' );

$callback_payload = 'callback-' . $run . '@example.test';
$callback_body = pow_native_setup_body( $partner, $secret, $callback_payload, 'callback-' . $run . '@example.test' );
$throwing_callback = static function ( int $user_id, Partner $hook_partner ) use ( $partner_id ): void {
	if ( $hook_partner->id === $partner_id ) {
		throw new RuntimeException( 'Injected provisioning callback failure.' );
	}
};
add_action( 'pow_buyer_provisioned', $throwing_callback, 1, 2 );
$callback_failed = pow_native_invoke_setup( $callback_body, '203.0.113.41' );
remove_action( 'pow_buyer_provisioned', $throwing_callback, 1 );
$callback_rows_before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . \POW\Installer::sessions_table() . ' WHERE partner_id = %d AND payload_id = %s', $partner_id, $callback_payload ) );
$callback_success_audits_before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . \POW\Installer::log_table() . " WHERE partner_id = %d AND payload_id = %s AND event = 'setup_ok'", $partner_id, $callback_payload ) );
$callback_failure_audits = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . \POW\Installer::log_table() . " WHERE partner_id = %d AND payload_id = %s AND event = 'setup_fail' AND result = '500' AND session_id > 0", $partner_id, $callback_payload ) );
$callback_retry = pow_native_invoke_setup( $callback_body, '203.0.113.41' );
$callback_rows_after = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . \POW\Installer::sessions_table() . ' WHERE partner_id = %d AND payload_id = %s AND user_id > 0 AND response_xml IS NOT NULL', $partner_id, $callback_payload ) );
$callback_success_audits_after = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . \POW\Installer::log_table() . " WHERE partner_id = %d AND payload_id = %s AND event = 'setup_ok'", $partner_id, $callback_payload ) );
$check( 500 === $callback_failed['status'] && 0 === $callback_rows_before && 0 === $callback_success_audits_before && 1 === $callback_failure_audits, 'callback failure leaves no replay claim, records the claimed request failure, and emits no successful-looking setup audit' );
$check( 200 === $callback_retry['status'] && 1 === $callback_rows_after && 1 === $callback_success_audits_after, 'the same setup can retry after callback failure' );

$claim_method = new ReflectionMethod( SetupEndpoint::class, 'claim_setup' );
$auth_claim_payload = 'auth-claim-' . $run . '@example.test';
$auth_claim_hash = hash( 'sha256', 'auth-claim-body-' . $run );
$auth_claim_token = hash( 'sha256', 'auth-claim-token-' . $run );
$auth_claim_id = $sessions->create( [ 'partner_id' => $partner_id, 'user_id' => 0, 'status' => POW\Sessions\Session::PENDING, 'payload_id' => $auth_claim_payload, 'body_hash' => $auth_claim_hash, 'one_time_token_hash' => $auth_claim_token, 'expires' => gmdate( 'Y-m-d H:i:s', time() + 300 ), 'response_xml' => null ] );
$auth_failed = false;
try {
	$claim_method->invoke( pow_native_endpoint(), $partner, pow_native_setup_message( $partner, $auth_claim_payload, 'claim-auth-' . $run . '@example.test', 'wrong-secret' ), '203.0.113.42', $auth_claim_payload, $auth_claim_hash, hash( 'sha256', random_bytes( 16 ) ), gmdate( 'Y-m-d H:i:s', time() + 300 ) );
} catch ( Throwable $error ) {
	$auth_failed = true;
}
$auth_claim_after = $sessions->find( $auth_claim_id );
$auth_claim_token_after = $wpdb->get_var( $wpdb->prepare( 'SELECT one_time_token_hash FROM ' . \POW\Installer::sessions_table() . ' WHERE id = %d', $auth_claim_id ) );
$check( $auth_failed && $auth_claim_after && $auth_claim_after->user_id === 0 && null === $auth_claim_after->response_xml && $auth_claim_token === $auth_claim_token_after, 'a second request authentication failure before insert cannot delete the existing in-flight claim' );
$wrong_token_claim = POW\Sessions\Session::from_row( [ 'id' => $auth_claim_id, 'partner_id' => $partner_id, 'user_id' => 0, 'status' => POW\Sessions\Session::PENDING, 'payload_id' => $auth_claim_payload, 'body_hash' => $auth_claim_hash, 'one_time_token_hash' => hash( 'sha256', 'wrong-token-' . $run ) ] );
$check( ! $sessions->complete_setup_claim( $wrong_token_claim, $preprovisioned, '<fixture/>' ) && ! $sessions->abandon_setup_claim( $wrong_token_claim ) && null !== $sessions->find( $auth_claim_id ), 'a mismatched claim token cannot commit or delete the pending row' );

$lock_claim_payload = 'lock-claim-' . $run . '@example.test';
$lock_claim_hash = hash( 'sha256', 'lock-claim-body-' . $run );
$lock_claim_token = hash( 'sha256', 'lock-claim-token-' . $run );
$lock_claim_id = $sessions->create( [ 'partner_id' => $partner_id, 'user_id' => 0, 'status' => POW\Sessions\Session::PENDING, 'payload_id' => $lock_claim_payload, 'body_hash' => $lock_claim_hash, 'one_time_token_hash' => $lock_claim_token, 'expires' => gmdate( 'Y-m-d H:i:s', time() + 300 ), 'response_xml' => null ] );
$lock_processes = pow_native_spawn( 'partner-lock-worker', 1, $run_directory, [ 'POW_NATIVE_RUN_DIRECTORY' => $run_directory, 'POW_NATIVE_LOCK_PARTNER' => (string) $partner_id ] );
pow_native_wait( static fn(): bool => is_file( $run_directory . '/partner-lock-ready' ), 10.0, 'Partner lock worker did not acquire the lock.' );
$lock_failed = false;
try {
	$claim_method->invoke( pow_native_endpoint(), $partner, pow_native_setup_message( $partner, $lock_claim_payload, 'claim-lock-' . $run . '@example.test', $secret ), '203.0.113.43', $lock_claim_payload, $lock_claim_hash, hash( 'sha256', random_bytes( 16 ) ), gmdate( 'Y-m-d H:i:s', time() + 300 ) );
} catch ( Throwable $error ) {
	$lock_failed = true;
} finally {
	touch( $run_directory . '/partner-lock-release' );
}
$lock_results = pow_native_finish( $lock_processes );
$lock_claim_after = $sessions->find( $lock_claim_id );
$lock_claim_token_after = $wpdb->get_var( $wpdb->prepare( 'SELECT one_time_token_hash FROM ' . \POW\Installer::sessions_table() . ' WHERE id = %d', $lock_claim_id ) );
$check( $lock_failed && true === ( $lock_results[0]['released'] ?? false ) && $lock_claim_after && $lock_claim_after->user_id === 0 && null === $lock_claim_after->response_xml && $lock_claim_token === $lock_claim_token_after, 'a second request lock timeout before insert cannot delete the existing in-flight claim' );

$provisioner = new Provisioner( $sessions, $audit, $plugin->logger() );
$privileged_message = pow_native_setup_message( $partner, 'privileged-' . $run, 'privileged-' . $run . '@example.test' );
$privileged_id = $provisioner->provision( $partner, $privileged_message );
if ( $privileged_id > 0 ) {
	( new WP_User( $privileged_id ) )->set_role( 'administrator' );
}
$check( $privileged_id > 0 && 0 === $provisioner->provision( $partner, $privileged_message ), 'a now-privileged WordPress account cannot be reused as a buyer' );

$foreign_id = $registry->insert( [ 'name' => 'Foreign ' . $run, 'status' => Partner::STATUS_ACTIVE, 'sender_domain' => 'NetworkID', 'sender_identity' => 'foreign-' . $run ], wp_generate_password( 40, false, false ) );
$cross_message = pow_native_setup_message( $partner, 'cross-' . $run, 'cross-' . $run . '@example.test' );
$cross_user_id = $provisioner->provision( $partner, $cross_message );
if ( $cross_user_id > 0 ) {
	update_user_meta( $cross_user_id, '_pow_partner_id', $foreign_id );
}
$check( $foreign_id > 0 && $cross_user_id > 0 && 0 === $provisioner->provision( $partner, $cross_message ), 'a buyer mapping moved to another company cannot be reused' );

$admin_email = 'ordinary-admin-' . $run . '@example.test';
$admin_id = wp_insert_user( [ 'user_login' => 'ordinary-admin-' . $run, 'user_pass' => wp_generate_password( 32 ), 'user_email' => $admin_email, 'role' => 'administrator' ] );
$email_buyer_id = is_wp_error( $admin_id ) ? 0 : $provisioner->provision( $partner, pow_native_setup_message( $partner, 'email-' . $run, $admin_email ) );
$admin_after = is_wp_error( $admin_id ) ? false : get_userdata( (int) $admin_id );
$check( ! is_wp_error( $admin_id ) && $email_buyer_id > 0 && $email_buyer_id !== (int) $admin_id && $admin_after && in_array( 'administrator', $admin_after->roles, true ) && '' === get_user_meta( (int) $admin_id, '_pow_partner_id', true ), 'UserEmail matching an administrator creates an isolated buyer instead of taking over the account' );

foreach ( [ 'false', 'throw', 'lost-readback' ] as $metadata_failure ) {
	$failure_identity = 'metadata-' . $metadata_failure . '-' . $run . '@example.test';
	$failure_message = pow_native_setup_message( $partner, 'metadata-' . $metadata_failure . '-' . $run, $failure_identity );
	$users_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
	$hook_calls = 0;
	$hook = static function ( int $user_id, Partner $hook_partner ) use ( $partner_id, &$hook_calls ): void { if ( $hook_partner->id === $partner_id ) { ++$hook_calls; } };
	add_action( 'pow_buyer_provisioned', $hook, 20, 2 );
	$metadata_filter = null;
	$filter_name = '';
	if ( 'lost-readback' === $metadata_failure ) {
		$filter_name = 'get_user_metadata';
		$lost = false;
		$metadata_filter = static function ( $value, int $user_id, string $key, bool $single ) use ( &$lost ) {
			if ( ! $lost && $single && '_pow_partner_id' === $key ) { $lost = true; return ''; }
			return $value;
		};
		add_filter( $filter_name, $metadata_filter, 10, 4 );
	} else {
		$filter_name = 'update_user_metadata';
		$failed_once = false;
		$metadata_filter = static function ( $check, int $user_id, string $key ) use ( $metadata_failure, &$failed_once ) {
			if ( ! $failed_once && '_pow_partner_id' === $key ) {
				$failed_once = true;
				if ( 'throw' === $metadata_failure ) { throw new RuntimeException( 'Injected buyer metadata failure.' ); }
				return false;
			}
			return $check;
		};
		add_filter( $filter_name, $metadata_filter, 10, 3 );
	}
	try { $metadata_failed_id = $provisioner->provision( $partner, $failure_message ); }
	catch ( Throwable $error ) { $metadata_failed_id = 0; }
	remove_filter( $filter_name, $metadata_filter, 10 );
	$users_after_failure = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
	$metadata_retry_id = $provisioner->provision( $partner, $failure_message );
	remove_action( 'pow_buyer_provisioned', $hook, 20 );
	$mapped = get_users( [ 'fields' => 'ids', 'meta_key' => '_pow_identity', 'meta_value' => $failure_identity ] );
	$metadata_recovered = 0 === $metadata_failed_id && $users_before === $users_after_failure && $metadata_retry_id > 0 && [ $metadata_retry_id ] === array_map( 'intval', $mapped ) && 1 === $hook_calls;
	if ( ! $metadata_recovered ) { echo 'DETAIL metadata-' . $metadata_failure . ' failed=' . $metadata_failed_id . ' users=' . $users_before . '/' . $users_after_failure . ' retry=' . $metadata_retry_id . ' mapped=' . count( $mapped ) . ' hooks=' . $hook_calls . "\n"; }
	$check( $metadata_recovered, 'new buyer ' . $metadata_failure . ' metadata failure removes only the unhooked account and a clean retry succeeds' );
}

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

echo 'Passed: ' . $passed . ' Failed: ' . $failed . " Skipped: 0\n";
echo 'Fixture: ' . $run_directory . "\n";
if ( $failed > 0 ) {
	WP_CLI::halt( 1 );
}
