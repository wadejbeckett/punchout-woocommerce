<?php
/**
 * Fixture steps for the end-to-end HTTP driver (tests/E2E/driver.py).
 *
 * Run only against an opted-in disposable local WordPress/WooCommerce:
 *   POW_NATIVE_TESTS=disposable POW_E2E_SCRIPT=$PWD/tests/E2E/fixture.php POW_E2E_STEP=seed \
 *     POW_E2E_FIXTURE=<run dir>/fixture.json wp --user=<fixture admin> eval 'require getenv("POW_E2E_SCRIPT");'
 *
 * Steps:
 * - seed: the linked build's schema upgrade (as a wp-admin page load would
 *   run it), then one ordinary customer account (the connection's login) with a
 *   deliverable shipping address, one active test connection bound to it,
 *   two priced simple products. For the run it also sets the review's words
 *   to non-default values and its tax sentence on (0.4.23 settings), the
 *   cart's checkout button label and the PunchOut payment method's
 *   place-order label to non-default values (0.5.0 settings), and adds one
 *   priced flat-rate method to the "rest of the world" shipping zone, so the
 *   driver can see the settings drawn and see that a connection without the
 *   delivery line shows no amount. It makes sure one page draws the classic
 *   cart ([woocommerce_cart]), whatever the shop's own cart page is. For the
 *   0.5.0 order lifecycle (ticket 74) the products keep stock (100 each), so
 *   checkout holds stock for the order; a third product has no SKU; and a
 *   100% coupon with a usage limit (so checkout holds it for the order) makes
 *   a zero-total cart. For the cXML from the order (0.5.0, ticket 75) the
 *   connection sends each line's name and short description, and "Order
 *   details sent": the ones the driver passes in POW_E2E_ORDER_DETAILS (JSON,
 *   from its --checkout-extras), then one a classic checkout always writes and
 *   one no order has; with POW_E2E_SEND_DELIVERY_COST=1 it also sends delivery
 *   cost. One product's short description is markup with entities, the other
 *   only repeats its name; the seed says what each must read as. Writes the
 *   run's facts, including the generated shared secret and what it changed,
 *   to POW_E2E_FIXTURE (mode 600). Prints nothing secret.
 * - inspect: reads back what the run left (POW_E2E_PAYLOAD names the visit by
 *   its setup payloadID): the visit's status, whether its login still
 *   verifies, and the orders that name the visit, with what each still holds
 *   (stock held for it, coupon holds) and whether WooCommerce's unpaid-order
 *   clean-up would cancel it; the visit's orders in the bin; the coupon's
 *   remaining holds; and each product's stock. Writes them to
 *   POW_E2E_RESULT.
 * - start_click: sets the start page's "Login link needs a click" setting to
 *   POW_E2E_START_CLICK ('yes' or 'no') for one case of the browser run
 *   (tests/E2E/browser.py); the seed records the setting as it was.
 * - retire: disables the run's connection, puts the run's settings (the
 *   plugin's review and start page settings and the payment method's) back
 *   as they were, removes the priced shipping method the seed added and moves
 *   the run's coupon to the bin, so a later run starts clean. The run's rows (account, connection, products,
 *   visit, order) stay for inspection.
 *
 * Every name is neutral and suffixed with a random run id.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'disposable' !== getenv( 'POW_NATIVE_TESTS' ) || 'local' !== wp_get_environment_type() || ! current_user_can( 'manage_woocommerce' ) || ! function_exists( 'WC' ) ) {
	throw new RuntimeException( 'Requires opted-in disposable local WordPress/WooCommerce CLI and a capable fixture administrator.' );
}

if ( ! POW\Plugin::instance()->enabled() ) {
	throw new RuntimeException( 'The PunchOut master switch must be on.' );
}

require_once dirname( __DIR__ ) . '/Support/native-visits.php';

final class PowEndToEndFixture {

	private const PRODUCTS = [ 'alpha' => '11.00', 'beta' => '23.50' ];

	/**
	 * Each product's short description, and the plain text the line description must carry after the name ('' =
	 * none: beta's only repeats its name, in other capitals). {name} is the product's name.
	 */
	private const SHORT_DESCRIPTIONS = [
		'alpha' => [ "<p>Brand&nbsp;<strong>Example</strong> &amp; Co</p>\n<ul><li>Red,\t600&#8239;ml</li></ul>", 'Brand Example & Co Red, 600 ml' ],
		'beta'  => [ '<p>{NAME}</p>', '' ],
	];

	/**
	 * The connection's own "Order details sent" after the driver's: a key WooCommerce's classic checkout writes on
	 * every order (as "yes" or "no"), and a key no order has, which must send nothing. With what they must send.
	 */
	private const ORDER_DETAILS = [
		[ 'meta_key' => 'is_vat_exempt', 'label' => 'Exempt', 'format' => 'text' ],
		[ 'meta_key' => '_pow_e2e_absent', 'label' => 'Absent', 'format' => 'unix_date' ],
	];
	private const ORDER_DETAILS_SENT = [ 'Exempt: no' ];

	/** The review's words for the run (0.4.23 settings), none of them a default; the tax sentence is on, which a connection without the delivery line must still never show. */
	private const REVIEW_SETTINGS = [ 'review_submit_label' => 'Send cart', 'review_items_heading' => 'Items on order', 'review_total_label' => 'Order total', 'review_tax_note' => 'yes', 'checkout_button_label' => 'Review' ];

	/** The PunchOut payment method's own settings for the run (0.5.0): its place-order label, not the default. */
	private const GATEWAY_OPTION   = 'woocommerce_punchout_settings';
	private const GATEWAY_SETTINGS = [ 'order_button_text' => 'Submit' ];

	/** The page that draws the classic cart for the run, found by its slug or made once. */
	private const CLASSIC_CART_SLUG = 'pow-e2e-classic-cart';

	/** The priced method the seed adds for the run, ex tax. */
	private const PRICED_COST = '50.00';

	/** Each product's stock. A Punchout Quote moves none of it. */
	private const STOCK = 100;

	private POW\Partners\Registry $registry;

	public function __construct() {
		$this->registry = POW\Plugin::instance()->registry() ?? throw new RuntimeException( 'Plugin services unavailable.' );
	}

	private static function path( string $name ): string {
		$path = (string) getenv( $name );
		if ( '' === $path || ! is_dir( dirname( $path ) ) ) { throw new RuntimeException( $name . ' must name a file in an existing directory.' ); }
		return $path;
	}

	private static function write( string $path, array $data ): void {
		$old = umask( 0077 );
		try {
			if ( false === file_put_contents( $path, wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) ) { throw new RuntimeException( 'Could not write ' . basename( $path ) ); }
			chmod( $path, 0600 );
		} finally { umask( $old ); }
	}

	/** @return array<string,mixed> */
	private static function read( string $path ): array {
		$data = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $data ) ) { throw new RuntimeException( basename( $path ) . ' is not the seed this run wrote.' ); }
		return $data;
	}

	/** A destination the store accepts, derived from its own base location (as TwoBuyerNative does). */
	private static function store_address(): array {
		$base    = wc_get_base_location();
		$country = (string) ( $base['country'] ?? '' );
		$states  = WC()->countries->get_states( $country );
		$state   = (string) ( $base['state'] ?? '' );
		if ( '' === $state && is_array( $states ) && [] !== $states ) { $state = (string) array_key_first( $states ); }
		$address = POW\Addresses\Shape::normalise(
			[
				'first_name' => 'Receiving',
				'last_name'  => 'Desk',
				'company'    => 'Buyer Co',
				'address_1'  => '1 Example Street',
				'address_2'  => '',
				'city'       => 'Example City',
				'state'      => $state,
				'postcode'   => (string) get_option( 'woocommerce_store_postcode', '' ),
				'country'    => $country,
				'phone'      => '',
			]
		);
		if ( $address instanceof WP_Error ) { throw new RuntimeException( 'The store base address is not an allowed destination: ' . $address->get_error_code() ); }
		return $address;
	}

	private static function product( string $sku, string $name, string $price, string $short ): int {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_sku( $sku );
		$product->set_short_description( $short );
		$product->set_regular_price( $price );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_virtual( false );
		// Stock kept, so checkout holds stock for the order it creates (0.5.0 releases that hold).
		$product->set_manage_stock( true );
		$product->set_stock_quantity( self::STOCK );
		$id    = (int) $product->save();
		$saved = $id > 0 ? wc_get_product( $id ) : null;
		if ( ! $saved instanceof WC_Product || ! $saved->is_purchasable() || ! $saved->needs_shipping() ) { throw new RuntimeException( 'Fixture product ' . $sku . ' is not a purchasable physical product.' ); }
		return $id;
	}

	/** A published 100% coupon with a usage limit: WooCommerce holds such a coupon for each checkout order. */
	private static function coupon( string $code ): array {
		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( 100 );
		$coupon->set_usage_limit( 1000 );
		$coupon->set_status( 'publish' );
		$id = (int) $coupon->save();
		if ( $id <= 0 ) { throw new RuntimeException( 'The run coupon could not be created.' ); }
		return [ 'id' => $id, 'code' => $code ];
	}

	/**
	 * One order as the driver reads it: lines, what it still holds, whether WooCommerce would auto-cancel it, and
	 * each "Order details sent" key (`$keys`) as the order holds it.
	 *
	 * @param list<string> $keys
	 */
	private static function order_facts( WC_Order $order, array $keys ): array {
		global $wpdb;
		$lines = [];
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			$lines[] = [ 'sku' => $product ? $product->get_sku() : '', 'qty' => $item->get_quantity(), 'total' => $item->get_total() ];
		}
		$held = 0;
		foreach ( [ '_coupon_held_keys', '_coupon_held_keys_for_users' ] as $key ) {
			$value = $order->get_meta( $key );
			$held += is_array( $value ) ? count( $value ) : ( '' !== (string) $value ? 1 : 0 );
		}
		$created_via = in_array( $order->get_created_via(), [ 'checkout', 'store-api' ], true );
		return [
			'id'             => $order->get_id(),
			'status'         => $order->get_status(),
			'customer'       => $order->get_customer_id(),
			'payment_method' => $order->get_payment_method(),
			'total'          => (string) $order->get_total(),
			'lines'          => $lines,
			'buyer_cookie'   => (string) $order->get_meta( POW\Orders\QuoteOrder::META_BUYER_COOKIE ),
			'customer_note'  => $order->get_customer_note(),
			'reserved_stock' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_reserved_stock WHERE order_id = %d", $order->get_id() ) ),
			'coupon_holds'   => $held,
			// WooCommerce's own question, asked as its unpaid-order clean-up asks it.
			'auto_cancel'    => (bool) apply_filters( 'woocommerce_cancel_unpaid_order', $created_via, $order ),
			// Evidence: each "Order details sent" key as the order holds it.
			'details'        => array_combine( $keys, array_map( static fn( string $key ): mixed => $order->get_meta( $key ), $keys ) ),
		];
	}

	public function seed(): void {
		$path    = self::path( 'POW_E2E_FIXTURE' );
		// A wp-admin page load runs the schema upgrade after an install; WP-CLI and the front end never do, so the
		// seed runs it for the linked build (schema 10 only adds columns an earlier build ignores).
		POW\Installer::maybe_upgrade();
		if ( (string) get_option( POW\Installer::DB_VERSION_KEY ) !== POW\Installer::DB_VERSION ) {
			throw new RuntimeException( 'The schema upgrade to ' . POW\Installer::DB_VERSION . ' did not land; see the WooCommerce log.' );
		}
		$run     = strtolower( bin2hex( random_bytes( 4 ) ) );
		$account = pow_native_bound_account( 'e2e' );
		$address = self::store_address();
		$customer = new WC_Customer( $account );
		foreach ( $address as $field => $value ) {
			$customer->{ 'set_shipping_' . $field }( $value );
			if ( 'phone' !== $field ) { $customer->{ 'set_billing_' . $field }( $value ); }
		}
		$customer->set_billing_email( $customer->get_email() );
		$customer->save();

		$secret = wp_generate_password( 40, false, false );
		$buyer  = 'BUYER-E2E-' . strtoupper( $run );
		$extra  = json_decode( (string) ( getenv( 'POW_E2E_ORDER_DETAILS' ) ?: '[]' ), true );
		if ( ! is_array( $extra ) || ! array_is_list( $extra ) ) { throw new RuntimeException( 'POW_E2E_ORDER_DETAILS must be a JSON list.' ); }
		$config = [
			'name'                  => 'End-to-end ' . $run,
			'status'                => POW\Partners\Partner::STATUS_ACTIVE,
			'owner_user_id'         => $account,
			'from_domain'           => 'NetworkID',
			'from_identity'         => $buyer,
			'sender_domain'         => 'NetworkID',
			'sender_identity'       => $buyer,
			'to_domain'             => 'NetworkID',
			'to_identity'           => 'supplier-e2e-' . $run,
			'cxml_version'          => '1.2.008',
			'deployment_mode'       => 'test',
			'return_encoding'       => 'base64',
			'allcaps_transform'     => 1,
			'session_ttl'           => 3600,
			'token_ttl'             => 600,
			// The launch profile: ship-to and notes travel, no delivery cost does (unless the run asks for it).
			'emit_ship_to'          => 1,
			'emit_delivery_line'    => '1' === getenv( 'POW_E2E_SEND_DELIVERY_COST' ) ? 1 : 0,
			'delivery_notes_policy' => 'item_detail_extrinsic',
			// 0.5.0 (schema 10; an earlier build's registry ignores both): name and short description, and the
			// order details the driver's extras name, then this fixture's own.
			'line_description'      => 'name_short_description',
			'order_details'         => array_merge( $extra, self::ORDER_DETAILS ),
		];
		$id      = $this->registry->insert( $config, $secret );
		$partner = $id > 0 ? $this->registry->find( $id ) : null;
		if ( ! $partner ) { throw new RuntimeException( 'The connection could not be created.' ); }

		$products = [];
		foreach ( self::PRODUCTS as $label => $price ) {
			$sku              = 'E2E-' . strtoupper( $label . '-' . $run );
			$name             = 'End-to-end ' . $label . ' ' . $run;
			[ $short, $detail ] = self::SHORT_DESCRIPTIONS[ $label ];
			$products[ $sku ] = [ 'id' => self::product( $sku, $name, $price, str_replace( '{NAME}', strtoupper( $name ), $short ) ), 'price' => $price, 'name' => $name, 'detail' => $detail ];
		}

		// 0.5.0 order lifecycle: a product without a SKU (refused before any order exists) and a 100% coupon with a
		// usage limit (a zero-total cart, and a coupon checkout holds for the order).
		$skuless_name = 'End-to-end no code ' . $run;
		$skuless      = [ 'id' => self::product( '', $skuless_name, '7.00', '' ), 'name' => $skuless_name ];
		$coupon       = self::coupon( 'e2e-free-' . $run );

		$checkout = (int) wc_get_page_id( 'checkout' );
		$classic_cart = self::classic_cart_page();
		[ $settings_before, $shipping ] = self::configure_review( $run );
		$gateway_before = self::configure_gateway();
		self::write(
			$path,
			[
				'run'          => $run,
				'versions'     => [ 'plugin' => POW\VERSION, 'schema' => POW\Installer::DB_VERSION, 'woocommerce' => WC_VERSION, 'wordpress' => get_bloginfo( 'version' ) ],
				'currency'     => get_woocommerce_currency(),
				'account'      => $account,
				'connection'   => [
					'id'                    => $partner->id,
					'buyer'                 => $partner->from_identity,
					'supplier'              => $partner->to_identity,
					'deployment_mode'       => $partner->deployment_mode,
					'cxml_version'          => $partner->cxml_version,
					'emit_ship_to'          => $partner->emit_ship_to,
					'emit_delivery_line'    => $partner->emit_delivery_line,
					'delivery_notes_policy' => $partner->delivery_notes_policy,
					'freight_supplier_part_id' => $partner->freight_supplier_part_id,
					'allcaps'               => $partner->allcaps_transform,
					// Null on a build before schema 10.
					'line_description'      => $partner->line_description ?? null,
					'order_details'         => method_exists( $partner, 'order_detail_list' ) ? $partner->order_detail_list() : null,
				],
				// What the fixture's own order details must send, after the driver's.
				'instructions_expected' => self::ORDER_DETAILS_SENT,
				// The sentence a freight line carries (and that then leads DeliveryInstructions).
				'estimate_note' => class_exists( 'POW\\Addresses\\EstimateNote' ) ? POW\Addresses\EstimateNote::text( POW\Plugin::instance()->settings() ) : '',
				'secret'       => $secret,
				'products'     => $products,
				'skuless'      => $skuless,
				'coupon'       => $coupon,
				'stock'        => self::STOCK,
				'ship_to'      => $address,
				'pages'        => [ 'shop' => (int) wc_get_page_id( 'shop' ), 'cart' => (int) wc_get_page_id( 'cart' ), 'checkout' => $checkout, 'classic_cart' => $classic_cart ],
				'checkout'     => [
					'page_id' => $checkout,
					'classic' => $checkout > 0 && has_shortcode( (string) get_post_field( 'post_content', $checkout ), 'woocommerce_checkout' ),
					// 0.5.0: the cart's and the Cart widget's checkout button, and the payment method's place-order button.
					'labels'  => [ 'proceed' => self::REVIEW_SETTINGS['checkout_button_label'], 'place_order' => self::GATEWAY_SETTINGS['order_button_text'] ],
					// For retire: the payment method's settings as they were, null when never saved.
					'gateway_before' => $gateway_before,
				],
				// An address book found by shape, as the plugin finds it: a get_address_book() in any namespace.
				'address_book' => [] !== array_filter( get_defined_functions()['user'], static fn( string $f ): bool => 'get_address_book' === substr( $f, -16 ) ),
				'review'       => [
					'labels'          => [ 'submit' => self::REVIEW_SETTINGS['review_submit_label'], 'items' => self::REVIEW_SETTINGS['review_items_heading'], 'total' => self::REVIEW_SETTINGS['review_total_label'] ],
					'tax_note'        => 'yes' === self::REVIEW_SETTINGS['review_tax_note'],
					// For retire: each changed setting as it was, null when it was not saved at all.
					'settings_before' => $settings_before,
				],
				'shipping'     => $shipping,
			]
		);
		echo 'seeded run ' . $run . ': connection ' . $partner->id . ', account ' . $account . ', ' . count( $products ) . " products\n";
	}

	/**
	 * The run's review settings and its priced shipping method. Returns [ the changed settings as they were (null =
	 * not saved), the method's facts ]. A failure here puts back whatever it changed before it rethrows.
	 *
	 * @return array{0: array<string, mixed>, 1: array{instance_id: int, priced_title: string, cost: string}}
	 */
	private static function configure_review( string $run ): array {
		$stored = get_option( POW\Settings::OPTION_KEY, [] );
		$stored = is_array( $stored ) ? $stored : [];
		$before = [];
		foreach ( self::REVIEW_SETTINGS as $key => $value ) { $before[ $key ] = $stored[ $key ] ?? null; }
		// Not changed by the seed; recorded so that retire can undo a start_click step.
		$before['start_link_click'] = $stored['start_link_click'] ?? null;
		$zone     = new WC_Shipping_Zone( 0 );
		$instance = 0;
		try {
			update_option( POW\Settings::OPTION_KEY, array_replace( $stored, self::REVIEW_SETTINGS ) );
			$instance = (int) $zone->add_shipping_method( 'flat_rate' );
			$method   = $instance > 0 ? WC_Shipping_Zones::get_shipping_method( $instance ) : false;
			if ( ! $method instanceof WC_Shipping_Flat_Rate ) { throw new RuntimeException( 'The priced shipping method could not be added.' ); }
			$title = 'Courier E2E ' . $run;
			update_option( $method->get_instance_option_key(), [ 'title' => $title, 'tax_status' => 'none', 'cost' => self::PRICED_COST ] );
			WC_Cache_Helper::get_transient_version( 'shipping', true );
		} catch ( Throwable $error ) {
			self::restore_settings( $before );
			if ( $instance > 0 ) { $zone->delete_shipping_method( $instance ); }
			throw $error;
		}
		return [ $before, [ 'instance_id' => $instance, 'priced_title' => $title, 'cost' => self::PRICED_COST ] ];
	}

	/** One published page whose content draws the classic cart, found by its slug or made once (left in place). */
	private static function classic_cart_page(): int {
		$page = get_page_by_path( self::CLASSIC_CART_SLUG, OBJECT, 'page' );
		if ( $page instanceof WP_Post && 'publish' === $page->post_status && has_shortcode( (string) $page->post_content, 'woocommerce_cart' ) ) { return (int) $page->ID; }
		$id = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => self::CLASSIC_CART_SLUG, 'post_title' => 'Cart (classic, end-to-end)', 'post_content' => "<!-- wp:shortcode -->\n[woocommerce_cart]\n<!-- /wp:shortcode -->" ], true );
		if ( $id instanceof WP_Error || (int) $id <= 0 ) { throw new RuntimeException( 'The classic cart page could not be made.' ); }
		return (int) $id;
	}

	/** Set the payment method's run settings; returns its option as it was (null = never saved). */
	private static function configure_gateway(): ?array {
		$stored = get_option( self::GATEWAY_OPTION, null );
		$before = is_array( $stored ) ? $stored : null;
		update_option( self::GATEWAY_OPTION, array_replace( $before ?? [], self::GATEWAY_SETTINGS ) );
		return $before;
	}

	private static function restore_gateway( ?array $before ): void {
		if ( null === $before ) { delete_option( self::GATEWAY_OPTION ); } else { update_option( self::GATEWAY_OPTION, $before ); }
	}

	/** @param array<string, mixed> $before Each changed setting as it was; null = not saved. */
	private static function restore_settings( array $before ): void {
		$stored = get_option( POW\Settings::OPTION_KEY, [] );
		$stored = is_array( $stored ) ? $stored : [];
		foreach ( $before as $key => $value ) {
			if ( ! array_key_exists( $key, self::REVIEW_SETTINGS ) && 'start_link_click' !== $key ) { continue; }
			if ( null === $value ) { unset( $stored[ $key ] ); } else { $stored[ $key ] = $value; }
		}
		update_option( POW\Settings::OPTION_KEY, $stored );
	}

	public function inspect(): void {
		global $wpdb;
		$seed    = self::read( self::path( 'POW_E2E_FIXTURE' ) );
		$payload = (string) getenv( 'POW_E2E_PAYLOAD' );
		$visit   = POW\Plugin::instance()->sessions()?->find_by_payload( (int) $seed['connection']['id'], $payload );
		$found   = [ 'orders' => [], 'trashed' => [] ];
		$keys    = array_values( array_map( static fn( array $entry ): string => (string) $entry['meta_key'], (array) ( $seed['connection']['order_details'] ?? [] ) ) );
		if ( $visit ) {
			// Every registered status: 'any' leaves out custom ones such as the Punchout Quote. The bin is asked apart.
			foreach ( [ 'orders' => array_keys( wc_get_order_statuses() ), 'trashed' => [ 'trash' ] ] as $list => $statuses ) {
				$ids = wc_get_orders( [ 'limit' => -1, 'return' => 'ids', 'status' => $statuses, 'meta_key' => POW\Orders\QuoteOrder::META_SESSION_ID, 'meta_value' => (string) $visit->id ] );
				foreach ( $ids as $order_id ) {
					$order = wc_get_order( $order_id );
					if ( $order ) { $found[ $list ][] = self::order_facts( $order, $keys ); }
				}
			}
		}
		$coupon_id = (int) ( $seed['coupon']['id'] ?? 0 );
		$stock     = [];
		foreach ( (array) ( $seed['products'] ?? [] ) as $sku => $product ) {
			$saved         = wc_get_product( (int) $product['id'] );
			$stock[ $sku ] = $saved ? $saved->get_stock_quantity() : null;
		}
		self::write(
			self::path( 'POW_E2E_RESULT' ),
			[
				'visit'        => $visit ? [ 'id' => $visit->id, 'status' => $visit->status, 'order_id' => $visit->order_id, 'login_live' => '' !== $visit->wp_session_token && WP_Session_Tokens::get_instance( $visit->user_id )->verify( $visit->wp_session_token ) ] : null,
				'orders'       => $found['orders'],
				'trashed'      => $found['trashed'],
				'coupon_holds' => $coupon_id > 0 ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s", $coupon_id, $wpdb->esc_like( '_coupon_held_' ) . '%' ) ) : null,
				'stock'        => $stock,
			]
		);
		echo 'inspected visit ' . ( $visit ? $visit->id : 'none' ) . ': ' . count( $found['orders'] ) . ' order(s), ' . count( $found['trashed'] ) . " in the bin\n";
	}

	/** One case of the browser run: the start page waits for a press ('yes') or posts itself ('no'). Retire puts it back. */
	public function start_click(): void {
		$seed = self::read( self::path( 'POW_E2E_FIXTURE' ) );
		if ( ! array_key_exists( 'start_link_click', (array) ( $seed['review']['settings_before'] ?? [] ) ) ) { throw new RuntimeException( 'This seed cannot put the start page setting back.' ); }
		$stored = get_option( POW\Settings::OPTION_KEY, [] );
		$stored = is_array( $stored ) ? $stored : [];
		$stored['start_link_click'] = 'yes' === getenv( 'POW_E2E_START_CLICK' ) ? 'yes' : 'no';
		update_option( POW\Settings::OPTION_KEY, $stored );
		echo 'start page: ' . ( 'yes' === $stored['start_link_click'] ? 'waits for a press' : 'posts itself' ) . "\n";
	}

	public function retire(): void {
		$seed = self::read( self::path( 'POW_E2E_FIXTURE' ) );
		$id   = (int) $seed['connection']['id'];
		$ok   = (bool) $this->registry->with_partner_lock( $id, fn(): bool => $this->registry->transition_status( $id, POW\Partners\Partner::STATUS_ACTIVE, [ 'status' => POW\Partners\Partner::STATUS_DISABLED ] ) );
		echo 'connection ' . $id . ( $ok ? ' disabled' : ' left as it was' ) . "\n";
		if ( isset( $seed['review']['settings_before'] ) && is_array( $seed['review']['settings_before'] ) ) {
			self::restore_settings( $seed['review']['settings_before'] );
			echo "review and start page settings put back\n";
		}
		if ( isset( $seed['checkout'] ) && is_array( $seed['checkout'] ) && array_key_exists( 'gateway_before', $seed['checkout'] ) ) {
			self::restore_gateway( is_array( $seed['checkout']['gateway_before'] ) ? $seed['checkout']['gateway_before'] : null );
			echo "payment method settings put back\n";
		}
		$coupon = (int) ( $seed['coupon']['id'] ?? 0 );
		if ( $coupon > 0 && 'shop_coupon' === get_post_type( $coupon ) && ( $seed['coupon']['code'] ?? null ) === get_the_title( $coupon ) ) {
			echo 'coupon ' . $coupon . ( wp_trash_post( $coupon ) ? ' moved to the bin' : ' left as it was' ) . "\n";
		}
		$instance = (int) ( $seed['shipping']['instance_id'] ?? 0 );
		$method   = $instance > 0 ? WC_Shipping_Zones::get_shipping_method( $instance ) : false;
		// Only the method this run added, still carrying this run's title.
		if ( $method instanceof WC_Shipping_Flat_Rate && ( $seed['shipping']['priced_title'] ?? null ) === $method->get_option( 'title' ) ) {
			$zone = WC_Shipping_Zones::get_zone_by( 'instance_id', $instance );
			echo 'priced shipping method ' . $instance . ( $zone && $zone->delete_shipping_method( $instance ) ? ' removed' : ' left as it was' ) . "\n";
		}
	}
}

$step    = (string) getenv( 'POW_E2E_STEP' );
$fixture = new PowEndToEndFixture();
if ( ! in_array( $step, [ 'seed', 'inspect', 'start_click', 'retire' ], true ) ) { throw new RuntimeException( 'POW_E2E_STEP must be seed, inspect, start_click or retire.' ); }
$fixture->{ $step }();
