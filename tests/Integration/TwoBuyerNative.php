<?php
/**
 * Opt-in native acceptance: two employees shopping one bound account, end to end.
 *
 * Run only against an opted-in disposable local WordPress/WooCommerce database:
 * POW_NATIVE_TESTS=disposable wp --user=<fixture-admin> eval-file tests/Integration/TwoBuyerNative.php
 *
 * This is the release's acceptance path, not a unit of it. One connection is
 * bound to one ordinary WooCommerce customer account, so two employees
 * punching in at the same moment are the same WordPress user: the same
 * capabilities, the same profile, the same persistent-cart meta row. Every
 * separation the model promises therefore has to come from the visit — its own
 * `WP_Session_Tokens` entry, its own auth cookie and its own per-visit
 * `wc_session_key` — and none of it can be proved by comparing user ids. The
 * script drives the real wire path for each of them: an authenticated
 * `PunchOutSetupRequest`, the StartPage redeem, the basket, the delivery
 * review, the cart return and the Punchout Quote it leaves behind, then reads
 * the rows back byte-exactly.
 *
 * It also carries the three regressions that were the confirmed blockers of
 * the shared login, because each of them is invisible until two requests share
 * an account: WooCommerce's own `wc_last_active` write and a colleague's
 * StartPage redeem both land in the window between one visit's basket stage and
 * its commit, and `_woocommerce_load_saved_cart_after_login` — left by an
 * ordinary password login of that same account — must be neither read nor
 * deleted nor honoured inside a visit.
 *
 * Fixture requirements it will not invent for you: a `local` environment type,
 * a store currency of ZAR (the delivery review's own assertion), and a store
 * base address that is a currently allowed shipping destination, since a fresh
 * visit's delivery candidate is the bound account's profile address. It creates
 * its own account, connections, products and visits and leaves them for
 * inspection; it deletes nothing. It proves the plugin's own decisions against
 * real rows, not the web server's: an actual HTTP run is still the only thing
 * that shows a 302 or a handoff POST reaching a browser.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'disposable' !== getenv( 'POW_NATIVE_TESTS' ) || 'local' !== wp_get_environment_type() || ! current_user_can( 'manage_woocommerce' ) || ! function_exists( 'WC' ) ) {
	throw new RuntimeException( 'Requires opted-in disposable local WordPress/WooCommerce CLI and a capable fixture administrator.' );
}

if ( ! POW\Plugin::instance()->enabled() ) {
	throw new RuntimeException( 'The master switch must be on: the cart guards and the buyer runtime are registered behind it.' );
}

if ( 'ZAR' !== get_woocommerce_currency() ) {
	throw new RuntimeException( 'The delivery review only accepts a ZAR store; set the fixture currency before running this suite.' );
}

require_once dirname( __DIR__ ) . '/Support/setup-io-stubs.php';
require_once dirname( __DIR__ ) . '/Support/native-visits.php';

/** A refusal that would have been a 302 in a browser. */
final class TwoBuyerRedirect extends RuntimeException {}

final class TwoBuyerNative {

	/** WooCommerce's "merge the saved cart on the next hydration" flag, which a visit may not touch. */
	private const MERGE_FLAG = '_woocommerce_load_saved_cart_after_login';

	private POW\Partners\Registry $registry;
	private POW\Sessions\Store $sessions;
	private POW\Audit\Log $audit;
	private POW\RouteGuard $guard;
	private POW\Cart\NativeSessionGuard $carts;
	private POW\Addresses\Confirmation $confirmation;
	private POW\Http\ReturnEndpoint $returns;

	private string $run;
	private int $admin;
	private int $account = 0;
	private string $secret = '';
	private POW\Partners\Partner $partner;
	private array $address = [];
	/** @var array<string,int> label => product id */
	private array $products = [];
	/** @var array<string,string> label => buyer identity */
	private array $identities = [];
	/** @var array<string,POW\Sessions\Session> label => visit row as last read */
	private array $visits = [];
	/** @var array<string,int> label => quote order id */
	private array $quotes = [];
	/** @var array<string,string> label => the logged_in cookie WordPress minted for that visit */
	private array $cookies = [];
	/** The bound account's own saved basket, as an ordinary password login left it. */
	private array $saved_cart = [];
	private int $passed = 0;
	private int $failed = 0;

	public function __construct() {
		$plugin         = POW\Plugin::instance();
		$this->registry = $plugin->registry() ?? throw new RuntimeException( 'Plugin services unavailable.' );
		$this->sessions = $plugin->sessions() ?? throw new RuntimeException( 'Plugin services unavailable.' );
		$this->audit    = $plugin->audit() ?? throw new RuntimeException( 'Plugin services unavailable.' );
		$this->guard    = new POW\RouteGuard( $plugin, $this->registry, $plugin->settings() );
		// The registered instance, not a new one: the session handler resolves
		// its own guard through NativeSessionGuard::registered(), so a second
		// instance would stage against one guard and commit against another.
		$this->carts    = POW\Cart\NativeSessionGuard::registered();
		$resolver       = new POW\Addresses\Resolver(
			$this->registry,
			new POW\Addresses\CompanyBook( $this->registry, $this->audit, new POW\Sessions\Current( $this->sessions ) ),
			new POW\Sessions\Current( $this->sessions )
		);
		$mapper             = new POW\Cart\PoomMapper( $plugin->settings(), $plugin->logger() );
		$this->confirmation = new POW\Addresses\Confirmation(
			$this->registry,
			$this->sessions,
			new POW\Addresses\QuoteAddress( $resolver ),
			new POW\Addresses\DeliveryEstimate( $plugin->settings() ),
			$resolver,
			$mapper,
			$this->carts
		);
		$this->returns = new POW\Http\ReturnEndpoint(
			$this->sessions,
			$this->registry,
			$mapper,
			new POW\Cxml\Builder(),
			$this->audit,
			new POW\Orders\QuoteOrder( $this->sessions, $this->audit, $plugin->settings(), $plugin->logger() ),
			$this->confirmation
		);
		$this->run   = strtolower( bin2hex( random_bytes( 4 ) ) );
		$this->admin = get_current_user_id();
	}

	/* ---------------------------------------------------------------------
	 * Harness
	 * ------------------------------------------------------------------ */

	private function check( bool $ok, string $label ): void {
		if ( $ok ) { ++$this->passed; echo 'PASS ' . $label . "\n"; return; }
		++$this->failed; echo 'FAIL ' . $label . "\n";
	}

	public function catch_redirect( string $location ): string {
		throw new TwoBuyerRedirect( $location );
	}

	/** Whether the door answered with what would be a browser redirect. */
	private function redirected( callable $operation ): bool {
		add_filter( 'wp_redirect', [ $this, 'catch_redirect' ], PHP_INT_MAX );
		try { $operation(); return false; }
		catch ( TwoBuyerRedirect $redirect ) { return true; }
		finally { remove_filter( 'wp_redirect', [ $this, 'catch_redirect' ], PHP_INT_MAX ); }
	}

	/** Set the WooCommerce endpoint query vars a real front-end request would carry. */
	private function endpoint( array $vars ): void {
		global $wp;
		foreach ( [ 'edit-account', 'orders', 'view-order', 'order-pay', 'order-received' ] as $endpoint ) { unset( $wp->query_vars[ $endpoint ] ); }
		foreach ( $vars as $endpoint => $value ) { $wp->query_vars[ $endpoint ] = $value; }
	}

	private function log_rows( int $partner_id, string $payload_id, string $event, string $result ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . POW\Installer::log_table() . ' WHERE partner_id = %d AND payload_id = %s AND event = %s AND result = %s', $partner_id, $payload_id, $event, $result ) );
	}

	/** The same count for an event a session writes rather than a request: no payload id of its own. */
	private function session_log_rows( int $session_id, string $event, string $result ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . POW\Installer::log_table() . ' WHERE session_id = %d AND event = %s AND result = %s', $session_id, $event, $result ) );
	}

	/** Every detail this connection has ever logged, for "no raw e-mail is written" assertions. */
	private function log_details( int $partner_id ): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GROUP_CONCAT(detail) FROM ' . POW\Installer::log_table() . ' WHERE partner_id = %d', $partner_id ) );
	}

	/** One user-meta row read outside every visit, so no plugin filter can answer it. */
	private function account_meta( string $key ): mixed {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT meta_value FROM ' . $wpdb->usermeta . ' WHERE user_id = %d AND meta_key = %s', $this->account, $key ) );
		return null === $value ? null : maybe_unserialize( (string) $value );
	}

	private function fresh( string $label ): POW\Sessions\Session {
		$visit = $this->sessions->find( $this->visits[ $label ]->id ) ?? throw new RuntimeException( 'Visit ' . $label . ' vanished.' );
		$this->visits[ $label ] = $visit;
		return $visit;
	}

	private function key( string $label ): string {
		return (string) $this->visits[ $label ]->wc_session_key;
	}

	/* ---------------------------------------------------------------------
	 * Fixtures
	 * ------------------------------------------------------------------ */

	private function seed(): void {
		$this->account = pow_native_bound_account( 'two-buyer' );
		$this->address = $this->store_address();
		$customer      = new WC_Customer( $this->account );
		foreach ( $this->address as $field => $value ) { $customer->{ 'set_shipping_' . $field }( $value ); }
		$customer->save();
		$this->secret  = wp_generate_password( 40, false, false );
		$this->partner = $this->connection( 'two-buyer', $this->account, $this->secret );
		foreach ( [ 'alpha' => '11.00', 'beta' => '23.50' ] as $label => $price ) { $this->products[ $label ] = $this->product( $label, $price ); }
		// The account holder's own saved basket and pending merge flag, exactly
		// as an ordinary password login of that account leaves them. Nothing a
		// visit does may read, merge, rewrite or delete either one.
		$this->saved_cart = [ 'cart' => [ 'two-buyer-own-line' => [ 'product_id' => $this->products['alpha'], 'quantity' => 7 ] ] ];
		update_user_meta( $this->account, '_woocommerce_persistent_cart_' . get_current_blog_id(), $this->saved_cart );
		update_user_meta( $this->account, self::MERGE_FLAG, '1' );
	}

	private function connection( string $label, int $owner, string $secret ): POW\Partners\Partner {
		$suffix = $label . '-' . $this->run;
		$id     = $this->registry->insert(
			[
				'name'            => 'Two Buyer ' . $suffix,
				'status'          => POW\Partners\Partner::STATUS_ACTIVE,
				'owner_user_id'   => $owner,
				'from_domain'     => 'NetworkID',
				'from_identity'   => 'buyer-' . $suffix,
				'sender_domain'   => 'NetworkID',
				'sender_identity' => 'sender-' . $suffix,
				'to_domain'       => 'NetworkID',
				'to_identity'     => 'supplier-' . $suffix,
				'cxml_version'    => '1.2.008',
				'deployment_mode' => 'test',
				'return_encoding' => 'base64',
				'session_ttl'     => 14400,
				'token_ttl'       => 300,
			],
			$secret
		);
		return $id > 0 ? ( $this->registry->find( $id ) ?? throw new RuntimeException( 'Connection ' . $label . ' missing.' ) ) : throw new RuntimeException( 'Connection ' . $label . ' could not be created.' );
	}

	/**
	 * A destination the store would actually accept.
	 *
	 * A fresh visit's delivery candidate is the bound account's profile
	 * address — the company's default destination, not one employee's — so the
	 * fixture address has to pass the same canonical validation the review
	 * does. Deriving it from the store's own base location keeps that true on
	 * whatever store this runs against; a refusal here is a fixture fault and
	 * says so.
	 */
	private function store_address(): array {
		$base    = wc_get_base_location();
		$country = (string) ( $base['country'] ?? '' );
		$states  = WC()->countries->get_states( $country );
		$address = POW\Addresses\Shape::normalise(
			[
				'first_name' => 'Company',
				'last_name'  => 'Delivery',
				'company'    => 'Two Buyer Fixture',
				'address_1'  => '1 Fixture Street',
				'address_2'  => '',
				'city'       => 'Fixture City',
				'state'      => is_array( $states ) && [] !== $states ? (string) array_key_first( $states ) : '',
				'postcode'   => (string) get_option( 'woocommerce_store_postcode', '' ),
				'country'    => $country,
				'phone'      => '',
			]
		);
		if ( $address instanceof WP_Error ) { throw new RuntimeException( 'The store base address is not an allowed punchout destination: ' . $address->get_error_code() ); }
		return $address;
	}

	private function product( string $label, string $price ): int {
		$product = new WC_Product_Simple();
		$product->set_name( 'Two-buyer ' . $label . ' ' . $this->run );
		$product->set_sku( 'POW-TWO-' . strtoupper( $label . '-' . $this->run ) );
		$product->set_regular_price( $price );
		$product->set_price( $price );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_virtual( false );
		$id    = (int) $product->save();
		$saved = $id > 0 ? wc_get_product( $id ) : null;
		if ( ! $saved instanceof WC_Product || ! $saved->is_purchasable() || ! $saved->needs_shipping() ) { throw new RuntimeException( 'Fixture product ' . $label . ' is not a purchasable physical product.' ); }
		return $id;
	}

	/* ---------------------------------------------------------------------
	 * The wire path
	 * ------------------------------------------------------------------ */

	/** An unidentified buyer is legal, so each half of the identity is omitted when empty. */
	private function setup_body( POW\Partners\Partner $partner, string $secret, string $payload_id, string $identity, string $name ): string {
		$xml        = static fn( string $value ): string => htmlspecialchars( $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
		$extrinsics = '' !== $identity ? '<Extrinsic name="UserEmail">' . $xml( $identity ) . '</Extrinsic>' : '';
		$extrinsics .= '' !== $name ? '<Extrinsic name="UserPrintableName">' . $xml( $name ) . '</Extrinsic>' : '';
		return '<cXML version="' . $xml( $partner->cxml_version ) . '" payloadID="' . $xml( $payload_id ) . '"><Header><From><Credential domain="' . $xml( $partner->from_domain ) . '"><Identity>' . $xml( $partner->from_identity ) . '</Identity></Credential></From><To><Credential domain="' . $xml( $partner->to_domain ) . '"><Identity>' . $xml( $partner->to_identity ) . '</Identity></Credential></To><Sender><Credential domain="' . $xml( $partner->sender_domain ) . '"><Identity>' . $xml( $partner->sender_identity ) . '</Identity><SharedSecret>' . $xml( $secret ) . '</SharedSecret></Credential><UserAgent>POW two-buyer fixture</UserAgent></Sender></Header><Request deploymentMode="test"><PunchOutSetupRequest operation="create"><BuyerCookie>two-buyer-' . $xml( $payload_id ) . '</BuyerCookie>' . $extrinsics . '<BrowserFormPost><URL>https://buyer.example.test/return</URL></BrowserFormPost></PunchOutSetupRequest></Request></cXML>';
	}

	/** @return array{status:int,token:string} */
	private function invoke_setup( POW\Partners\Partner $partner, string $secret, string $payload_id, string $identity, string $name, string $ip ): array {
		$endpoint = new POW\Http\SetupEndpoint(
			$this->registry,
			$this->sessions,
			new POW\Cxml\Parser(),
			new POW\Cxml\Builder(),
			new POW\Http\RateLimiter( 0 ),
			$this->audit,
			new POW\Http\RateLimiter( 0 )
		);
		$server                             = $_SERVER;
		$_SERVER['REMOTE_ADDR']             = $ip;
		$_SERVER['REQUEST_METHOD']          = 'POST';
		$_SERVER['CONTENT_TYPE']            = 'text/xml';
		$GLOBALS['pow_test_setup_io']       = [ 'body' => $this->setup_body( $partner, $secret, $payload_id, $identity, $name ), 'reads' => 0, 'parses' => 0, 'headers' => [] ];
		ob_start();
		try { $endpoint->handle(); $response = (string) ob_get_contents(); }
		finally { ob_end_clean(); $_SERVER = $server; unset( $GLOBALS['pow_test_setup_io'] ); }
		$document = new DOMDocument();
		if ( ! $document->loadXML( $response ) ) { throw new RuntimeException( 'The setup response was not XML.' ); }
		$status = $document->getElementsByTagName( 'Status' )->item( 0 );
		$start  = (string) ( new DOMXPath( $document ) )->evaluate( 'string(//StartPage/URL)' );
		$path   = '' !== $start ? (string) wp_parse_url( $start, PHP_URL_PATH ) : '';
		return [ 'status' => $status ? (int) $status->getAttribute( 'code' ) : 0, 'token' => '' !== $path ? basename( $path ) : '' ];
	}

	/**
	 * Redeem one StartPage token and hand back the activated visit.
	 *
	 * The redeem is what mints the visit's WP session token, its auth cookie
	 * and its own `wc_session_key`, so every claim about two baskets has to go
	 * through it. The cookie WordPress actually writes is captured here rather
	 * than reconstructed, because "two auth cookies on one account" is one of
	 * the things under test. The caller's actor and cookies are restored: a
	 * coordinator that quietly became the buyer would make every later
	 * capability check meaningless.
	 */
	private function redeem( string $token, string $label ): ?POW\Sessions\Session {
		$plugin  = POW\Plugin::instance();
		$actor   = get_current_user_id();
		$cookies = $_COOKIE;
		$server  = $_SERVER;
		$capture = function ( string $cookie ) use ( $label ): void { $this->cookies[ $label ] = $cookie; };
		$_SERVER['REQUEST_METHOD'] = 'GET';
		add_action( 'set_logged_in_cookie', $capture );
		ob_start();
		try { ( new POW\Http\StartEndpoint( $this->sessions, $this->registry, $plugin->settings(), $this->audit ) )->handle( $token ); }
		finally {
			ob_end_clean();
			remove_action( 'set_logged_in_cookie', $capture );
			pow_native_leave_visit( $actor );
			$_SERVER = $server;
			$_COOKIE = $cookies;
		}
		return $this->sessions->find_by_token_hash( POW\Sessions\Tokens::hash( $token ) );
	}

	/** One whole punch-in: an authenticated setup request and its redeem. */
	private function punch_in( string $label, string $identity, string $name, string $ip = '203.0.113.60' ): POW\Sessions\Session {
		$payload  = $label . '-' . $this->run;
		$response = $this->invoke_setup( $this->partner, $this->secret, $payload, $identity, $name, $ip );
		if ( 200 !== $response['status'] || '' === $response['token'] ) { throw new RuntimeException( 'Punch-in ' . $label . ' was refused with cXML ' . $response['status'] . '.' ); }
		$visit = $this->redeem( $response['token'], $label );
		if ( ! $visit || POW\Sessions\Session::ACTIVE !== $visit->status ) { throw new RuntimeException( 'Punch-in ' . $label . ' did not activate a visit.' ); }
		$this->identities[ $label ] = $identity;
		$this->visits[ $label ]     = $visit;
		return $visit;
	}

	/* ---------------------------------------------------------------------
	 * Being inside one visit
	 * ------------------------------------------------------------------ */

	/**
	 * Detach the per-cart and per-session callbacks of objects this script is
	 * about to replace. One CLI process plays several requests, and a stale
	 * WC_Cart or WC_Session_Handler left on a hook would write one visit's
	 * state through another visit's objects.
	 */
	private function detach_native_hooks(): void {
		global $wp_filter;
		foreach ( $wp_filter as $hook => $dispatcher ) {
			foreach ( $dispatcher->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'];
					if ( ! is_array( $function ) || ! is_object( $function[0] ) || ! ( $function[0] instanceof WC_Cart || $function[0] instanceof WC_Cart_Session || $function[0] instanceof WC_Session_Handler ) ) { continue; }
					remove_filter( $hook, $function, $priority );
				}
			}
		}
	}

	/**
	 * Put this request inside one visit, holding that visit's own basket.
	 *
	 * Only the plugin's handler may serve a visit: core's init_session()
	 * deletes a `pow_` row and migrates what survives onto
	 * `session_key = '<user id>'`, which is exactly the collision two
	 * employees of one bound account would suffer. The customer is rebuilt on
	 * the session data store so this visit's address snapshot overlays the
	 * shared profile rather than the previous visit's.
	 */
	private function enter( string $label ): POW\Cart\NativeSessionHandler {
		$this->detach_native_hooks();
		$handler         = pow_native_visit_handler( $this->fresh( $label ) );
		WC()->session    = $handler;
		WC()->customer   = new WC_Customer( $this->account, true );
		WC()->cart       = new WC_Cart();
		WC()->shipping()->reset_shipping();
		return $handler;
	}

	/**
	 * Hydrate the live cart from the visit's own session row.
	 *
	 * This is core's own wp_loaded hydration, called explicitly because a CLI
	 * script runs after wp_loaded has already fired. It is also the exact code
	 * path that reads `_woocommerce_load_saved_cart_after_login` and merges the
	 * shared account's saved basket, which is why the merge regression drives
	 * it rather than a plugin method.
	 */
	private function hydrate(): void {
		( new WC_Cart_Session( WC()->cart ) )->get_cart_from_session();
	}

	private function add_line( string $product ): void {
		if ( ! WC()->cart->add_to_cart( $this->products[ $product ], 1 ) ) { throw new RuntimeException( 'Fixture product ' . $product . ' could not be added.' ); }
	}

	private function persist( string $label ): void {
		$handler = WC()->session;
		if ( ! $handler instanceof POW\Cart\NativeSessionHandler || ! $handler->save_checked() ) { throw new RuntimeException( 'Visit ' . $label . ' basket could not be persisted (' . (string) ( $handler instanceof POW\Cart\NativeSessionHandler ? $handler->last_refusal() : 'no handler' ) . ').' ); }
	}

	/** The product ids one visit's stored basket holds, read straight from its row. */
	private function stored_lines( string $label ): array {
		$cart = pow_native_cart_value( $this->key( $label ), 'cart' );
		$ids  = [];
		foreach ( is_array( $cart ) ? $cart : [] as $line ) { $ids[] = (int) ( $line['product_id'] ?? 0 ); }
		sort( $ids );
		return $ids;
	}

	/* ---------------------------------------------------------------------
	 * Two visits of one account
	 * ------------------------------------------------------------------ */

	private function open_two_visits(): void {
		$first  = $this->punch_in( 'superseded', 'alice-' . $this->run . '@example.invalid', 'Alice Buyer' );
		$second = $this->punch_in( 'b', 'bob-' . $this->run . '@example.invalid', 'Bob Buyer', '203.0.113.61' );
		$tokens = WP_Session_Tokens::get_instance( $this->account );

		$this->check( $first->user_id === $this->account && $second->user_id === $this->account, 'two setups on one connection open two visits of the one bound account' );
		$this->check( $first->id !== $second->id && $first->partner_id === $this->partner->id && $second->partner_id === $this->partner->id, 'each setup gets its own visit row on that connection' );
		$this->check( ! hash_equals( $first->wp_session_token, $second->wp_session_token ) && $tokens->verify( $first->wp_session_token ) && $tokens->verify( $second->wp_session_token ), 'the one account holds one live WP_Session_Tokens entry per visit' );
		$this->check(
			POW\Cart\SessionKey::is_visit_key( (string) $first->wc_session_key ) && POW\Cart\SessionKey::is_visit_key( (string) $second->wc_session_key ) && ! hash_equals( (string) $first->wc_session_key, (string) $second->wc_session_key ),
			'each visit carries its own per-visit cart key'
		);
		$this->check( POW\Cart\SessionKey::LENGTH === strlen( (string) $first->wc_session_key ), 'the per-visit cart key is exactly as wide as WooCommerce session_key column' );
		$cookies = array_intersect_key( $this->cookies, [ 'superseded' => true, 'b' => true ] );
		$parsed  = [];
		foreach ( $cookies as $label => $cookie ) { $parsed[ $label ] = (string) ( wp_parse_auth_cookie( $cookie, 'logged_in' )['token'] ?? '' ); }
		$this->check(
			2 === count( $cookies ) && 2 === count( array_unique( $cookies ) ) && ( $parsed['superseded'] ?? '' ) === $first->wp_session_token && ( $parsed['b'] ?? '' ) === $second->wp_session_token,
			'each redeem writes its own auth cookie carrying its own visit token'
		);
		$this->check(
			'alice-' . $this->run . '@example.invalid' === $first->buyer_identity && 'Alice Buyer' === $first->buyer_name && hash( 'sha256', $this->partner->id . '|alice-' . $this->run . '@example.invalid' ) === $first->buyer_identity_hash,
			'the buyer identity and name the request named are stored on the visit as data'
		);
		$this->check( 1 === $this->log_rows( $this->partner->id, 'superseded-' . $this->run, 'setup_ok', 'ok' ) && 1 === $this->log_rows( $this->partner->id, 'b-' . $this->run, 'setup_ok', 'ok' ), 'both punch-ins are audited' );
		$this->check( ! str_contains( $this->log_details( $this->partner->id ), 'alice-' . $this->run . '@example.invalid' ), 'no raw buyer e-mail reaches the audit log' );
	}

	private function fill_two_baskets(): void {
		foreach ( [ 'superseded' => 'alpha', 'b' => 'beta' ] as $label => $product ) {
			$this->enter( $label );
			$this->add_line( $product );
			WC()->session->set( 'chosen_shipping_methods', [ 'pow-' . $label => 'fixture:' . $label ] );
			$this->persist( $label );
		}
		pow_native_leave_visit( $this->admin );

		$this->check( [ $this->products['alpha'] ] === $this->stored_lines( 'superseded' ) && [ $this->products['beta'] ] === $this->stored_lines( 'b' ), 'each visit basket row holds only the lines that visit added' );
		$this->check(
			[ 'pow-superseded' => 'fixture:superseded' ] === pow_native_cart_value( $this->key( 'superseded' ), 'chosen_shipping_methods' ) && [ 'pow-b' => 'fixture:b' ] === pow_native_cart_value( $this->key( 'b' ), 'chosen_shipping_methods' ),
			'each visit keeps its own chosen shipping methods'
		);
		$this->check( null === pow_native_cart_row( (string) $this->account ), 'no visit writes a WooCommerce session row keyed on the shared account' );
		$this->check( $this->saved_cart === $this->account_meta( '_woocommerce_persistent_cart_' . get_current_blog_id() ), 'the account holder own saved basket is untouched by two visits' );
	}

	/**
	 * The same buyer punching in again supersedes only her own earlier visit.
	 *
	 * Latest-wins is per identity, not per account: superseding by user id
	 * would end every colleague's live visit on every punch-in. The earlier
	 * visit's login and basket row must both go, and the colleague's must both
	 * stay.
	 */
	private function supersede(): void {
		$stale     = $this->visits['superseded'];
		$colleague = $this->visits['b'];
		$this->punch_in( 'a', $this->identities['superseded'], 'Alice Buyer', '203.0.113.62' );
		$tokens = WP_Session_Tokens::get_instance( $this->account );
		$row    = pow_native_visit_row( $stale->id );

		$this->check( POW\Sessions\Session::EXPIRED === ( $row['status'] ?? '' ), 'a second punch-in by the same buyer identity expires that buyer earlier visit' );
		$this->check( ! $tokens->verify( $stale->wp_session_token ), 'the superseded visit login is destroyed' );
		$this->check( null === pow_native_cart_row( (string) $stale->wc_session_key ), 'the superseded visit basket row is deleted with it' );
		$this->check( 1 === $this->session_log_rows( $stale->id, 'session_expired', 'superseded' ), 'the supersede is audited against the visit it ended' );
		$this->check(
			POW\Sessions\Session::ACTIVE === ( pow_native_visit_row( $colleague->id )['status'] ?? '' ) && $tokens->verify( $colleague->wp_session_token ) && [ $this->products['beta'] ] === $this->stored_lines( 'b' ),
			'the colleague visit, login and basket are untouched by the supersede'
		);
		$this->check( $this->visits['a']->buyer_identity === $stale->buyer_identity && ! hash_equals( (string) $this->visits['a']->wc_session_key, (string) $stale->wc_session_key ), 'the replacement visit is the same buyer with a new basket' );
	}

	private function fill_replacement(): void {
		$this->enter( 'a' );
		$this->hydrate();
		$this->check( [] === WC()->cart->get_cart(), 'a fresh visit starts from an empty basket of its own' );
		$this->add_line( 'alpha' );
		WC()->session->set( 'chosen_shipping_methods', [ 'pow-a' => 'fixture:a' ] );
		$this->persist( 'a' );

		$this->check( [ $this->products['alpha'] ] === $this->stored_lines( 'a' ) && [ $this->products['beta'] ] === $this->stored_lines( 'b' ), 'the replacement visit basket and the colleague basket stay separate' );
		$this->check( $this->carts->owned_key( $this->key( 'a' ) ) && ! $this->carts->owned_key( $this->key( 'b' ) ), 'only this request own visit key names this request basket' );
	}

	/* ---------------------------------------------------------------------
	 * The three confirmed blockers of a shared login
	 * ------------------------------------------------------------------ */

	private function blockers(): void {
		$this->last_active_between_stage_and_commit();
		$this->colleague_redeem_between_stage_and_commit();
		$this->saved_cart_merge_flag();
	}

	/**
	 * WooCommerce's own `wc_last_active` write must not refuse a commit.
	 *
	 * wc_current_user_is_active() writes that row on the 'wp' action, which
	 * runs after wp_loaded — where staging happens — and before shutdown,
	 * where the commit happens. One buyer shopping alone therefore used to
	 * lose her basket to a fingerprint that read every user-meta row of the
	 * account.
	 */
	private function last_active_between_stage_and_commit(): void {
		$visit    = $this->fresh( 'a' );
		$prepared = $this->carts->prepare( $visit );
		update_user_meta( $this->account, 'wc_last_active', (string) time() );
		wp_cache_delete( $this->account, 'user_meta' );
		$committed = $this->registry->with_partner_lock( $visit->partner_id, fn(): bool => $this->carts->commit_locked( $visit, $prepared ) );

		$this->check( true === $committed, 'WooCommerce own wc_last_active write between a stage and its commit does not refuse that commit' );
	}

	/**
	 * A colleague's StartPage redeem must not refuse it either.
	 *
	 * The redeem writes the one shared `session_tokens` user-meta row of the
	 * account — every live visit's login lives in it — so a fingerprint over
	 * that row made one employee's punch-in discard another's basket.
	 */
	private function colleague_redeem_between_stage_and_commit(): void {
		$visit    = $this->fresh( 'a' );
		$prepared = $this->carts->prepare( $visit );
		$this->punch_in( 'c', 'carol-' . $this->run . '@example.invalid', 'Carol Buyer', '203.0.113.63' );
		// The redeem signed itself in and out again; this request is still A's.
		pow_native_enter_visit( $visit );
		$committed = $this->registry->with_partner_lock( $visit->partner_id, fn(): bool => $this->carts->commit_locked( $visit, $prepared ) );

		$this->check( true === $committed, 'a colleague StartPage redeem between a stage and its commit does not refuse that commit' );
		$this->check( WP_Session_Tokens::get_instance( $this->account )->verify( $visit->wp_session_token ), 'the colleague redeem leaves this visit login in the shared session_tokens row' );
		$this->check( [ $this->products['alpha'] ] === $this->stored_lines( 'a' ), 'and leaves this visit basket exactly as it was' );
	}

	/**
	 * The saved-cart merge flag is neither read, honoured nor deleted.
	 *
	 * wc_user_logged_in() sets it on every wp_login of the bound account, and
	 * WC_Cart_Session::get_cart_from_session() reads it and deletes it outside
	 * the persistent-cart guard. Without the two user-meta filters a visit
	 * would merge the account holder's own saved basket into its own and then
	 * delete a flag that is hers.
	 */
	private function saved_cart_merge_flag(): void {
		$meta_key = '_woocommerce_persistent_cart_' . get_current_blog_id();
		pow_native_enter_visit( $this->fresh( 'a' ) );

		$this->check( '' === get_user_meta( $this->account, self::MERGE_FLAG, true ) && [] === get_user_meta( $this->account, self::MERGE_FLAG, false ), 'the saved-cart merge flag reads as unset inside a visit' );
		$this->check( true === delete_user_meta( $this->account, self::MERGE_FLAG ) && '1' === $this->account_meta( self::MERGE_FLAG ), 'a visit deletion of that flag reports success and leaves the row alone' );
		$this->check( false === apply_filters( 'woocommerce_persistent_cart_enabled', true ), 'the persistent cart is off for the whole of a visit' );

		$this->hydrate();
		$this->persist( 'a' );
		$this->check( [ $this->products['alpha'] ] === $this->stored_lines( 'a' ), 'hydration inside a visit does not merge the shared saved basket into it' );
		$this->check( $this->saved_cart === $this->account_meta( $meta_key ) && '1' === $this->account_meta( self::MERGE_FLAG ), 'and writes nothing into the account own saved basket or flag' );

		pow_native_leave_visit( $this->admin );
		$this->check( '1' === get_user_meta( $this->account, self::MERGE_FLAG, true ) && true === (bool) apply_filters( 'woocommerce_persistent_cart_enabled', true ), 'outside every visit the account keeps its flag and its persistent cart' );
	}

	/* ---------------------------------------------------------------------
	 * Delivery review and the cart return
	 * ------------------------------------------------------------------ */

	/** One visit's own delivery consent, through the real review. */
	private function confirm_delivery( string $label ): void {
		$visit   = $this->fresh( $label );
		$partner = $this->registry->find( $visit->partner_id ) ?? throw new RuntimeException( 'Connection vanished.' );
		$view    = $this->confirmation->preview( $visit, $partner, [] );
		if ( $view instanceof WP_Error ) { throw new RuntimeException( 'Delivery review refused ' . $label . ': ' . $view->get_error_code() ); }
		if ( $view['error'] instanceof WP_Error ) { throw new RuntimeException( 'Delivery review refused ' . $label . ': ' . $view['error']->get_error_code() ); }
		$choice = $view['selected_choice'];
		$this->check( is_array( $choice ) && 'customer' === $choice['source'] && POW\Addresses\DeliveryData::fingerprint( $this->address ) === POW\Addresses\DeliveryData::fingerprint( $choice['address'] ), 'the review offers the bound account company address as visit ' . $label . ' destination' );
		$confirmed = $this->confirmation->confirm(
			$visit,
			$partner,
			[
				'provider'      => $choice['provider'],
				'key'           => $choice['key'],
				'notes'         => 'Notes from visit ' . $label,
				'review_digest' => $view['review_digest'],
				'acknowledge_unknown' => true,
			]
		);
		if ( $confirmed instanceof WP_Error ) { throw new RuntimeException( 'Delivery confirmation refused ' . $label . ': ' . $confirmed->get_error_code() ); }
		$stored = $this->fresh( $label )->delivery_confirmation();
		$this->check( is_array( $stored ) && $stored['session_id'] === $visit->id && 'Notes from visit ' . $label === $stored['notes'], 'visit ' . $label . ' consent is stored against its own visit row' );
	}

	/**
	 * The reviewed cart return, driven through the endpoint itself.
	 *
	 * @return int The Punchout Quote the return left behind.
	 */
	private function return_cart( string $label ): int {
		$visit                     = $this->fresh( $label );
		$post                      = $_POST;
		$server                    = $_SERVER;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = [ 'pow_nonce' => wp_create_nonce( 'pow_return' ), 'pow_mode' => 'cart' ];
		ob_start();
		try { $this->returns->handle(); $markup = (string) ob_get_contents(); }
		finally { ob_end_clean(); $_POST = $post; $_SERVER = $server; }
		$this->check( str_contains( $markup, 'https://buyer.example.test/return' ) && ! str_contains( $markup, 'expired' ), 'visit ' . $label . ' return renders the handoff form for its own BrowserFormPost URL' );
		$fresh = $this->fresh( $label );
		$this->check( POW\Sessions\Session::RETURNED === $fresh->status && $fresh->order_id > 0, 'visit ' . $label . ' return transitions that visit to returned and links its quote' );
		$this->quotes[ $label ] = $fresh->order_id;
		return $fresh->order_id;
	}

	private function first_return(): void {
		$colleague = $this->visits['b'];
		$this->enter( 'a' );
		$this->hydrate();
		$this->check( [ $this->products['alpha'] ] === array_map( static fn( array $line ): int => (int) $line['product_id'], array_values( WC()->cart->get_cart() ) ), 'the return starts from the visit own hydrated basket' );
		$this->confirm_delivery( 'a' );
		$stale = $this->visits['a'];
		$this->return_cart( 'a' );
		$tokens = WP_Session_Tokens::get_instance( $this->account );

		$this->check( ! $tokens->verify( $stale->wp_session_token ) && $tokens->verify( $colleague->wp_session_token ), 'the teardown destroys only the returning visit login' );
		$this->check( null === pow_native_cart_row( $this->key( 'a' ) ) && [ $this->products['beta'] ] === $this->stored_lines( 'b' ), 'and deletes only the returning visit basket row' );
		$this->check(
			POW\Sessions\Session::ACTIVE === ( pow_native_visit_row( $colleague->id )['status'] ?? '' ) && null === $this->sessions->find( $colleague->id )?->delivery_confirmation_json,
			'the colleague visit stays live and keeps its own unconfirmed delivery state'
		);
		pow_native_leave_visit( $this->admin );
	}

	private function second_return(): void {
		$this->enter( 'b' );
		$this->hydrate();
		$this->check( [ $this->products['beta'] ] === array_map( static fn( array $line ): int => (int) $line['product_id'], array_values( WC()->cart->get_cart() ) ), 'the colleague basket survived the other visit whole return' );
		$this->foreign_quote( 'b', $this->quotes['a'] );
		$this->confirm_delivery( 'b' );
		$this->return_cart( 'b' );
		pow_native_leave_visit( $this->admin );
	}

	/** A quote order made by another visit is not this visit's to open, anywhere. */
	private function foreign_quote( string $label, int $order ): void {
		foreach ( [ 'view-order', 'order-pay', 'order-received' ] as $endpoint ) {
			$this->endpoint( [ $endpoint => (string) $order ] );
			$this->check( $this->redirected( fn() => $this->guard->guard() ), 'a colleague quote order is refused at ' . $endpoint . ' inside visit ' . $label );
		}
		$this->endpoint( [] );
	}

	/* ---------------------------------------------------------------------
	 * What the two returns left behind
	 * ------------------------------------------------------------------ */

	private function quotes_attribution(): void {
		$quotes = new POW\Orders\QuoteOrder( $this->sessions, $this->audit, POW\Plugin::instance()->settings(), POW\Plugin::instance()->logger() );
		$this->check( $this->quotes['a'] > 0 && $this->quotes['b'] > 0 && $this->quotes['a'] !== $this->quotes['b'], 'the two visits return two separate Punchout Quotes' );
		foreach ( [ 'a' => 'Alice Buyer', 'b' => 'Bob Buyer' ] as $label => $name ) {
			$order = wc_get_order( $this->quotes[ $label ] );
			$visit = $this->visits[ $label ];
			$this->check( $order instanceof WC_Order && $order->get_customer_id() === $this->account, 'quote ' . $label . ' is owned by the bound account, like every other order of it' );
			$this->check(
				$this->identities[ $label ] === (string) $order->get_meta( POW\Orders\QuoteOrder::META_BUYER_IDENTITY ) && $name === (string) $order->get_meta( POW\Orders\QuoteOrder::META_BUYER_NAME ) && $visit->buyer_cookie === (string) $order->get_meta( POW\Orders\QuoteOrder::META_BUYER_COOKIE ),
				'quote ' . $label . ' carries the buyer identity, name and cookie of its own visit'
			);
			$this->check( (int) $order->get_meta( POW\Orders\QuoteOrder::META_SESSION_ID ) === $visit->id, 'quote ' . $label . ' is stamped with its own visit row' );
			$sentence = POW\Orders\QuoteOrder::bought_by( $name, $this->identities[ $label ], $this->partner->name );
			$this->check( $sentence === $quotes->bought_by_for( $order ), 'quote ' . $label . ' Bought by line names that buyer' );
			ob_start();
			$quotes->render_bought_by( $order );
			$rendered = (string) ob_get_clean();
			$this->check( str_contains( $rendered, esc_html( $sentence ) ), 'the order screen renders quote ' . $label . ' Bought by line' );
			$notes = wc_get_order_notes( [ 'order_id' => $this->quotes[ $label ], 'limit' => 20 ] );
			$found = false;
			foreach ( $notes as $note ) { if ( str_contains( (string) $note->content, $sentence ) ) { $found = true; } }
			$this->check( $found, 'and an order note records the same sentence on quote ' . $label );
			$other = $label === 'a' ? 'b' : 'a';
			$this->check( ! str_contains( (string) $order->get_meta( POW\Orders\QuoteOrder::META_BUYER_IDENTITY ), $this->identities[ $other ] ), 'quote ' . $label . ' names no other buyer' );
		}
	}

	/** The still-live third visit may read only its own quote order. */
	private function cross_visit_orders(): void {
		$carol = $this->fresh( 'c' );
		pow_native_enter_visit( $carol );
		foreach ( [ 'a', 'b' ] as $label ) { $this->foreign_quote( 'c', $this->quotes[ $label ] ); }
		$own = wc_create_order( [ 'customer_id' => $this->account, 'status' => POW\Orders\Status::SLUG ] );
		if ( ! $own instanceof WC_Order ) { throw new RuntimeException( 'Fixture order unavailable.' ); }
		$own->update_meta_data( POW\Orders\QuoteOrder::META_SESSION_ID, $carol->id );
		$own->update_meta_data( POW\Orders\QuoteOrder::META_PARTNER_ID, $this->partner->id );
		$own->save();
		$this->endpoint( [ 'view-order' => (string) $own->get_id() ] );
		$this->check( ! $this->redirected( fn() => $this->guard->guard() ), 'a visit may read the quote order stamped with its own visit' );
		$this->endpoint( [] );
		pow_native_leave_visit( $this->admin );
	}

	/* ---------------------------------------------------------------------
	 * The two setup refusals an operator will actually meet
	 * ------------------------------------------------------------------ */

	private function refusals(): void {
		// Its own bound account, not the two buyers': one account may own one
		// connection, and Registry::find_by_owner() refuses an ambiguous owner
		// rather than guessing — which would refuse the buyers' own traffic.
		$capped_account = pow_native_bound_account( 'two-buyer-capped' );
		$capped_secret  = wp_generate_password( 40, false, false );
		$capped         = $this->connection( 'capped', $capped_account, $capped_secret );
		$filler         = 0;
		while ( $this->sessions->count_open_for_partner( $capped->id ) < POW\Sessions\Store::MAX_OPEN_VISITS ) {
			$id = $this->sessions->create(
				[
					'partner_id'          => $capped->id,
					'user_id'             => $capped_account,
					'status'              => POW\Sessions\Session::ACTIVE,
					'payload_id'          => 'cap-' . $filler . '-' . $this->run,
					'body_hash'           => hash( 'sha256', 'cap-' . $filler . '-' . $this->run ),
					'one_time_token_hash' => hash( 'sha256', random_bytes( 32 ) ),
					'expires'             => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
				]
			);
			if ( $id <= 0 ) { throw new RuntimeException( 'Cap fixture row failed.' ); }
			++$filler;
		}
		$payload  = 'over-cap-' . $this->run;
		$response = $this->invoke_setup( $capped, $capped_secret, $payload, 'over-cap-' . $this->run . '@example.invalid', 'Over Cap', '203.0.113.64' );
		$this->check( 500 === $response['status'] && 1 === $this->log_rows( $capped->id, $payload, 'setup_visit_cap', 'cap' ), 'a punch-in over the connection open-visit cap is refused with cXML 500 and a setup_visit_cap row' );

		$unbound_secret = wp_generate_password( 40, false, false );
		$unbound        = $this->connection( 'unbound', 0, $unbound_secret );
		$payload        = 'unbound-' . $this->run;
		$response       = $this->invoke_setup( $unbound, $unbound_secret, $payload, 'unbound-' . $this->run . '@example.invalid', 'No Login', '203.0.113.65' );
		global $wpdb;
		$rows = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . POW\Installer::sessions_table() . ' WHERE partner_id = %d', $unbound->id ) );
		$this->check( 500 === $response['status'] && 1 === $this->log_rows( $unbound->id, $payload, 'setup_no_login', 'no_login' ) && 0 === $rows, 'a connection with no bound store account is refused with cXML 500, setup_no_login and no visit row' );
		$this->check( ! str_contains( $this->log_details( $unbound->id ), 'unbound-' . $this->run . '@example.invalid' ), 'the unbound refusal records no buyer e-mail' );
	}

	/**
	 * The account the two employees shopped as is still an ordinary customer.
	 *
	 * Nothing in the release may write plugin user meta on it: that absence is
	 * what makes the shared-login basket fingerprint viable, and it is also the
	 * promise that the plugin never touches a user.
	 */
	private function account_untouched(): void {
		global $wpdb;
		$plugin_meta = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->usermeta . ' WHERE user_id = %d AND meta_key LIKE %s', $this->account, $wpdb->esc_like( '_pow_' ) . '%' ) );
		$account     = get_userdata( $this->account );

		$this->check( 0 === $plugin_meta, 'the bound account carries no plugin user meta after two whole visits' );
		$this->check( $account && user_can( $account, 'read' ) && ! POW\Partners\Registry::privileged( $account ) && in_array( 'customer', (array) $account->roles, true ), 'and is still the ordinary customer account it was bound as' );
		$this->check( $this->saved_cart === $this->account_meta( '_woocommerce_persistent_cart_' . get_current_blog_id() ) && '1' === $this->account_meta( self::MERGE_FLAG ), 'its own saved basket and merge flag survived both visits byte for byte' );
		$this->check( null === pow_native_cart_row( (string) $this->account ), 'and no punchout visit ever wrote a basket row keyed on it' );
	}

	/* ------------------------------------------------------------------ */

	public function run(): void {
		$cookies  = $_COOKIE;
		$session  = WC()->session ?? null;
		$cart     = WC()->cart ?? null;
		$customer = WC()->customer ?? null;
		ob_start();
		try {
			$this->seed();
			$this->open_two_visits();
			$this->fill_two_baskets();
			$this->supersede();
			$this->fill_replacement();
			$this->blockers();
			$this->first_return();
			$this->second_return();
			$this->quotes_attribution();
			$this->cross_visit_orders();
			$this->refusals();
			$this->account_untouched();
		} finally {
			$this->endpoint( [] );
			pow_native_leave_visit( $this->admin );
			$_COOKIE       = $cookies;
			WC()->session  = $session;
			WC()->cart     = $cart;
			WC()->customer = $customer;
			$output        = ob_get_clean();
			if ( is_string( $output ) ) { echo $output; }
		}
		echo 'Passed: ' . $this->passed . ' Failed: ' . $this->failed . " Skipped: 0\n";
		echo 'Fixture: connection ' . $this->partner->id . ', account ' . $this->account . ', visits ' . implode( '/', array_map( static fn( POW\Sessions\Session $visit ): int => $visit->id, $this->visits ) ) . ', quotes ' . implode( '/', $this->quotes ) . "\n";
		if ( $this->failed > 0 ) { WP_CLI::halt( 1 ); }
	}
}

( new TwoBuyerNative() )->run();
