<?php
/** The connection account's saved shipping addresses, read through the site's address-book API. @package POW @license AGPL-3.0-or-later */
declare( strict_types = 1 );
namespace POW\Addresses;

use POW\Partners\Partner;
use POW\Partners\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce itself keeps one shipping address per customer. A site that wants several keeps
 * them in an address-book extension, and this adapter reads that book for the connection's own
 * account: every visit of the connection then chooses from the account's saved addresses, the
 * account's default first. The company book (CompanyBook) stays the store while no such API
 * exists or the account holds no usable address.
 *
 * The API is found by shape, not by name of any product: a PHP function called `get_address_book`
 * (in any namespace) taking a `WC_Customer` and a type and returning an object with `addresses()`
 * (key → array of unprefixed WooCommerce address fields, plus an optional `address_nickname`) and
 * `default_key()`. The filter `punchout_address_book_function` can name the callable outright.
 *
 * Delivery codes are the plugin's own (AccountCodes), stored on the account per connection, so a
 * key the extension reuses after a deletion never carries a code onto another address.
 */
final class AccountBook {
	public const PROVIDER = 'account';
	public const SOURCE = 'account_book';
	private const META_PREFIX = '_pow_account_codes_';
	private const FIELDS = [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' ];

	/** @var string|false|null The resolved API function; false when none was found this request. */
	private static string|false|null $api = null;

	public function __construct( private Registry $registry ) {}

	/* ---------------------------------------------------------------- the API */

	/** Reset the per-request lookup (tests, or after plugins load). */
	public static function forget(): void { self::$api = null; }

	/** The address-book function, or null when the site has none. */
	public static function api(): ?string {
		if ( null !== self::$api ) { return false === self::$api ? null : self::$api; }
		$named = apply_filters( 'punchout_address_book_function', '' );
		$candidates = is_string( $named ) && '' !== $named ? [ $named ] : ( function_exists( 'get_defined_functions' ) ? get_defined_functions()['user'] : [] );
		self::$api = self::find_api( $candidates ) ?? false;
		return false === self::$api ? null : self::$api;
	}

	public static function available(): bool { return null !== self::api(); }

	/**
	 * Pick the address-book function out of a list of declared function names by its shape alone.
	 *
	 * @param list<string> $functions
	 */
	public static function find_api( array $functions ): ?string {
		foreach ( $functions as $name ) {
			if ( ! is_string( $name ) || 1 !== preg_match( '~(?:^|\\\\)get_address_book\z~i', $name ) || ! function_exists( $name ) ) { continue; }
			try {
				$reflection = new \ReflectionFunction( $name );
				$parameters = $reflection->getParameters();
				if ( count( $parameters ) < 2 ) { continue; }
				$type = $parameters[0]->getType();
				$class = $type instanceof \ReflectionNamedType ? ltrim( $type->getName(), '\\' ) : '';
				if ( 'WC_Customer' !== $class ) { continue; }
				return $name;
			} catch ( \Throwable $error ) { continue; }
		}
		return null;
	}

	/* ---------------------------------------------------------------- reading */

	/**
	 * The account's usable shipping addresses, default first: key → {label, address (canonical ten fields), fingerprint}.
	 * Addresses the store's own country rules refuse are left out. A WP_Error when the book cannot be read.
	 *
	 * @return array<string, array{label: string, address: array, fingerprint: string}>|\WP_Error
	 */
	public function read( int $user_id ): array|\WP_Error {
		$api = self::api();
		if ( null === $api || $user_id <= 0 || ! class_exists( '\WC_Customer' ) ) { return self::unavailable(); }
		try {
			$book = $api( new \WC_Customer( $user_id ), 'shipping' );
			if ( ! is_object( $book ) || ! method_exists( $book, 'addresses' ) || ! method_exists( $book, 'default_key' ) ) { return self::unavailable(); }
			$raw = $book->addresses();
			$default = $book->default_key();
			if ( ! is_array( $raw ) ) { return self::unavailable(); }
			return self::entries( $raw, is_string( $default ) ? $default : null );
		} catch ( \Throwable $error ) { return self::unavailable(); }
	}

	/**
	 * Canonical entries from the raw book: the default key first, then the book's own order; unusable addresses skipped.
	 *
	 * @param array<string, mixed> $raw
	 * @return array<string, array{label: string, address: array, fingerprint: string}>
	 */
	public static function entries( array $raw, ?string $default ): array {
		$entries = [];
		foreach ( self::ordered( $raw, $default ) as $key => $address ) {
			$fields = [];
			foreach ( self::FIELDS as $field ) { $fields[ $field ] = $address[ $field ] ?? ''; }
			$canonical = Shape::normalise( $fields );
			if ( $canonical instanceof \WP_Error ) { continue; }
			$entries[ $key ] = [ 'label' => self::label_for( $address, $canonical ), 'address' => $canonical, 'fingerprint' => AccountCodes::fingerprint( $canonical ) ];
		}
		return $entries;
	}

	/**
	 * The book's addresses with the default first, then the book's own order; keys that are not plain identifiers or whose value is not an array are dropped.
	 *
	 * @return array<string, array>
	 */
	public static function ordered( array $raw, ?string $default ): array {
		$ordered = [];
		if ( null !== $default && array_key_exists( $default, $raw ) ) { $ordered[ (string) $default ] = $raw[ $default ]; }
		foreach ( $raw as $key => $address ) { $ordered[ (string) $key ] ??= $address; }
		return array_filter( $ordered, static fn( mixed $address, string $key ): bool => is_array( $address ) && 1 === preg_match( '/\A[A-Za-z0-9_-]{1,190}\z/', $key ), ARRAY_FILTER_USE_BOTH );
	}

	/** The option text a buyer sees: the saved nickname, else the company, else "street, city". Bounded to 190 characters. */
	public static function label_for( array $raw, array $canonical ): string {
		$nickname = $raw['address_nickname'] ?? '';
		$nickname = is_scalar( $nickname ) ? trim( sanitize_text_field( (string) $nickname ) ) : '';
		$label = '' !== $nickname ? $nickname : trim( (string) ( $canonical['company'] ?? '' ) );
		if ( '' === $label ) { $label = trim( implode( ', ', array_filter( [ $canonical['address_1'] ?? '', $canonical['city'] ?? '' ], static fn( string $part ): bool => '' !== trim( $part ) ) ) ); }
		if ( '' === $label ) { $label = __( 'Saved address', 'punchout-woocommerce' ); }
		return preg_match_all( '/./us', $label ) > 190 ? (string) mb_substr( $label, 0, 190 ) : $label;
	}

	/* ---------------------------------------------------------------- choices for a visit */

	/**
	 * The review's choices for this connection, or null when the account book is not in use (no API, unreadable, or empty).
	 * Caller holds the partner mutex. Codes are reconciled and, when changed, stored on the account.
	 *
	 * @return list<array>|null
	 */
	public function choices_locked( Partner $partner ): ?array {
		if ( ! self::available() || $partner->owner_user_id <= 0 || 'company' === apply_filters( 'punchout_address_provider', 'auto', $partner ) ) { return null; }
		$entries = $this->read( $partner->owner_user_id );
		if ( $entries instanceof \WP_Error || [] === $entries ) { return null; }
		$codes = $this->codes_for( $partner, $entries );
		if ( $codes instanceof \WP_Error ) { return null; }
		$choices = [];
		foreach ( $entries as $key => $entry ) { $choices[] = self::choice( $partner, $key, $entry, $codes[ $key ] ?? '' ); }
		return $choices;
	}

	/** Whether a visit of this connection currently chooses from the account book rather than the company book. */
	public function in_use( Partner $partner ): bool {
		try { return null !== $this->registry->with_partner_lock( $partner->id, fn() => $this->choices_locked( $partner ) ); }
		catch ( \Throwable $error ) { return false; }
	}

	/** One review choice in the plugin's fixed shape. */
	public static function choice( Partner $partner, string $key, array $entry, string $code ): array {
		return [ 'schema' => 1, 'partner_id' => $partner->id, 'storage_user_id' => $partner->owner_user_id, 'provider' => self::PROVIDER, 'key' => $key, 'code' => $code, 'address' => $entry['address'], 'label' => $entry['label'], 'source' => self::SOURCE, 'book_revision' => null, 'entry_fingerprint' => self::entry_fingerprint( $key, $entry['label'], $entry['address'], $code ) ];
	}

	/** What a stored selection is checked against later: the key, the label, the address and the code, nothing else. */
	public static function entry_fingerprint( string $key, string $label, array $address, string $code ): string {
		return DeliveryData::fingerprint( [ 'key' => $key, 'label' => $label, 'address' => $address, 'code' => $code ] );
	}

	/**
	 * Is this accepted choice still the account's address it was when chosen? Caller holds the partner mutex.
	 *
	 * @return true|\WP_Error address_unavailable (gone) or address_changed (different address, label or code under that key).
	 */
	public function validate_choice_locked( Partner $partner, array $choice ): bool|\WP_Error {
		$entries = $this->read( $partner->owner_user_id );
		if ( $entries instanceof \WP_Error ) { return new \WP_Error( 'address_state_unavailable', __( 'The delivery address could not be verified. Reload the page and try again.', 'punchout-woocommerce' ) ); }
		$entry = $entries[ $choice['key'] ] ?? null;
		if ( null === $entry ) { return new \WP_Error( 'address_unavailable', __( 'The selected address is no longer available. Select an enabled company address.', 'punchout-woocommerce' ) ); }
		$codes = $this->codes_for( $partner, $entries );
		if ( $codes instanceof \WP_Error ) { return new \WP_Error( 'address_state_unavailable', __( 'The delivery address could not be verified. Reload the page and try again.', 'punchout-woocommerce' ) ); }
		$current = self::entry_fingerprint( $choice['key'], $entry['label'], $entry['address'], $codes[ $choice['key'] ] ?? '' );
		if ( ! is_string( $choice['entry_fingerprint'] ?? null ) || ! hash_equals( $choice['entry_fingerprint'], $current ) ) { return new \WP_Error( 'address_changed', __( 'The selected address changed. Review and confirm it again.', 'punchout-woocommerce' ) ); }
		return true;
	}

	/* ---------------------------------------------------------------- the code map */

	/** The stored map for this connection's account. */
	public function map( Partner $partner ): array {
		return AccountCodes::normalise( get_user_meta( $partner->owner_user_id, self::META_PREFIX . $partner->id, true ) );
	}

	/** Store a map (caller holds the partner mutex). */
	public function save_map( Partner $partner, array $map ): bool {
		$stored = update_user_meta( $partner->owner_user_id, self::META_PREFIX . $partner->id, $map );
		return false !== $stored || $map === $this->map( $partner );
	}

	/**
	 * Each entry's code, after reconciling the map with the addresses as they are now; a changed map is stored.
	 *
	 * @param array<string, array{fingerprint: string}> $entries
	 * @return array<string, string>|\WP_Error
	 */
	private function codes_for( Partner $partner, array $entries ): array|\WP_Error {
		try {
			$result = AccountCodes::reconcile( $this->map( $partner ), array_map( static fn( array $entry ): string => $entry['fingerprint'], $entries ), $partner->delivery_code_prefix );
		} catch ( \Throwable $error ) { return self::unavailable(); }
		if ( $result['changed'] && ! $this->save_map( $partner, $result['map'] ) ) { return self::unavailable(); }
		return $result['codes'];
	}

	/* ---------------------------------------------------------------- migration */

	/**
	 * Copy the company book's enabled entries into the account's address book and carry their codes over.
	 * Entries whose address the account already holds are skipped (so a rerun changes nothing). The first
	 * copied entry becomes the account's default when it had none. Caller holds the partner mutex.
	 *
	 * @param array<string, array{label: string, address: array, code: string, use_for_punchout: bool}> $company The company book's addresses.
	 * @return array{copied: list<array{from: string, to: string, label: string, code: string}>, skipped: list<array{from: string, to: string, label: string}>, default: ?string}|\WP_Error
	 */
	public function migrate_locked( Partner $partner, array $company, bool $dry_run ): array|\WP_Error {
		$api = self::api();
		if ( null === $api || $partner->owner_user_id <= 0 || ! class_exists( '\WC_Customer' ) ) { return self::unavailable(); }
		$existing = $this->read( $partner->owner_user_id );
		if ( $existing instanceof \WP_Error ) { return $existing; }
		$by_fingerprint = [];
		foreach ( $existing as $key => $entry ) { $by_fingerprint[ $entry['fingerprint'] ] = $key; }
		$report = [ 'copied' => [], 'skipped' => [], 'default' => null ];
		$map = $this->map( $partner );
		$book = null;
		foreach ( $company as $from => $entry ) {
			if ( true !== ( $entry['use_for_punchout'] ?? false ) ) { continue; }
			$canonical = Shape::normalise( $entry['address'] );
			if ( $canonical instanceof \WP_Error ) { return new \WP_Error( 'address_invalid', sprintf( 'Company entry %s does not pass the store\'s address rules.', (string) $from ) ); }
			$fingerprint = AccountCodes::fingerprint( $canonical );
			$code = (string) ( $entry['code'] ?? '' );
			if ( isset( $by_fingerprint[ $fingerprint ] ) ) {
				$report['skipped'][] = [ 'from' => (string) $from, 'to' => $by_fingerprint[ $fingerprint ], 'label' => (string) $entry['label'] ];
				continue;
			}
			$address = $canonical + [ 'address_nickname' => (string) $entry['label'] ];
			if ( $dry_run ) {
				$report['copied'][] = [ 'from' => (string) $from, 'to' => '(new)', 'label' => (string) $entry['label'], 'code' => $code ];
				continue;
			}
			try {
				$book ??= $api( new \WC_Customer( $partner->owner_user_id ), 'shipping' );
				if ( ! is_object( $book ) || ! method_exists( $book, 'add' ) ) { return self::unavailable(); }
				$as_default = null === $book->default_key() && [] === $report['copied'];
				$to = (string) $book->add( $address, $as_default );
				if ( $as_default ) { $report['default'] = $to; }
			} catch ( \Throwable $error ) { return self::unavailable(); }
			$map = AccountCodes::assign( $map, $to, $code, $fingerprint );
			if ( $map instanceof \WP_Error ) { return $map; }
			$by_fingerprint[ $fingerprint ] = $to;
			$report['copied'][] = [ 'from' => (string) $from, 'to' => $to, 'label' => (string) $entry['label'], 'code' => $code ];
		}
		if ( ! $dry_run && [] !== $report['copied'] && ! $this->save_map( $partner, $map ) ) { return self::unavailable(); }
		return $report;
	}

	private static function unavailable(): \WP_Error {
		return new \WP_Error( 'address_state_unavailable', __( 'The account’s saved addresses could not be read.', 'punchout-woocommerce' ) );
	}
}
