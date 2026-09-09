<?php
/** Real return endpoint, mapper, builder, quote service and Store against recorded WP/Woo/DB boundaries.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );
use PHPUnit\Framework\TestCase;
use POW\Audit\Log;
use POW\Cart\PoomMapper;
use POW\Cxml\Builder;
use POW\Http\ReturnEndpoint;
use POW\Orders\QuoteOrder;
use POW\Partners\Registry;
use POW\Partners\Secrets;
use POW\Sessions\Store;
require_once dirname( __DIR__ ) . '/Support/return-database.php';

final class ReturnIntegrationTest extends TestCase {
	private ReturnDatabase $db;
	private ReturnEndpoint $endpoint;
	private array $saved = [];
	private array $post;
	private array $server;
	protected function setUp(): void {
		if ( ! extension_loaded( 'dom' ) ) { self::markTestSkipped( 'ext-dom required for handoff XML' ); }
		foreach ( array_keys( $GLOBALS ) as $key ) {
			if ( str_starts_with( $key, 'pow_test_' ) || 'wpdb' === $key ) { $this->saved[$key] = $GLOBALS[$key]; unset( $GLOBALS[$key] ); }
		}
		$this->post = $_POST; $this->server = $_SERVER;
		$GLOBALS['wpdb'] = $this->db = new ReturnDatabase();
		$this->db->session['expires'] = gmdate( 'Y-m-d H:i:s', time() + 3600 );
		$GLOBALS['pow_test_session_tokens'][99]['test-login'] = [ 'expiration' => time() + 3600 ];
		$GLOBALS['pow_test_current_user_id'] = 99;
		$GLOBALS['pow_test_roles'] = [ POW\Installer::ROLE ];
		$GLOBALS['pow_test_valid_nonce'] = 'valid';
		$GLOBALS['pow_test_login_token'] = 'test-login';
		$GLOBALS['pow_test_orders'] = [];
		$GLOBALS['pow_test_mail'] = [];
		$GLOBALS['pow_test_setup_io'] = [ 'headers' => [] ];
		$GLOBALS['pow_test_wc'] = new POW_Test_WC();
		$GLOBALS['pow_test_wc']->cart = new class {
			public function get_cart(): array { return []; }
			public function empty_cart( bool $persistent ): void { $GLOBALS['pow_test_cart_emptied'][] = true; }
		};
		$GLOBALS['pow_test_filters']['pow_poom_lines'] = static fn( $value ) => [ 'currency' => 'ZAR', 'total_cents' => 37533, 'skipped' => [], 'items' => [ [ 'quantity' => 3, 'supplier_part_id' => 'SKU-1001', 'aux_id' => '412|0', 'unit_price_cents' => 12511, 'description' => 'Example item', 'uom' => 'EA', 'classification' => '' ] ] ];
		if ( ! defined( 'POW\VERSION' ) ) { define( 'POW\VERSION', 'test' ); }
		$settings = new QuoteOrderTestSettings(); $logger = new QuoteOrderTestLogger();
		$store = new Store(); $audit = new Log( $logger );
		$this->endpoint = new ReturnEndpoint( $store, new Registry( new Secrets( str_repeat( 'x', 32 ) ) ), new PoomMapper( $settings, $logger ), new Builder(), $audit, new QuoteOrder( $store, $audit, $settings, $logger ) );
		$_POST = [ 'pow_nonce' => 'valid' ]; $_SERVER['REQUEST_METHOD'] = 'POST';
	}
	protected function tearDown(): void {
		foreach ( array_keys( $GLOBALS ) as $key ) { if ( str_starts_with( $key, 'pow_test_' ) || 'wpdb' === $key ) { unset( $GLOBALS[$key] ); } }
		foreach ( $this->saved as $key => $value ) { $GLOBALS[$key] = $value; }
		$_POST = $this->post; $_SERVER = $this->server;
	}
	private function response(): string {
		$level = ob_get_level(); ob_start();
		try { $this->endpoint->handle(); return (string) ob_get_contents(); }
		finally { while ( ob_get_level() > $level ) { ob_end_clean(); } }
	}
	public function test_two_snapshot_contenders_create_only_the_handoff_winners_quote(): void {
		$other = ''; $ran = false;
		// Both read active/0. B completes after A mapped but before A attempts its transition.
		$mapping = $GLOBALS['pow_test_filters']['pow_poom_lines'];
		$GLOBALS['pow_test_filters']['pow_poom_lines'] = function( $value ) use ( &$other, &$ran, $mapping ) {
			$mapped = $mapping( $value );
			if ( ! $ran ) { $ran = true; $other = $this->response(); }
			return $mapped;
		};
		$first = $this->response();
		self::assertCount( 1, $GLOBALS['pow_test_orders'] );
		self::assertStringContainsString( 'expired', $first );
		self::assertStringContainsString( 'pow-handoff-form', $other );
		self::assertSame( 'returned', $this->db->session['status'] );
		$order = array_values( $GLOBALS['pow_test_orders'] )[0];
		preg_match( '/name="cxml-base64" value="([^"]+)"/', $other, $m );
		self::assertSame( base64_decode( html_entity_decode( $m[1] ), true ), $order->get_meta( QuoteOrder::META_POOM_XML ) );
		self::assertSame( 375.33, $order->items[0]['total'] );
		self::assertSame( $order->get_id(), $this->db->session['order_id'] );
		self::assertCount( 1, $GLOBALS['pow_test_destroyed_tokens'] );
	}
	public function test_template_throw_does_not_consume_session_or_create_quote(): void {
		$GLOBALS['pow_test_filters']['pow_handoff_copy'] = static function() { throw new RuntimeException( 'template failed' ); };
		try { $this->response(); } catch ( RuntimeException $e ) { self::assertSame( 'template failed', $e->getMessage() ); }
		self::assertSame( 'active', $this->db->session['status'] ); self::assertSame( [], $GLOBALS['pow_test_orders'] );
	}
	public function test_partner_disabled_after_mapping_cannot_return_a_stale_snapshot(): void {
		$GLOBALS['pow_test_filters']['pow_handoff_copy'] = function ( $copy ) { $this->db->partner_status = 'disabled'; return $copy; };
		$this->response();
		self::assertSame( [], $GLOBALS['pow_test_orders'] );
		self::assertSame( 'active', $this->db->session['status'] );
	}
	public function test_missing_template_does_not_consume_session_or_create_quote(): void {
		$GLOBALS['pow_test_filters']['pow_template_handoff'] = static fn() => '/missing-handoff-template.php';
		$this->response();
		self::assertSame( 'active', $this->db->session['status'] ); self::assertSame( [], $GLOBALS['pow_test_orders'] );
	}
	public function test_builder_throw_does_not_consume_session_or_create_quote(): void {
		$mapping = $GLOBALS['pow_test_filters']['pow_poom_lines'];
		$GLOBALS['pow_test_filters']['pow_poom_lines'] = static function( $value ) use ( $mapping ) { $mapped = $mapping( $value ); $mapped['items'][0]['classification'] = new stdClass(); return $mapped; };
		try { $this->response(); } catch ( TypeError $e ) { /* The real Builder rejects the malformed mapped total. */ }
		self::assertSame( 'active', $this->db->session['status'] ); self::assertSame( [], $GLOBALS['pow_test_orders'] );
	}
	public function test_checkout_link_after_snapshot_is_preserved(): void {
		$GLOBALS['pow_test_filters']['pow_quote_shipping_address'] = function( $address ) { (new Store())->update( 42, [ 'order_id' => 777 ] ); return $address; };
		self::assertStringContainsString( 'pow-handoff-form', $this->response() );
		self::assertSame( 777, $this->db->session['order_id'] );
		$order = array_values( $GLOBALS['pow_test_orders'] )[0]; self::assertSame( '42', $order->get_meta( QuoteOrder::META_SESSION_ID ) );
	}
	public function test_failed_transition_creates_no_quote_and_no_handoff(): void {
		$this->db->transition_fails = true;
		self::assertStringContainsString( 'expired', $this->response() );
		self::assertSame( [], $GLOBALS['pow_test_orders'] );
	}
	public function test_diagnostic_throw_does_not_cancel_good_quote_or_block_handoff(): void {
		$this->db->audit_error = new RuntimeException( 'private diagnostic detail' );
		self::assertStringContainsString( 'pow-handoff-form', $this->response() );
		$order = array_values( $GLOBALS['pow_test_orders'] )[0]; self::assertSame( 'punchout-quote', $order->get_status() );
		self::assertStringContainsString( '<cXML', $order->get_meta( QuoteOrder::META_POOM_XML ) );
		self::assertCount( 1, $GLOBALS['pow_test_destroyed_tokens'] );
	}
	public function test_quote_failure_and_all_reporting_failures_still_handoff(): void {
		$GLOBALS['pow_test_create_order_error'] = 'private failure';
		$GLOBALS['pow_test_logger_error'] = new RuntimeException( 'logger failed' );
		$GLOBALS['pow_test_options']['admin_email'] = 'ops@example.test';
		$GLOBALS['pow_test_mail_error'] = new RuntimeException( 'mail failed' );
		$this->db->audit_error = new RuntimeException( 'audit failed' );
		self::assertStringContainsString( 'pow-handoff-form', $this->response() );
		self::assertSame( [], $GLOBALS['pow_test_orders'] ); self::assertSame( 'returned', $this->db->session['status'] );
	}
	public function test_empty_and_paid_closeout_create_no_quote(): void {
		$_POST['pow_mode'] = 'empty';
		foreach ( [ 'active', 'ordered' ] as $status ) {
			$this->db->session['status'] = $status;
			$this->db->session['order_id'] = 'ordered' === $status ? 777 : 0;
			if ( 'ordered' === $status ) { $GLOBALS['pow_test_orders'][777] = new WC_Order(777); }
			$GLOBALS['pow_test_session_tokens'][99]['test-login'] = [ 'expiration' => time() + 3600 ];
			$response = $this->response(); self::assertStringContainsString( 'pow-handoff-form', $response );
			self::assertSame( 'closed', $this->db->session['status'] );
		}
		self::assertCount( 1, $GLOBALS['pow_test_orders'] ); self::assertSame( 'pending', $GLOBALS['pow_test_orders'][777]->get_status() );
	}
	public function test_invalid_nonce_and_login_never_transition_or_create(): void {
		foreach ( [ 'nonce', 'token', 'role' ] as $failure ) {
			$_POST['pow_nonce'] = 'nonce' === $failure ? 'bad' : 'valid';
			$GLOBALS['pow_test_login_token'] = 'token' === $failure ? 'wrong-login' : 'test-login';
			$GLOBALS['pow_test_roles'] = 'role' === $failure ? [] : [ POW\Installer::ROLE ];
			self::assertStringNotContainsString( 'pow-handoff-form', $this->response() );
		}
		self::assertSame( [], $this->db->queries ); self::assertSame( [], $GLOBALS['pow_test_orders'] );
	}
}
