<?php
/**
 * Fixture steps for the end-to-end HTTP driver (tests/E2E/driver.py).
 *
 * Run only against an opted-in disposable local WordPress/WooCommerce:
 *   POW_NATIVE_TESTS=disposable POW_E2E_SCRIPT=$PWD/tests/E2E/fixture.php POW_E2E_STEP=seed \
 *     POW_E2E_FIXTURE=<run dir>/fixture.json wp --user=<fixture admin> eval 'require getenv("POW_E2E_SCRIPT");'
 *
 * Steps:
 * - seed: one ordinary customer account (the connection's login) with a
 *   deliverable shipping address, one active test connection bound to it,
 *   two priced simple products. For the run it also sets the review's words
 *   to non-default values and its tax sentence on (0.4.23 settings), and adds
 *   one priced flat-rate method to the "rest of the world" shipping zone, so
 *   the driver can see the settings drawn and see that a connection without
 *   the delivery line shows no amount. Writes the run's facts, including the
 *   generated shared secret and what it changed, to POW_E2E_FIXTURE (mode
 *   600). Prints nothing secret.
 * - inspect: reads back what the run left (POW_E2E_PAYLOAD names the visit by
 *   its setup payloadID): the visit's status, whether its login still
 *   verifies, and the orders that name the visit. Writes them to
 *   POW_E2E_RESULT.
 * - retire: disables the run's connection, puts the review settings back as
 *   they were and removes the priced shipping method the seed added, so a
 *   later run starts clean. The run's rows (account, connection, products,
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

	/** The review's words for the run (0.4.23 settings), none of them a default; the tax sentence is on, which a connection without the delivery line must still never show. */
	private const REVIEW_SETTINGS = [ 'review_submit_label' => 'Send cart', 'review_items_heading' => 'Items on order', 'review_total_label' => 'Order total', 'review_tax_note' => 'yes' ];

	/** The priced method the seed adds for the run, ex tax. */
	private const PRICED_COST = '50.00';

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

	private static function product( string $sku, string $name, string $price ): int {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_sku( $sku );
		$product->set_regular_price( $price );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_virtual( false );
		$id    = (int) $product->save();
		$saved = $id > 0 ? wc_get_product( $id ) : null;
		if ( ! $saved instanceof WC_Product || ! $saved->is_purchasable() || ! $saved->needs_shipping() ) { throw new RuntimeException( 'Fixture product ' . $sku . ' is not a purchasable physical product.' ); }
		return $id;
	}

	public function seed(): void {
		$path    = self::path( 'POW_E2E_FIXTURE' );
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
			// The launch profile: ship-to and notes travel, no delivery cost does.
			'emit_ship_to'          => 1,
			'emit_delivery_line'    => 0,
			'delivery_notes_policy' => 'item_detail_extrinsic',
		];
		$id      = $this->registry->insert( $config, $secret );
		$partner = $id > 0 ? $this->registry->find( $id ) : null;
		if ( ! $partner ) { throw new RuntimeException( 'The connection could not be created.' ); }

		$products = [];
		foreach ( self::PRODUCTS as $label => $price ) {
			$sku              = 'E2E-' . strtoupper( $label . '-' . $run );
			$products[ $sku ] = [ 'id' => self::product( $sku, 'End-to-end ' . $label . ' ' . $run, $price ), 'price' => $price ];
		}

		$checkout = (int) wc_get_page_id( 'checkout' );
		[ $settings_before, $shipping ] = self::configure_review( $run );
		self::write(
			$path,
			[
				'run'          => $run,
				'versions'     => [ 'plugin' => POW\VERSION, 'woocommerce' => WC_VERSION, 'wordpress' => get_bloginfo( 'version' ) ],
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
				],
				'secret'       => $secret,
				'products'     => $products,
				'ship_to'      => $address,
				'pages'        => [ 'shop' => (int) wc_get_page_id( 'shop' ), 'cart' => (int) wc_get_page_id( 'cart' ), 'checkout' => $checkout ],
				'checkout'     => [ 'page_id' => $checkout, 'classic' => $checkout > 0 && has_shortcode( (string) get_post_field( 'post_content', $checkout ), 'woocommerce_checkout' ) ],
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

	/** @param array<string, mixed> $before Each changed setting as it was; null = not saved. */
	private static function restore_settings( array $before ): void {
		$stored = get_option( POW\Settings::OPTION_KEY, [] );
		$stored = is_array( $stored ) ? $stored : [];
		foreach ( $before as $key => $value ) {
			if ( ! array_key_exists( $key, self::REVIEW_SETTINGS ) ) { continue; }
			if ( null === $value ) { unset( $stored[ $key ] ); } else { $stored[ $key ] = $value; }
		}
		update_option( POW\Settings::OPTION_KEY, $stored );
	}

	public function inspect(): void {
		$seed    = self::read( self::path( 'POW_E2E_FIXTURE' ) );
		$payload = (string) getenv( 'POW_E2E_PAYLOAD' );
		$visit   = POW\Plugin::instance()->sessions()?->find_by_payload( (int) $seed['connection']['id'], $payload );
		$orders  = [];
		if ( $visit ) {
			// Every registered status: 'any' leaves out custom ones such as the Punchout Quote.
			$ids = wc_get_orders( [ 'limit' => -1, 'return' => 'ids', 'status' => array_keys( wc_get_order_statuses() ), 'meta_key' => POW\Orders\QuoteOrder::META_SESSION_ID, 'meta_value' => (string) $visit->id ] );
			foreach ( $ids as $order_id ) {
				$order = wc_get_order( $order_id );
				if ( ! $order ) { continue; }
				$lines = [];
				foreach ( $order->get_items() as $item ) {
					$product = $item->get_product();
					$lines[] = [ 'sku' => $product ? $product->get_sku() : '', 'qty' => $item->get_quantity(), 'total' => $item->get_total() ];
				}
				$orders[] = [
					'id'             => $order->get_id(),
					'status'         => $order->get_status(),
					'customer'       => $order->get_customer_id(),
					'payment_method' => $order->get_payment_method(),
					'lines'          => $lines,
					'buyer_cookie'   => (string) $order->get_meta( POW\Orders\QuoteOrder::META_BUYER_COOKIE ),
					'customer_note'  => $order->get_customer_note(),
				];
			}
		}
		self::write(
			self::path( 'POW_E2E_RESULT' ),
			[
				'visit'  => $visit ? [ 'id' => $visit->id, 'status' => $visit->status, 'order_id' => $visit->order_id, 'login_live' => '' !== $visit->wp_session_token && WP_Session_Tokens::get_instance( $visit->user_id )->verify( $visit->wp_session_token ) ] : null,
				'orders' => $orders,
			]
		);
		echo 'inspected visit ' . ( $visit ? $visit->id : 'none' ) . ': ' . count( $orders ) . " order(s)\n";
	}

	public function retire(): void {
		$seed = self::read( self::path( 'POW_E2E_FIXTURE' ) );
		$id   = (int) $seed['connection']['id'];
		$ok   = (bool) $this->registry->with_partner_lock( $id, fn(): bool => $this->registry->transition_status( $id, POW\Partners\Partner::STATUS_ACTIVE, [ 'status' => POW\Partners\Partner::STATUS_DISABLED ] ) );
		echo 'connection ' . $id . ( $ok ? ' disabled' : ' left as it was' ) . "\n";
		if ( isset( $seed['review']['settings_before'] ) && is_array( $seed['review']['settings_before'] ) ) {
			self::restore_settings( $seed['review']['settings_before'] );
			echo "review settings put back\n";
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
if ( ! in_array( $step, [ 'seed', 'inspect', 'retire' ], true ) ) { throw new RuntimeException( 'POW_E2E_STEP must be seed, inspect or retire.' ); }
$fixture->{ $step }();
