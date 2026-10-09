<?php
/**
 * The order -> cXML mapper (0.5.0): what the buyer's purchasing system receives is built from the order WooCommerce
 * checkout placed, through the unchanged builder. Lines, prices, ShipTo, the buyer's note, no freight, and the
 * envelope (identities reversed, the visit's cookie and mode).
 *
 * @package POW
 */

declare( strict_types = 1 );

namespace {
	require_once dirname( __DIR__ ) . '/Support/ExactCxmlDtd.php';

	use PHPUnit\Framework\TestCase;
	use POW\Checkout\OrderMessage;
	use POW\Partners\Partner;
	use POW\Sessions\Session;

	/** A placed order's product line, as much of WC_Order_Item_Product as the mapper reads. */
	final class OrderMessageItem extends WC_Order_Item_Product {
		/** @param array<string, string> $item_meta */
		public function __construct( private ?WC_Product $product, private int $qty, private string $line_total, private int $product_id, private int $variation_id = 0, private array $item_meta = [] ) {}
		public function get_product(): ?WC_Product { return $this->product; }
		public function get_quantity(): int { return $this->qty; }
		public function get_total(): string { return $this->line_total; }
		public function get_product_id(): int { return $this->product_id; }
		public function get_variation_id(): int { return $this->variation_id; }
		public function get_name(): string { return $this->product ? $this->product->get_name() : 'Removed product'; }
		public function get_meta( string $key, bool $single = true ): mixed { return $this->item_meta[ $key ] ?? ''; }
	}

	/** A variation: its attributes as WooCommerce reports them ('' = "any", chosen on the line). */
	final class OrderMessageVariation extends WC_Product {
		/** @param array<string, string> $attributes */
		public function __construct( int $id, string $name, string $sku, private array $attributes ) { parent::__construct( $id, $name, $sku ); }
		/** @return array<string, string> */
		public function get_variation_attributes(): array { return $this->attributes; }
	}

	/** A placed order: its lines, currency, shipping address and the buyer's note. */
	final class OrderMessageOrder extends WC_Order {
		/** @param list<OrderMessageItem> $lines @param array<string, string> $shipping */
		public function __construct( private array $lines, private array $shipping = [], private string $note = '', private string $order_currency = 'ZAR', private string $shipping_total = '0.00' ) { parent::__construct( 501 ); }
		/** @return array<int, OrderMessageItem> */
		public function get_items( string|array $types = 'line_item' ): array { return 'line_item' === $types ? $this->lines : []; }
		public function get_currency(): string { return $this->order_currency; }
		/** @return array<string, string> */
		public function get_address( string $type = 'billing' ): array { return 'shipping' === $type ? $this->shipping : []; }
		public function get_customer_note(): string { return $this->note; }
		public function get_shipping_total(): string { return $this->shipping_total; }
	}

	final class OrderMessageTest extends TestCase {

		private const SHIPPING = [ 'first_name' => 'Receiving', 'last_name' => 'Desk', 'company' => 'Buyer Co', 'address_1' => '1 Example Street', 'address_2' => '', 'city' => 'Example City', 'state' => 'GP', 'postcode' => '2001', 'country' => 'ZA', 'phone' => '' ];

		private array $saved = [];

		protected function setUp(): void {
			foreach ( [ 'pow_test_filters', 'pow_test_attribute_terms' ] as $key ) {
				$this->saved[ $key ] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null ];
				unset( $GLOBALS[ $key ] );
			}
		}

		protected function tearDown(): void {
			foreach ( $this->saved as $key => [ $existed, $value ] ) {
				if ( $existed ) { $GLOBALS[ $key ] = $value; } else { unset( $GLOBALS[ $key ] ); }
			}
		}

		/** @param array<string, mixed> $overrides */
		private function partner( array $overrides = [] ): Partner {
			return Partner::from_row(
				$overrides + [
					'id' => 5, 'name' => 'Example connection', 'status' => 'active',
					'from_domain' => 'NetworkID', 'from_identity' => 'BUYER-TEST', 'sender_domain' => 'NetworkID', 'sender_identity' => 'BUYER-TEST',
					'to_domain' => 'DUNS', 'to_identity' => 'supplier-test', 'cxml_version' => '1.2.008', 'deployment_mode' => 'test', 'return_encoding' => 'base64',
					'allcaps_transform' => 0, 'owner_user_id' => 20, 'emit_ship_to' => 1, 'emit_delivery_line' => 0, 'delivery_notes_policy' => 'item_detail_extrinsic',
				]
			);
		}

		private function visit(): Session {
			return Session::from_row( [ 'id' => 42, 'partner_id' => 5, 'user_id' => 20, 'buyer_cookie' => '{COOKIE-1}', 'browser_form_post_url' => 'https://buyer.example.com/punchout/return', 'status' => 'active', 'deployment_mode' => 'test', 'cxml_version' => '1.2.008' ] );
		}

		private function order( string $note = '', array $shipping = self::SHIPPING ): OrderMessageOrder {
			return new OrderMessageOrder(
				[
					new OrderMessageItem( new WC_Product( 101, 'Alpha', 'E2E-ALPHA' ), 2, '22.00', 101 ),
					new OrderMessageItem( new WC_Product( 102, 'Beta', 'E2E-BETA' ), 3, '70.50', 102 ),
				],
				$shipping,
				$note,
				'ZAR',
				'50.00'
			);
		}

		/** @return array<string, mixed> */
		private function args( ?WC_Order $order = null, ?Partner $partner = null ): array {
			return OrderMessage::builder_args( $order ?? $this->order(), $partner ?? $this->partner(), $this->visit(), 'UNSPSC-1', 'shop.example.test' )[0];
		}

		public function test_each_line_carries_the_orders_sku_quantity_and_ex_tax_unit_price(): void {
			$items = $this->args()['items'];
			self::assertCount( 2, $items );
			self::assertSame( [ 'E2E-ALPHA', 2.0, '101|0', 1100 ], [ $items[0]['supplier_part_id'], (float) $items[0]['quantity'], $items[0]['aux_id'], $items[0]['unit_price_cents'] ] );
			self::assertSame( [ 'E2E-BETA', 3.0, '102|0', 2350 ], [ $items[1]['supplier_part_id'], (float) $items[1]['quantity'], $items[1]['aux_id'], $items[1]['unit_price_cents'] ] );
			foreach ( $items as $item ) {
				self::assertSame( 'EA', $item['uom'] );
				self::assertSame( 'UNSPSC-1', $item['classification'] );
				self::assertSame( $item['description'], $item['short_name'], 'ShortName is the name' );
			}
			self::assertSame( [ 'Alpha', 'Beta' ], [ $items[0]['description'], $items[1]['description'] ] );
		}

		public function test_the_unit_price_is_the_discounted_line_total_over_the_quantity_and_the_total_sums_what_was_sent(): void {
			// 100.00 over 3 is 33.33 each; the Total is the sum of what the lines say, never the raw line total.
			$order = new OrderMessageOrder( [ new OrderMessageItem( new WC_Product( 7, 'Gamma', 'G-1' ), 3, '100.00', 7 ) ] );
			$args  = $this->args( $order );
			self::assertSame( 3333, $args['items'][0]['unit_price_cents'] );
			self::assertSame( 9999, $args['total_cents'] );
			self::assertSame( 'ZAR', $args['currency'] );
		}

		public function test_no_freight_line_and_the_total_is_merchandise_only_even_when_the_order_carries_shipping(): void {
			$args = $this->args();
			self::assertSame( 22.00 * 100 + 70.50 * 100, (float) $args['total_cents'] );
			foreach ( $args['items'] as $item ) { self::assertNotSame( 'freight', $item['line_type'] ?? 'merchandise' ); }
			self::assertFalse( in_array( 'DELIVERY', array_column( $args['items'], 'supplier_part_id' ), true ) );
		}

		public function test_a_line_without_a_sku_is_left_out_and_named(): void {
			$order = new OrderMessageOrder( [ new OrderMessageItem( new WC_Product( 9, 'Loose item', '' ), 1, '5.00', 9 ), new OrderMessageItem( new WC_Product( 101, 'Alpha', 'E2E-ALPHA' ), 1, '11.00', 101 ) ] );
			[ $args, $skipped ] = OrderMessage::builder_args( $order, $this->partner(), $this->visit(), '', 'shop.example.test' );
			self::assertSame( [ 'E2E-ALPHA' ], array_column( $args['items'], 'supplier_part_id' ) );
			self::assertSame( [ 'Loose item' ], $skipped );
			self::assertSame( 1100, $args['total_cents'] );
		}

		public function test_an_all_caps_connection_gets_capitals_on_the_line_text_only(): void {
			$order = new OrderMessageOrder( [ new OrderMessageItem( new WC_Product( 101, 'Alpha crate', 'e2e-alpha' ), 1, '11.00', 101 ) ] );
			$item  = $this->args( $order, $this->partner( [ 'allcaps_transform' => 1 ] ) )['items'][0];
			self::assertSame( [ 'E2E-ALPHA', 'ALPHA CRATE', 'ALPHA CRATE', '101|0' ], [ $item['supplier_part_id'], $item['description'], $item['short_name'], $item['aux_id'] ] );
		}

		public function test_a_variation_line_names_the_attributes_the_buyer_chose(): void {
			$GLOBALS['pow_test_attribute_terms'] = [ 'pa_size' => [ 'large' => 'Large' ] ];
			$variation = new OrderMessageVariation( 205, 'Shirt', 'SHIRT-L', [ 'attribute_pa_size' => 'large', 'attribute_colour' => '' ] );
			$order = new OrderMessageOrder( [ new OrderMessageItem( $variation, 1, '15.00', 200, 205, [ 'colour' => 'Blue' ] ) ] );
			$item  = $this->args( $order )['items'][0];
			self::assertSame( 'Shirt - Large, Blue', $item['description'] );
			self::assertSame( '200|205', $item['aux_id'] );
		}

		public function test_ship_to_is_the_orders_shipping_address_while_the_connection_sends_it(): void {
			$args = $this->args();
			self::assertTrue( $args['emit_ship_to'] );
			self::assertSame( 'Buyer Co', $args['ship_to']['name'] );
			self::assertSame( [ 'Receiving Desk' ], $args['ship_to']['deliver_to'] );
			self::assertSame( [ '1 Example Street' ], $args['ship_to']['street'] );
			self::assertSame( [ 'Example City', 'GP', '2001', 'ZA' ], [ $args['ship_to']['city'], $args['ship_to']['state'], $args['ship_to']['postal_code'], $args['ship_to']['iso_country'] ] );
			self::assertFalse( $this->args( null, $this->partner( [ 'emit_ship_to' => 0 ] ) )['emit_ship_to'] );
		}

		public function test_an_order_without_a_usable_shipping_address_sends_no_ship_to(): void {
			self::assertNull( $this->args( $this->order( '', [] ) )['ship_to'] );
			self::assertNull( $this->args( $this->order( '', [ 'address_1' => '' ] + self::SHIPPING ) )['ship_to'], 'No street, no ShipTo' );
		}

		public function test_the_buyers_note_goes_to_the_delivery_instructions_cleaned_and_capped(): void {
			$args = $this->args( $this->order( "Leave at the gate.\x01\x07 Ring first." ) );
			self::assertSame( 'Leave at the gate. Ring first.', $args['delivery_notes'] );
			self::assertSame( 'item_detail_extrinsic', $args['delivery_notes_policy'] );
			self::assertSame( 2000, mb_strlen( $this->args( $this->order( str_repeat( 'é', 2500 ) ) )['delivery_notes'] ) );
			self::assertSame( '', $this->args( $this->order( '' ) )['delivery_notes'] );
		}

		public function test_the_envelope_is_reversed_identity_only_with_the_visits_cookie_and_mode(): void {
			$args = $this->args();
			self::assertSame( [ 'domain' => 'DUNS', 'identity' => 'supplier-test' ], $args['from'] );
			self::assertSame( [ 'domain' => 'NetworkID', 'identity' => 'BUYER-TEST' ], $args['to'] );
			self::assertSame( $args['from'], $args['sender'] );
			self::assertSame( '{COOKIE-1}', $args['buyer_cookie'] );
			self::assertSame( 'test', $args['deployment_mode'] );
			self::assertSame( '1.2.008', $args['version'] );
			self::assertSame( 'create', $args['operation_allowed'] );
			self::assertNull( $args['supplier_order_info'] );
			self::assertTrue( str_ends_with( $args['payload_id'], '@shop.example.test' ) );
			self::assertFalse( array_key_exists( 'shared_secret', $args ) );
		}

		public function test_the_unchanged_builder_turns_it_into_a_valid_message(): void {
			if ( ! extension_loaded( 'dom' ) ) { self::markTestSkipped( 'ext-dom is required.' ); }
			$xml    = ( new POW\Cxml\Builder() )->poom( $this->args( $this->order( 'Leave at the gate.' ) ) );
			$result = ExactCxmlDtd::validate( $xml );
			self::assertTrue( $result['valid'], implode( '; ', $result['errors'] ) );
			$doc = new DOMDocument();
			$doc->loadXML( $xml );
			$path = new DOMXPath( $doc );
			self::assertSame( '92.50', $path->evaluate( 'string(//PunchOutOrderMessageHeader/Total/Money)' ) );
			self::assertSame( 2.0, $path->evaluate( 'count(//ItemIn)' ) );
			self::assertSame( 'Leave at the gate.', $path->evaluate( 'string(//ItemIn[1]/ItemDetail/Extrinsic[@name="DeliveryInstructions"])' ) );
			self::assertSame( 0.0, $path->evaluate( 'count(//SharedSecret)' ) );
		}
	}
}
