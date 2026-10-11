<?php
/**
 * Saved addresses only in a visit (0.5.1): the buyer chooses one of the bound account's saved delivery addresses
 * and cannot add, edit or type one.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Checkout;

use POW\Addresses\AccountBook;
use POW\Plugin;
use POW\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Every buyer of a connection shops on the one account the connection is bound to, and the addresses that account
 * keeps are the ones the store has agreed to deliver to. Ruling of 11 October 2026: during a live visit the buyer
 * chooses one of them, and nothing else. With the setting "Saved addresses only in a visit" on (the default; the
 * filter `punchout_visit_saved_addresses_only` runs last) and inside a live visit:
 *
 * - **The checkout fields are rewritten last** (`woocommerce_checkout_fields`, PHP_INT_MAX, after any address book
 *   has drawn its selector). In the billing and shipping groups, a field whose key ends in `_address_book` (an
 *   address-book plugin's selector, whatever the plugin) loses its `add_new` choice; a default of `add_new` moves
 *   to the first saved entry. Every other field in those groups becomes WooCommerce's own `hidden` type, keeping
 *   its key (so its id and name), its value, its classes and its required flag, and losing its label and
 *   placeholder: it still posts, an address-book script still fills the same ids on a card change, and the chosen
 *   saved address supplies what is required. The selector itself stays visible. Other groups are not touched.
 * - **A posted delivery address that is not a saved entry is refused** before any order exists (through
 *   `Checkout\Validation`): its street, town, postcode and country are compared, after trim and lowercase, with
 *   each of the account's saved entries. The entries are found by shape, never by the name of a product:
 *   WooCommerce's own customer shipping address, the address-book API a site may have (`Addresses\AccountBook`),
 *   and the account's user meta (`shipping_<key>_address_1` with its siblings, and any serialised array under a
 *   key naming shipping that holds an `address_1`). An account with no saved delivery address takes what is
 *   posted, so a shop without an address book still works; an empty posted address is nothing to compare.
 *
 * Outside a live visit, and with the setting off, nothing changes: ordinary shoppers keep WooCommerce's checkout,
 * and a visit may type a one-off address as in 0.5.0.
 */
final class SavedAddresses {

	/** The option key an address-book selector offers for typing a new address. */
	public const ADD_NEW = 'add_new';

	/** The suffix of an address-book selector's field key. */
	public const SELECTOR_SUFFIX = '_address_book';

	/** The fields that decide whether two addresses are the same place. */
	public const CORE = [ 'address_1', 'city', 'postcode', 'country' ];

	/** The checkout field groups that hold an address. */
	private const GROUPS = [ 'billing', 'shipping' ];

	/** A user meta key holding a saved shipping street: the customer's own, or an address book's prefixed entry. */
	private const META_STREET = '/\Ashipping_(?:([a-z0-9]+)_)?address_1\z/';

	/** @var \Closure(int): list<array<string, mixed>> Reads an account's saved delivery entries, raw. */
	private \Closure $saved;

	/**
	 * @param \Closure(int): list<array<string, mixed>>|null $saved Reads the account's saved entries by user id; the
	 *                                                              store's customer, address-book API and user meta
	 *                                                              by default.
	 */
	public function __construct(
		private Plugin $plugin,
		private PaymentMethod $method,
		private Settings $settings,
		?\Closure $saved = null,
	) {
		$this->saved = $saved ?? static fn( int $user_id ): array => self::stored_entries( $user_id );
	}

	public function register(): void {
		add_filter( 'woocommerce_checkout_fields', [ $this, 'checkout_fields' ], PHP_INT_MAX );
	}

	/** Whether the rule applies to this request: the setting on (after its filter) and a live visit owning it. */
	public function active(): bool {
		try {
			return $this->settings->visit_saved_addresses_only() && $this->method->live_visit();
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/** `woocommerce_checkout_fields`, last: inside a live visit the address fields offer saved entries only. */
	public function checkout_fields( mixed $fields ): mixed {
		if ( ! is_array( $fields ) || ! $this->active() ) { return $fields; }
		return self::restrict( $fields );
	}

	/**
	 * The checkout fields with every address-book selector's add-new choice removed and every other billing and
	 * shipping field hidden. Pure.
	 *
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	public static function restrict( array $fields ): array {
		foreach ( self::GROUPS as $group ) {
			if ( ! isset( $fields[ $group ] ) || ! is_array( $fields[ $group ] ) ) { continue; }
			foreach ( $fields[ $group ] as $key => $field ) {
				if ( ! is_array( $field ) ) { continue; }
				$fields[ $group ][ $key ] = str_ends_with( (string) $key, self::SELECTOR_SUFFIX ) ? self::without_add_new( $field ) : self::hidden( $field );
			}
		}
		return $fields;
	}

	/** An address-book selector without its add-new choice; a default of add new moves to the first remaining entry. */
	private static function without_add_new( array $field ): array {
		if ( ! isset( $field['options'] ) || ! is_array( $field['options'] ) ) { return $field; }
		unset( $field['options'][ self::ADD_NEW ] );
		if ( self::ADD_NEW === ( $field['default'] ?? null ) ) {
			$first = array_key_first( $field['options'] );
			if ( null === $first ) {
				unset( $field['default'] );
			} else {
				$field['default'] = (string) $first;
			}
		}
		return $field;
	}

	/** A field that still posts but cannot be typed: WooCommerce's own hidden type, without a label or placeholder. */
	private static function hidden( array $field ): array {
		$field['type'] = 'hidden';
		unset( $field['label'], $field['placeholder'] );
		return $field;
	}

	/**
	 * Whether the posted checkout data names a delivery address that is not one of the visit's account's saved
	 * entries, while the rule applies. An account with no saved entry, an empty posted address and an account whose
	 * entries cannot be read refuse nothing.
	 *
	 * @param array<string, mixed> $data The posted checkout data (shipping_* keys as WooCommerce hands them on).
	 */
	public function refuses( array $data ): bool {
		if ( ! $this->active() ) { return false; }
		$posted = [];
		foreach ( self::CORE as $field ) {
			$value            = $data[ 'shipping_' . $field ] ?? '';
			$posted[ $field ] = is_scalar( $value ) ? (string) $value : '';
		}
		if ( self::blank( $posted ) ) { return false; }
		try {
			$visit   = $this->plugin->current_session();
			$entries = null !== $visit ? ( $this->saved )( $visit->user_id ) : [];
		} catch ( \Throwable $error ) {
			return false;
		}
		$entries = array_values( array_filter( array_map( [ self::class, 'core' ], array_filter( $entries, 'is_array' ) ), static fn( array $entry ): bool => ! self::blank( $entry ) ) );
		if ( [] === $entries ) { return false; }
		return ! self::matches( $posted, $entries );
	}

	/**
	 * Whether the posted address is one of the entries: the core fields compared after trim and lowercase.
	 *
	 * @param array<string, string>       $posted
	 * @param list<array<string, string>> $entries Each already reduced to its core fields.
	 */
	public static function matches( array $posted, array $entries ): bool {
		$wanted = self::core( $posted );
		foreach ( $entries as $entry ) {
			if ( self::core( $entry ) === $wanted ) { return true; }
		}
		return false;
	}

	/**
	 * The core fields of an address, each trimmed and lowercased, in a fixed order.
	 *
	 * @param array<string, mixed> $address
	 * @return array<string, string>
	 */
	public static function core( array $address ): array {
		$core = [];
		foreach ( self::CORE as $field ) {
			$value          = $address[ $field ] ?? '';
			$core[ $field ] = is_scalar( $value ) ? mb_strtolower( trim( (string) $value ) ) : '';
		}
		return $core;
	}

	/** @param array<string, string> $core */
	private static function blank( array $core ): bool {
		return '' === trim( implode( '', self::core( $core ) ) );
	}

	/**
	 * The saved delivery entries found in an account's user meta, by shape: a `shipping_address_1` or
	 * `shipping_<key>_address_1` key with its sibling city, postcode and country keys, and any serialised array
	 * under a key naming shipping that holds an `address_1`. Nothing named after a product; billing entries are
	 * not delivery addresses.
	 *
	 * @param array<string, mixed> $meta Every meta row of the account, key => list of raw values (get_user_meta).
	 * @return list<array<string, string>>
	 */
	public static function from_meta( array $meta ): array {
		$entries = [];
		foreach ( $meta as $key => $values ) {
			$key = (string) $key;
			if ( ! is_array( $values ) || [] === $values ) { continue; }
			$raw = reset( $values );
			if ( 1 === preg_match( self::META_STREET, $key, $found ) ) {
				if ( ! is_scalar( $raw ) ) { continue; }
				$prefix = 'shipping_' . ( isset( $found[1] ) && '' !== $found[1] ? $found[1] . '_' : '' );
				$entry  = [ 'address_1' => (string) $raw ];
				foreach ( [ 'city', 'postcode', 'country' ] as $field ) {
					$sibling         = $meta[ $prefix . $field ] ?? [];
					$value           = is_array( $sibling ) ? reset( $sibling ) : null;
					$entry[ $field ] = is_scalar( $value ) ? (string) $value : '';
				}
				$entries[] = $entry;
				continue;
			}
			if ( ! str_contains( $key, 'shipping' ) ) { continue; }
			$array = self::unserialised( $raw );
			if ( null !== $array && isset( $array['address_1'] ) && is_scalar( $array['address_1'] ) ) {
				$entry = [];
				foreach ( self::CORE as $field ) { $entry[ $field ] = isset( $array[ $field ] ) && is_scalar( $array[ $field ] ) ? (string) $array[ $field ] : ''; }
				$entries[] = $entry;
			}
		}
		return $entries;
	}

	/** A serialised PHP array (no objects) as an array, else null. */
	private static function unserialised( mixed $raw ): ?array {
		if ( is_array( $raw ) ) { return $raw; }
		if ( ! is_string( $raw ) || ! str_starts_with( $raw, 'a:' ) ) { return null; }
		$value = @unserialize( $raw, [ 'allowed_classes' => false ] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a value that is not serialised is not an entry.
		return is_array( $value ) ? $value : null;
	}

	/**
	 * An account's saved delivery entries as the store keeps them: the customer's own shipping address, the entries
	 * of an address-book API found by shape (`Addresses\AccountBook`), and every entry its user meta holds.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function stored_entries( int $user_id ): array {
		$entries = [];
		if ( class_exists( '\WC_Customer' ) ) {
			try {
				$customer = new \WC_Customer( $user_id );
				$shipping = $customer->get_shipping();
				if ( is_array( $shipping ) ) { $entries[] = $shipping; }
			} catch ( \Throwable $error ) {
				// The account's own address is also read from its meta below.
			}
			$api = AccountBook::api();
			if ( null !== $api ) {
				try {
					$book = $api( new \WC_Customer( $user_id ), 'shipping' );
					$raw  = is_object( $book ) && method_exists( $book, 'addresses' ) ? $book->addresses() : [];
					foreach ( is_array( $raw ) ? $raw : [] as $address ) {
						if ( is_array( $address ) ) { $entries[] = $address; }
					}
				} catch ( \Throwable $error ) {
					// The book's entries are also read from the meta below.
				}
			}
		}
		if ( function_exists( 'get_user_meta' ) ) {
			$meta = get_user_meta( $user_id );
			foreach ( self::from_meta( is_array( $meta ) ? $meta : [] ) as $entry ) { $entries[] = $entry; }
		}
		return $entries;
	}
}
