<?php
/**
 * Opt-in address-provider acceptance against real local WP/Woo and the candidate plugin.
 * Requires POW_NATIVE_TESTS=disposable and an actual fixture administrator. Run suite, then verify in a fresh CLI request with the same unused private POW_PROVIDER_FIXTURE JSON path. Only new invented fixtures are written; existing site settings and accounts are untouched. This proves service/storage boundaries, not browser checkout or the future final-return guard.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */
declare( strict_types = 1 );

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'disposable' !== getenv( 'POW_NATIVE_TESTS' ) || 'local' !== wp_get_environment_type() || ! current_user_can( 'manage_woocommerce' ) || ! function_exists( 'WC' ) ) {
	throw new RuntimeException( 'Requires opted-in disposable local WordPress/WooCommerce CLI and a capable fixture administrator.' );
}

final class AddressProviderNative {
	private POW\Partners\Registry $registry;
	private POW\Sessions\Store $sessions;
	private POW\Addresses\CompanyBook $book;
	private POW\Addresses\Resolver $resolver;
	private POW\Addresses\Provider $provider;
	private int $admin;
	private int $passed = 0;
	private int $failed = 0;
	public function __construct() {
		$plugin = POW\Plugin::instance();
		$this->registry = $plugin->registry();
		$this->sessions = $plugin->sessions();
		$visits = new POW\Sessions\Current( $this->sessions );
		$this->book = new POW\Addresses\CompanyBook( $this->registry, $plugin->audit(), $visits );
		$this->resolver = new POW\Addresses\Resolver( $this->registry, $this->book, $visits );
		$this->provider = $this->resolver->current();
		$this->admin = get_current_user_id();
	}
	private function check( bool $ok, string $label ): void {
		if ( ! $ok ) { ++$this->failed; throw new RuntimeException( 'FAIL ' . $label ); }
		++$this->passed; echo 'PASS ' . $label . "\n";
	}
	private function array_result( mixed $value, string $label ): array { $this->check( is_array( $value ), $label ); return $value; }
	private function error_code( mixed $value, string $code ): bool { return is_wp_error( $value ) && $code === $value->get_error_code() && strlen( $value->get_error_message() ) < 256; }
	private function user(): int {
		$suffix = bin2hex( random_bytes( 8 ) );
		$id = wp_insert_user( [ 'user_login' => 'provider-' . $suffix, 'user_email' => $suffix . '@example.invalid', 'user_pass' => wp_generate_password(), 'role' => 'customer' ] );
		if ( is_wp_error( $id ) || $id <= 0 ) { throw new RuntimeException( 'Native provider fixture creation failed.' ); }
		return (int) $id;
	}
	private function seed_company(): array {
		$owner = $this->user(); $suffix = bin2hex( random_bytes( 8 ) );
		$id = $this->registry->insert( [ 'name' => 'Example provider company', 'owner_user_id' => $owner, 'status' => 'active', 'sender_domain' => 'NetworkID', 'sender_identity' => 'provider-' . $suffix, 'from_domain' => 'NetworkID', 'from_identity' => 'provider-' . $suffix, 'to_domain' => 'NetworkID', 'to_identity' => 'supplier', 'mode' => 'requisition_only', 'deployment_mode' => 'test', 'return_encoding' => 'base64', 'cxml_version' => '1.2.008', 'delivery_code_prefix' => 'DEPOT' ] );
		if ( $id <= 0 ) { throw new RuntimeException( 'Native provider company creation failed.' ); }
		return [ 'id' => $id, 'owner' => $owner ];
	}
	private function fields( string $label, bool $enabled ): array {
		return [ 'label' => $label, 'code' => '', 'use_for_punchout' => $enabled, 'address' => [ 'first_name' => 'Zoë', 'last_name' => "O'Neil", 'company' => 'Example Company', 'address_1' => '12 Test Street', 'address_2' => '', 'city' => 'Oakland', 'state' => 'CA', 'postcode' => '94612', 'country' => 'US', 'phone' => '+1 (510) 555-0100' ] ];
	}
	private function choice( array $f, array $saved ): array {
		return [ 'schema' => 1, 'partner_id' => $f['id'], 'storage_user_id' => $f['owner'], 'provider' => 'native', 'source' => 'company_book', 'key' => $saved['key'], 'label' => $saved['entry']['label'], 'address' => $saved['entry']['address'], 'code' => $saved['entry']['code'], 'book_revision' => $saved['revision'], 'entry_fingerprint' => POW\Addresses\CompanyBook::entry_fingerprint( $saved['key'], $saved['entry'] ) ];
	}
	private function active( POW\Sessions\Session $session, array $choice ): bool|WP_Error {
		return $this->registry->with_partner_lock( $session->partner_id, fn() => $this->resolver->validate_active_choice_locked( $session, $this->registry->find( $session->partner_id ), $choice ) );
	}
	private function suite(): array {
		global $wpdb;
		$f = $this->seed_company(); $other = $this->seed_company(); $partner = $this->registry->find( $f['id'] );
		$plugin = POW\Plugin::instance();
		$provisioner = new POW\Buyers\Provisioner( $this->sessions, $plugin->audit(), $plugin->logger() );
		$values = get_object_vars( POW\Docs\Samples::parsed() ); $suffix = bin2hex( random_bytes( 8 ) );
		$message_a = new POW\Cxml\SetupMessage( ...array_replace( $values, [ 'payload_id' => 'provider-a-' . $suffix, 'extrinsics' => [ 'UserEmail' => 'provider-a-' . $suffix . '@example.invalid' ] ] ) );
		$message_b = new POW\Cxml\SetupMessage( ...array_replace( $values, [ 'payload_id' => 'provider-b-' . $suffix, 'extrinsics' => [ 'UserEmail' => 'provider-b-' . $suffix . '@example.invalid' ] ] ) );
		$a = $provisioner->provision( $partner, $message_a ); $b = $provisioner->provision( $partner, $message_b );
		$this->check( $a > 0 && $b > 0 && $a !== $b && $a !== $f['owner'] && $b !== $f['owner'], 'two actual provisioned buyers have separate native accounts' );
		$this->check( $a === $provisioner->provision( $partner, $message_a ), 'returning company UserEmail resolves the original native buyer' );
		wp_set_current_user( $f['owner'] );
		$selected = $this->array_result( $this->book->save( $f['id'], $f['owner'], 0, null, $this->fields( 'Primary depot', true ) ), 'native enabled company entry' );
		$disabled = $this->array_result( $this->book->save( $f['id'], $f['owner'], 1, null, $this->fields( 'Disabled depot', false ) ), 'native disabled company entry' );
		$book_before = $this->book->read( $f['id'], $f['owner'] );
		$cart_data = [];
		foreach ( [ $a, $b ] as $index => $buyer ) {
			wp_set_current_user( $buyer );
			$handler = new WC_Session_Handler(); $handler->init();
			$cart_data[$buyer] = [ 'fixture-' . $buyer => [ 'product_id' => 0, 'quantity' => $index + 1, 'fixture' => $suffix ] ];
			$handler->set( 'cart', $cart_data[$buyer] ); $handler->save_data();
			$this->check( (string) $buyer === (string) $handler->get_customer_id(), 'native Woo session belongs to its individual buyer' );
			$this->check( $f['owner'] === $this->resolver->storage_user_id( $buyer ), 'buyer resolves the company owner without becoming that user' );
			$options = $this->provider->list_for_user( $buyer );
			$this->check( 1 === count( $options ) && $selected['key'] === $options[0]['key'] && $selected['entry']['address'] === $options[0]['address'], 'both buyers receive exactly the enabled company option' );
		}
		$observer = new WC_Session_Handler();
		$this->check( $cart_data[$a] === maybe_unserialize( $observer->get_session( (string) $a )['cart'] ) && $cart_data[$b] === maybe_unserialize( $observer->get_session( (string) $b )['cart'] ), 'native Woo persisted cart slots stay separate' );
		wp_set_current_user( $f['owner'] );
		$this->check( $book_before === $this->book->read( $f['id'], $f['owner'] ), 'provider reads do not mutate the company aggregate' );
		$choice = $this->choice( $f, $selected );
		$id = $this->sessions->create( [ 'partner_id' => $f['id'], 'user_id' => $a, 'status' => POW\Sessions\Session::ACTIVE, 'one_time_token_hash' => hash( 'sha256', random_bytes( 32 ) ), 'payload_id' => 'provider-session-' . $suffix, 'expires' => gmdate( 'Y-m-d H:i:s', time() + 3600 ), 'delivery_choice' => wp_json_encode( $choice ) ] );
		$this->check( $id > 0, 'accepted choice stored in native session TEXT' );
		$session = $this->sessions->find( $id ); wp_set_current_user( $a );
		$this->check( $choice === $this->provider->selected_for_session( $session ) && true === $this->active( $session, $choice ), 'native stored snapshot and active entry initially agree' );
		$policy = static function ( array $countries ): array { unset( $countries['US'] ); return $countries; };
		add_filter( 'woocommerce_countries_shipping_countries', $policy );
		try {
			wp_set_current_user( $f['owner'] );
			$this->check( is_array( $this->book->read( $f['id'], $f['owner'] ) ), 'changed native country policy leaves the company book readable for correction' );
			$this->check( $choice['entry_fingerprint'] === POW\Addresses\CompanyBook::entry_fingerprint( $selected['key'], $selected['entry'] ), 'changed native policy cannot alter the stored entry fingerprint' );
			$this->check( $this->error_code( $this->active( $session, $choice ), 'address_unavailable' ), 'current native policy still prevents using that historical destination' );
		} finally { remove_filter( 'woocommerce_countries_shipping_countries', $policy ); wp_set_current_user( $a ); }
		$forged = $choice; $forged['storage_user_id'] = $other['owner'];
		$this->check( $this->error_code( $this->active( $session, $forged ), 'address_unavailable' ), 'forged owner refuses current authorization' );
		$forged = $choice; $forged['key'] = str_repeat( 'f', 32 );
		$this->check( $this->error_code( $this->active( $session, $forged ), 'address_unavailable' ), 'unknown company key refuses current authorization' );
		$forged = $choice; $forged['address']['city'] = 'Forged city';
		$this->check( $this->error_code( $this->active( $session, $forged ), 'address_changed' ), 'copying a valid fingerprint cannot authorize altered snapshot content' );
		$this->check( ! $this->provider->set_code( $a, $selected['key'], 'FORGED' ), 'native provisioned buyer cannot change the company code' );
		wp_set_current_user( $f['owner'] );
		$unrelated = $this->array_result( $this->book->save( $f['id'], $f['owner'], 2, $disabled['key'], array_replace( $disabled['entry'], [ 'label' => 'Renamed disabled depot' ] ) ), 'unrelated native master edit' );
		$this->check( true === $this->active( $session, $choice ), 'unrelated aggregate revision preserves an unchanged selection' );
		// Native order persistence is independent from the mutable book; the final-return consumer is tested in its own task.
		$order = wc_create_order( [ 'customer_id' => $a, 'status' => POW\Orders\Status::SLUG ] );
		if ( ! $order instanceof WC_Order ) { throw new RuntimeException( 'Native historical quote fixture unavailable.' ); }
		$props = []; foreach ( $choice['address'] as $key => $value ) { $props['shipping_' . $key] = $value; }
		if ( is_wp_error( $order->set_props( $props ) ) ) { throw new RuntimeException( 'Native quote address fixture refused.' ); }
		$order->update_meta_data( POW\Orders\QuoteOrder::META_DELIVERY_CODE, $choice['code'] ); $order->save(); $quote = $order->get_id();
		$changed = $this->array_result( $this->book->save( $f['id'], $f['owner'], 3, $selected['key'], array_replace( $selected['entry'], [ 'label' => 'Changed depot' ] ) ), 'selected native master edit' );
		$this->check( $this->error_code( $this->active( $session, $choice ), 'address_changed' ), 'selected entry edit requires reconfirmation' );
		$this->check( $choice === $this->resolver->selected_snapshot( $this->sessions->find( $id ) ), 'master edit never silently rewrites the accepted session snapshot' );
		$off = $this->array_result( $this->book->save( $f['id'], $f['owner'], 4, $selected['key'], array_replace( $changed['entry'], [ 'use_for_punchout' => false ] ) ), 'disable selected native entry' );
		$this->check( $this->error_code( $this->active( $session, $choice ), 'address_unavailable' ), 'disabled selected entry requires eligible reselection' );
		$this->check( true === $this->book->remove( $f['id'], $f['owner'], 5, $selected['key'] ) && $this->error_code( $this->active( $session, $choice ), 'address_unavailable' ), 'removed selected entry requires eligible reselection' );
		$sibling = $this->array_result( $this->book->save( $f['id'], $f['owner'], 6, null, $this->fields( 'Remaining enabled depot', true ) ), 'a different enabled entry remains available' );
		$saved_order = wc_get_order( $quote );
		$this->check( $choice['address'] === $saved_order->get_address( 'shipping' ) && $choice['code'] === $saved_order->get_meta( POW\Orders\QuoteOrder::META_DELIVERY_CODE ), 'historical native Quote address and code survive master edits and removal' );
		wp_set_current_user( $a );
		$filter = static fn( string $sql ): string => str_contains( $sql, 'SELECT meta_value FROM' ) && str_contains( $sql, '_pow_delivery_book_' . $f['id'] ) ? 'SELECT PRIVATE_missing_column FROM PRIVATE_missing_table' : $sql;
		$hidden = $wpdb->suppress_errors( true ); add_filter( 'query', $filter );
		try {
			$this->check( $this->error_code( $this->active( $session, $choice ), 'address_state_unavailable' ), 'real aggregate SQL read failure is a bounded unavailable result' );
			$threw = false; try { $this->provider->list_for_user( $a ); } catch ( DomainException $error ) { $threw = strlen( $error->getMessage() ) < 256; }
			$this->check( $threw, 'real provider SQL failure does not pretend the company has no addresses' );
		} finally { remove_filter( 'query', $filter ); $wpdb->suppress_errors( $hidden ); }
		$this->resolver->storage_user_id( $a ); // Populate the native request cache before a raw concurrent-style mutation.
		$wpdb->update( $wpdb->usermeta, [ 'meta_value' => (string) $other['id'] ], [ 'user_id' => $a, 'meta_key' => '_pow_partner_id' ] );
		$this->check( $this->error_code( $this->active( $session, $choice ), 'address_unavailable' ), 'fresh association bypasses a stale native user-meta cache' );
		$wpdb->update( $wpdb->usermeta, [ 'meta_value' => (string) $f['id'] ], [ 'user_id' => $a, 'meta_key' => '_pow_partner_id' ] );
		$wpdb->insert( $wpdb->usermeta, [ 'user_id' => $a, 'meta_key' => '_pow_partner_id', 'meta_value' => (string) $f['id'] ] ); $duplicate = $wpdb->insert_id;
		$this->check( 0 === $this->resolver->storage_user_id( $a ), 'duplicate native buyer mapping refuses even when values agree' );
		$wpdb->delete( $wpdb->usermeta, [ 'umeta_id' => $duplicate, 'user_id' => $a ] );
		update_user_meta( $a, '_pow_deactivated', time() );
		$this->check( 0 === $this->resolver->storage_user_id( $a ), 'deactivated native buyer cannot resolve current options' ); delete_user_meta( $a, '_pow_deactivated' );
		$viewer = $this->user(); ( new WP_User( $viewer ) )->add_cap( 'manage_woocommerce' ); wp_set_current_user( $viewer );
		$this->check( $sibling['key'] === $this->provider->list_for_user( $a )[0]['key'], 'actual cross-user grant exposes a nonempty list before revocation barrier' );
		return $f + [ 'buyers' => [ $a, $b ], 'cart_data' => $cart_data, 'session' => $id, 'choice' => $choice, 'quote' => $quote, 'viewer' => $viewer ];
	}
	private function verify( array $f ): void {
		wp_set_current_user( $f['buyers'][0] );
		$this->check( $f['choice'] === $this->resolver->selected_snapshot( $this->sessions->find( $f['session'] ) ), 'fresh request preserves accepted snapshot after master removal' );
		$this->check( $this->error_code( $this->active( $this->sessions->find( $f['session'] ), $f['choice'] ), 'address_unavailable' ), 'fresh request still refuses the removed active address' );
		$observer = new WC_Session_Handler();
		foreach ( $f['buyers'] as $buyer ) { $this->check( $f['cart_data'][$buyer] === maybe_unserialize( $observer->get_session( (string) $buyer )['cart'] ), 'fresh request reads each separate native Woo cart slot' ); }
		$order = wc_get_order( $f['quote'] );
		$this->check( $f['choice']['address'] === $order->get_address( 'shipping' ) && $f['choice']['code'] === $order->get_meta( POW\Orders\QuoteOrder::META_DELIVERY_CODE ), 'fresh request historical Quote remains independent of the master' );
	}
	private function marker( string $directory, string $name ): void {
		if ( ! str_starts_with( $directory, '/' ) || ! is_dir( $directory ) || false === file_put_contents( $directory . '/' . $name, 'ready', LOCK_EX ) ) { throw new RuntimeException( 'Private native barrier marker unavailable.' ); }
	}
	private function cap_barrier( string $mode, array $f ): void {
		$directory = getenv( 'POW_PROVIDER_BARRIER' ) ?: '';
		if ( 'cap_restore' === $mode ) { ( new WP_User( $f['viewer'] ) )->add_cap( 'manage_woocommerce' ); $this->check( user_can( get_userdata( $f['viewer'] ), 'manage_woocommerce' ), 'native viewer capability enabled before the barrier' ); return; }
		if ( 'cap_hold' === $mode ) {
			$this->registry->with_partner_lock( $f['id'], function () use ( $f, $directory ) {
				$this->marker( $directory, 'held' );
				$deadline = microtime( true ) + 8;
				while ( ! is_file( $directory . '/revoke' ) ) { if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'Private revocation barrier timed out.' ); } usleep( 10000 ); }
				( new WP_User( $f['viewer'] ) )->remove_cap( 'manage_woocommerce' );
				$this->check( ! user_can( get_userdata( $f['viewer'] ), 'manage_woocommerce' ), 'independent holder revoked the native viewer capability' );
			} );
			return;
		}
		wp_set_current_user( $f['viewer'] );
		$this->check( current_user_can( 'manage_woocommerce' ), 'reader warms its native capability cache before independent revocation' );
		$filter = function ( string $sql ) use ( $directory ): string { if ( str_contains( $sql, 'GET_LOCK(' ) ) { $this->marker( $directory, 'waiting' ); } return $sql; };
		add_filter( 'query', $filter );
		try {
			$target = getenv( 'POW_PROVIDER_BARRIER_TARGET' ) ?: 'provider';
			if ( 'book' === $target ) { $this->check( $this->error_code( $this->book->read( $f['id'], $f['viewer'] ), 'address_forbidden' ), 'blocked company book reader refreshes revoked administrator authorization' ); }
			elseif ( 'import' === $target ) { $import = new POW\Addresses\NativeImport( $this->registry, $this->book ); $this->check( $this->error_code( $import->copy( $f['id'], $f['viewer'], 0, 'shipping', [] ), 'address_forbidden' ), 'blocked import cannot retain a revoked administrator grant' ); }
			elseif ( 'provider' === $target ) { $this->check( [] === $this->provider->list_for_user( $f['buyers'][0] ), 'blocked address reader refreshes revoked cross-user authorization after acquiring the lock' ); }
			else { throw new RuntimeException( 'Unknown native barrier target.' ); }
		}
		finally { remove_filter( 'query', $filter ); }
	}
	public function run( string $mode, string $path ): void {
		try {
			if ( ! str_starts_with( $path, '/' ) || ! in_array( $mode, [ 'suite', 'verify', 'cap_hold', 'cap_read', 'cap_restore' ], true ) ) { throw new RuntimeException( 'Choose a supported native mode and a private absolute fixture path.' ); }
			if ( in_array( $mode, [ 'cap_hold', 'cap_read', 'cap_restore' ], true ) ) { $this->cap_barrier( $mode, json_decode( file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR ) ); }
			elseif ( 'verify' === $mode ) { $this->verify( json_decode( file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR ) ); }
			else {
				$handle = fopen( $path, 'x' ); if ( false === $handle ) { throw new RuntimeException( 'Choose an unused fixture path.' ); }
				try { chmod( $path, 0600 ); $json = json_encode( $this->suite(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ); if ( strlen( $json ) !== fwrite( $handle, $json ) ) { throw new RuntimeException( 'Fixture write failed.' ); } }
				finally { fclose( $handle ); }
			}
		} finally { wp_set_current_user( $this->admin ); echo "Native provider ($mode): {$this->passed} passed, {$this->failed} failed, 0 skipped\n"; }
	}
}

( new AddressProviderNative() )->run( getenv( 'POW_PROVIDER_MODE' ) ?: 'suite', getenv( 'POW_PROVIDER_FIXTURE' ) ?: '' );
