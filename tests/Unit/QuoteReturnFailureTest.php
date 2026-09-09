<?php
/** Persistence and reporting failures must never turn an optional quote into a failed basket return.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );
use PHPUnit\Framework\TestCase;
use POW\Audit\Log;
use POW\Orders\QuoteOrder;
use POW\Partners\Partner;
use POW\Sessions\Session;
use POW\Sessions\Store;
require_once dirname( __DIR__ ) . '/Support/return-database.php';
final class QuoteReturnFailureTest extends TestCase {
	private array $saved = [];
	private ReturnDatabase $db;
	private QuoteOrder $quotes;
	protected function setUp(): void {
		foreach ( array_keys( $GLOBALS ) as $key ) { if ( str_starts_with( $key, 'pow_test_' ) || 'wpdb' === $key ) { $this->saved[$key] = $GLOBALS[$key]; unset( $GLOBALS[$key] ); } }
		$GLOBALS['wpdb'] = $this->db = new ReturnDatabase();
		$GLOBALS['pow_test_orders'] = []; $GLOBALS['pow_test_mail'] = [];
		$GLOBALS['pow_test_options']['admin_email'] = 'ops@example.test';
		$this->quotes = new QuoteOrder( new Store(), new Log( new QuoteOrderTestLogger() ), new QuoteOrderTestSettings(), new QuoteOrderTestLogger() );
	}
	protected function tearDown(): void {
		foreach ( array_keys( $GLOBALS ) as $key ) { if ( str_starts_with( $key, 'pow_test_' ) || 'wpdb' === $key ) { unset( $GLOBALS[$key] ); } }
		foreach ( $this->saved as $key => $value ) { $GLOBALS[$key] = $value; }
	}
	private function create(): int { return $this->quotes->create_for_session( Session::from_row( $this->db->session ), Partner::from_row( [ 'id' => 7, 'name' => 'Example buyer' ] ), [ 'currency' => 'ZAR', 'total_cents' => 0, 'items' => [] ] ); }
	public function test_zero_final_save_is_failure_and_never_linked(): void {
		$GLOBALS['pow_test_order_save'] = static fn( $order ) => 'cancelled' === $order->get_status() ? null : 0;
		self::assertSame( 0, $this->create() ); self::assertSame( 0, $this->db->session['order_id'] );
		self::assertSame( 'quote_order_failed', $this->db->audits[0]['event'] );
		self::assertSame( 'cancelled', array_values( $GLOBALS['pow_test_orders'] )[0]->get_status() );
	}
	public function test_false_and_throwing_link_leave_persisted_quote_correlated_and_audited(): void {
		foreach ( [ false, new RuntimeException( 'secret link error' ) ] as $failure ) {
			$this->db->link_result = $failure;
			$id = $this->create(); self::assertGreaterThan( 0, $id );
			self::assertSame( 'punchout-quote', wc_get_order( $id )->get_status() );
			self::assertSame( '42', wc_get_order( $id )->get_meta( QuoteOrder::META_SESSION_ID ) );
			$created = array_values( array_filter( $this->db->audits, static fn( $row ) => 'quote_order_created' === $row['event'] ) );
			self::assertSame( 'error', ( json_decode( end( $created )['detail'], true )['session_link'] ?? null ) );
		}
	}
	public function test_checked_audit_reports_false_insert_and_preserves_success_despite_logger_throw(): void {
		$log = new Log( new QuoteOrderTestLogger() ); $this->db->audit_result = false;
		self::assertFalse( $log->write_checked( 'return_sent', [ 'xml' => '<cXML/>' ] ) );
		$this->db->audit_result = true; $GLOBALS['pow_test_logger_error'] = new RuntimeException( 'logger failed' );
		self::assertTrue( $log->write_checked( 'return_sent', [ 'xml' => '<cXML/>' ] ) );
		self::assertCount( 1, $this->db->audits );
	}
	public function test_failed_audit_insert_is_reported_without_cancelling_quote(): void {
		$this->db->audit_result = false;
		$id = $this->create(); self::assertGreaterThan( 0, $id );
		self::assertSame( 'punchout-quote', wc_get_order( $id )->get_status() );
		self::assertSame( [], $this->db->audits ); self::assertTrue( [] !== ( $GLOBALS['pow_test_errors'] ?? [] ) );
	}
	public function test_logger_failure_after_audit_insert_keeps_good_quote(): void {
		$GLOBALS['pow_test_logger_error'] = new RuntimeException( 'logger failed' );
		$id = $this->create(); self::assertGreaterThan( 0, $id ); self::assertSame( 'punchout-quote', wc_get_order( $id )->get_status() );
		self::assertSame( 'quote_order_created', $this->db->audits[0]['event'] );
	}
	public function test_false_attachment_save_is_reported_without_cancelling_quote(): void {
		$id = $this->create(); $GLOBALS['pow_test_order_save'] = static fn() => 0;
		$this->quotes->attach_poom( $id, '<cXML>private basket</cXML>' );
		self::assertSame( 'quote_poom_failed', end( $this->db->audits )['event'] );
		self::assertSame( 'punchout-quote', wc_get_order( $id )->get_status() );
	}
	public function test_attachment_reporting_failures_are_contained(): void {
		$id = $this->create(); $GLOBALS['pow_test_order_save'] = static function() { throw new RuntimeException( 'private basket' ); };
		$GLOBALS['pow_test_logger_error'] = new RuntimeException( 'logger failed' ); $this->db->audit_error = new RuntimeException( 'audit failed' );
		$this->quotes->attach_poom( $id, '<cXML/>' ); self::assertSame( 'punchout-quote', wc_get_order( $id )->get_status() );
	}
	public function test_failure_reports_never_include_third_party_exception_document_or_secret(): void {
		$GLOBALS['pow_test_create_order_error'] = '<cXML><SharedSecret>private-secret</SharedSecret></cXML> private-document';
		self::assertSame( 0, $this->create() );
		$reported = json_encode( [ $this->db->audits, $GLOBALS['pow_test_mail'], $GLOBALS['pow_test_errors'] ?? [] ] );
		self::assertStringNotContainsString( 'private-secret', $reported ); self::assertStringNotContainsString( 'private-document', $reported );
	}
	public function test_cancellation_failure_is_not_reported_as_confirmed_cancelled(): void {
		$GLOBALS['pow_test_filters']['pow_quote_shipping_address'] = static function() { throw new RuntimeException( 'creation failed' ); };
		$GLOBALS['pow_test_order_save'] = static fn() => 0;
		self::assertSame( 0, $this->create() );
		self::assertStringNotContainsString( 'has been cancelled', $GLOBALS['pow_test_mail'][0]['message'] );
		self::assertStringContainsString( 'not confirmed', $GLOBALS['pow_test_mail'][0]['message'] );
	}
	public function test_throwing_note_does_not_prevent_cancelling_incomplete_order(): void {
		$GLOBALS['pow_test_filters']['pow_quote_shipping_address'] = static function() { throw new RuntimeException( 'creation failed' ); };
		$GLOBALS['pow_test_order_note'] = static function() { throw new RuntimeException( 'note failed' ); };
		self::assertSame( 0, $this->create() ); self::assertSame( 'cancelled', array_values( $GLOBALS['pow_test_orders'] )[0]->get_status() );
	}
	public function test_false_mail_does_not_claim_throttle_delivery_or_prevent_retry(): void {
		$GLOBALS['pow_test_create_order_error'] = 'creation failed'; $GLOBALS['pow_test_mail_result'] = false;
		self::assertSame( 0, $this->create() ); $GLOBALS['pow_test_mail_result'] = true;
		self::assertSame( 0, $this->create() ); self::assertCount( 2, $GLOBALS['pow_test_mail'] );
	}
	public function test_positive_save_id_without_persisted_quote_status_is_failure(): void {
		$GLOBALS['pow_test_order_save'] = static function( $order ) {
			if ( 'punchout-quote' === $order->get_status() ) {
				$persisted = clone $order; $persisted->set_status( 'pending' ); $GLOBALS['pow_test_orders'][$order->get_id()] = $persisted;
				return $order->get_id();
			}
			return null;
		};
		self::assertSame( 0, $this->create() ); self::assertSame( 0, $this->db->session['order_id'] );
	}
	public function test_positive_attachment_save_id_without_stored_xml_is_reported(): void {
		$id = $this->create(); $persisted = clone wc_get_order( $id );
		$GLOBALS['pow_test_order_save'] = static function( $order ) use ( $persisted ) { $GLOBALS['pow_test_orders'][$order->get_id()] = $persisted; return $order->get_id(); };
		$this->quotes->attach_poom( $id, '<cXML/>' ); self::assertSame( 'quote_poom_failed', end( $this->db->audits )['event'] );
	}
	public function test_rejected_product_bare_line_and_shipping_props_fail_creation(): void {
		foreach ( [ 'product', 'bare', 'shipping' ] as $boundary ) {
			unset( $GLOBALS['pow_test_add_product_result'], $GLOBALS['pow_test_add_item_result'], $GLOBALS['pow_test_props_error'] );
			$GLOBALS['pow_test_products'] = [];
			if ( 'product' === $boundary ) { $GLOBALS['pow_test_products'][412] = new WC_Product(412, 'Example', 'SKU'); $GLOBALS['pow_test_add_product_result'] = 0; }
			if ( 'bare' === $boundary ) { $GLOBALS['pow_test_add_item_result'] = false; }
			if ( 'shipping' === $boundary ) { $GLOBALS['pow_test_props_error'] = new WP_Error('bad', 'private detail'); $GLOBALS['pow_test_filters']['pow_quote_shipping_address'] = static fn() => [ 'address' => [ 'city' => 'Example' ], 'code' => '' ]; }
			$id = $this->quotes->create_for_session( Session::from_row( $this->db->session ), Partner::from_row( [ 'id' => 7 ] ), [ 'currency' => 'ZAR', 'items' => [ [ 'quantity' => 1, 'supplier_part_id' => 'SKU', 'aux_id' => '412|0', 'unit_price_cents' => 100, 'description' => 'Example' ] ] ] );
			self::assertSame( 0, $id, $boundary );
		}
	}

	public function test_conditional_link_distinguishes_all_outcomes_and_keeps_payexit_update(): void {
		$store = new Store();
		self::assertSame( 'linked', $store->link_quote_if_empty( 42, 1001 ) );
		self::assertSame( 'already_owned', $store->link_quote_if_empty( 42, 1002 ) );
		self::assertSame( 1001, $this->db->session['order_id'] );
		$store->update( 42, [ 'order_id' => 777 ] ); self::assertSame( 777, $this->db->session['order_id'] );
		$this->db->link_result = false; self::assertSame( 'error', $store->link_quote_if_empty( 42, 1003 ) );
		self::assertCount( 3, $this->db->queries );
	}
}
