<?php
/**
 * The order -> cXML mapper (0.5.0): what the buyer's purchasing system receives is built from the order WooCommerce
 * checkout placed, through the unchanged builder. Lines, prices, ShipTo, the buyer's note, no freight, and the
 * envelope (identities reversed, the visit's cookie and mode). 0.5.0 adds what the connection screen sets
 * (schema 10): the line description (the name, or the name and its cleaned short description), the "Order details
 * sent" after the note in DeliveryInstructions (text, or a Unix timestamp as its UTC day at any server timezone),
 * and the freight line only while "Send delivery cost" is on.
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

	/** A product with the short description the shop wrote, and the parent a variation reads it from. */
	final class OrderMessageDescribedProduct extends WC_Product {
		public function __construct( int $id, string $name, string $sku, private string $short = '', private int $parent = 0 ) { parent::__construct( $id, $name, $sku ); }
		public function get_short_description(): string { return $this->short; }
		public function get_parent_id(): int { return $this->parent; }
	}

	/** A variation with no short description of its own, as WooCommerce has it. */
	final class OrderMessageDescribedVariation extends WC_Product {
		/** @param array<string, string> $attributes */
		public function __construct( int $id, string $name, string $sku, private int $parent, private array $attributes ) { parent::__construct( $id, $name, $sku ); }
		public function get_short_description(): string { return ''; }
		public function get_parent_id(): int { return $this->parent; }
		/** @return array<string, string> */
		public function get_variation_attributes(): array { return $this->attributes; }
	}

	/** The order's shipping line, as much of WC_Order_Item_Shipping as the mapper reads. */
	final class OrderMessageShipping {
		public function __construct( private string $method_title ) {}
		public function get_name(): string { return $this->method_title; }
	}

	/** A placed order: its lines, currency, shipping address and the buyer's note. */
	final class OrderMessageOrder extends WC_Order {
		/** @param list<OrderMessageItem> $lines @param array<string, string> $shipping @param list<OrderMessageShipping> $shipping_lines */
		public function __construct( private array $lines, private array $shipping = [], private string $note = '', private string $order_currency = 'ZAR', private string $shipping_total = '0.00', private array $shipping_lines = [] ) { parent::__construct( 501 ); }
		/** @return array<int, OrderMessageShipping> */
		public function get_shipping_methods(): array { return $this->shipping_lines; }
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
			foreach ( [ 'pow_test_filters', 'pow_test_attribute_terms', 'pow_test_products' ] as $key ) {
				$this->saved[ $key ] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null ];
				unset( $GLOBALS[ $key ] );
			}
			$this->timezone = date_default_timezone_get();
		}

		protected function tearDown(): void {
			foreach ( $this->saved as $key => [ $existed, $value ] ) {
				if ( $existed ) { $GLOBALS[ $key ] = $value; } else { unset( $GLOBALS[ $key ] ); }
			}
			date_default_timezone_set( $this->timezone );
		}

		private string $timezone = 'UTC';

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
		private function args( ?WC_Order $order = null, ?Partner $partner = null, string $estimate_note = '' ): array {
			return OrderMessage::builder_args( $order ?? $this->order(), $partner ?? $this->partner(), $this->visit(), 'UNSPSC-1', 'shop.example.test', $estimate_note )[0];
		}

		/** One line whose product has $short as its short description. */
		private function described( string $short, string $name = 'Alpha' ): OrderMessageOrder {
			return new OrderMessageOrder( [ new OrderMessageItem( new OrderMessageDescribedProduct( 101, $name, 'E2E-ALPHA', $short ), 2, '22.00', 101 ) ], self::SHIPPING, 'Leave at the gate.' );
		}

		/** @param list<array{meta_key: string, label: string, format: string}> $details */
		private function with_details( array $details, array $overrides = [] ): Partner {
			return $this->partner( $overrides + [ 'order_details' => json_encode( $details ) ] );
		}

		/** The text of the DeliveryInstructions extrinsic on every merchandise line of the built message. */
		private function instructions_on_the_wire( array $args ): array {
			$doc = new DOMDocument();
			$doc->loadXML( ( new POW\Cxml\Builder() )->poom( $args ) );
			$found = [];
			foreach ( ( new DOMXPath( $doc ) )->query( '//ItemIn/ItemDetail/Extrinsic[@name="DeliveryInstructions"]' ) as $node ) { $found[] = $node->textContent; }
			return $found;
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

		public function test_a_name_stored_with_html_entities_reaches_the_wire_as_plain_text(): void {
			// WordPress stores a term name "Black & White" as "Black &amp; White" (and a title saved without
			// unfiltered_html the same way); the DOM would escape it again and the buyer's line would read "&amp;".
			$GLOBALS['pow_test_attribute_terms'] = [ 'pa_colour' => [ 'bw' => 'Black &amp; White' ] ];
			$variation = new OrderMessageVariation( 206, 'Tom &amp; Co&#8217;s shirt', 'SHIRT-BW', [ 'attribute_pa_colour' => 'bw' ] );
			$order = new OrderMessageOrder( [ new OrderMessageItem( $variation, 1, '15.00', 200, 206 ) ] );
			$item  = $this->args( $order )['items'][0];
			self::assertSame( 'Tom & Co’s shirt - Black & White', $item['short_name'] );
			self::assertSame( $item['short_name'], $item['description'] );
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

		// ------------------------------------------------------------------ 0.5.0: the line description

		public function test_by_default_each_line_is_described_by_its_name_whatever_its_short_description_says(): void {
			$item = $this->args( $this->described( '<p>Brand Example, red</p>' ) )['items'][0];
			self::assertSame( [ 'Alpha', 'Alpha' ], [ $item['description'], $item['short_name'] ] );
		}

		public function test_name_and_short_description_sends_the_short_description_as_plain_text_after_the_name(): void {
			$partner = $this->partner( [ 'line_description' => 'name_short_description' ] );
			$item    = $this->args( $this->described( "<p>Brand&nbsp;<strong>Example</strong> &amp; Co</p>\n<ul><li>Red,\t\t600&#8239;ml</li><li>Crate &quot;24&quot;</li></ul>" ), $partner )['items'][0];
			self::assertSame( 'Alpha - Brand Example & Co Red, 600 ml Crate "24"', $item['description'], 'Tags stripped, entities decoded, spaces collapsed, list items kept apart' );
			self::assertSame( 'Alpha', $item['short_name'], 'ShortName is always the name' );
		}

		public function test_a_short_description_that_is_empty_or_only_repeats_the_name_is_left_out(): void {
			$partner = $this->partner( [ 'line_description' => 'name_short_description' ] );
			foreach ( [ '', '<p> &nbsp; </p>', '  ALPHA ', '<p>alpha</p>' ] as $short ) {
				self::assertSame( 'Alpha', $this->args( $this->described( $short ), $partner )['items'][0]['description'], json_encode( $short ) );
			}
		}

		public function test_the_description_is_capped_by_shortening_only_the_short_description(): void {
			$partner = $this->partner( [ 'line_description' => 'name_short_description' ] );
			self::assertSame( 2000, OrderMessage::DESCRIPTION_LIMIT );
			$long = $this->args( $this->described( str_repeat( 'é', 3000 ) ), $partner )['items'][0]['description'];
			self::assertSame( 2000, mb_strlen( $long ) );
			self::assertSame( 'Alpha - éé', mb_substr( $long, 0, 10 ) );
			self::assertSame( '…', mb_substr( $long, -1 ) );

			$GLOBALS['pow_test_filters']['punchout_line_description_limit'] = static fn(): int => 20;
			self::assertSame( 'Alpha - Brand Examp…', $this->args( $this->described( 'Brand Example detail' ), $partner )['items'][0]['description'] );
			$name = str_repeat( 'N', 30 );
			self::assertSame( $name, $this->args( $this->described( 'Detail', $name ), $partner )['items'][0]['description'], 'The name itself is never cut' );
			$GLOBALS['pow_test_filters']['punchout_line_description_limit'] = static fn(): int => 5000;
			self::assertSame( 2000, mb_strlen( $this->args( $this->described( str_repeat( 'x', 3000 ) ), $partner )['items'][0]['description'] ), 'A filter can lower the cap, never raise it' );
		}

		public function test_a_variation_takes_its_parents_short_description(): void {
			$GLOBALS['pow_test_attribute_terms'] = [ 'pa_size' => [ 'large' => 'Large' ] ];
			$GLOBALS['pow_test_products'][200]   = new OrderMessageDescribedProduct( 200, 'Shirt', 'SHIRT', '<p>Cotton</p>' );
			$variation = new OrderMessageDescribedVariation( 205, 'Shirt', 'SHIRT-L', 200, [ 'attribute_pa_size' => 'large', 'attribute_colour' => '' ] );
			$order = new OrderMessageOrder( [ new OrderMessageItem( $variation, 1, '15.00', 200, 205, [ 'colour' => 'Blue' ] ) ] );
			$item  = $this->args( $order, $this->partner( [ 'line_description' => 'name_short_description' ] ) )['items'][0];
			self::assertSame( 'Shirt - Large, Blue - Cotton', $item['description'] );
			self::assertSame( 'Shirt - Large, Blue', $item['short_name'] );
		}

		public function test_an_all_caps_connection_gets_the_short_description_in_capitals_too(): void {
			$item = $this->args( $this->described( 'Brand example' ), $this->partner( [ 'line_description' => 'name_short_description', 'allcaps_transform' => 1 ] ) )['items'][0];
			self::assertSame( [ 'ALPHA - BRAND EXAMPLE', 'ALPHA' ], [ $item['description'], $item['short_name'] ] );
		}

		// ------------------------------------------------------- 0.5.0: DeliveryInstructions and order details

		/**
		 * The builder flattens every line break in element text to a space (Dynamics breaks on newlines), so the
		 * parts are joined with a visible " | " that survives onto the wire.
		 */
		public function test_the_order_details_follow_the_note_as_label_and_value_parts(): void {
			$order = $this->order( 'Leave at the gate.' );
			$order->meta = [ '_delivery_ts' => '1791936000', '_file' => 'spec sheet.pdf' ];
			$partner = $this->with_details( [ [ 'meta_key' => '_delivery_ts', 'label' => 'Preferred delivery date', 'format' => 'unix_date' ], [ 'meta_key' => '_file', 'label' => 'Attachment', 'format' => 'text' ] ] );
			self::assertSame( "Leave at the gate. | Preferred delivery date: 2026-10-14 | Attachment: spec sheet.pdf", $this->args( $order, $partner )['delivery_notes'] );

			$order = $this->order( '' );
			$order->meta = [ '_delivery_ts' => 1791936000 ];
			self::assertSame( 'Preferred delivery date: 2026-10-14', $this->args( $order, $partner )['delivery_notes'], 'Without a note the details stand alone, and a missing one is left out' );
		}

		public function test_a_unix_date_is_its_utc_calendar_day_at_every_server_timezone(): void {
			$partner = $this->with_details( [ [ 'meta_key' => '_delivery_ts', 'label' => 'Preferred delivery date', 'format' => 'unix_date' ] ] );
			// Midnight UTC is what a date plugin's mktime() stores under WordPress, which runs PHP in UTC; the last
			// second of that day is the other edge.
			foreach ( [ 'UTC', 'Africa/Johannesburg', 'America/Los_Angeles', 'Pacific/Pago_Pago', 'Pacific/Kiritimati', 'Asia/Kolkata', 'Europe/London' ] as $zone ) {
				date_default_timezone_set( $zone );
				foreach ( [ 1791936000, 1792022399 ] as $stamp ) {
					$order = $this->order( '' );
					$order->meta = [ '_delivery_ts' => (string) $stamp ];
					self::assertSame( 'Preferred delivery date: 2026-10-14', $this->args( $order, $partner )['delivery_notes'], $zone . ' ' . $stamp );
				}
			}
			date_default_timezone_set( 'America/Los_Angeles' );
			self::assertSame( '2026-10-13', date( 'Y-m-d', 1791936000 ), 'The server-local day would be the day before west of UTC' );
		}

		public function test_an_order_detail_without_a_usable_value_is_left_out(): void {
			$partner = $this->with_details( [ [ 'meta_key' => '_text', 'label' => 'Text', 'format' => 'text' ], [ 'meta_key' => '_date', 'label' => 'Date', 'format' => 'unix_date' ] ] );
			foreach ( [ [ '_text' => '', '_date' => '' ], [ '_text' => [ 'a' ], '_date' => 'tomorrow' ], [ '_text' => '  ', '_date' => '0' ], [ '_text' => true, '_date' => '-86400' ], [ '_text' => null, '_date' => '1791936000.5' ], [ '_text' => new stdClass(), '_date' => [ 1791936000 ] ] ] as $meta ) {
				$order = $this->order( 'Note.' );
				$order->meta = $meta;
				self::assertSame( 'Note.', $this->args( $order, $partner )['delivery_notes'], json_encode( $meta ) );
			}
			$order = $this->order( 'Note.' );
			$order->meta = [ '_text' => 42, '_date' => 1791936000 ];
			self::assertSame( "Note. | Text: 42 | Date: 2026-10-14", $this->args( $order, $partner )['delivery_notes'], 'A number is text' );
		}

		public function test_a_text_value_is_plain_text_on_one_line_and_capped(): void {
			$partner = $this->with_details( [ [ 'meta_key' => '_file', 'label' => 'Attachment', 'format' => 'text' ] ] );
			$order = $this->order( '' );
			$order->meta = [ '_file' => "<b>spec</b>\n sheet &amp; co\x07.pdf" ];
			self::assertSame( 'Attachment: spec sheet & co.pdf', $this->args( $order, $partner )['delivery_notes'] );
			$order->meta = [ '_file' => str_repeat( 'x', 700 ) ];
			$line = $this->args( $order, $partner )['delivery_notes'];
			self::assertSame( OrderMessage::DETAIL_LIMIT, mb_strlen( $line ) - mb_strlen( 'Attachment: ' ) );
			self::assertSame( '…', mb_substr( $line, -1 ) );
		}

		public function test_the_instructions_fit_the_builders_limit_by_shortening_only_the_note(): void {
			$partner = $this->with_details( [ [ 'meta_key' => '_delivery_ts', 'label' => 'Preferred delivery date', 'format' => 'unix_date' ] ] );
			$order = $this->order( str_repeat( 'n', 2000 ) );
			$order->meta = [ '_delivery_ts' => '1791936000' ];
			$notes = $this->args( $order, $partner )['delivery_notes'];
			self::assertSame( OrderMessage::NOTE_LIMIT, mb_strlen( $notes ) );
			self::assertTrue( str_ends_with( $notes, "n | Preferred delivery date: 2026-10-14" ), 'The details are kept whole' );
			if ( extension_loaded( 'dom' ) ) {
				self::assertSame( [ $notes, $notes ], $this->instructions_on_the_wire( $this->args( $order, $partner ) ), 'The builder takes it on every merchandise line' );
			}
		}

		public function test_a_filter_can_extend_the_order_details_and_its_entries_are_checked(): void {
			$order = $this->order( 'Note.' );
			$order->meta = [ '_po' => 'PO-7', '_bad' => 'x' ];
			$GLOBALS['pow_test_filters']['punchout_order_details_sent'] = static function ( array $entries, $filtered_order, $filtered_partner ) use ( $order ): array {
				self::assertSame( $order, $filtered_order );
				self::assertSame( 5, $filtered_partner->id );
				return array_merge( $entries, [ [ 'meta_key' => '_po', 'label' => 'Order reference', 'format' => 'text' ], [ 'meta_key' => '_bad', 'label' => 'Bad', 'format' => 'html' ], 'junk' ] );
			};
			self::assertSame( "Note. | Order reference: PO-7", $this->args( $order )['delivery_notes'] );
		}

		// --------------------------------------------------------------- 0.5.0: freight and ShipTo on the wire

		public function test_with_send_delivery_cost_off_there_is_no_freight_line_and_no_estimate_sentence(): void {
			$order = new OrderMessageOrder( $this->order()->get_items(), self::SHIPPING, 'Note.', 'ZAR', '50.00', [ new OrderMessageShipping( 'Courier' ) ] );
			$args  = $this->args( $order, $this->partner( [ 'emit_delivery_line' => 0 ] ), 'The delivery charge is an estimate.' );
			self::assertSame( [ 'E2E-ALPHA', 'E2E-BETA' ], array_column( $args['items'], 'supplier_part_id' ) );
			self::assertSame( 9250, $args['total_cents'] );
			self::assertSame( 'Note.', $args['delivery_notes'] );
		}

		public function test_with_send_delivery_cost_on_the_orders_shipping_total_is_one_freight_line(): void {
			$order   = new OrderMessageOrder( $this->order()->get_items(), self::SHIPPING, 'Note.', 'ZAR', '50.00', [ new OrderMessageShipping( 'Courier <em>(2&ndash;3 days)</em>' ), new OrderMessageShipping( 'Local pickup' ) ] );
			$partner = $this->partner( [ 'emit_delivery_line' => 1, 'freight_supplier_part_id' => 'FREIGHT-1', 'freight_uom' => 'LOT', 'freight_classification_domain' => 'supplier', 'freight_classification' => 'carriage' ] );
			$args    = $this->args( $order, $partner, 'The delivery charge is an estimate.' );
			self::assertCount( 3, $args['items'] );
			self::assertSame(
				[ 'line_type' => 'freight', 'quantity' => 1, 'supplier_part_id' => 'FREIGHT-1', 'aux_id' => '', 'unit_price_cents' => 5000, 'description' => 'Courier (2–3 days); Local pickup — The delivery charge is an estimate.', 'uom' => 'LOT', 'classification_domain' => 'supplier', 'classification' => 'carriage' ],
				$args['items'][2]
			);
			self::assertSame( 9250 + 5000, $args['total_cents'] );
			self::assertSame( "The delivery charge is an estimate. | Note.", $args['delivery_notes'], 'The estimate sentence travels only with the freight line' );
			if ( extension_loaded( 'dom' ) ) {
				$xml = ( new POW\Cxml\Builder() )->poom( $args );
				self::assertTrue( ExactCxmlDtd::validate( $xml )['valid'] );
				$path = new DOMXPath( ( static function ( string $xml ): DOMDocument { $doc = new DOMDocument(); $doc->loadXML( $xml ); return $doc; } )( $xml ) );
				self::assertSame( '142.50', $path->evaluate( 'string(//PunchOutOrderMessageHeader/Total/Money)' ) );
				self::assertSame( 0.0, $path->evaluate( 'count(//ItemIn[ItemID/SupplierPartID="FREIGHT-1"]/ItemDetail/Extrinsic)' ), 'No instructions on the freight line' );
			}
		}

		public function test_a_freight_line_needs_a_shipping_line_and_follows_capitals(): void {
			$on = $this->partner( [ 'emit_delivery_line' => 1, 'allcaps_transform' => 1 ] );
			self::assertCount( 2, $this->args( new OrderMessageOrder( $this->order()->get_items(), self::SHIPPING, '', 'ZAR', '0.00' ), $on )['items'], 'No shipping line, no freight' );
			$priced = $this->args( new OrderMessageOrder( $this->order()->get_items(), self::SHIPPING, '', 'ZAR', '12.00', [ new OrderMessageShipping( 'Courier' ) ] ), $on )['items'][2];
			self::assertSame( [ 1200, 'DELIVERY' ], [ $priced['unit_price_cents'], $priced['supplier_part_id'] ] );
			self::assertStringStartsWith( 'COURIER', $priced['description'], 'In capitals for an all-caps connection' );
			$untitled = $this->args( new OrderMessageOrder( $this->order()->get_items(), self::SHIPPING, '', 'ZAR', '12.00', [ new OrderMessageShipping( '<b></b>' ) ] ), $this->partner( [ 'emit_delivery_line' => 1 ] ) )['items'][2];
			self::assertSame( 'Delivery', $untitled['description'] );
		}

		/** Since 0.5.0: a free-shipping order sends no freight line and no estimate sentence, even with "Send delivery cost" on. */
		public function test_a_free_shipping_order_sends_no_freight_line_and_no_estimate_sentence(): void {
			$on   = $this->partner( [ 'emit_delivery_line' => 1 ] );
			$args = $this->args( new OrderMessageOrder( $this->order()->get_items(), self::SHIPPING, 'Note.', 'ZAR', '0.00', [ new OrderMessageShipping( 'Free shipping' ) ] ), $on, 'The delivery charge is an estimate.' );
			self::assertSame( [ 'E2E-ALPHA', 'E2E-BETA' ], array_column( $args['items'], 'supplier_part_id' ), 'Nothing to charge, no line' );
			self::assertSame( 9250, $args['total_cents'] );
			self::assertSame( 'Note.', $args['delivery_notes'], 'And no estimate sentence' );
			$args = $this->args( new OrderMessageOrder( $this->order()->get_items(), self::SHIPPING, 'Note.', 'ZAR', '0.004', [ new OrderMessageShipping( 'Rounds to nothing' ) ] ), $on, 'The delivery charge is an estimate.' );
			self::assertCount( 2, $args['items'] );
		}

		public function test_ship_to_reaches_the_message_only_while_the_connection_sends_it(): void {
			if ( ! extension_loaded( 'dom' ) ) { self::markTestSkipped( 'ext-dom is required.' ); }
			$on  = ( new POW\Cxml\Builder() )->poom( $this->args( null, $this->partner( [ 'emit_ship_to' => 1 ] ) ) );
			$off = ( new POW\Cxml\Builder() )->poom( $this->args( null, $this->partner( [ 'emit_ship_to' => 0 ] ) ) );
			self::assertStringContainsString( '<ShipTo>', $on );
			self::assertStringContainsString( '<Street>1 Example Street</Street>', $on );
			self::assertStringNotContainsString( '<ShipTo>', $off );
		}
	}
}
