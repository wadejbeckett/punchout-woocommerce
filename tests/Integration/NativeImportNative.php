<?php
/**
 * Opt-in native import acceptance, only after Task2/3 approval and exclusive disposable-fixture handoff.
 * Requires actual local WordPress/WooCommerce and the candidate plugin, never tests/bootstrap.php.
 * Set POW_NATIVE_TESTS=disposable, POW_NATIVE_IMPORT_MODE=suite (then verify in a fresh CLI request), and POW_NATIVE_IMPORT_FIXTURE to an unused private absolute JSON path. Load through the coordinator's wp --user=<admin> eval-file or POW_NATIVE_SCRIPT convention. The suite leaves its invented users/partner/book for inspection; verify only reads them. No shared settings or owner data outside those new fixtures is changed.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */
declare( strict_types = 1 );

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'disposable' !== getenv( 'POW_NATIVE_TESTS' ) || 'local' !== wp_get_environment_type() || ! current_user_can( 'manage_woocommerce' ) || ! function_exists( 'WC' ) ) {
	throw new RuntimeException( 'Requires opted-in disposable local WordPress/WooCommerce CLI and a capable fixture administrator.' );
}

final class NativeImportNative {
	private POW\Partners\Registry $registry;
	private POW\Addresses\CompanyBook $book;
	private POW\Addresses\NativeImport $import;
	private int $admin;
	private int $passed = 0;
	private int $failed = 0;
	public function __construct() {
		$this->registry = POW\Plugin::instance()->registry();
		$this->book = new POW\Addresses\CompanyBook( $this->registry, POW\Plugin::instance()->audit(), new POW\Sessions\Current( POW\Plugin::instance()->sessions() ) );
		$this->import = new POW\Addresses\NativeImport( $this->registry, $this->book );
		$this->admin = get_current_user_id();
	}
	private function check( bool $ok, string $label ): void { if ( ! $ok ) { ++$this->failed; throw new RuntimeException( 'FAIL ' . $label ); } ++$this->passed; echo 'PASS ' . $label . "\n"; }
	private function ok( mixed $result, string $label ): array { $this->check( is_array( $result ), $label ); return $result; }
	private function refused( mixed $result, string $label, ?string $code = null ): void { $this->check( is_wp_error( $result ) && ( null === $code || $code === $result->get_error_code() ) && strlen( $result->get_error_message() ) < 256, $label ); }
	private function user( string $role = 'customer' ): int {
		$suffix = bin2hex( random_bytes( 8 ) );
		$id = wp_insert_user( [ 'user_login' => 'native-import-' . $suffix, 'user_email' => $suffix . '@example.invalid', 'user_pass' => wp_generate_password(), 'role' => $role ] );
		if ( is_wp_error( $id ) || $id <= 0 ) { throw new RuntimeException( 'Native import fixture user creation failed.' ); }
		return (int) $id;
	}
	private function source( int $owner ): array {
		$customer = new WC_Customer( $owner );
		if ( $customer->get_id() !== $owner ) { throw new RuntimeException( 'Native import fixture customer unavailable.' ); }
		return [ 'shipping' => $customer->get_shipping( 'edit' ), 'billing' => $customer->get_billing( 'edit' ) ];
	}
	private function seed(): array {
		$owner = $this->user(); $suffix = bin2hex( random_bytes( 8 ) );
		$id = $this->registry->insert( [ 'name' => 'Example native import company', 'owner_user_id' => $owner, 'status' => 'active', 'from_domain' => 'NetworkID', 'from_identity' => 'import-' . $suffix, 'sender_domain' => 'NetworkID', 'sender_identity' => 'import-' . $suffix, 'to_domain' => 'NetworkID', 'to_identity' => 'supplier', 'mode' => 'requisition_only', 'deployment_mode' => 'test', 'return_encoding' => 'base64', 'cxml_version' => '1.2.008', 'delivery_code_prefix' => 'DEPOT' ] );
		if ( $id <= 0 ) { throw new RuntimeException( 'Native import fixture partner creation failed.' ); }
		return [ 'id' => $id, 'owner' => $owner ];
	}
	private function suite(): array {
		$f = $this->seed(); $owner = $f['owner']; $id = $f['id']; wp_set_current_user( $owner );
		$this->refused( $this->import->preview( $id, $owner, 'shipping' ), 'empty native source preview refuses' );
		$this->refused( $this->import->copy( $id, $owner, 0, 'shipping', [] ), 'empty native source copy refuses' );
		$address = [ 'first_name' => 'Zoë', 'last_name' => "O'Neil", 'company' => 'Example Company', 'address_1' => "12 O'Neil \\ Yard / 李", 'address_2' => '', 'city' => 'Oakland', 'state' => 'CA', 'postcode' => '94612', 'country' => 'US', 'phone' => '+1 (510) 555-0100' ];
		$customer = new WC_Customer( $owner );
		foreach ( $address as $key => $value ) { $customer->{'set_shipping_' . $key}( $value ); $customer->{'set_billing_' . $key}( $value ); }
		$customer->set_billing_address_1( '45 Billing Street' ); $customer->set_billing_email( 'private@example.invalid' ); $customer->save();
		$before = $this->source( $owner );
		$shipping = $this->ok( $this->import->preview( $id, $owner, 'shipping' ), 'native owner shipping preview' );
		$billing = $this->ok( $this->import->preview( $id, $owner, 'billing' ), 'native owner billing preview' );
		$this->check( $address === $shipping['address'] && 'woocommerce_shipping' === $shipping['source'] && 0 === $shipping['revision'], 'shipping preview canonical fields and provenance' );
		$this->check( array_replace( $address, [ 'address_1' => '45 Billing Street' ] ) === $billing['address'] && 'woocommerce_billing' === $billing['source'], 'billing preview strips email and retains canonical shipping fields' );
		$this->check( ! metadata_exists( 'user', $owner, '_pow_delivery_book_' . $id ) && $before === $this->source( $owner ), 'preview does not create book or alter source' );
		// Deliberate fixture source edit between preview and POST exercises the new WC_Customer reread.
		$customer = new WC_Customer( $owner ); $customer->set_shipping_address_1( '99 Changed Street' ); $customer->save();
		$before = $this->source( $owner );
		$a = $this->ok( $this->import->copy( $id, $owner, 0, 'shipping', [] ), 'native shipping copy' );
		$this->check( '99 Changed Street' === $a['entry']['address']['address_1'] && 'DEPOT-001' === $a['entry']['code'] && false === $a['entry']['use_for_punchout'], 'copy rereads source and saves generated disabled entry' );
		$this->refused( $this->import->copy( $id, $owner, 0, 'shipping', [] ), 'stale repeated POST refuses', 'address_book_stale' );
		$enabled = $this->ok( $this->book->save( $id, $owner, 1, $a['key'], array_replace( $a['entry'], [ 'use_for_punchout' => true ] ) ), 'separate explicit enable action' );
		wp_set_current_user( $this->admin );
		$b = $this->ok( $this->import->copy( $id, $this->admin, 2, 'billing', [ 'label' => 'Reviewed billing depot', 'code' => 'BILLING' ] ), 'actual administrator copies owner billing source' );
		$this->check( false === $b['entry']['use_for_punchout'] && $billing['address'] === $b['entry']['address'] && 'BILLING' === $b['entry']['code'] && 'Reviewed billing depot' === $b['entry']['label'], 'billing copy has requested label/code, stripped email and disabled state' );
		$c = $this->ok( $this->import->copy( $id, $this->admin, 3, 'shipping', [] ), 'deliberate second shipping copy' );
		$this->check( $a['key'] !== $c['key'] && 'DEPOT-002' === $c['entry']['code'] && false === $c['entry']['use_for_punchout'], 'same source gets a new key and code instead of matching postal text' );
		$this->refused( $this->import->copy( $id, $this->admin, 4, 'billing', [ 'code' => 'BILLING' ] ), 'duplicate code refuses', 'address_book_invalid' );
		foreach ( [ [ 'use_for_punchout' => true ], [ 'source_user_id' => $this->admin ], [ 'address' => $address ] ] as $overrides ) { $this->refused( $this->import->copy( $id, $this->admin, 4, 'shipping', $overrides ), 'unsupported import override refuses' ); }
		$this->refused( $this->import->preview( $id, $owner, 'billing' ), 'forged actor preview refuses', 'address_forbidden' );
		$this->refused( $this->import->copy( $id, $owner, 4, 'billing', [] ), 'forged actor copy refuses', 'address_forbidden' );
		$other = $this->user(); wp_set_current_user( $other );
		$this->refused( $this->import->preview( $id, $other, 'shipping' ), 'cross-company preview refuses', 'address_forbidden' );
		$this->refused( $this->import->copy( $id, $other, 4, 'shipping', [] ), 'cross-company copy refuses', 'address_forbidden' );
		wp_set_current_user( $this->admin ); $buyer = $this->user( POW\Installer::ROLE ); update_user_meta( $buyer, '_pow_partner_id', $id ); wp_set_current_user( $buyer );
		$this->refused( $this->import->preview( $id, $buyer, 'shipping' ), 'provisioned buyer preview refuses', 'address_forbidden' );
		$this->refused( $this->import->copy( $id, $buyer, 4, 'shipping', [] ), 'provisioned buyer copy refuses', 'address_forbidden' );
		wp_set_current_user( $owner );
		$customer = new WC_Customer( $owner ); $customer->set_shipping_postcode( 'INVALID' ); $customer->save();
		$this->refused( $this->import->copy( $id, $owner, 4, 'shipping', [] ), 'invalid current native postcode refuses despite old valid preview', 'address_invalid' );
		$customer->set_shipping_postcode( $before['shipping']['postcode'] ); $customer->save();
		$book = $this->ok( $this->book->read( $id, $owner ), 'native persisted book readback' );
		$this->check( 4 === $book['revision'] && 3 === count( $book['addresses'] ) && $enabled['entry'] === $book['addresses'][$a['key']], 'refusals leave revision unchanged and enabled entry intact' );
		$this->check( $before === $this->source( $owner ), 'both native sources unchanged by all import commands' );
		return $f + [ 'source' => $before, 'book' => $book ];
	}
	private function verify( array $fixture ): void {
		wp_set_current_user( $fixture['owner'] );
		$this->check( $fixture['source'] === $this->source( $fixture['owner'] ), 'fresh request native shipping and billing unchanged' );
		$book = $this->ok( $this->book->read( $fixture['id'], $fixture['owner'] ), 'fresh request checked native book readback' );
		$this->check( $fixture['book'] === $book, 'fresh request aggregate exactly matches accepted copies and enabled entry' );
	}
	public function run( string $mode, string $path ): void {
		try {
			if ( ! str_starts_with( $path, '/' ) || ! in_array( $mode, [ 'suite', 'verify' ], true ) ) { throw new RuntimeException( 'Choose suite or verify and an absolute private fixture path.' ); }
			if ( 'verify' === $mode ) {
				$this->verify( json_decode( file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR ) );
			} else {
				$handle = fopen( $path, 'x' );
				if ( false === $handle ) { throw new RuntimeException( 'Choose an unused private fixture path.' ); }
				try {
					if ( ! chmod( $path, 0600 ) ) { throw new RuntimeException( 'Private fixture permissions failed.' ); }
					$json = json_encode( $this->suite(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
					if ( strlen( $json ) !== fwrite( $handle, $json ) ) { throw new RuntimeException( 'Native import fixture write failed.' ); }
				} finally { fclose( $handle ); }
			}
		} finally {
			wp_set_current_user( $this->admin );
			echo "Native import ($mode): {$this->passed} passed, {$this->failed} failed, 0 skipped\n";
		}
	}
}

( new NativeImportNative() )->run( getenv( 'POW_NATIVE_IMPORT_MODE' ) ?: 'suite', getenv( 'POW_NATIVE_IMPORT_FIXTURE' ) ?: '' );
