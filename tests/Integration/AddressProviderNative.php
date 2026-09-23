<?php
/**
 * Opt-in address-provider acceptance against real local WP/Woo and the candidate plugin.
 * Requires POW_NATIVE_TESTS=disposable and an actual fixture administrator. Run suite, then verify in a fresh CLI request with the same unused private POW_PROVIDER_FIXTURE JSON path. Only new invented fixtures are written; existing site settings and accounts are untouched. This proves service/storage boundaries, not browser checkout or the future final-return guard.
 *
 * The connection has ONE bound customer account and two live visits of it, because that is the only shape the confirmed model allows: no buyer account is ever created, so "two employees" is one WordPress user holding two session rows, two login tokens and two per-visit `wc_session_key`s. Every isolation assertion below therefore compares keys and rows, never user ids — under one shared login a user-id comparison is true of both employees at once and proves nothing.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */
declare( strict_types = 1 );

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'disposable' !== getenv( 'POW_NATIVE_TESTS' ) || 'local' !== wp_get_environment_type() || ! current_user_can( 'manage_woocommerce' ) || ! function_exists( 'WC' ) ) {
	throw new RuntimeException( 'Requires opted-in disposable local WordPress/WooCommerce CLI and a capable fixture administrator.' );
}

require_once dirname( __DIR__ ) . '/Support/native-visits.php';

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
	private function user(): int { return pow_native_bound_account( 'provider' ); }
	private function seed_company(): array {
		$owner = $this->user(); $suffix = bin2hex( random_bytes( 8 ) );
		$id = $this->registry->insert( [ 'name' => 'Example provider company', 'owner_user_id' => $owner, 'status' => 'active', 'sender_domain' => 'NetworkID', 'sender_identity' => 'provider-' . $suffix, 'from_domain' => 'NetworkID', 'from_identity' => 'provider-' . $suffix, 'to_domain' => 'NetworkID', 'to_identity' => 'supplier', 'deployment_mode' => 'test', 'return_encoding' => 'base64', 'cxml_version' => '1.2.008', 'delivery_code_prefix' => 'DEPOT' ] );
		if ( $id <= 0 ) { throw new RuntimeException( 'Native provider company creation failed.' ); }
		return [ 'id' => $id, 'owner' => $owner ];
	}
	/** The account's own management request: signed in, and deliberately not inside any visit. */
	private function as_owner( int $owner ): void { pow_native_leave_visit( $owner ); }
	private function enter( int $session_id ): POW\Sessions\Session {
		$visit = $this->sessions->find( $session_id );
		if ( ! $visit ) { throw new RuntimeException( 'Native visit row missing.' ); }
		pow_native_enter_visit( $visit );
		return $visit;
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
		$suffix = bin2hex( random_bytes( 8 ) );
		$visits = [];
		foreach ( [ 'first', 'second' ] as $index => $label ) {
			$visits[] = pow_native_open_visit( $f['id'], $f['owner'], [ 'buyer_identity' => $label . '-' . $suffix . '@example.invalid', 'buyer_name' => 'Employee ' . $label ] );
		}
		[ $first, $second ] = $visits;
		$this->check(
			$first->user_id === $f['owner'] && $second->user_id === $f['owner'] && $first->id !== $second->id
			&& POW\Cart\SessionKey::is_visit_key( (string) $first->wc_session_key ) && $first->wc_session_key !== $second->wc_session_key
			&& $first->wp_session_token !== $second->wp_session_token,
			'two visits of one bound account hold two cart keys and two login tokens'
		);
		$same_identity = pow_native_open_visit( $f['id'], $f['owner'], [ 'buyer_identity' => 'first-' . $suffix . '@example.invalid' ] );
		$own = array_map( static fn( POW\Sessions\Session $s ): int => $s->id, $this->sessions->open_for_identity( $f['id'], (string) $same_identity->buyer_identity_hash ) );
		sort( $own );
		$this->check( [ $first->id, $same_identity->id ] === $own, 'the same buyer identity supersedes only its own earlier visit' );
		$this->check( $this->sessions->expire_and_destroy( $this->sessions->find( $same_identity->id ), $this->registry ), 'the returning identity fixture releases its own visit' );
		$this->as_owner( $f['owner'] );
		$selected = $this->array_result( $this->book->save( $f['id'], $f['owner'], 0, null, $this->fields( 'Primary depot', true ) ), 'native enabled company entry' );
		$disabled = $this->array_result( $this->book->save( $f['id'], $f['owner'], 1, null, $this->fields( 'Disabled depot', false ) ), 'native disabled company entry' );
		$book_before = $this->book->read( $f['id'], $f['owner'] );
		$cart_data = [];
		foreach ( $visits as $index => $visit ) {
			$key = POW\Cart\SessionKey::for_session( $visit );
			$handler = pow_native_visit_handler( $visit );
			WC()->session = $handler;
			$cart_data[ $key ] = [ 'fixture-' . $visit->id => [ 'product_id' => 0, 'quantity' => $index + 1, 'fixture' => $suffix ] ];
			$handler->set( 'cart', $cart_data[ $key ] );
			$this->check( $handler->save_checked(), 'this visit persists its own native basket' );
			$this->check( hash_equals( $key, (string) $handler->get_customer_id() ), 'the native Woo session belongs to this visit, not to the shared account' );
			$this->check( $f['owner'] === $this->resolver->storage_user_id( $f['owner'] ), 'the visit resolves the connection account without becoming another user' );
			$options = $this->provider->list_for_user( $f['owner'] );
			$this->check( 1 === count( $options ) && $selected['key'] === $options[0]['key'] && $selected['entry']['address'] === $options[0]['address'], 'both visits receive exactly the enabled company option' );
		}
		foreach ( $cart_data as $key => $lines ) {
			$this->check( $lines === pow_native_cart_value( $key, 'cart' ), 'each visit key names its own persisted native basket' );
		}
		$this->check( null === pow_native_cart_row( (string) $f['owner'] ), 'no visit ever writes a basket row keyed on the shared account' );
		$this->as_owner( $f['owner'] );
		$this->check( $book_before === $this->book->read( $f['id'], $f['owner'] ), 'provider reads do not mutate the company aggregate' );
		$choice = $this->choice( $f, $selected );
		$this->check( $this->sessions->update( $first->id, [ 'delivery_choice' => wp_json_encode( $choice ) ] ), 'accepted choice stored in native session TEXT' );
		$session = $this->enter( $first->id );
		$this->check( $choice === $this->provider->selected_for_session( $session ) && true === $this->active( $session, $choice ), 'native stored snapshot and active entry initially agree' );
		$sibling = $this->enter( $second->id );
		$this->check( null === $this->provider->selected_for_session( $sibling ) && $this->error_code( $this->active( $session, $choice ), 'address_unavailable' ), 'a colleague visit cannot present another visit selection as its own' );
		$session = $this->enter( $first->id );
		$policy = static function ( array $countries ): array { unset( $countries['US'] ); return $countries; };
		add_filter( 'woocommerce_countries_shipping_countries', $policy );
		try {
			$this->as_owner( $f['owner'] );
			$this->check( is_array( $this->book->read( $f['id'], $f['owner'] ) ), 'changed native country policy leaves the company book readable for correction' );
			$this->check( $choice['entry_fingerprint'] === POW\Addresses\CompanyBook::entry_fingerprint( $selected['key'], $selected['entry'] ), 'changed native policy cannot alter the stored entry fingerprint' );
			$session = $this->enter( $first->id );
			$this->check( $this->error_code( $this->active( $session, $choice ), 'address_unavailable' ), 'current native policy still prevents using that historical destination' );
		} finally { remove_filter( 'woocommerce_countries_shipping_countries', $policy ); $session = $this->enter( $first->id ); }
		$forged = $choice; $forged['storage_user_id'] = $other['owner'];
		$this->check( $this->error_code( $this->active( $session, $forged ), 'address_unavailable' ), 'forged owner refuses current authorization' );
		$forged = $choice; $forged['key'] = str_repeat( 'f', 32 );
		$this->check( $this->error_code( $this->active( $session, $forged ), 'address_unavailable' ), 'unknown company key refuses current authorization' );
		$forged = $choice; $forged['address']['city'] = 'Forged city';
		$this->check( $this->error_code( $this->active( $session, $forged ), 'address_changed' ), 'copying a valid fingerprint cannot authorize altered snapshot content' );
		$this->check( ! $this->provider->set_code( $f['owner'], $selected['key'], 'FORGED' ), 'a live visit cannot change the company delivery code' );
		$this->as_owner( $f['owner'] );
		$unrelated = $this->array_result( $this->book->save( $f['id'], $f['owner'], 2, $disabled['key'], array_replace( $disabled['entry'], [ 'label' => 'Renamed disabled depot' ] ) ), 'unrelated native master edit' );
		$session = $this->enter( $first->id );
		$this->check( true === $this->active( $session, $choice ), 'unrelated aggregate revision preserves an unchanged selection' );
		// Native order persistence is independent from the mutable book; the final-return consumer is tested in its own task.
		$order = wc_create_order( [ 'customer_id' => $f['owner'], 'status' => POW\Orders\Status::SLUG ] );
		if ( ! $order instanceof WC_Order ) { throw new RuntimeException( 'Native historical quote fixture unavailable.' ); }
		$props = []; foreach ( $choice['address'] as $key => $value ) { $props['shipping_' . $key] = $value; }
		if ( is_wp_error( $order->set_props( $props ) ) ) { throw new RuntimeException( 'Native quote address fixture refused.' ); }
		$order->update_meta_data( POW\Orders\QuoteOrder::META_DELIVERY_CODE, $choice['code'] ); $order->save(); $quote = $order->get_id();
		$this->as_owner( $f['owner'] );
		$changed = $this->array_result( $this->book->save( $f['id'], $f['owner'], 3, $selected['key'], array_replace( $selected['entry'], [ 'label' => 'Changed depot' ] ) ), 'selected native master edit' );
		$session = $this->enter( $first->id );
		$this->check( $this->error_code( $this->active( $session, $choice ), 'address_changed' ), 'selected entry edit requires reconfirmation' );
		$this->check( $choice === $this->resolver->selected_snapshot( $this->sessions->find( $first->id ) ), 'master edit never silently rewrites the accepted session snapshot' );
		$this->as_owner( $f['owner'] );
		$off = $this->array_result( $this->book->save( $f['id'], $f['owner'], 4, $selected['key'], array_replace( $changed['entry'], [ 'use_for_punchout' => false ] ) ), 'disable selected native entry' );
		$session = $this->enter( $first->id );
		$this->check( $this->error_code( $this->active( $session, $choice ), 'address_unavailable' ), 'disabled selected entry requires eligible reselection' );
		$this->as_owner( $f['owner'] );
		$removed = true === $this->book->remove( $f['id'], $f['owner'], 5, $selected['key'] );
		$session = $this->enter( $first->id );
		$this->check( $removed && $this->error_code( $this->active( $session, $choice ), 'address_unavailable' ), 'removed selected entry requires eligible reselection' );
		$this->as_owner( $f['owner'] );
		$sibling_entry = $this->array_result( $this->book->save( $f['id'], $f['owner'], 6, null, $this->fields( 'Remaining enabled depot', true ) ), 'a different enabled entry remains available' );
		$saved_order = wc_get_order( $quote );
		$this->check( $choice['address'] === $saved_order->get_address( 'shipping' ) && $choice['code'] === $saved_order->get_meta( POW\Orders\QuoteOrder::META_DELIVERY_CODE ), 'historical native Quote address and code survive master edits and removal' );
		$session = $this->enter( $first->id );
		$filter = static fn( string $sql ): string => str_contains( $sql, 'SELECT meta_value FROM' ) && str_contains( $sql, '_pow_delivery_book_' . $f['id'] ) ? 'SELECT PRIVATE_missing_column FROM PRIVATE_missing_table' : $sql;
		$hidden = $wpdb->suppress_errors( true ); add_filter( 'query', $filter );
		try {
			$this->check( $this->error_code( $this->active( $session, $choice ), 'address_state_unavailable' ), 'real aggregate SQL read failure is a bounded unavailable result' );
			$threw = false; try { $this->provider->list_for_user( $f['owner'] ); } catch ( DomainException $error ) { $threw = strlen( $error->getMessage() ) < 256; }
			$this->check( $threw, 'real provider SQL failure does not pretend the company has no addresses' );
		} finally { remove_filter( 'query', $filter ); $wpdb->suppress_errors( $hidden ); }
		// The connection account is the association, so a raw change to owner_user_id must be seen at once.
		$this->resolver->storage_user_id( $f['owner'] ); // Populate the native request cache before a raw concurrent-style mutation.
		$wpdb->update( POW\Installer::partners_table(), [ 'owner_user_id' => $other['owner'] ], [ 'id' => $f['id'] ] );
		$this->check( $this->error_code( $this->active( $session, $choice ), 'address_unavailable' ) && 0 === $this->resolver->storage_user_id( $f['owner'] ), 'a rebound connection account bypasses a stale native registry cache' );
		$wpdb->update( POW\Installer::partners_table(), [ 'owner_user_id' => $f['owner'] ], [ 'id' => $f['id'] ] );
		$duplicate = $this->registry->insert( [ 'name' => 'Ambiguous owner', 'owner_user_id' => $f['owner'], 'status' => 'active', 'sender_domain' => 'NetworkID', 'sender_identity' => 'ambiguous-' . $suffix, 'from_domain' => 'NetworkID', 'from_identity' => 'ambiguous-' . $suffix, 'to_domain' => 'NetworkID', 'to_identity' => 'supplier', 'cxml_version' => '1.2.008' ] );
		$this->check( $duplicate > 0 && 0 === $this->resolver->storage_user_id( $f['owner'] ), 'two connections naming one account refuse to resolve either' );
		$this->check( $this->error_code( $this->active( $session, $choice ), 'address_unavailable' ), 'an ambiguous bound account is not an authorization' );
		$this->check( $this->registry->delete( $duplicate ), 'the ambiguous connection fixture is withdrawn' );
		$viewer = $this->user(); ( new WP_User( $viewer ) )->add_cap( 'manage_woocommerce' ); $this->as_owner( $viewer );
		$this->check( $sibling_entry['key'] === $this->provider->list_for_user( $f['owner'] )[0]['key'], 'actual cross-user grant exposes a nonempty list before revocation barrier' );
		$this->enter( $first->id );
		$this->check( [] === $this->provider->list_for_user( $other['owner'] ), 'a visit never reads another connection book, whatever the account holds' );
		return $f + [
			'account' => $f['owner'],
			'foreign_account' => $other['owner'],
			'foreign_partner' => $other['id'],
			'visits' => array_map( static fn( POW\Sessions\Session $s ): array => [ 'id' => $s->id, 'key' => (string) $s->wc_session_key ], $visits ),
			'cart_data' => $cart_data,
			'session' => $first->id,
			'choice' => $choice,
			'quote' => $quote,
			'viewer' => $viewer,
		];
	}
	private function verify( array $f ): void {
		$this->enter( (int) $f['session'] );
		$this->check( $f['choice'] === $this->resolver->selected_snapshot( $this->sessions->find( (int) $f['session'] ) ), 'fresh request preserves accepted snapshot after master removal' );
		$this->check( $this->error_code( $this->active( $this->sessions->find( (int) $f['session'] ), $f['choice'] ), 'address_unavailable' ), 'fresh request still refuses the removed active address' );
		foreach ( $f['cart_data'] as $key => $lines ) { $this->check( $lines === pow_native_cart_value( (string) $key, 'cart' ), 'fresh request reads each visit own native basket row' ); }
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
		$this->as_owner( (int) $f['viewer'] );
		$this->check( current_user_can( 'manage_woocommerce' ), 'reader warms its native capability cache before independent revocation' );
		$filter = function ( string $sql ) use ( $directory ): string { if ( str_contains( $sql, 'GET_LOCK(' ) ) { $this->marker( $directory, 'waiting' ); } return $sql; };
		add_filter( 'query', $filter );
		try {
			$target = getenv( 'POW_PROVIDER_BARRIER_TARGET' ) ?: 'provider';
			if ( 'book' === $target ) { $this->check( $this->error_code( $this->book->read( $f['id'], $f['viewer'] ), 'address_forbidden' ), 'blocked company book reader refreshes revoked administrator authorization' ); }
			elseif ( 'import' === $target ) { $import = new POW\Addresses\NativeImport( $this->registry, $this->book ); $this->check( $this->error_code( $import->copy( $f['id'], $f['viewer'], 0, 'shipping', [] ), 'address_forbidden' ), 'blocked import cannot retain a revoked administrator grant' ); }
			elseif ( 'provider' === $target ) { $this->check( [] === $this->provider->list_for_user( (int) $f['account'] ), 'blocked address reader refreshes revoked cross-user authorization after acquiring the lock' ); }
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
		} finally { pow_native_leave_visit( $this->admin ); echo "Native provider ($mode): {$this->passed} passed, {$this->failed} failed, 0 skipped\n"; }
	}
}

( new AddressProviderNative() )->run( getenv( 'POW_PROVIDER_MODE' ) ?: 'suite', getenv( 'POW_PROVIDER_FIXTURE' ) ?: '' );
