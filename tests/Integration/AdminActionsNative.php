<?php
/**
 * Opt-in native admin persistence tests. Creates neutral fixtures; never deletes fixtures or prints credentials.
 * Coordinator only, in disposable local WordPress/WooCommerce with this candidate active:
 * POW_NATIVE_TESTS=disposable wp --user=<fixture-admin> eval-file tests/Integration/AdminActionsNative.php
 * Real admin callbacks, nonces, users, SQL, sealed slots, audit, session tokens and captured mail. Binding a connection to a store account is admin work and the only writer of owner_user_id, so its refusals — a missing account, a privileged one, a legacy association and an account another connection already names — are proved here against real accounts.
 * CLI intercepts only terminal wp_die/redirect responses. Actual HTTP headers, browser behavior and independent-process races require a separate HTTP run.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */
declare( strict_types = 1 );
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'disposable' !== getenv( 'POW_NATIVE_TESTS' ) || 'local' !== wp_get_environment_type() || ! current_user_can( 'manage_woocommerce' ) || ! function_exists( 'WC' ) ) {
	throw new RuntimeException( 'Requires opted-in disposable local WordPress/WooCommerce CLI and a capable fixture administrator.' );
}

require_once dirname( __DIR__ ) . '/Support/native-visits.php';

final class AdminNativeResponse extends RuntimeException {
	public function __construct( public string $html, public array $args ) { parent::__construct( 'Native response intercepted' ); }
}
final class AdminActionsNative {
	private POW\Partners\Registry $registry;
	private POW\Sessions\Store $sessions;
	private int $admin;
	private array $mail = [];
	private array $queries = [];
	private int $passed = 0;
	private int $failed = 0;

	public function __construct() {
		$this->admin = get_current_user_id();
		$this->registry = POW\Plugin::instance()->registry();
		$this->sessions = new POW\Sessions\Store();
	}
	private function check( bool $ok ): void { if ( ! $ok ) { throw new RuntimeException( 'Native assertion failed' ); } }
	public function die_handler( mixed $handler ): callable {
		return static function ( mixed $message, mixed $title = '', mixed $args = [] ): void {
			throw new AdminNativeResponse( (string) $message, is_array( $args ) ? $args : [] );
		};
	}
	public function redirect( string $url ): string { throw new AdminNativeResponse( '', [ 'redirect' => $url ] ); }
	public function capture_mail( mixed $pre, array $atts ): bool { $this->mail[] = $atts; return true; }
	public function capture_query( string $sql ): string { $this->queries[] = $sql; return $sql; }
	private function account( string $role = 'customer' ): int {
		$suffix = bin2hex( random_bytes( 8 ) );
		$id = wp_insert_user( [ 'user_login' => 'admin-check-' . $suffix, 'user_email' => $suffix . '@example.invalid', 'user_pass' => wp_generate_password(), 'role' => $role ] );
		$this->check( ! is_wp_error( $id ) && $id > 0 );
		return (int) $id;
	}
	private function fixture( bool $legacy = false ): array {
		$owner = $legacy ? 0 : $this->account();
		$suffix = bin2hex( random_bytes( 8 ) );
		$data = [ 'name' => 'Example native admin company', 'owner_user_id' => $owner, 'status' => 'pending', 'from_domain' => 'NetworkID', 'from_identity' => 'buyer-' . $suffix, 'sender_domain' => 'NetworkID', 'sender_identity' => 'buyer-' . $suffix, 'to_domain' => 'NetworkID', 'to_identity' => 'supplier', 'deployment_mode' => 'test', 'return_encoding' => 'base64', 'cxml_version' => '1.2.008', 'company_profile' => '{"book":"preserve"}' ];
		$id = $this->registry->insert( $data );
		$this->check( $id > 0 );
		return compact( 'id', 'owner', 'data' );
	}
	private function call( string $action, int $id, array $fields = [], string $method = 'POST', ?int $actor = null, bool $nonce = true ): AdminNativeResponse {
		wp_set_current_user( $actor ?? $this->admin );
		$prefix = [ 'save_partner' => 'save_partner', 'approve_partner' => 'approve_' . $id, 'reset_partner' => 'reset_' . $id, 'associate_partner' => 'associate_' . $id, 'rotate_partner' => 'rotate_' . $id, 'close_rotation' => 'close_' . $id, 'delete_partner' => 'delete_' . $id ][ $action ];
		$_SERVER['REQUEST_METHOD'] = $method;
		$_POST = [ 'action' => 'pow_' . $action, 'partner' => $id, '_wpnonce' => $nonce ? wp_create_nonce( 'pow_' . $prefix ) : 'invalid' ] + $fields;
		$_GET = 'GET' === $method ? $_POST : [];
		try { do_action( 'admin_post_pow_' . $action ); }
		catch ( AdminNativeResponse $response ) { return $response; }
		throw new RuntimeException( 'Native callback did not terminate' );
	}
	private function secret( AdminNativeResponse $response ): string {
		preg_match( '#<code>([^<]+)</code>#', $response->html, $match );
		$secret = html_entity_decode( $match[1] ?? '', ENT_QUOTES );
		$this->check( 200 === ( $response->args['response'] ?? 0 ) && strlen( $secret ) > 20 );
		$this->check( ! str_contains( (string) wp_json_encode( [ $this->mail, $this->queries ] ), $secret ) );
		return $secret;
	}
	private function case( string $label, callable $case ): void {
		try { $case(); echo 'PASS ' . $label . "\n"; ++$this->passed; }
		catch ( Throwable $e ) { echo 'FAIL ' . $label . ' (' . get_class( $e ) . ")\n"; ++$this->failed; }
		finally { wp_set_current_user( $this->admin ); }
	}
	public function run(): void {
		$saved = [ $_POST, $_GET, $_SERVER['REQUEST_METHOD'] ?? null ];
		// Captures all SQL/mail writes without emitting them or changing native persistence.
		add_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], PHP_INT_MAX, 2 );
		add_filter( 'wp_die_handler', [ $this, 'die_handler' ], PHP_INT_MAX );
		add_filter( 'wp_redirect', [ $this, 'redirect' ], PHP_INT_MAX );
		add_filter( 'query', [ $this, 'capture_query' ], PHP_INT_MAX );
		ob_start(); // Keep CLI output from making native response headers unavailable mid-suite.
		try {
			$this->case( 'pending preparation ignores forged active and secrets; explicit approval issues once', function () {
				$f = $this->fixture();
				$this->call( 'save_partner', $f['id'], array_replace( $f['data'], [ 'status' => 'active', 'secret' => 'forged', 'generate_secret' => '1' ] ) );
				$p = $this->registry->find( $f['id'] );
				$this->check( $p->is_pending() && '' === $p->secret_current && '' === $p->secret_previous );
				$s = $this->secret( $this->call( 'approve_partner', $f['id'] ) );
				$this->check( POW\Partners\Secrets::SLOT_CURRENT === $this->registry->verify_secret( $this->registry->find( $f['id'] ), $s ) );
				$before = $this->registry->find( $f['id'] )->secret_current;
				$this->call( 'approve_partner', $f['id'] );
				$this->check( $before === $this->registry->find( $f['id'] )->secret_current );
				$this->check( false === get_transient( 'pow_notice_' . $this->admin ) || ! str_contains( (string) wp_json_encode( get_transient( 'pow_notice_' . $this->admin ) ), $s ) );
			} );
			$this->case( 'incomplete supplier identity cannot approve', function () {
				$f = $this->fixture(); $this->registry->update( $f['id'], [ 'to_identity' => '' ] );
				$this->call( 'approve_partner', $f['id'] ); $p = $this->registry->find( $f['id'] );
				$this->check( $p->is_pending() && '' === $p->secret_current );
			} );
			$this->case( 'GET invalid nonce and ordinary account refuse every admin mutation', function () {
				$f = $this->fixture();
				foreach ( [ 'save_partner', 'approve_partner', 'reset_partner', 'associate_partner', 'rotate_partner', 'close_rotation', 'delete_partner' ] as $action ) {
					foreach ( [ 'GET', 'nonce', 'actor' ] as $gate ) {
						$r = $this->call( $action, $f['id'], [], 'GET' === $gate ? 'GET' : 'POST', 'actor' === $gate ? $f['owner'] : null, 'nonce' !== $gate );
						$this->check( ( 'GET' === $gate ? 405 : 403 ) === ( $r->args['response'] ?? 0 ) );
					}
				}
				$p = $this->registry->find( $f['id'] ); $this->check( $p->is_pending() && '' === $p->secret_current );
			} );
			$this->case( 'legacy creation and rotation return local credentials without plaintext stores', function () {
				$f = $this->fixture();
				$s = $this->secret( $this->call( 'save_partner', 0, array_replace( $f['data'], [ 'status' => 'active', 'sender_identity' => $f['data']['sender_identity'] . '-manual', 'generate_secret' => 1 ] ) ) );
				$p = $this->registry->find_by_sender( 'NetworkID', $f['data']['sender_identity'] . '-manual' );
				$this->check( $p && $p->is_active() && 0 === $p->owner_user_id );
				$new = $this->secret( $this->call( 'rotate_partner', $p->id ) );
				$this->check( POW\Partners\Secrets::SLOT_PREVIOUS === $this->registry->verify_secret( $this->registry->find( $p->id ), $s ) );
				$this->call( 'close_rotation', $p->id );
				$this->check( null === $this->registry->verify_secret( $this->registry->find( $p->id ), $s ) );
				$this->check( POW\Partners\Secrets::SLOT_CURRENT === $this->registry->verify_secret( $this->registry->find( $p->id ), $new ) );
			} );
			$this->case( 'admin maps legacy owner and audits exact IDs; transfers refuse', function () {
				global $wpdb;
				$f = $this->fixture( true ); $owner = $this->account();
				$this->call( 'associate_partner', $f['id'], [ 'owner_user_id' => $owner ] );
				$this->check( $owner === $this->registry->find( $f['id'] )->owner_user_id );
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . POW\Installer::log_table() . ' WHERE partner_id = %d AND event = %s ORDER BY id DESC LIMIT 1', $f['id'], 'partner_owner_associated' ), ARRAY_A );
				$detail = json_decode( $row['detail'] ?? '', true );
				$this->check( 0 === ( $detail['old_owner_user_id'] ?? null ) && $owner === ( $detail['new_owner_user_id'] ?? null ) );
				$this->call( 'associate_partner', $f['id'], [ 'owner_user_id' => $this->account() ] );
				$this->check( $owner === $this->registry->find( $f['id'] )->owner_user_id );
			} );
			$this->case( 'missing privileged duplicate legacy and self-claimed store accounts refuse binding', function () {
				$f = $this->fixture( true ); $existing = $this->fixture();
				// A shop manager is exactly the account a buyer's purchasing system
				// must never be signed in as, so it cannot be bound either.
				$privileged = $this->account(); ( new WP_User( $privileged ) )->add_cap( 'manage_woocommerce' );
				$legacy = $this->account(); update_user_meta( $legacy, '_pow_partner_id', $existing['id'] );
				foreach ( [ PHP_INT_MAX, $existing['owner'], $privileged, $legacy ] as $owner ) {
					$this->call( 'associate_partner', $f['id'], [ 'owner_user_id' => $owner ] );
					$this->check( 0 === $this->registry->find( $f['id'] )->owner_user_id );
				}
				$plain = $this->account();
				$this->call( 'associate_partner', $f['id'], [ 'owner_user_id' => $plain ] );
				$this->check( $plain === $this->registry->find( $f['id'] )->owner_user_id );
				$r = $this->call( 'associate_partner', $f['id'], [ 'owner_user_id' => $existing['owner'] ], 'POST', $existing['owner'] );
				$this->check( 403 === $r->args['response'] );
			} );
			$this->case( 'the My Account list saves from real checkboxes and the name box, refuses payment pages and malformed names, and a new connection starts with the dashboard', function () {
				$f = $this->fixture();
				$this->check( 'dashboard' === $this->registry->find( $f['id'] )->visit_endpoints );
				$this->call( 'save_partner', $f['id'], $f['data'] + [ 'visit_endpoints' => [ '', 'dashboard', 'orders', 'late-page' ] ] );
				$this->check( 'dashboard,orders,late-page' === $this->registry->find( $f['id'] )->visit_endpoints );
				$this->call( 'save_partner', $f['id'], $f['data'] + [ 'visit_endpoints' => [ '', 'dashboard', 'payment-methods' ] ] );
				$this->check( 'dashboard,orders,late-page' === $this->registry->find( $f['id'] )->visit_endpoints );
				$this->check( str_contains( (string) wp_json_encode( get_transient( 'pow_notice_' . $this->admin ) ), 'never pays on site' ) );
				$r = $this->call( 'save_partner', $f['id'], $f['data'] + [ 'visit_endpoints' => [ 'x' => [ 'dashboard' ] ] ] );
				$this->check( 400 === ( $r->args['response'] ?? 0 ) );
				// The form's name box posts one more entry of the same list.
				$this->call( 'save_partner', $f['id'], $f['data'] + [ 'visit_endpoints' => [ '', 'dashboard', 'orders', 'typed-page' ] ] );
				$this->check( 'dashboard,orders,typed-page' === $this->registry->find( $f['id'] )->visit_endpoints );
				$this->call( 'save_partner', $f['id'], $f['data'] + [ 'visit_endpoints' => [ '', 'dashboard', 'Typed Page' ] ] );
				$this->check( 'dashboard,orders,typed-page' === $this->registry->find( $f['id'] )->visit_endpoints );
				$this->check( str_contains( (string) wp_json_encode( get_transient( 'pow_notice_' . $this->admin ) ), 'lowercase letters, digits and hyphens' ) );
				$this->call( 'save_partner', $f['id'], $f['data'] + [ 'visit_endpoints' => [ '' ] ] );
				$this->check( '' === $this->registry->find( $f['id'] )->visit_endpoints );
			} );
			$this->case( 'reset removes both slots and every live visit login while preserving the bound account and its book', function () {
				$f = $this->fixture(); $old = $this->secret( $this->call( 'approve_partner', $f['id'] ) ); $overlap = $this->secret( $this->call( 'rotate_partner', $f['id'] ) );
				// Two employees, one bound account: two visits, two login tokens, two cart keys.
				$first = pow_native_open_visit( $f['id'], $f['owner'], [ 'buyer_identity' => 'first-' . bin2hex( random_bytes( 5 ) ) . '@example.invalid' ] );
				$second = pow_native_open_visit( $f['id'], $f['owner'], [ 'buyer_identity' => 'second-' . bin2hex( random_bytes( 5 ) ) . '@example.invalid' ] );
				$manager = WP_Session_Tokens::get_instance( $f['owner'] ); $unrelated = $manager->create( time() + 3600 );
				$this->check( $manager->verify( $first->wp_session_token ) && $manager->verify( $second->wp_session_token ) && $first->wc_session_key !== $second->wc_session_key );
				$this->secret( $this->call( 'reset_partner', $f['id'], [ 'sender_identity' => 'forged' ] ) ); $p = $this->registry->find( $f['id'] );
				$this->check( '' === $p->secret_previous && null === $this->registry->verify_secret( $p, $old ) && null === $this->registry->verify_secret( $p, $overlap ) );
				$this->check( $f['owner'] === $p->owner_user_id && $f['data']['company_profile'] === $p->company_profile && $f['data']['sender_identity'] === $p->sender_identity );
				foreach ( [ $first, $second ] as $visit ) {
					$this->check( 'expired' === $this->sessions->find( $visit->id )->status && ! $manager->verify( $visit->wp_session_token ) && null === pow_native_cart_row( (string) $visit->wc_session_key ) );
				}
				// The account itself survives: only the visits' own logins are destroyed.
				$this->check( $manager->verify( $unrelated ) && get_userdata( $f['owner'] ) instanceof WP_User );
			} );
			$this->case( 'reset SQL revocation failure reports error and never supplies replacement', function () {
				$f = $this->fixture(); $this->secret( $this->call( 'approve_partner', $f['id'] ) );
				$hit = false;
				$filter = static function ( string $sql ) use ( &$hit ): string {
					if ( str_starts_with( $sql, 'UPDATE `' . POW\Installer::partners_table() . '`' ) && str_contains( $sql, "`secret_current` = ''" ) ) { $hit = true; return ''; }
					return $sql;
				};
				add_filter( 'query', $filter, 10 );
				try { $r = $this->call( 'reset_partner', $f['id'] ); } finally { remove_filter( 'query', $filter, 10 ); }
				$notice = get_transient( 'pow_notice_' . $this->admin );
				$this->check( $hit && ! str_contains( $r->html, '<code>' ) && 'error' === $notice['type'] && 'disabled' === $this->registry->find( $f['id'] )->status );
			} );
		} finally {
			remove_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], PHP_INT_MAX ); remove_filter( 'wp_die_handler', [ $this, 'die_handler' ], PHP_INT_MAX ); remove_filter( 'wp_redirect', [ $this, 'redirect' ], PHP_INT_MAX ); remove_filter( 'query', [ $this, 'capture_query' ], PHP_INT_MAX );
			[ $_POST, $_GET, $method ] = $saved;
			if ( null === $method ) { unset( $_SERVER['REQUEST_METHOD'] ); } else { $_SERVER['REQUEST_METHOD'] = $method; }
			wp_set_current_user( $this->admin );
			ob_end_flush();
		}
		echo "Native admin: {$this->passed} passed, {$this->failed} failed\n";
		if ( $this->failed ) { WP_CLI::halt( 1 ); }
	}
}
( new AdminActionsNative() )->run();
