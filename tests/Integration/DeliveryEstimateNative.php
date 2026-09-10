<?php
/**
 * Prepared only: coordinator-owned disposable native shipping acceptance probe.
 *
 * Run only after source approval and exclusive fixture handoff, with actual local WordPress/Woo and no unit bootstrap. POW_NATIVE_TESTS=disposable, POW_NATIVE_DELIVERY_MODE=suite and POW_NATIVE_DELIVERY_FIXTURE must name a private absolute JSON fixture supplied by Main. The fixture must contain partner_id, buyer_ids (two distinct disposable linked buyers), physical_product_id, virtual_product_id, address (ten canonical shipping fields), default_rate_id, expensive_rate_id, pickup_rate_id and free_rate_id. Main supplies a native shipping zone with these offered rates; default must differ from cheapest and expensive must have positive cost. Both supplied buyers must have empty carts. This probe changes only those disposable buyers' Woo sessions/current request, restores the original request objects and reads back their unchanged master addresses. It creates no zone, product, user or partner. It does not prove HTTP acknowledgement, guarded return concurrency or fresh-process persistence.
 *
 * Optional Quote candidate: POW_NATIVE_DELIVERY_QUOTES=1 additionally requires fixture.quote_session_ids with four distinct genuine disposable RETURNED, unlinked session IDs for the first buyer, keyed free/multiple/unknown/disabled. Main supplies these already-claimed rows. This mode creates and leaves four native Quote orders, protected delivery metadata, notes, audit rows and session links for Main's inspection/cleanup. The synthetic read-only producer sessions are NEVER passed to QuoteOrder. Run separately in Main's chosen CPT/HPOS fixture; this file does not change storage mode or claim returns.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */
declare( strict_types = 1 );

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'disposable' !== getenv( 'POW_NATIVE_TESTS' ) || 'suite' !== getenv( 'POW_NATIVE_DELIVERY_MODE' ) || 'local' !== wp_get_environment_type() || ! current_user_can( 'manage_woocommerce' ) || ! function_exists( 'WC' ) || ! empty( $_COOKIE ) ) {
	throw new RuntimeException( 'Requires an opted-in disposable local native fixture and its administrator.' );
}

final class DeliveryEstimateNative {
	private POW\Addresses\DeliveryEstimate $producer;
	private POW\Partners\Partner $partner;
	private array $fixture;
	private int $passed = 0;
	private array $filters = [];
	private array $original_native_hooks = [];
	private array $master_before = [];
	private int $actor;
	private bool $quote_items;

	public function __construct( array $fixture ) {
		$this->fixture = $fixture;
		$this->quote_items = '1' === getenv( 'POW_NATIVE_DELIVERY_QUOTES' );
		$this->actor = get_current_user_id();
		$this->partner = POW\Plugin::instance()->registry()->find( (int) ( $fixture['partner_id'] ?? 0 ) ) ?? throw new RuntimeException( 'Native fixture partner missing.' );
		$this->producer = new POW\Addresses\DeliveryEstimate( new POW\Settings() );
		$buyers = $fixture['buyer_ids'] ?? [];
		if ( ! is_array( $buyers ) || ! array_is_list( $buyers ) || count( $buyers ) !== 2 || ! is_int( $buyers[0] ) || ! is_int( $buyers[1] ) || $buyers[0] <= 0 || $buyers[1] <= 0 || $buyers[0] === $buyers[1] || ! $this->partner->is_active() ) { throw new RuntimeException( 'Two distinct disposable linked buyers are required.' ); }
		foreach ( $buyers as $id ) {
			$user = get_userdata( (int) $id );
			if ( ! $user || ! in_array( POW\Installer::ROLE, $user->roles, true ) || (int) get_user_meta( (int) $id, '_pow_partner_id', true ) !== $this->partner->id || ! empty( get_user_meta( (int) $id, '_pow_deactivated', true ) ) ) { throw new RuntimeException( 'Native fixture buyer association missing.' ); }
			$this->master_before[ $id ] = $this->master( (int) $id );
		}
		$this->master_before[ $this->partner->owner_user_id ] = $this->master( $this->partner->owner_user_id );
		foreach ( [ 'default_rate_id', 'expensive_rate_id', 'pickup_rate_id', 'free_rate_id' ] as $key ) { if ( ! is_string( $fixture[$key] ?? null ) || '' === $fixture[$key] ) { throw new RuntimeException( 'Native fixture rate IDs missing.' ); } }
		foreach ( [ 'physical_product_id' => true, 'virtual_product_id' => false ] as $key => $physical ) { $product = wc_get_product( (int) ( $fixture[$key] ?? 0 ) ); if ( ! $product || $physical !== $product->needs_shipping() ) { throw new RuntimeException( 'Native fixture product type mismatch.' ); } }
		$address = POW\Addresses\Shape::normalise( (array) ( $fixture['address'] ?? [] ) );
		if ( is_wp_error( $address ) || POW\Addresses\DeliveryData::fingerprint( $address ) !== POW\Addresses\DeliveryData::fingerprint( $fixture['address'] ) ) { throw new RuntimeException( 'Native fixture requires a canonical allowed destination.' ); }
		if ( $this->quote_items ) {
			$ids = $fixture['quote_session_ids'] ?? null;
			if ( ! is_array( $ids ) || count( $ids ) !== 4 || count( array_unique( array_values( $ids ), SORT_REGULAR ) ) !== 4 ) { throw new RuntimeException( 'Four distinct disposable returned sessions are required for Quote acceptance.' ); }
			foreach ( [ 'free', 'multiple', 'unknown', 'disabled' ] as $case ) { $this->quote_session( $case ); }
		}
	}
	private function quote_session( string $case ): POW\Sessions\Session {
		$id = $this->fixture['quote_session_ids'][$case] ?? null;
		if ( ! is_int( $id ) || $id <= 0 ) { throw new RuntimeException( 'Native Quote fixture session ID missing.' ); }
		$session = POW\Plugin::instance()->sessions()?->find( $id );
		if ( ! $session || POW\Sessions\Session::RETURNED !== $session->status || $session->partner_id !== $this->partner->id || $session->user_id !== $this->fixture['buyer_ids'][0] || $session->order_id !== 0 ) { throw new RuntimeException( 'Native Quote fixture must be a dedicated returned, unlinked row for the first buyer and partner.' ); }
		return $session;
	}
	private function quote_order( string $case, array $delivery ): void {
		if ( ! $this->quote_items ) { return; }
		$plugin = POW\Plugin::instance(); $session = $this->quote_session( $case );
		$mapped = ( new POW\Cart\PoomMapper( $plugin->settings(), $plugin->logger() ) )->from_cart( $this->partner() );
		$this->check( ! empty( $mapped['items'] ) && empty( $mapped['skipped'] ) && $mapped['currency'] === $delivery['currency'], 'native Quote fixture merchandise maps ' . $case );
		$merchandise = $mapped['total_cents']; $line = POW\Addresses\DeliveryEstimate::poom_line( $delivery );
		$charge = null === $line ? 0 : $line['unit_price_cents'];
		$mapped['total_cents'] += $charge; $mapped['delivery'] = $delivery; $mapped['delivery_destination'] = $this->destination();
		if ( ! empty( $this->fixture['prove_confirmation'] ) ) {
			$entry = $this->fixture['book_before']['addresses'][$this->fixture['book_key']];
			$choice = [ 'schema' => 1, 'partner_id' => $this->partner->id, 'storage_user_id' => $this->partner->owner_user_id, 'provider' => 'native', 'key' => $this->fixture['book_key'], 'label' => $entry['label'], 'book_revision' => $this->fixture['book_before']['revision'], 'entry_fingerprint' => POW\Addresses\CompanyBook::entry_fingerprint( $this->fixture['book_key'], $entry ), 'source' => 'company_book', 'address' => $entry['address'], 'code' => $entry['code'] ];
			// This fixture uses an uncoded destination; preserve the accepted native code consistently in both values.
			$mapped['delivery_destination'] = POW\Addresses\QuoteAddress::payload( $choice );
			$mapped['delivery']['code'] = $choice['code']; $delivery = $mapped['delivery'];
			$mapped['delivery_choice'] = $choice; $mapped['delivery_notes'] = sanitize_textarea_field( $this->fixture['notes'] );
			$mapped['delivery_confirmation'] = [ 'schema' => 1, 'session_id' => $session->id, 'buyer_user_id' => $session->user_id, 'choice_hash' => POW\Addresses\DeliveryData::fingerprint( $choice ), 'cart_fingerprint' => str_repeat( 'a', 64 ), 'policy_fingerprint' => str_repeat( 'b', 64 ), 'delivery' => $delivery, 'notes' => $mapped['delivery_notes'], 'confirmed_at' => time() ];
			$session = new POW\Sessions\Session( ...array_replace( get_object_vars( $session ), [ 'status' => POW\Sessions\Session::ACTIVE ] ) );
		}
		$wire_items = $mapped['items']; if ( null !== $line ) { $wire_items[] = $line; }
		$xml = ( new POW\Cxml\Builder() )->poom( [ 'version' => '1.2.008', 'payload_id' => 'native-shipping@example.test', 'timestamp' => gmdate( 'c' ), 'from' => [ 'domain' => 'NetworkID', 'identity' => 'SUPPLIER' ], 'to' => [ 'domain' => 'NetworkID', 'identity' => 'BUYER' ], 'sender' => [ 'domain' => 'NetworkID', 'identity' => 'SUPPLIER' ], 'buyer_cookie' => 'disposable-probe', 'currency' => $mapped['currency'], 'total_cents' => $mapped['total_cents'], 'items' => $wire_items ] );
		require_once dirname( __DIR__ ) . '/Support/ExactCxmlDtd.php';
		$this->check( ExactCxmlDtd::validate( $xml )['valid'], 'native mapped freight uses exact offline declared DTD ' . $case );
		$document = new DOMDocument(); $document->loadXML( $xml, LIBXML_NONET ); $xpath = new DOMXPath( $document );
		$this->check( $xpath->query( '//ItemIn' )->length === count( $mapped['items'] ) + ( null === $line ? 0 : 1 ) && POW\Cxml\Money::to_cents( $xpath->evaluate( 'string(//PunchOutOrderMessageHeader/Total/Money)' ) ) === $mapped['total_cents'], 'native outgoing total includes at most one freight ItemIn ' . $case );
		if ( null !== $line ) { $this->check( POW\Cxml\Money::to_cents( $xpath->evaluate( 'string(//ItemIn[last()]/ItemDetail/UnitPrice/Money)' ) ) === $charge, 'native outgoing freight equals summed selected packages ' . $case ); }
		$product_id = (int) $this->fixture['physical_product_id']; $stock_before = wc_get_product( $product_id )->get_stock_quantity();
		$quotes = new POW\Orders\QuoteOrder( $plugin->sessions(), $plugin->audit(), $plugin->settings(), $plugin->logger() );
		$id = $quotes->create_for_session( $session, $this->partner(), $mapped );
		$this->check( $id > 0, 'native Quote persisted ' . $case );
		$order = wc_get_order( $id );
		if ( ! empty( $this->fixture['prove_confirmation'] ) ) {
			$this->check( $order->get_customer_note( 'edit' ) === $mapped['delivery_notes'] && $order->get_meta( POW\Orders\QuoteOrder::META_DELIVERY_NOTES ) === $mapped['delivery_notes'], 'native multiline customer note and protected note remain exact ' . $case );
			$this->check( json_decode( $order->get_meta( POW\Orders\QuoteOrder::META_DELIVERY_CHOICE ), true ) === $mapped['delivery_choice'] && json_decode( $order->get_meta( POW\Orders\QuoteOrder::META_DELIVERY_CONFIRMATION ), true ) === $mapped['delivery_confirmation'], 'native complete immutable choice and confirmation survive readback ' . $case );
		}
		$this->check( $order instanceof WC_Order && POW\Orders\Status::SLUG === $order->get_status() && $order->get_customer_id() === $session->user_id, 'native Quote buyer and nonpayable status ' . $case );
		$this->check( POW\Cxml\Money::to_cents( $order->get_shipping_total( 'edit' ) ) === $charge && POW\Cxml\Money::to_cents( $order->get_total( 'edit' ) ) === $merchandise + $charge && 0 === POW\Cxml\Money::to_cents( $order->get_total_tax() ), 'native Quote ex-tax total equals merchandise plus emitted freight ' . $case );
		$expected = []; foreach ( $delivery['emit'] ? $delivery['rates'] : [] as $rate ) { $expected[(string) $rate['package_key']] = $rate; }
		$this->check( count( $expected ) === count( $order->get_items( 'shipping' ) ) && count( $mapped['items'] ) === count( $order->get_items() ), 'native shipping and merchandise item types stay separate ' . $case );
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			$key = (string) $item->get_meta( '_pow_package_key' ); $rate = $expected[$key] ?? null;
			$this->check( $item instanceof WC_Order_Item_Shipping && null !== $rate && $item->get_meta( '_pow_rate_id' ) === $rate['rate_id'] && $item->get_method_id() === $rate['method_id'] && (int) $item->get_instance_id() === $rate['instance_id'] && $item->get_method_title() === $rate['label'] && POW\Cxml\Money::to_cents( $item->get_total() ) === $rate['amount_cents'] && [ 'total' => [] ] === $item->get_taxes(), 'native saved shipping identity and ex-tax amount ' . $case );
			unset( $expected[$key] );
		}
		$this->check( [] === $expected && POW\Addresses\DeliveryData::fingerprint( $delivery ) === POW\Addresses\DeliveryData::fingerprint( $order->get_meta( POW\Orders\QuoteOrder::META_DELIVERY ) ), 'native saved delivery snapshot retains estimates and taxes ' . $case );
		$linked = $plugin->sessions()->find( $session->id );
		$this->check( $linked && $linked->order_id === $id && POW\Sessions\Session::RETURNED === $linked->status && $stock_before === wc_get_product( $product_id )->get_stock_quantity(), 'native Quote links only its returned fixture and leaves stock unchanged ' . $case );
		echo 'Quote candidate ' . $case . ': order ' . $id . ', session ' . $session->id . "\n";
	}
	private function check( bool $ok, string $label ): void { if ( ! $ok ) { throw new RuntimeException( 'FAIL ' . $label ); } ++$this->passed; echo 'PASS ' . $label . "\n"; }
	private function refused( callable $operation, string $label ): void { try { $operation(); } catch ( DomainException $error ) { $this->check( strlen( $error->getMessage() ) < 256, $label ); return; } throw new RuntimeException( 'FAIL ' . $label ); }
	private function master( int $id ): array { $customer = new WC_Customer( $id ); if ( $customer->get_id() !== $id ) { throw new RuntimeException( 'Native fixture customer missing.' ); } return [ 'shipping' => $customer->get_shipping( 'edit' ), 'billing' => $customer->get_billing( 'edit' ) ]; }
	private function destination(): array { return [ 'address' => $this->fixture['address'], 'code' => '', 'source' => 'customer' ]; }
	private function session( int $buyer ): POW\Sessions\Session { return POW\Sessions\Session::from_row( [ 'id' => 1, 'partner_id' => $this->partner->id, 'user_id' => $buyer, 'status' => POW\Sessions\Session::ACTIVE ] ); }
	private function partner( array $overrides = [] ): POW\Partners\Partner { return POW\Partners\Partner::from_row( array_replace( get_object_vars( $this->partner ), [ 'emit_delivery_line' => true ], $overrides ) ); }
	private function filter( string $hook, callable $callback, int $args = 1 ): void { add_filter( $hook, $callback, 999, $args ); $this->filters[] = [ $hook, $callback ]; }
	private function remove_filters(): void { foreach ( $this->filters as [ $hook, $callback ] ) { remove_filter( $hook, $callback, 999 ); } $this->filters = []; }
	/** Detach native per-cart/session callbacks before swapping buyer objects in this single CLI request. Keep shipping/plugin callbacks intact. */
	private function detach_native_hooks(): array {
		global $wp_filter;
		$detached = [];
		foreach ( $wp_filter as $hook => $dispatcher ) {
			foreach ( $dispatcher->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'];
					if ( ! is_array( $function ) || ! is_object( $function[0] ) || ! ( $function[0] instanceof WC_Cart || $function[0] instanceof WC_Cart_Session || $function[0] instanceof WC_Session_Handler ) ) { continue; }
					$detached[] = [ $hook, $function, $priority, $callback['accepted_args'] ];
					remove_filter( $hook, $function, $priority );
				}
			}
		}
		return $detached;
	}
	private function buyer( int $id ): void {
		$this->detach_native_hooks();
		wp_set_current_user( $id );
		$handler = new WC_Session_Handler(); $handler->init();
		// Guard before touching an existing cart: Main must supply dedicated empty fixture buyers.
		if ( ! empty( $handler->get( 'cart', [] ) ) ) { throw new RuntimeException( 'Native fixture buyer has an existing cart.' ); }
		WC()->session = $handler;
		WC()->customer = new WC_Customer( $id, true );
		WC()->cart = new WC_Cart();
		WC()->cart->cart_context = 'shortcode';
		WC()->shipping()->reset_shipping();
	}
	private function add_product( string $key ): void { if ( ! WC()->cart->add_to_cart( (int) $this->fixture[ $key ], 1 ) ) { throw new RuntimeException( 'Native fixture product cannot be added.' ); } }
	private function confirmed( int $buyer, array $delivery ): POW\Sessions\Session {
		$choice = [ 'schema' => 1, 'partner_id' => $this->partner->id, 'storage_user_id' => $this->partner->owner_user_id, 'provider' => 'customer', 'key' => 'candidate', 'label' => 'Fixture destination', 'book_revision' => null, 'entry_fingerprint' => null ] + $this->destination();
		$confirmation = [ 'schema' => 1, 'session_id' => 1, 'buyer_user_id' => $buyer, 'choice_hash' => POW\Addresses\DeliveryData::fingerprint( $choice ), 'cart_fingerprint' => str_repeat( 'a', 64 ), 'policy_fingerprint' => str_repeat( 'b', 64 ), 'delivery' => $delivery, 'notes' => '', 'confirmed_at' => time() ];
		return POW\Sessions\Session::from_row( [ 'id' => 1, 'partner_id' => $this->partner->id, 'user_id' => $buyer, 'status' => POW\Sessions\Session::ACTIVE, 'delivery_choice' => wp_json_encode( $choice ), 'delivery_confirmation' => wp_json_encode( $confirmation ) ] );
	}
	private function quote( int $buyer, ?POW\Partners\Partner $partner = null ): array { return $this->producer->quote( $this->session( $buyer ), $partner ?? $this->partner(), $this->destination() ); }
	private function run_cases(): void {
		[ $first, $second ] = $this->fixture['buyer_ids'];
		$this->buyer( $first ); $this->add_product( 'physical_product_id' );
		$result = $this->quote( $first );
		$this->check( count( $result['packages'] ) === 1, 'fixture starts with one real native package' );
		$package = $result['packages'][0]; $key = $package['package_key']; $offered = array_column( $package['rates'], null, 'rate_id' );
		foreach ( [ 'default_rate_id', 'expensive_rate_id', 'pickup_rate_id', 'free_rate_id' ] as $name ) { $this->check( isset( $offered[ $this->fixture[$name] ] ), 'native configured offer ' . $name ); }
		$this->check( $this->fixture['default_rate_id'] === $package['selected_rate_id'] && $offered[$package['selected_rate_id']]['amount_cents'] > min( array_column( $offered, 'amount_cents' ) ), 'native configured default differs from cheapest' );
		WC()->session->set( 'chosen_shipping_methods', [ $key => $this->fixture['expensive_rate_id'] ] );
		$delivery = $this->quote( $first )['delivery'];
		$this->check( $delivery['amount_cents'] > 0 && $delivery['rates'][0]['rate_id'] === $this->fixture['expensive_rate_id'], 'native selected expensive rate preserved' );
		if ( ! empty( $this->fixture['require_native_tax'] ) ) { $this->check( array_sum( $delivery['rates'][0]['taxes'] ) > 0, 'native shipping estimate includes real positive Woo tax before ex-tax Quote mapping' ); }
		$confirmed = $this->confirmed( $first, $delivery );
		$this->check( $delivery === $this->producer->for_return( $confirmed, $this->partner(), $this->destination() ), 'native unchanged stored rate returns its immutable values' );
		$this->filter( 'woocommerce_package_rates', static function( array $rates ): array { foreach ( $rates as $rate ) { $rate->set_cost( (float) $rate->get_cost() + 1 ); } return $rates; } );
		$this->refused( fn() => $this->producer->for_return( $confirmed, $this->partner(), $this->destination() ), 'changed native amount refuses despite earlier package cache' );
		$this->remove_filters();
		WC()->cart->cart_context = 'store-api'; WC()->session->set( 'chosen_shipping_methods', [ $key => $this->fixture['pickup_rate_id'] ] );
		$pickup = $this->quote( $first );
		$this->check( 'store-api' === WC()->cart->cart_context && $pickup['delivery']['rates'][0]['rate_id'] === $this->fixture['pickup_rate_id'], 'native Blocks-context pickup remains selected' );
		WC()->session->set( 'chosen_shipping_methods', [ $key => $this->fixture['free_rate_id'] ] );
		$free = $this->quote( $first )['delivery'];
		$this->check( 'quoted' === $free['status'] && 0 === $free['amount_cents'] && 0 === POW\Addresses\DeliveryEstimate::poom_line( $free )['unit_price_cents'], 'native free rate is quoted zero' );
		$this->quote_order( 'free', $free );
		WC()->cart->cart_context = 'shortcode';
		$this->filter( 'woocommerce_cart_shipping_packages', static function( array $packages ): array { $package = reset( $packages ); return [ 0 => $package, 4 => array_replace( $package, [ 'package_name' => 'Second fixture parcel' ] ) ]; } );
		WC()->session->set( 'chosen_shipping_methods', [ 0 => $this->fixture['expensive_rate_id'], 4 => $this->fixture['expensive_rate_id'] ] );
		$multiple = $this->quote( $first )['delivery']; $line = POW\Addresses\DeliveryEstimate::poom_line( $multiple );
		$this->check( count( $multiple['rates'] ) === 2 && [ 0, 4 ] === array_column( $multiple['rates'], 'package_key' ) && array_sum( array_column( $multiple['rates'], 'amount_cents' ) ) === $line['unit_price_cents'], 'two native package ex-tax snapshots equal one freight line' );
		$this->quote_order( 'multiple', $multiple );
		$this->remove_filters();
		$this->filter( 'woocommerce_package_rates', static fn( array $rates ): array => [] );
		$unknown = $this->quote( $first );
		$this->check( 'unknown' === $unknown['delivery']['status'] && null === $unknown['delivery']['amount_cents'] && false === $unknown['can_confirm'], 'native unavailable rates refuse require_rate confirmation' );
		$separate = $this->quote( $first, $this->partner( [ 'delivery_unknown_policy' => 'quote_separately' ] ) );
		$this->check( $separate['can_confirm'] && $separate['requires_unknown_acknowledgement'] && null === POW\Addresses\DeliveryEstimate::poom_line( $separate['delivery'] ), 'native quote-separately exposes acknowledgement requirement without a charge' );
		$this->quote_order( 'unknown', $separate['delivery'] );
		$this->remove_filters();
		$disabled = $this->quote( $first, $this->partner( [ 'emit_delivery_line' => false ] ) );
		$this->check( 'disabled' === $disabled['delivery']['status'] && null !== $disabled['delivery']['amount_cents'] && false === $disabled['delivery']['emit'], 'emission off retains native estimate' );
		$this->quote_order( 'disabled', $disabled['delivery'] );
		$first_address = WC()->customer->get_shipping( 'edit' ); $first_session = WC()->session;
		$this->buyer( $second ); $this->add_product( 'virtual_product_id' );
		$virtual = $this->producer->quote( $this->session( $second ), $this->partner(), null );
		$this->check( 'not_required' === $virtual['delivery']['status'] && [] === $virtual['delivery']['rates'], 'native virtual-only cart needs no destination or freight' );
		$this->check( (string) $first !== (string) WC()->session->get_customer_id() && $first_address['city'] === $first_session->get( 'customer' )['shipping_city'], 'two native buyer sessions remain isolated' );
		foreach ( $this->master_before as $id => $before ) { $this->check( $before === $this->master( (int) $id ), 'owner or buyer master address unchanged ' . $id ); }
	}
	public function run(): void {
		$original = [ WC()->session, WC()->customer, WC()->cart ];
		ob_start(); // Keep native cookie callbacks ahead of output while exercising multiple buyer contexts.
		$this->original_native_hooks = $this->detach_native_hooks();
		$disable_persistent_cart = static fn(): bool => false;
		add_filter( 'woocommerce_persistent_cart_enabled', $disable_persistent_cart, 999 );
		try { $this->run_cases(); echo 'Passed: ' . $this->passed . ' Failed: 0 Skipped: 0 (' . ( $this->quote_items ? 'native producer and Quote items' : 'native producer only; Quote items not selected' ) . '; HTTP/CAS/fresh-process persistence untested)' . "\n"; }
		finally {
			$this->remove_filters();
			$this->detach_native_hooks();
			remove_filter( 'woocommerce_persistent_cart_enabled', $disable_persistent_cart, 999 );
			[ WC()->session, WC()->customer, WC()->cart ] = $original;
			wp_set_current_user( $this->actor );
			foreach ( $this->original_native_hooks as [ $hook, $callback, $priority, $args ] ) { add_filter( $hook, $callback, $priority, $args ); }
			$output = ob_get_clean();
			if ( is_string( $output ) ) { echo $output; }
		}
	}
}

$fixture_path = getenv( 'POW_NATIVE_DELIVERY_FIXTURE' );
if ( ! is_string( $fixture_path ) || ! str_starts_with( $fixture_path, '/' ) || ! is_file( $fixture_path ) || ( fileperms( $fixture_path ) & 0077 ) !== 0 ) { throw new RuntimeException( 'A private absolute Main-owned fixture JSON path is required.' ); }
$fixture = json_decode( file_get_contents( $fixture_path ), true, 16, JSON_THROW_ON_ERROR );
if ( ! is_array( $fixture ) ) { throw new RuntimeException( 'Native fixture must be an object.' ); }
( new DeliveryEstimateNative( $fixture ) )->run();
