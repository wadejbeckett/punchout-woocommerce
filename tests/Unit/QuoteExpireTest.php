<?php
/**
 * Quote retention query, persistence failures and stale candidates.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Orders\QuoteOrder;
use POW\Orders\Status;

final class QuoteExpireTest extends TestCase {

	protected function setUp(): void {
		$this->reset();
	}

	protected function tearDown(): void {
		$this->reset();
	}

	private function reset(): void {
		QuoteOrderTestLog::$written = [];
		unset( $GLOBALS['pow_test_get_order_error'], $GLOBALS['pow_test_get_orders_error'], $GLOBALS['pow_test_current_user_id'] );
		$GLOBALS['pow_test_orders'] = [];
		$GLOBALS['pow_test_order_queries'] = [];
		$GLOBALS['pow_test_query_order_ids'] = [];
		$GLOBALS['pow_test_get_order_errors'] = [];
	}

	private function quotes( int $days = 90 ): QuoteOrder {
		return new QuoteOrder( new QuoteOrderTestStore(), new QuoteOrderTestLog(), new QuoteExpireTestSettings( $days ), new QuoteOrderTestLogger() );
	}

	private function order( int $id, string $status = Status::SLUG ): WC_Order {
		$order = new WC_Order( $id );
		$order->set_status( $status );
		$order->update_meta_data( QuoteOrder::META_SESSION_ID, 42 );
		$order->update_meta_data( QuoteOrder::META_PARTNER_ID, 23 );
		$order->update_meta_data( QuoteOrder::META_POOM_XML, '<cXML />' );
		$GLOBALS['pow_test_orders'][ $id ] = $order;
		$GLOBALS['pow_test_query_order_ids'][] = $id;
		return $order;
	}

	public function test_disabled_retention_does_not_query(): void {
		foreach ( [ 0, -1 ] as $days ) {
			self::assertSame( 0, $this->quotes( $days )->expire() );
		}
		self::assertSame( [], $GLOBALS['pow_test_order_queries'] );
		self::assertSame( [], QuoteOrderTestLog::$written );
	}

	public function test_empty_sweep_uses_bounded_oldest_first_utc_timestamp_query(): void {
		$before = time() - 90 * 86400;
		self::assertSame( 0, $this->quotes()->expire() );
		$after = time() - 90 * 86400;
		self::assertCount( 1, $GLOBALS['pow_test_order_queries'] );
		$args = $GLOBALS['pow_test_order_queries'][0];
		self::assertTrue( 1 === preg_match( '/^<\d+$/', $args['date_created'] ), 'Cutoff must be a Unix timestamp, not a date string' );
		$cutoff = (int) substr( $args['date_created'], 1 );
		self::assertTrue( $cutoff >= $before && $cutoff <= $after );
		unset( $args['date_created'] );
		self::assertSame( [ 'status' => Status::SLUG, 'limit' => 100, 'return' => 'ids', 'orderby' => 'date', 'order' => 'ASC' ], $args );
		self::assertSame( [], QuoteOrderTestLog::$written );
	}

	public function test_expiry_cancels_preserves_xml_and_audits_only_once(): void {
		$order = $this->order( 101 );
		self::assertSame( 1, $this->quotes( 1 )->expire() );
		self::assertSame( 'cancelled', $order->get_status() );
		self::assertCount( 1, $order->notes );
		self::assertTrue( str_contains( $order->notes[0], 'within 1 days' ) );
		self::assertSame( '<cXML />', $order->get_meta( QuoteOrder::META_POOM_XML ) );
		self::assertSame( [ [ 'quote_order_expired', [ 'order_id' => 101, 'session_id' => 42, 'partner_id' => 23, 'result' => 'retention' ] ] ], QuoteOrderTestLog::$written );
		// Even if a subsequent query returns the stale id again, it must not move it twice.
		self::assertSame( 0, $this->quotes()->expire() );
		self::assertCount( 1, $order->notes );
		self::assertCount( 1, QuoteOrderTestLog::$written );
	}

	public function test_missing_and_non_quote_candidates_are_never_cancelled(): void {
		$GLOBALS['pow_test_query_order_ids'][] = 999;
		$paid = $this->order( 102, 'processing' );
		$quote = $this->order( 103 );
		self::assertSame( 1, $this->quotes()->expire() );
		self::assertSame( 'processing', $paid->get_status() );
		self::assertSame( [], $paid->notes );
		self::assertSame( 'cancelled', $quote->get_status() );
		self::assertCount( 1, QuoteOrderTestLog::$written );
		self::assertSame( 103, QuoteOrderTestLog::$written[0][1]['order_id'] );
	}

	public function test_false_update_does_not_count_and_later_quote_expires(): void {
		$failed = $this->order( 104 );
		$failed->update_status_result = false;
		$later = $this->order( 105 );
		self::assertSame( 1, $this->quotes()->expire() );
		self::assertSame( Status::SLUG, $failed->get_status() );
		self::assertSame( [], $failed->notes );
		self::assertSame( 'cancelled', $later->get_status() );
		self::assertCount( 2, QuoteOrderTestLog::$written );
		self::assertSame( 'quote_order_expire_failed', QuoteOrderTestLog::$written[0][0] );
		self::assertSame( 'error', QuoteOrderTestLog::$written[0][1]['result'] );
		self::assertSame( 104, QuoteOrderTestLog::$written[0][1]['order_id'] );
		self::assertSame( 'quote_order_expired', QuoteOrderTestLog::$written[1][0] );
	}

	public function test_throwing_update_does_not_count_and_later_quote_expires(): void {
		foreach ( [ new RuntimeException( 'write failed' ), new Error( 'hook failed' ) ] as $error ) {
			$this->reset();
			$failed = $this->order( 106 );
			$failed->update_status_error = $error;
			$later = $this->order( 107 );
			self::assertSame( 1, $this->quotes()->expire() );
			self::assertSame( Status::SLUG, $failed->get_status() );
			self::assertSame( 'cancelled', $later->get_status() );
			self::assertCount( 2, QuoteOrderTestLog::$written );
			self::assertSame( 'quote_order_expire_failed', QuoteOrderTestLog::$written[0][0] );
			self::assertSame( 'error', QuoteOrderTestLog::$written[0][1]['result'] );
			self::assertSame( 'quote_order_expired', QuoteOrderTestLog::$written[1][0] );
		}
	}

	public function test_throwing_lookup_is_isolated_to_one_candidate(): void {
		$this->order( 108 );
		$GLOBALS['pow_test_get_order_errors'][108] = new Error( 'lookup failed' );
		$later = $this->order( 109 );
		self::assertSame( 1, $this->quotes()->expire() );
		self::assertSame( 'cancelled', $later->get_status() );
		self::assertCount( 2, QuoteOrderTestLog::$written );
		self::assertSame( 'quote_order_expire_failed', QuoteOrderTestLog::$written[0][0] );
		self::assertSame( 108, QuoteOrderTestLog::$written[0][1]['order_id'] );
		self::assertSame( 'quote_order_expired', QuoteOrderTestLog::$written[1][0] );
	}

	public function test_unavailable_failure_reporting_does_not_abort_later_candidates(): void {
		$failed = $this->order( 110 );
		$failed->update_status_result = false;
		$later = $this->order( 111 );
		$audit = new class extends \POW\Audit\Log {
			public function __construct() {}
			public function write( string $event, array $context = [] ): void {
				if ( 110 === ( $context['order_id'] ?? 0 ) ) {
					throw new Error( 'audit unavailable' );
				}
				QuoteOrderTestLog::$written[] = [ $event, $context ];
			}
		};
		$logger = new class extends \POW\Logger {
			public function __construct() {}
			public function error( string $message, array $context = [] ): void {
				throw new Error( 'logger unavailable' );
			}
		};
		$quotes = new QuoteOrder( new QuoteOrderTestStore(), $audit, new QuoteExpireTestSettings( 90 ), $logger );

		self::assertSame( 1, $quotes->expire() );
		self::assertSame( Status::SLUG, $failed->get_status() );
		self::assertSame( 'cancelled', $later->get_status() );
		self::assertCount( 1, QuoteOrderTestLog::$written );
		self::assertSame( 'quote_order_expired', QuoteOrderTestLog::$written[0][0] );
		self::assertSame( 111, QuoteOrderTestLog::$written[0][1]['order_id'] );
	}

	public function test_throwing_query_returns_zero_and_audits_failure(): void {
		foreach ( [ new RuntimeException( 'query failed' ), new Error( 'query hook failed' ) ] as $error ) {
			$this->reset();
			$GLOBALS['pow_test_get_orders_error'] = $error;
			self::assertSame( 0, $this->quotes()->expire() );
			self::assertCount( 1, QuoteOrderTestLog::$written );
			self::assertSame( 'quote_order_expire_failed', QuoteOrderTestLog::$written[0][0] );
			self::assertSame( 'error', QuoteOrderTestLog::$written[0][1]['result'] );
		}
	}
}

final class QuoteExpireTestSettings extends \POW\Settings {

	public function __construct( private int $days ) {}

	public function int( string $key ): int {
		return 'quote_retention_days' === $key ? $this->days : 0;
	}
}
