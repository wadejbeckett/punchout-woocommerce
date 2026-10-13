<?php
/**
 * Housekeeping of pending PunchOut orders (0.5.0 hardening): a pending order carrying a visit's
 * provenance whose visit is no longer active (returned, closed, expired, gone, or past its time) and older than the
 * handoff TTL is moved to the bin with no status change, so no e-mail is sent and nothing of the visit lingers as
 * an order WooCommerce would resume. A pending order of a live visit is WooCommerce's to resume.
 *
 * @package POW
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Checkout\Handoff;
use POW\Orders\QuoteOrder;
use POW\Sessions\Session;

/** Visit rows by id; a lookup a test makes fail. */
final class StalePendingStore extends POW\Sessions\Store {
	/** @var array<int, Session> */
	public array $rows = [];
	public ?Throwable $error = null;
	public function __construct() {}
	public function find( int $id ): ?Session {
		if ( null !== $this->error ) { throw $this->error; }
		return $this->rows[ $id ] ?? null;
	}
}

/** An order that records whether it was binned, and can refuse. */
final class StalePendingOrder extends WC_Order {
	public bool $trashed = false;
	public bool $forced  = false;
	public ?Throwable $error = null;
	public function delete( bool $force_delete = false ): bool {
		if ( null !== $this->error ) { throw $this->error; }
		$this->trashed = true;
		$this->forced  = $force_delete;
		return true;
	}
}

final class StalePendingOrderTest extends TestCase {
	private StalePendingStore $store;

	protected function setUp(): void {
		QuoteOrderTestLog::$written = [];
		unset( $GLOBALS['pow_test_get_orders_error'] );
		$GLOBALS['pow_test_orders']          = [];
		$GLOBALS['pow_test_order_queries']   = [];
		$GLOBALS['pow_test_query_order_ids'] = [];
		$GLOBALS['pow_test_get_order_errors'] = [];
		$this->store = new StalePendingStore();
	}

	protected function tearDown(): void {
		QuoteOrderTestLog::$written = [];
		unset( $GLOBALS['pow_test_orders'], $GLOBALS['pow_test_order_queries'], $GLOBALS['pow_test_query_order_ids'], $GLOBALS['pow_test_get_order_errors'], $GLOBALS['pow_test_get_orders_error'] );
	}

	private function quotes(): QuoteOrder {
		return new QuoteOrder( $this->store, new QuoteOrderTestLog(), new QuoteOrderTestSettings(), new QuoteOrderTestLogger() );
	}

	private function visit( int $id, string $status, int $expires_in = 3600 ): Session {
		return Session::from_row( [ 'id' => $id, 'partner_id' => 5, 'user_id' => 20, 'status' => $status, 'expires' => gmdate( 'Y-m-d H:i:s', time() + $expires_in ) ] );
	}

	private function order( int $id, ?int $session_id, string $status = 'pending' ): StalePendingOrder {
		$order = new StalePendingOrder( $id );
		$order->set_status( $status );
		$order->payment_method = 'punchout';
		if ( null !== $session_id ) {
			$order->update_meta_data( QuoteOrder::META_SESSION_ID, (string) $session_id );
			$order->update_meta_data( QuoteOrder::META_PARTNER_ID, '5' );
		}
		$GLOBALS['pow_test_orders'][ $id ]     = $order;
		$GLOBALS['pow_test_query_order_ids'][] = $id;
		return $order;
	}

	public function test_the_query_asks_for_pending_orders_with_a_visit_older_than_the_handoff_ttl(): void {
		$before = time() - Handoff::TTL;
		self::assertSame( 0, $this->quotes()->trash_stale_pending( Handoff::TTL ) );
		$after = time() - Handoff::TTL;
		self::assertCount( 1, $GLOBALS['pow_test_order_queries'] );
		$query = $GLOBALS['pow_test_order_queries'][0];
		self::assertSame( 'pending', $query['status'] );
		self::assertSame( QuoteOrder::META_SESSION_ID, $query['meta_key'] );
		self::assertSame( 'EXISTS', $query['meta_compare'] );
		self::assertSame( 'ids', $query['return'] );
		self::assertSame( 100, $query['limit'] );
		$cutoff = (int) substr( (string) $query['date_created'], 1 );
		self::assertTrue( str_starts_with( (string) $query['date_created'], '<' ) && $cutoff >= $before && $cutoff <= $after, (string) $query['date_created'] );
	}

	public function test_a_pending_order_whose_visit_is_over_is_binned_with_no_status_change_and_audited(): void {
		$this->store->rows = [ 42 => $this->visit( 42, 'returned' ), 43 => $this->visit( 43, 'closed' ), 44 => $this->visit( 44, 'expired' ), 45 => $this->visit( 45, 'active', -60 ) ];
		$orders = [ $this->order( 501, 42 ), $this->order( 502, 43 ), $this->order( 503, 44 ), $this->order( 504, 45 ), $this->order( 505, 99 ) ];
		self::assertSame( 5, $this->quotes()->trash_stale_pending( Handoff::TTL ) );
		foreach ( $orders as $order ) {
			self::assertTrue( $order->trashed, 'Order ' . $order->get_id() );
			self::assertFalse( $order->forced, 'The bin, never a permanent delete' );
			self::assertSame( 'pending', $order->get_status(), 'No status change, so no e-mail' );
			self::assertSame( [], $order->notes );
		}
		self::assertCount( 5, QuoteOrderTestLog::$written );
		[ $event, $context ] = QuoteOrderTestLog::$written[0];
		self::assertSame( 'pending_order_trashed', $event );
		self::assertSame( [ 501, 42, 5, 'stale' ], [ $context['order_id'], $context['session_id'], $context['partner_id'], $context['result'] ] );
		self::assertSame( 99, QuoteOrderTestLog::$written[4][1]['session_id'], 'A visit row that is gone leaves its order to the bin too' );
	}

	public function test_a_pending_order_of_a_live_visit_is_woocommerces_to_resume(): void {
		$this->store->rows = [ 42 => $this->visit( 42, 'active' ), 43 => $this->visit( 43, 'ordered' ) ];
		$live = $this->order( 501, 42 );
		$ordered = $this->order( 502, 43 );
		self::assertSame( 0, $this->quotes()->trash_stale_pending( Handoff::TTL ) );
		self::assertFalse( $live->trashed );
		self::assertFalse( $ordered->trashed );
		self::assertSame( [], QuoteOrderTestLog::$written );
	}

	public function test_an_order_that_is_not_pending_or_carries_no_visit_is_left_alone_whatever_the_query_returned(): void {
		$this->store->rows = [ 42 => $this->visit( 42, 'returned' ) ];
		$quote = $this->order( 501, 42, 'punchout-quote' );
		$plain = $this->order( 502, null );
		self::assertSame( 0, $this->quotes()->trash_stale_pending( Handoff::TTL ) );
		self::assertFalse( $quote->trashed );
		self::assertFalse( $plain->trashed );
	}

	public function test_a_visit_or_order_that_cannot_be_read_is_left_alone_and_the_rest_continues(): void {
		$this->store->rows = [ 42 => $this->visit( 42, 'returned' ), 43 => $this->visit( 43, 'returned' ) ];
		$first  = $this->order( 501, 42 );
		$second = $this->order( 502, 43 );
		$third  = $this->order( 503, 43 );
		$GLOBALS['pow_test_get_order_errors'][502] = new RuntimeException( 'Order lookup failed.' );
		$third->error = new RuntimeException( 'Bin failed.' );
		self::assertSame( 1, $this->quotes()->trash_stale_pending( Handoff::TTL ) );
		self::assertTrue( $first->trashed );
		self::assertFalse( $second->trashed );
		$events = array_column( QuoteOrderTestLog::$written, 0 );
		self::assertSame( [ 'pending_order_trashed', 'pending_order_trash_failed', 'pending_order_trash_failed' ], $events );
		$this->store->error = new RuntimeException( 'Session lookup failed.' );
		QuoteOrderTestLog::$written = [];
		$first->trashed = false;
		self::assertSame( 0, $this->quotes()->trash_stale_pending( Handoff::TTL ), 'A visit that cannot be read is not provably over' );
		self::assertFalse( $first->trashed );
	}

	public function test_a_failing_query_is_recorded_and_bins_nothing(): void {
		$GLOBALS['pow_test_get_orders_error'] = new RuntimeException( 'Query failed.' );
		self::assertSame( 0, $this->quotes()->trash_stale_pending( Handoff::TTL ) );
		self::assertSame( [ 'pending_order_sweep_failed' ], array_column( QuoteOrderTestLog::$written, 0 ) );
	}

	public function test_the_hourly_job_runs_the_sweep_at_the_handoff_ttl(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/Cron.php' );
		self::assertStringContainsString( 'trash_stale_pending( \POW\Checkout\Handoff::TTL )', $source );
	}
}
