<?php
/**
 * Native account acceptance companion; run only in an opted-in disposable local WP CLI.
 * The HTTP driver owns credentials in a private fixture file, never plugin reveal storage.
 * @package POW
 * @license AGPL-3.0-or-later
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'disposable' !== getenv( 'POW_NATIVE_TESTS' ) || 'local' !== wp_get_environment_type() || ! current_user_can( 'manage_woocommerce' ) ) {
	throw new RuntimeException( 'Requires an opted-in disposable local fixture administrator.' );
}
$fixture_file = getenv( 'POW_ACCOUNT_FIXTURE' );
if ( ! $fixture_file || ! is_dir( dirname( $fixture_file ) ) ) { throw new RuntimeException( 'Private fixture directory required.' ); }
$f = is_file( $fixture_file ) ? json_decode( file_get_contents( $fixture_file ), true, 512, JSON_THROW_ON_ERROR ) : [];
$plugin = POW\Plugin::instance();
$r = $plugin->registry();
$store = $plugin->sessions();
$log = $plugin->audit();
$svc = new POW\Partners\Registration( $r, $store, $log );
global $wpdb;
function account_check( bool $ok, string $name ): void {
	if ( ! $ok ) { throw new RuntimeException( $name ); }
	echo "PASS {$name}\n";
}
function account_tab(): POW\Account\IntegrationTab {
	global $wp_filter;
	foreach ( $wp_filter['woocommerce_account_menu_items']->callbacks as $callbacks ) {
		foreach ( $callbacks as $cb ) {
			if ( is_array( $cb['function'] ) && $cb['function'][0] instanceof POW\Account\IntegrationTab ) { return $cb['function'][0]; }
		}
	}
	throw new RuntimeException( 'Boot did not register Account controller.' );
}
function account_clear_limits( array $f ): void {
	foreach ( [ 'account|user|' . $f['owner'], 'account|user|' . $f['other'], 'account|ip|127.0.0.1' ] as $bucket ) {
		delete_transient( 'pow_rl_3600_' . md5( $bucket ) );
	}
}
switch ( getenv( 'POW_ACCOUNT_STEP' ) ) {
	case 'seed':
		account_check( ! defined( 'WP_HOME' ) || WP_HOME === getenv( 'POW_ACCOUNT_URL' ), 'HTTP test URL matches fixed native home URL' );
		account_check( str_contains( DB_HOST, '/server.sock' ) && in_array( wp_parse_url( home_url(), PHP_URL_HOST ), [ '127.0.0.1', 'localhost' ], true ), 'private socket and loopback target' );
		account_check( has_filter( 'pre_wp_mail' ) && 'local_network_blocked' === wp_remote_get( 'https://example.invalid' )->get_error_code() && ! function_exists( 'mail' ), 'captured mail and blocked outbound HTTP guards' );
		$f['original'] = [ 'home' => get_option( 'home' ), 'siteurl' => get_option( 'siteurl' ), 'settings' => get_option( 'pow_settings' ), 'permalink_structure' => get_option( 'permalink_structure' ), 'myaccount' => get_option( 'woocommerce_myaccount_page_id' ) ];
		update_option( 'home', getenv( 'POW_ACCOUNT_URL' ) );
		update_option( 'siteurl', getenv( 'POW_ACCOUNT_URL' ) );
		update_option( 'permalink_structure', '/%postname%/' );
		global $wp_rewrite;
		$wp_rewrite->init();
		update_option( 'pow_settings', array_replace( (array) get_option( 'pow_settings' ), [ 'enabled' => 'yes' ] ) );
		$run = bin2hex( random_bytes( 6 ) );
		$f['password'] = wp_generate_password( 32, false );
		foreach ( [ 'owner' => 'customer', 'other' => 'customer', 'buyer' => POW\Installer::ROLE, 'linked' => 'customer' ] as $key => $role ) {
			$f[$key . '_login'] = 'account-' . $key . '-' . $run;
			$f[$key] = wp_insert_user( [ 'user_login' => $f[$key . '_login'], 'user_email' => $key . '-' . $run . '@example.invalid', 'user_pass' => $f['password'], 'role' => $role ] );
			account_check( is_int( $f[$key] ) && $f[$key] > 0, 'native ' . $key . ' fixture user' );
		}
		$f['sender'] = 'account-' . $run;
		$f['legacy'] = $r->insert( [ 'name' => 'Unowned private connection', 'owner_user_id' => 0, 'sender_domain' => 'NetworkID', 'sender_identity' => 'legacy-' . $run ] );
		update_user_meta( $f['linked'], '_pow_partner_id', $f['legacy'] );
		$f['page'] = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Account native ' . $run, 'post_name' => 'account-native-' . $run, 'post_content' => '[woocommerce_my_account]' ] );
		update_option( 'woocommerce_myaccount_page_id', $f['page'] );
		$f['account_url'] = get_permalink( $f['page'] ) . POW\Account\IntegrationTab::ENDPOINT . '/';
		$f['docs'] = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Native integration guide ' . $run, 'post_content' => '[punchout_docs]' ] );
		$f['docs_url'] = get_permalink( $f['docs'] );
		POW\Installer::activate();
		account_clear_limits( $f );
		break;
	case 'rewrites':
		global $wp_rewrite;
		$count = 0;
		add_action( 'generate_rewrite_rules', static function () use ( &$count ) { ++$count; } );
		POW\Installer::deactivate();
		account_check( 1 === $count && ! str_contains( wp_json_encode( get_option( 'rewrite_rules' ) ), 'punchout-integration' ), 'deactivation flushes once and removes endpoint rules' );
		POW\Installer::activate();
		account_check( 2 === $count && str_contains( wp_json_encode( get_option( 'rewrite_rules' ) ), 'punchout-integration' ), 'activation flushes once and creates endpoint rules' );
		POW\Installer::maybe_upgrade();
		account_check( 2 === $count, 'current activation does not flush on upgrade check' );
		delete_option( POW\Installer::REWRITE_VERSION_KEY );
		update_option( POW\Installer::DB_VERSION_KEY, '3' );
		POW\Installer::maybe_upgrade();
		account_check( 3 === $count && '1' === get_option( POW\Installer::REWRITE_VERSION_KEY ), 'schema3 upgrade gets independent rewrite revision once' );
		POW\Installer::maybe_upgrade();
		account_check( 3 === $count, 'repeated upgrade check does not flush' );
		$tab = account_tab();
		$account_registration = ( new ReflectionProperty( $tab, 'registration' ) )->getValue( $tab );
		global $wp_filter;
		$admin = $wp_filter['admin_post_pow_save_partner']->callbacks[10];
		$admin = reset( $admin )['function'][0];
		account_check( $account_registration === ( new ReflectionProperty( $admin, 'registration' ) )->getValue( $admin ), 'Account and Admin receive the same Registration instance' );
		break;
	case 'pending':
		$p = $r->find_by_owner( $f['owner'] );
		account_check( null !== $p && $p->is_pending() && '' === $p->secret_current && '' === $p->secret_previous, 'HTTP application persists pending with empty secret slots' );
		account_check( $p->sender_identity === $f['sender'] && $p->owner_user_id === $f['owner'] && $r->find( $f['legacy'] )->owner_user_id === 0, 'posted owner status and secret cannot claim legacy row or bypass approval' );
		$f['partner'] = $p->id;
		account_check( 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . POW\Installer::partners_table() . ' WHERE owner_user_id=%d', $f['owner'] ) ), 'duplicate application creates no second owned row' );
		break;
	case 'approve':
		account_check( $r->update( $f['partner'], [ 'to_domain' => 'NetworkID', 'to_identity' => 'native-supplier' ] ), 'admin configures pending supplier identity' );
		$f['old_secret'] = $svc->approve( $f['partner'], get_current_user_id() );
		account_check( '' !== $f['old_secret'] && $r->find( $f['partner'] )->is_active(), 'native approval activates application' );
		account_clear_limits( $f );
		break;
	case 'audit':
		$id = $f['partner'];
		account_check( null === $log->last_success( $id ), 'native audit absence is null' );
		foreach ( [ [ $id, 'setup_ok', 'ok', '2026-01-01 01:00:00.000' ], [ $id, 'setup_ok', 'replay', '2026-01-01 02:00:00.000' ], [ $id, 'setup_ok', 'error', '2026-01-01 03:00:00.000' ], [ $f['legacy'], 'setup_ok', 'ok', '2026-01-01 04:00:00.000' ], [ $id, 'unrelated', 'ok', '2026-01-01 05:00:00.000' ] ] as [ $partner_id, $event, $result, $ts ] ) {
			account_check( 1 === $wpdb->insert( POW\Installer::log_table(), compact( 'partner_id', 'event', 'result', 'ts' ) ), 'native audit row persisted' );
		}
		account_check( '2026-01-01 02:00:00.000' === $log->last_success( $id ) && '2026-01-01 05:00:00.000' === $log->last_success( $id, 'unrelated' ) && null === $log->last_success( 0 ), 'native audit matches partner event successful result and latest row' );
		$filter = static fn( $q ) => str_starts_with( $q, 'SELECT ts FROM ' . POW\Installer::log_table() ) ? 'SELECT ts FROM account_missing_table' : $q;
		add_filter( 'query', $filter );
		$threw = false;
		$old_suppression = $wpdb->suppress_errors;
		try { $log->last_success( $id ); } catch ( RuntimeException $e ) { $threw = true; }
		remove_filter( 'query', $filter );
		account_check( $threw && $old_suppression === $wpdb->suppress_errors, 'native SQL failure throws neutral error and restores suppression' );
		break;
	case 'clear_limits': account_clear_limits( $f ); break;
	case 'expiry':
		$key = 'pow_rl_3600_' . md5( 'account|user|' . $f['owner'] );
		account_check( 5 === (int) get_transient( $key ) && (int) get_option( '_transient_timeout_' . $key ) - time() > 3500, 'native hourly counter stops at five with one-hour expiry' );
		foreach ( [ 'account|user|' . $f['owner'], 'account|ip|127.0.0.1' ] as $bucket ) { update_option( '_transient_timeout_pow_rl_3600_' . md5( $bucket ), time() - 1 ); }
		break;
	case 'rotation':
		$secret = $f['new_secret'];
		$p = $r->find( $f['partner'] );
		account_check( null !== $r->verify_secret( $p, $secret ) && null !== $r->verify_secret( $p, $f['old_secret'] ) && $p->secret_current !== $secret, 'native rotation keeps both accepted sealed slots' );
		break;
	case 'finished':
		$p = $r->find( $f['partner'] );
		account_check( null !== $r->verify_secret( $p, $f['new_secret'] ) && null === $r->verify_secret( $p, $f['old_secret'] ) && '' === $p->secret_previous, 'native finish revokes old secret only' );
		break;
	case 'no_leaks':
		$secret = $f['new_secret'];
		foreach ( [ $wpdb->options, $wpdb->usermeta, $wpdb->postmeta, POW\Installer::log_table(), POW\Installer::sessions_table() ] as $table ) {
			$rows = $wpdb->get_results( 'SELECT * FROM ' . $table, ARRAY_A );
			account_check( '' === $wpdb->last_error && ! str_contains( wp_json_encode( $rows ), $secret ), 'no plaintext reveal in ' . str_replace( $wpdb->prefix, '', $table ) );
		}
		account_check( ! str_contains( file_get_contents( getenv( 'POW_ACCOUNT_MAIL' ) ), $secret ), 'captured mail contains no rotated secret' );
		break;
	case 'employees':
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . POW\Installer::sessions_table() . ' WHERE partner_id=%d ORDER BY id', $f['partner'] ), ARRAY_A );
		account_check( count( $rows ) === 3 && $rows[0]['user_id'] !== $rows[1]['user_id'] && $rows[0]['user_id'] === $rows[2]['user_id'], 'two company employees isolated and returning employee recognised via native setup' );
		$f['employee'] = (int) $rows[0]['user_id'];
		$f['token'] = (string) $rows[2]['wp_session_token'];
		$f['unrelated_token'] = WP_Session_Tokens::get_instance( $f['employee'] )->create( time() + 3600 );
		account_check( 'active' === $rows[2]['status'] && '' !== $f['token'] && WP_Session_Tokens::get_instance( $f['employee'] )->verify( $f['token'] ), 'HTTP start created and bound native employee login token' );
		$f['session'] = (int) $rows[2]['id'];
		break;
	case 'deactivated':
		$p = $r->find( $f['partner'] );
		account_check( $p->status === 'disabled' && $p->secret_current === '' && $p->secret_previous === '' && [] === $store->open_for_partner( $p->id ), 'HTTP deactivation disables clears credentials and drains all open sessions' );
		$tokens = WP_Session_Tokens::get_instance( $f['employee'] );
		account_check( ! $tokens->verify( $f['token'] ) && $tokens->verify( $f['unrelated_token'] ), 'native exact login token revoked and unrelated token preserved' );
		break;
	case 'disabled':
		update_option( 'pow_settings', array_replace( (array) get_option( 'pow_settings' ), [ 'enabled' => 'no' ] ) );
		break;
	case 'disabled_boot':
		account_check( account_tab() instanceof POW\Account\IntegrationTab && str_contains( wp_json_encode( get_option( 'rewrite_rules' ) ), 'punchout-integration' ), 'disabled boot retains controller and rewrite endpoint' );
		break;
	case 'docs_absent': wp_update_post( [ 'ID' => $f['docs'], 'post_status' => 'draft' ] ); break;
	case 'restore':
		foreach ( [ 'home', 'siteurl', 'permalink_structure' ] as $key ) { update_option( $key, $f['original'][$key] ); }
		update_option( 'pow_settings', $f['original']['settings'] );
		update_option( 'woocommerce_myaccount_page_id', $f['original']['myaccount'] );
		flush_rewrite_rules( false );
		break;
	default: throw new RuntimeException( 'Unknown native account step.' );
}
file_put_contents( $fixture_file, wp_json_encode( $f ), LOCK_EX );
