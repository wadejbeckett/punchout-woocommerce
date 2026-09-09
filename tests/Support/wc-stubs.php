<?php
/**
 * Minimal WooCommerce stubs for the order-creation tests.
 *
 * Loaded from bootstrap.php and guarded throughout: under a real
 * WooCommerce bootstrap this file defines nothing. Only the surface
 * Orders\QuoteOrder touches is stubbed — enough to prove what is set on
 * the order, never enough to pretend this is an integration test.
 *
 * Symbol ownership (the guards protect each symbol individually, but two
 * files claiming one symbol makes the winning body depend on require
 * order): this file owns the WooCommerce surface only —
 * get_woocommerce_currency, WC, wc_create_order, wc_get_order,
 * wc_get_product and the classes WC_Order, WC_Order_Item_Product,
 * WC_Product, WC_Customer and the WC() container. The WordPress surface,
 * apply_filters and WP_Error included, belongs to Support/wp-stubs.php,
 * which loads first. Check the other file before adding anything to
 * either.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

if ( ! function_exists( 'get_woocommerce_currency' ) ) {
	function get_woocommerce_currency(): string { // phpcs:ignore
		return 'ZAR';
	}
}

if ( ! class_exists( 'WC_Product' ) ) {
	/** A catalogue product, as much of one as an order line needs. */
	class WC_Product { // phpcs:ignore

		public function __construct( private int $id = 0, private string $name = '', private string $sku = '' ) {}

		public function get_id(): int {
			return $this->id;
		}

		public function get_name(): string {
			return $this->name;
		}

		public function get_sku(): string {
			return $this->sku;
		}
	}
}

if ( ! class_exists( 'WC_Order_Item_Product' ) ) {
	/** The bare line a vanished product leaves behind. */
	class WC_Order_Item_Product { // phpcs:ignore

		public string $name     = '';
		public float $quantity  = 0.0;
		public float $subtotal  = 0.0;
		public float $total     = 0.0;

		/** @var array<string, mixed> */
		public array $meta = [];

		public function set_name( string $name ): void {
			$this->name = $name;
		}

		public function set_quantity( float $quantity ): void {
			$this->quantity = $quantity;
		}

		public function set_subtotal( float $subtotal ): void {
			$this->subtotal = $subtotal;
		}

		public function set_total( float $total ): void {
			$this->total = $total;
		}

		public function add_meta_data( string $key, mixed $value, bool $unique = false ): void {
			$this->meta[ $key ] = $value;
		}
	}
}

if ( ! class_exists( 'WC_Order' ) ) {
	/** Records what was set, so tests assert on state rather than on SQL. */
	class WC_Order { // phpcs:ignore

		/** @var array<string, mixed> */
		public array $meta = [];

		/** @var list<array<string, mixed>> */
		public array $items = [];

		/** @var list<string> */
		public array $notes = [];

		/** @var array<string, string> */
		public array $props = [];

		/** @var list<bool> */
		public array $totals_calls = [];

		public string $status      = 'pending';
		public string $currency    = '';
		public int $customer_id    = 0;
		public bool $saved         = false;

		/** Set by a test to prove the callers survive a failing save. */
		public bool $save_throws = false;

		public function __construct( public int $id = 0 ) {}

		public function get_id(): int {
			return $this->id;
		}

		public function set_status( string $status ): void {
			$this->status = $status;
		}

		public function get_status(): string {
			return $this->status;
		}

		public function set_currency( string $currency ): void {
			$this->currency = $currency;
		}

		public function set_customer_id( int $id ): void {
			$this->customer_id = $id;
		}

		/**
		 * The HPOS-friendly setter: shipping_* and billing_* props are
		 * columns of their own, so the stub keeps them as it is given them.
		 *
		 * @param array<string, string> $props Order props.
		 */
		public function set_props( array $props ): void {
			$this->props = array_merge( $this->props, $props );
		}

		public function update_meta_data( string $key, mixed $value ): void {
			$this->meta[ $key ] = $value;
		}

		public function get_meta( string $key, bool $single = true ): mixed {
			return $this->meta[ $key ] ?? '';
		}

		public function add_order_note( string $note, int $is_customer_note = 0 ): int {
			$this->notes[] = $note;

			return count( $this->notes );
		}

		/**
		 * The line a live product produces. The snapshot carries the
		 * product id, which is what separates this branch from the bare
		 * item below when a test looks at $items.
		 *
		 * @param array<string, float> $args subtotal/total overrides.
		 */
		public function add_product( WC_Product $product, float $quantity = 1, array $args = [] ): int {
			$this->items[] = [
				'product_id' => $product->get_id(),
				'name'       => $product->get_name(),
				'sku'        => $product->get_sku(),
				'quantity'   => $quantity,
				'subtotal'   => (float) ( $args['subtotal'] ?? 0 ),
				'total'      => (float) ( $args['total'] ?? 0 ),
			];

			return count( $this->items );
		}

		public function add_item( WC_Order_Item_Product $item ): void {
			$this->items[] = [
				'product_id' => 0,
				'name'       => $item->name,
				'sku'        => (string) ( $item->meta['SKU'] ?? '' ),
				'quantity'   => $item->quantity,
				'subtotal'   => $item->subtotal,
				'total'      => $item->total,
			];
		}

		public function calculate_totals( bool $and_taxes = true ): float {
			$this->totals_calls[] = $and_taxes;

			return 0.0;
		}

		public function save(): int {
			if ( $this->save_throws ) {
				throw new \RuntimeException( 'order save failed' );
			}

			$this->saved = true;

			return $this->id;
		}
	}
}

if ( ! function_exists( 'wc_create_order' ) ) {
	/**
	 * Returns WC_Order, or the WP_Error the real function returns when the
	 * insert fails — pow_test_create_order_error holds the message.
	 *
	 * @param array<string, mixed> $args Order args.
	 * @return WC_Order|WP_Error
	 */
	function wc_create_order( array $args = [] ) { // phpcs:ignore
		static $next = 1000;

		if ( isset( $GLOBALS['pow_test_create_order_error'] ) ) {
			return new WP_Error( 'db_error', (string) $GLOBALS['pow_test_create_order_error'] );
		}

		$order = new WC_Order( ++$next );
		$order->set_customer_id( (int) ( $args['customer_id'] ?? 0 ) );

		$GLOBALS['pow_test_orders'][ $order->get_id() ] = $order;

		return $order;
	}
}

if ( ! function_exists( 'wc_get_order' ) ) {
	function wc_get_order( int $id ): ?WC_Order { // phpcs:ignore
		return $GLOBALS['pow_test_orders'][ $id ] ?? null;
	}
}

if ( ! function_exists( 'wc_get_product' ) ) {
	/**
	 * Only products a test registered in pow_test_products exist; every
	 * other id is a product that has been deleted since the punchout,
	 * which is the case the bare-line fallback is for.
	 */
	function wc_get_product( int $id ): ?WC_Product { // phpcs:ignore
		return $GLOBALS['pow_test_products'][ $id ] ?? null;
	}
}

if ( ! class_exists( 'WC_Customer' ) ) {
	/** The saved shipping address, and nothing else. */
	class WC_Customer { // phpcs:ignore

		/**
		 * @param array<string, string> $shipping Saved shipping fields.
		 */
		public function __construct( private array $shipping = [] ) {}

		/**
		 * @return array<string, string>
		 */
		public function get_shipping(): array {
			return $this->shipping;
		}
	}
}

if ( ! class_exists( 'POW_Test_WC' ) ) {
	/**
	 * Stand-in for the WooCommerce singleton. Both properties are null
	 * until a test sets one, which is what a request outside the shop
	 * context looks like.
	 */
	class POW_Test_WC { // phpcs:ignore

		public ?WC_Customer $customer = null;

		public mixed $cart = null;
	}
}

if ( ! function_exists( 'WC' ) ) {
	function WC(): POW_Test_WC { // phpcs:ignore
		return $GLOBALS['pow_test_wc'] ??= new POW_Test_WC();
	}
}
