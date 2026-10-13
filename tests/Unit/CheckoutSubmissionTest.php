<?php
/**
 * Submit at checkout, the order lifecycle (0.5.0): build first, then win the visit's return under its
 * lock, then each remaining step on its own (release checkout's stock and coupon holds, empty the cart, the Punchout
 * Quote status last), and always the handoff. A build that fails answers failure and leaves the order pending; the
 * loser of two racing Submits bins its own order and releases its holds; nothing is ever set to failed.
 *
 * @package POW
 */

declare( strict_types = 1 );

namespace {
	use PHPUnit\Framework\TestCase;
	use POW\Checkout\Handoff;
	use POW\Checkout\Submission;
	use POW\Sessions\Session;

	// WooCommerce's notice queue, shared with the route guard suite (whichever file loads first defines it).
	if ( ! function_exists( 'wc_add_notice' ) ) { function wc_add_notice( string $message, string $type = 'success', array $data = [] ): void { $GLOBALS['pow_route_guard_test']['notices'][] = [ $type, $message ]; } }

	/** The connection registry's database: one connection and the connection lock. */
	final class SubmissionDatabase {
		public string $prefix = 'wp_';
		public string $last_error = '';
		public function suppress_errors( bool $suppress = true ): bool { return false; }
		public function prepare( string $sql, mixed ...$args ): string { return $sql; }
		public function get_var( string $sql ): mixed {
			if ( str_contains( $sql, 'GET_LOCK(' ) || str_contains( $sql, 'RELEASE_LOCK(' ) ) { return '1'; }
			throw new LogicException( 'Unexpected query: ' . $sql );
		}
		public function get_row( string $sql, mixed $format = null ): ?array {
			if ( ! str_contains( $sql, 'pow_partners' ) ) { throw new LogicException( 'Unexpected query: ' . $sql ); }
			return [
				'id' => 5, 'name' => 'Example connection', 'status' => 'active', 'owner_user_id' => 20,
				'from_domain' => 'NetworkID', 'from_identity' => 'BUYER-TEST', 'sender_domain' => 'NetworkID', 'sender_identity' => 'BUYER-TEST',
				'to_domain' => 'DUNS', 'to_identity' => 'supplier-test', 'cxml_version' => '1.2.008', 'deployment_mode' => 'test', 'return_encoding' => 'base64',
				'allcaps_transform' => 0, 'emit_ship_to' => 1, 'emit_delivery_line' => 0, 'delivery_notes_policy' => 'item_detail_extrinsic',
			];
		}
	}

	/** The visit rows, as the lock re-reads them. `$on_find` changes a row as another request would have. */
	final class SubmissionStore extends POW\Sessions\Store {
		/** @var array<int, array<string, mixed>> */
		public array $rows = [];
		public ?Closure $on_find = null;
		public function __construct() {}
		public function find( int $id ): ?Session {
			if ( null !== $this->on_find ) { $change = $this->on_find; $this->on_find = null; $change( $this ); }
			return isset( $this->rows[ $id ] ) ? Session::from_row( $this->rows[ $id ] ) : null;
		}
		public function transition( int $id, string $from, string $to, array $extra = [], ?array $expected_guard = null ): bool {
			if ( ( $this->rows[ $id ]['status'] ?? null ) !== $from ) { return false; }
			$this->rows[ $id ] = array_replace( $this->rows[ $id ], [ 'status' => $to ], $extra );
			$GLOBALS['pow_test_steps'][] = 'win:' . $id;
			return true;
		}
		public function login_valid_checked( Session $session ): bool { return true; }
		public function destroy_login_checked( Session $session ): bool { $GLOBALS['pow_test_steps'][] = 'end_login:' . $session->id; return true; }
	}

	/** The audit trail: rows recorded; a test can make every write throw. */
	final class SubmissionLog extends POW\Audit\Log {
		/** @var list<string> */
		public array $events = [];
		public ?Throwable $error = null;
		public function __construct() {}
		public function write_checked( string $event, array $context = [] ): bool {
			if ( null !== $this->error ) { throw $this->error; }
			$this->events[] = $event;
			return true;
		}
	}

	/** One product line of the placed order. */
	final class SubmissionItem extends WC_Order_Item_Product {
		public function __construct( private WC_Product $product, private int $qty, private string $line_total ) {}
		public function get_product(): WC_Product { return $this->product; }
		public function get_quantity(): int { return $this->qty; }
		public function get_total(): string { return $this->line_total; }
		public function get_product_id(): int { return $this->product->get_id(); }
		public function get_variation_id(): int { return 0; }
		public function get_name(): string { return $this->product->get_name(); }
		public function get_meta( string $key, bool $single = true ): mixed { return ''; }
	}

	/** The order checkout placed: its lines, key and note; its status change and its bin are recorded as steps. */
	final class SubmissionOrder extends WC_Order {
		public bool $trashed = false;
		public ?Throwable $status_error = null;
		/** @param list<SubmissionItem> $lines */
		public function __construct( int $id, private array $lines, private string $key ) { parent::__construct( $id ); $this->payment_method = 'punchout'; }
		public function get_items( string|array $types = 'line_item' ): array { return 'line_item' === $types ? $this->lines : []; }
		public function get_currency(): string { return 'ZAR'; }
		public function get_address( string $type = 'billing' ): array { return []; }
		public function get_customer_note(): string { return 'Leave at the gate.'; }
		public function get_order_key(): string { return $this->key; }
		public function update_status( string $status, string $note = '' ): bool {
			$GLOBALS['pow_test_steps'][] = 'status:' . $status;
			if ( null !== $this->status_error ) { throw $this->status_error; }
			return parent::update_status( $status, $note );
		}
		public function delete( bool $force_delete = false ): bool {
			$GLOBALS['pow_test_steps'][] = ( $force_delete ? 'delete:' : 'trash:' ) . $this->get_id();
			$this->trashed = ! $force_delete;
			return true;
		}
	}

	/** The visit's cart. */
	final class SubmissionCart {
		public ?Throwable $error = null;
		public function empty_cart(): void {
			$GLOBALS['pow_test_steps'][] = 'empty_cart';
			if ( null !== $this->error ) { throw $this->error; }
		}
	}

	final class CheckoutSubmissionTest extends TestCase {
		private const GLOBALS = [ 'wpdb', 'pow_test_options', 'pow_test_filters', 'pow_test_current_user_id', 'pow_test_login_token', 'pow_test_transients', 'pow_test_orders', 'pow_test_steps', 'pow_test_release_errors', 'pow_test_cookies_cleared', 'pow_test_wc', 'pow_route_guard_test' ];
		private array $saved = [];
		private SubmissionStore $store;
		private SubmissionLog $log;
		private SubmissionCart $cart;
		private SubmissionOrder $order;

		protected function setUp(): void {
			foreach ( self::GLOBALS as $key ) {
				$this->saved[ $key ] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null ];
				unset( $GLOBALS[ $key ] );
			}
			$GLOBALS['wpdb'] = new SubmissionDatabase();
			$GLOBALS['pow_test_current_user_id'] = 20;
			$GLOBALS['pow_test_login_token']     = 'login-a';
			$GLOBALS['pow_test_steps']           = [];
			$this->store = new SubmissionStore();
			$this->store->rows[42] = $this->row();
			$this->log   = new SubmissionLog();
			$this->cart  = new SubmissionCart();
			WC()->cart   = $this->cart;
			$this->order = $this->place( 501, 'wc_order_k1' );
		}

		protected function tearDown(): void {
			foreach ( $this->saved as $key => [ $existed, $value ] ) {
				if ( $existed ) { $GLOBALS[ $key ] = $value; } else { unset( $GLOBALS[ $key ] ); }
			}
		}

		/** @return array<string, mixed> */
		private function row( array $overrides = [] ): array {
			return array_replace(
				[
					'id' => 42, 'partner_id' => 5, 'user_id' => 20, 'status' => 'active', 'wp_session_token' => 'login-a', 'order_id' => 0,
					'wc_session_key' => 'pow_1a2b3c4d5e6f708192a3b4c5d6e7', 'buyer_cookie' => '{COOKIE-1}', 'buyer_identity' => 'buyer@example.test', 'buyer_name' => 'Example Buyer',
					'browser_form_post_url' => 'https://buyer.example.com/punchout/return', 'deployment_mode' => 'test', 'cxml_version' => '1.2.008',
					'expires' => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
				],
				$overrides
			);
		}

		private function place( int $id, string $key ): SubmissionOrder {
			$order = new SubmissionOrder( $id, [ new SubmissionItem( new WC_Product( 11, 'Widget', 'W-1' ), 2, '22.00' ) ], $key );
			$GLOBALS['pow_test_orders'][ $id ] = $order;
			return $order;
		}

		private function submission(): Submission {
			$plugin = ( new ReflectionClass( POW\Plugin::class ) )->newInstanceWithoutConstructor();
			( new ReflectionProperty( $plugin, 'settings' ) )->setValue( $plugin, new POW\Settings() );
			( new ReflectionProperty( $plugin, 'session_resolved' ) )->setValue( $plugin, true );
			( new ReflectionProperty( $plugin, 'current_session' ) )->setValue( $plugin, Session::from_row( $this->row() ) );
			$registry = new POW\Partners\Registry( new POW\Partners\Secrets( str_repeat( 'f', 32 ) ) );
			return new Submission( $plugin, $registry, $this->store, new POW\Cxml\Builder(), $this->log, new POW\Settings() );
		}

		/** @return list<string> */
		private function steps(): array { return $GLOBALS['pow_test_steps'] ?? []; }

		/** @return list<string> */
		private function notices(): array { return array_column( $GLOBALS['pow_route_guard_test']['notices'] ?? [], 1 ); }

		public function test_a_won_submit_releases_the_holds_empties_the_cart_and_sets_the_quote_status_last(): void {
			$answer = $this->submission()->submit( 501 );

			self::assertSame( [ 'result' => 'success', 'redirect' => Handoff::url( 501, 'wc_order_k1' ) ], $answer );
			self::assertSame( [ 'win:42', 'end_login:42', 'release_stock:501', 'release_coupons:501', 'empty_cart', 'status:punchout-quote' ], $this->steps(), 'Win first, then each step, the status change last' );
			self::assertSame( 'punchout-quote', $this->order->get_status() );
			self::assertSame( 'returned', $this->store->rows[42]['status'] );
			self::assertSame( 501, $this->store->rows[42]['order_id'] );
			self::assertNotSame( '', (string) $this->order->get_meta( POW\Orders\QuoteOrder::META_POOM_XML ), 'Stamped with the cXML copy before the win' );
			self::assertNotNull( Handoff::take( 'login-a', 'wc_order_k1' ), 'The handoff page is kept for this browser' );
		}

		public function test_the_answer_never_clears_the_login_cookie(): void {
			$this->submission()->submit( 501 );
			self::assertSame( [], $GLOBALS['pow_test_cookies_cleared'] ?? [], 'The handoff, keyed to that cookie, clears it' );
		}

		public function test_each_step_after_the_win_runs_on_its_own_and_none_stops_the_handoff(): void {
			$GLOBALS['pow_test_release_errors'] = [ 'stock' => new RuntimeException( 'stock' ), 'coupons' => new TypeError( 'coupons' ) ];
			$this->cart->error          = new RuntimeException( 'cart' );
			$this->order->status_error  = new Error( 'status' );
			$this->log->error           = new RuntimeException( 'audit' );

			$answer = $this->submission()->submit( 501 );

			self::assertSame( [ 'result' => 'success', 'redirect' => Handoff::url( 501, 'wc_order_k1' ) ], $answer, 'The visit has returned: the buyer always gets the handoff' );
			self::assertSame( [ 'win:42', 'end_login:42', 'release_stock:501', 'release_coupons:501', 'empty_cart', 'status:punchout-quote' ], $this->steps(), 'Every step was still tried' );
			self::assertNotSame( 'failed', $this->order->get_status() );
		}

		public function test_a_build_that_fails_answers_failure_and_leaves_the_order_pending_and_the_visit_open(): void {
			$this->order->save_throws = true; // The provenance stamp cannot be saved.

			$answer = $this->submission()->submit( 501 );

			self::assertSame( [ 'result' => 'failure' ], $answer );
			self::assertSame( 'pending', $this->order->get_status(), 'Never failed: WooCommerce resumes the same pending order on the next Submit' );
			self::assertFalse( $this->order->trashed );
			self::assertSame( 'active', $this->store->rows[42]['status'], 'The visit stays open' );
			self::assertSame( [], $this->steps(), 'Nothing was won, released or changed' );
			self::assertNull( Handoff::take( 'login-a', 'wc_order_k1' ) );
			self::assertCount( 1, $this->notices(), 'One plain message' );
		}

		public function test_the_loser_of_two_racing_submits_bins_its_own_order_releases_its_holds_and_reaches_sent(): void {
			// The other Submit of this visit won while this one was being built.
			$this->place( 777, 'wc_order_winner' );
			$this->store->on_find = fn( SubmissionStore $store ) => $store->rows[42] = $this->row( [ 'status' => 'returned', 'order_id' => 777 ] );

			$answer = $this->submission()->submit( 501 );

			self::assertSame( [ 'result' => 'success', 'redirect' => Handoff::sent_url() ], $answer, 'Its cart was sent by the other Submit' );
			self::assertSame( [ 'release_stock:501', 'release_coupons:501', 'trash:501' ], $this->steps(), 'Holds released, then its own order binned' );
			self::assertTrue( $this->order->trashed );
			self::assertSame( 'pending', $this->order->get_status(), 'No status change, so no e-mail' );
			self::assertSame( 777, $this->store->rows[42]['order_id'], 'The winner keeps the visit' );
			self::assertNull( Handoff::take( 'login-a', 'wc_order_k1' ), 'Nothing kept for the loser' );
			self::assertFalse( in_array( 'return_sent', $this->log->events, true ), 'The loser reports no return' );
			self::assertSame( [], $this->notices() );
		}

		public function test_the_loser_goes_to_the_winners_handoff_while_that_page_is_still_kept(): void {
			$this->place( 777, 'wc_order_winner' );
			Handoff::remember( 'login-a', 'wc_order_winner', 42, 777, '<form id="pow-handoff-form"></form>' );
			$this->store->on_find = fn( SubmissionStore $store ) => $store->rows[42] = $this->row( [ 'status' => 'returned', 'order_id' => 777 ] );

			$answer = $this->submission()->submit( 501 );

			self::assertSame( [ 'result' => 'success', 'redirect' => Handoff::url( 777, 'wc_order_winner' ) ], $answer, 'The winning answer may never reach a browser; its page is served once, to whichever asks first' );
			self::assertTrue( $this->order->trashed );
		}

		public function test_a_loser_resuming_the_winning_order_bins_nothing_and_releases_its_holds_again(): void {
			// WooCommerce handed both Submits the same pending order (order_awaiting_payment), and the other one won.
			$this->store->on_find = fn( SubmissionStore $store ) => $store->rows[42] = $this->row( [ 'status' => 'returned', 'order_id' => 501 ] );

			$answer = $this->submission()->submit( 501 );

			self::assertSame( 'success', $answer['result'] );
			self::assertFalse( $this->order->trashed, 'That order is the Quote the visit sent' );
			self::assertSame( [ 'release_stock:501', 'release_coupons:501' ], $this->steps(), 'Resuming it took new holds on the Quote, so they are released again' );
		}

		public function test_a_visit_that_ended_without_a_return_answers_failure_and_keeps_the_order(): void {
			$this->store->on_find = fn( SubmissionStore $store ) => $store->rows[42] = $this->row( [ 'status' => 'expired' ] );

			$answer = $this->submission()->submit( 501 );

			self::assertSame( [ 'result' => 'failure' ], $answer );
			self::assertFalse( $this->order->trashed );
			self::assertSame( 'pending', $this->order->get_status() );
			self::assertCount( 1, $this->notices() );
		}
	}
}
