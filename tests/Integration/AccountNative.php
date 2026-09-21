<?php
/**
 * Native account acceptance companion; run only in an opted-in disposable local WP CLI.
 * The HTTP driver owns credentials in a private fixture file, never plugin reveal storage.
 *
 * The My Account surface is now one read-only screen: the setup-XML download for
 * the account a connection is bound to. There is no application form, no
 * rotation, no deactivation and no per-account rate budget to exercise, because
 * connections are created, credentialled and bound by an administrator. What is
 * left to prove natively is the lifecycle behind that screen and the visits in
 * front of it: three punch-ins on ONE bound account produce three session rows
 * that differ by login token, per-visit cart key and buyer identity, a repeat
 * identity supersedes only its own earlier visit, and the screen does not exist
 * while a visit is live.
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
require_once dirname( __DIR__ ) . '/Support/native-visits.php';
global $wpdb;
$account_actor = get_current_user_id();
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
		// Every fixture account is an ordinary customer: the plugin registers no
		// role and creates no user, and the account a connection is bound to is
		// the same kind of login as any other shopper's.
		foreach ( [ 'owner', 'other', 'linked' ] as $key ) {
			$f[$key . '_login'] = 'account-' . $key . '-' . $run;
			$f[$key] = wp_insert_user( [ 'user_login' => $f[$key . '_login'], 'user_email' => $key . '-' . $run . '@example.invalid', 'user_pass' => $f['password'], 'role' => 'customer' ] );
			account_check( is_int( $f[$key] ) && $f[$key] > 0, 'native ' . $key . ' fixture user' );
		}
		$f['sender'] = 'account-' . $run;
		$f['legacy'] = $r->insert( [ 'name' => 'Unowned private connection', 'owner_user_id' => 0, 'sender_domain' => 'NetworkID', 'sender_identity' => 'legacy-' . $run ] );
		// A legacy association on an ordinary account is what the binding screen
		// refuses, and it must not turn that account into a manager of anything.
		update_user_meta( $f['linked'], '_pow_partner_id', $f['legacy'] );
		// The administrator creates and binds the connection; there is no application flow.
		$f['partner'] = $r->insert( [ 'name' => 'Native account company', 'status' => 'pending', 'owner_user_id' => $f['owner'], 'from_domain' => 'NetworkID', 'from_identity' => $f['sender'], 'sender_domain' => 'NetworkID', 'sender_identity' => $f['sender'], 'to_domain' => 'NetworkID', 'to_identity' => 'native-supplier', 'cxml_version' => '1.2.008', 'deployment_mode' => 'test', 'return_encoding' => 'base64' ] );
		account_check( is_int( $f['partner'] ) && $f['partner'] > 0, 'admin-created pending connection bound to the store account' );
		$f['page'] = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Account native ' . $run, 'post_name' => 'account-native-' . $run, 'post_content' => '[woocommerce_my_account]' ] );
		update_option( 'woocommerce_myaccount_page_id', $f['page'] );
		$f['account_url'] = get_permalink( $f['page'] ) . POW\Account\IntegrationTab::ENDPOINT . '/';
		$f['docs'] = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Native integration guide ' . $run, 'post_content' => '[punchout_docs]' ] );
		$f['docs_url'] = get_permalink( $f['docs'] );
		POW\Installer::activate();
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
		global $wp_filter;
		$admin = $wp_filter['admin_post_pow_save_partner']->callbacks[10];
		$admin = reset( $admin )['function'][0];
		// The tab is a reader now: it holds no Registration, no limiter and no
		// address book, and every lifecycle service lives behind the admin post.
		$dependencies = array_map( static fn( ReflectionParameter $p ): string => (string) $p->getType(), ( new ReflectionMethod( $tab, '__construct' ) )->getParameters() );
		account_check( [ 'POW\Plugin', 'POW\Partners\Registry', 'POW\Audit\Log' ] === $dependencies, 'the account tab depends only on the plugin, the registry and the audit log' );
		account_check( ( new ReflectionProperty( $admin, 'registration' ) )->getValue( $admin ) instanceof POW\Partners\Registration, 'the admin post owns the registration lifecycle' );
		break;
	case 'pending':
		$p = $r->find_by_owner( $f['owner'] );
		account_check( null !== $p && $p->id === $f['partner'] && $p->is_pending() && '' === $p->secret_current && '' === $p->secret_previous, 'the admin-created connection is pending with empty secret slots' );
		account_check( $p->sender_identity === $f['sender'] && $p->owner_user_id === $f['owner'] && $r->find( $f['legacy'] )->owner_user_id === 0, 'binding one account never claims the unowned legacy row' );
		account_check( ! $r->associate_owner( $f['legacy'], $f['owner'] ) && 0 === $r->find( $f['legacy'] )->owner_user_id, 'an account that already owns a connection cannot be bound to a second one' );
		account_check( ! $r->associate_owner( $f['legacy'], $f['linked'] ) && 0 === $r->find( $f['legacy'] )->owner_user_id, 'an account carrying a legacy association cannot be bound' );
		account_check( 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . POW\Installer::partners_table() . ' WHERE owner_user_id=%d', $f['owner'] ) ), 'one account never owns a second connection row' );
		break;
	case 'approve':
		account_check( $r->update( $f['partner'], [ 'to_domain' => 'NetworkID', 'to_identity' => 'native-supplier' ] ), 'admin configures pending supplier identity' );
		$f['old_secret'] = $svc->approve( $f['partner'], get_current_user_id() );
		account_check( '' !== $f['old_secret'] && $r->find( $f['partner'] )->is_active(), 'native approval activates the connection' );
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
	case 'rotation':
		// Rotation is administrator work; the bound account cannot re-issue the
		// credential its own buyers authenticate with.
		$f['new_secret'] = (string) $r->rotate( $f['partner'] );
		$p = $r->find( $f['partner'] );
		account_check( '' !== $f['new_secret'] && null !== $r->verify_secret( $p, $f['new_secret'] ) && null !== $r->verify_secret( $p, $f['old_secret'] ) && $p->secret_current !== $f['new_secret'], 'native rotation keeps both accepted sealed slots' );
		break;
	case 'finished':
		account_check( $r->close_rotation( $f['partner'] ), 'native rotation closes' );
		$p = $r->find( $f['partner'] );
		account_check( null !== $r->verify_secret( $p, $f['new_secret'] ) && null === $r->verify_secret( $p, $f['old_secret'] ) && '' === $p->secret_previous, 'native finish revokes old secret only' );
		break;
	case 'no_leaks':
		$secrets = array_values( array_filter( [ $f['new_secret'] ?? '', $f['old_secret'] ?? '', $f['reset_secret'] ?? '' ], static fn( string $value ): bool => '' !== $value ) );
		account_check( [] !== $secrets, 'at least one issued credential is under test' );
		foreach ( [ $wpdb->options, $wpdb->usermeta, $wpdb->postmeta, POW\Installer::log_table(), POW\Installer::sessions_table() ] as $table ) {
			$rows = $wpdb->get_results( 'SELECT * FROM ' . $table, ARRAY_A );
			$encoded = wp_json_encode( $rows );
			foreach ( $secrets as $secret ) {
				account_check( '' === $wpdb->last_error && ! str_contains( $encoded, $secret ), 'no plaintext reveal in ' . str_replace( $wpdb->prefix, '', $table ) );
			}
		}
		$mail = file_get_contents( getenv( 'POW_ACCOUNT_MAIL' ) );
		foreach ( $secrets as $secret ) { account_check( ! str_contains( $mail, $secret ), 'captured mail contains no issued secret' ); }
		break;
	case 'employees':
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . POW\Installer::sessions_table() . ' WHERE partner_id=%d ORDER BY id', $f['partner'] ), ARRAY_A );
		account_check( count( $rows ) === 3 && [ $f['owner'] ] === array_values( array_unique( array_map( 'intval', array_column( $rows, 'user_id' ) ) ) ), 'three punch-ins open three visits of the one bound store account' );
		// Two employees, three punch-ins: the first buyer came back, so the
		// identity hash is what separates the visits and what supersedes.
		account_check( $rows[0]['buyer_identity_hash'] === $rows[2]['buyer_identity_hash'] && $rows[0]['buyer_identity_hash'] !== $rows[1]['buyer_identity_hash'] && '' !== (string) $rows[1]['buyer_identity'], 'each visit records the buyer identity its request named' );
		account_check( 'expired' === $rows[0]['status'] && 'pending' === $rows[1]['status'], 'a returning buyer supersedes only its own earlier visit' );
		$f['employee'] = $f['owner'];
		$f['token'] = (string) $rows[2]['wp_session_token'];
		$f['visit_key'] = (string) $rows[2]['wc_session_key'];
		$f['unrelated_token'] = WP_Session_Tokens::get_instance( $f['owner'] )->create( time() + 3600 );
		account_check( 'active' === $rows[2]['status'] && '' !== $f['token'] && WP_Session_Tokens::get_instance( $f['owner'] )->verify( $f['token'] ), 'HTTP start bound this visit its own login token' );
		account_check( POW\Cart\SessionKey::is_visit_key( $f['visit_key'] ) && '' === (string) $rows[1]['wc_session_key'], 'the redeemed visit alone holds a per-visit cart key' );
		$f['session'] = (int) $rows[2]['id'];
		break;
	case 'visit':
		// The one screen that survives must not exist for a request that is
		// inside a visit: then the signed-in account is carrying an employee of
		// the buying company, not the account holder reading its own details.
		$visit = $store->find( (int) $f['session'] );
		account_check( null !== $visit && POW\Sessions\Session::ACTIVE === $visit->status, 'the live visit is the one the driver redeemed' );
		pow_native_enter_visit( $visit );
		try {
			$tab = account_tab();
			$actor = new ReflectionMethod( $tab, 'actor' );
			account_check( 0 === $actor->invoke( $tab ), 'the account tab has no actor inside a live visit' );
			account_check( ! array_key_exists( POW\Account\IntegrationTab::ENDPOINT, $tab->add_menu_item( [ 'dashboard' => 'Dashboard' ] ) ), 'the account menu offers no punchout item inside a live visit' );
		} finally { pow_native_leave_visit( $account_actor ); }
		break;
	case 'deactivated':
		// Withdrawing a connection is administrator work: fence, revoke both
		// slots, drain every open visit, destroy each visit's own login.
		$f['reset_secret'] = $svc->reset( $f['partner'], [ 'from_domain' => 'NetworkID', 'from_identity' => $f['sender'], 'sender_domain' => 'NetworkID', 'sender_identity' => $f['sender'], 'to_domain' => 'NetworkID', 'to_identity' => 'native-supplier' ], get_current_user_id() );
		account_check( '' !== $f['reset_secret'], 'admin reset drains every open visit before reissuing' );
		account_check( $r->with_partner_lock( $f['partner'], static fn(): bool => $r->transition_status( $f['partner'], 'active', [ 'status' => 'disabled' ] ) ), 'admin disables the connection under its own mutex' );
		account_check( $r->revoke_secret( $f['partner'] ), 'admin revokes both sealed slots' );
		$p = $r->find( $f['partner'] );
		account_check( $p->status === 'disabled' && $p->secret_current === '' && $p->secret_previous === '' && [] === $store->open_for_partner( $p->id ), 'withdrawal disables, clears credentials and drains every open visit' );
		$tokens = WP_Session_Tokens::get_instance( $f['owner'] );
		account_check( ! $tokens->verify( $f['token'] ) && $tokens->verify( $f['unrelated_token'] ), 'each visit login token is revoked and an unrelated login of the same account survives' );
		account_check( null === pow_native_cart_row( (string) $f['visit_key'] ), 'the drained visit basket row is gone' );
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
