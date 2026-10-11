<?php
/**
 * Checkout in a visit and the bound account: it reads the account's billing profile, and nothing in a visit writes to
 * the account (0.5.0).
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Checkout;

use POW\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Every buyer of a connection shops on the one WooCommerce account the connection is bound to, so what checkout does
 * with that account is everyone's:
 *
 * - **Nothing is saved to it.** WooCommerce copies the posted billing and shipping fields onto the customer and saves
 *   it whenever `woocommerce_checkout_update_customer_data` answers true. A plugin that keeps an address book saves
 *   its entries (and, for the default entry, the customer's own billing or shipping fields) from that same filter,
 *   later, and only while the value it receives is still true. Inside a visit, or when that cannot be established,
 *   the answer is false early (priority 10), so neither runs, and again last, so the answer WooCommerce reads stays
 *   false whatever ran in between. In a visit an address book therefore saves no entries: buyers choose from the
 *   saved addresses the store keeps on the account, and an address typed for one order stays on that order only.
 * - **Nothing else writes to it either (the account fence).** Checkout's filter is one door; the account's user meta
 *   has many (an address book's own AJAX actions on My Account, a profile form, any plugin that saves a preference
 *   on the signed-in user). Inside a live visit every add, update and delete of the visit's account's user meta is
 *   refused through WordPress's own metadata short-circuits (`add_user_metadata`, `update_user_metadata`,
 *   `delete_user_metadata`, and the by-row-id pair `update_user_metadata_by_mid` and `delete_user_metadata_by_mid`
 *   that WooCommerce's own data store writes through), early and again last, except the keys a visit's own life writes: the login's
 *   `session_tokens` row, WooCommerce's `wc_last_active` stamp, the profile's `last_update` and the persistent
 *   cart row (`_woocommerce_persistent_cart_*`, off in a visit anyway). Each refused key is audited once per request
 *   as `visit_account_write_refused`. An answer another filter already gave stands (the cart guard keeps the
 *   saved-cart merge flag that way). A request whose visit cannot be established writes nothing to its own account.
 * - **Billing opens on the account's saved billing profile.** WooCommerce opens a billing field on the visit's
 *   session customer, which holds whatever was typed earlier in this visit. During a live visit each billing field
 *   the account has a saved value for opens on that value instead, read from the account itself
 *   (`woocommerce_checkout_get_value`). A field the account leaves empty, a value another plugin already gave, and
 *   every other field are left to WooCommerce. PunchOut owns no field.
 *
 * Ordinary shoppers, including the bound account on its own password login, keep WooCommerce's behaviour.
 */
final class VisitAccount {

	/** The user meta keys a visit's own life writes on the bound account, exactly. */
	public const META_ALLOWED = [ 'session_tokens', 'wc_last_active', 'last_update' ];

	/** And by prefix: WooCommerce's persistent cart row, one per account. */
	public const META_ALLOWED_PREFIXES = [ '_woocommerce_persistent_cart' ];

	/** @var \Closure(int): object Reads an account as WooCommerce stores it (a WC_Customer from the database). */
	private \Closure $account;

	/** @var array<string, true> The keys this request has already recorded a refusal for. */
	private array $refused = [];

	/**
	 * @param \Closure(int): object|null $account Reads the account by id; WooCommerce's own customer by default.
	 */
	public function __construct(
		private Plugin $plugin,
		private PaymentMethod $method,
		?\Closure $account = null,
	) {
		$this->account = $account ?? static fn( int $user_id ): object => new \WC_Customer( $user_id );
	}

	public function register(): void {
		add_filter( 'woocommerce_checkout_update_customer_data', [ $this, 'update_customer_data' ], 10 );
		add_filter( 'woocommerce_checkout_update_customer_data', [ $this, 'update_customer_data' ], PHP_INT_MAX );
		add_filter( 'woocommerce_checkout_get_value', [ $this, 'checkout_value' ], 10, 2 );
		// The account fence: first, so the refusal is the first answer, and last, so nothing in between turns it back
		// into a write.
		foreach ( [ 'add', 'update', 'delete' ] as $operation ) {
			add_filter( $operation . '_user_metadata', [ $this, 'refuse_' . $operation ], 1, 3 );
			add_filter( $operation . '_user_metadata', [ $this, 'refuse_' . $operation ], PHP_INT_MAX, 3 );
		}
		foreach ( [ 'update', 'delete' ] as $operation ) {
			add_filter( $operation . '_user_metadata_by_mid', [ $this, 'refuse_' . $operation . '_by_mid' ], 1, 2 );
			add_filter( $operation . '_user_metadata_by_mid', [ $this, 'refuse_' . $operation . '_by_mid' ], PHP_INT_MAX, 2 );
		}
	}

	/** `update_user_metadata_by_mid`: the row the id names, fenced like a keyed write. */
	public function refuse_update_by_mid( mixed $check, mixed $meta_id = 0 ): mixed {
		return $this->refuse_meta_write_by_id( 'update_by_mid', $check, $meta_id );
	}

	/** `delete_user_metadata_by_mid`: the row the id names, fenced like a keyed write. */
	public function refuse_delete_by_mid( mixed $check, mixed $meta_id = 0 ): mixed {
		return $this->refuse_meta_write_by_id( 'delete_by_mid', $check, $meta_id );
	}

	/** A write by meta row id: the row is read (get_metadata_by_mid) and judged by its user and key; a row that does not exist is WordPress's to refuse. */
	private function refuse_meta_write_by_id( string $operation, mixed $check, mixed $meta_id ): mixed {
		if ( null !== $check || ! function_exists( 'get_metadata_by_mid' ) ) { return $check; }
		try {
			$row = is_numeric( $meta_id ) ? get_metadata_by_mid( 'user', (int) $meta_id ) : false;
		} catch ( \Throwable $error ) {
			return null;
		}
		if ( ! is_object( $row ) || ! isset( $row->user_id, $row->meta_key ) ) { return null; }
		return $this->refuse_meta_write( $operation, null, $row->user_id, (string) $row->meta_key );
	}

	/** `add_user_metadata`: inside a live visit, no new meta on the visit's account. */
	public function refuse_add( mixed $check, mixed $object_id = 0, mixed $meta_key = '' ): mixed {
		return $this->refuse_meta_write( 'add', $check, $object_id, $meta_key );
	}

	/** `update_user_metadata`: inside a live visit, no change to the visit's account's meta. */
	public function refuse_update( mixed $check, mixed $object_id = 0, mixed $meta_key = '' ): mixed {
		return $this->refuse_meta_write( 'update', $check, $object_id, $meta_key );
	}

	/** `delete_user_metadata`: inside a live visit, nothing of the visit's account's meta is removed. */
	public function refuse_delete( mixed $check, mixed $object_id = 0, mixed $meta_key = '' ): mixed {
		return $this->refuse_meta_write( 'delete', $check, $object_id, $meta_key );
	}

	/** Whether a key is one a visit's own life writes. */
	public static function meta_allowed( string $meta_key ): bool {
		if ( in_array( $meta_key, self::META_ALLOWED, true ) ) { return true; }
		foreach ( self::META_ALLOWED_PREFIXES as $prefix ) {
			if ( str_starts_with( $meta_key, $prefix ) ) { return true; }
		}
		return false;
	}

	/**
	 * The fence's one answer: null lets WordPress write, false refuses, and an answer another filter already gave
	 * stands. A write is refused when it names the visit's own account and a key the visit's life does not write;
	 * when the visit cannot be established, when it names the signed-in account.
	 */
	private function refuse_meta_write( string $operation, mixed $check, mixed $object_id, mixed $meta_key ): mixed {
		if ( null !== $check ) { return $check; }
		$key     = is_string( $meta_key ) ? $meta_key : '';
		$user_id = is_numeric( $object_id ) ? (int) $object_id : 0;
		if ( $user_id <= 0 || self::meta_allowed( $key ) ) { return null; }
		try {
			$visit = $this->plugin->current_session();
			if ( null === $visit || $visit->user_id !== $user_id ) { return null; }
		} catch ( \Throwable $error ) {
			if ( $user_id !== (int) get_current_user_id() ) { return null; }
			$visit = null;
		}
		$this->record_refusal( $operation, $user_id, $key, $visit );
		return false;
	}

	/** One audit row per key per request; a failed row never lets the write through. */
	private function record_refusal( string $operation, int $user_id, string $key, ?\POW\Sessions\Session $visit ): void {
		if ( isset( $this->refused[ $key ] ) ) { return; }
		$this->refused[ $key ] = true;
		try {
			$this->plugin->audit()?->write_checked(
				'visit_account_write_refused',
				[
					'partner_id' => $visit?->partner_id ?? 0,
					'session_id' => $visit?->id ?? 0,
					'user_id'    => $user_id,
					'result'     => 'refused',
					'detail'     => [ 'operation' => $operation, 'meta_key' => substr( (string) preg_replace( '/[^\x20-\x7e]/', '', $key ), 0, 190 ) ],
				]
			);
		} catch ( \Throwable $error ) {
			// The refusal is the protection; the record is a courtesy.
		}
	}

	/** `woocommerce_checkout_update_customer_data`: false inside a visit (or when that cannot be established). */
	public function update_customer_data( mixed $update ): mixed {
		try {
			return null !== $this->plugin->current_session() ? false : $update;
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/**
	 * `woocommerce_checkout_get_value`: during a live visit, a billing field opens on the account's saved value.
	 *
	 * @param mixed $value A value an earlier filter gave, else null.
	 * @param mixed $input The checkout field's name.
	 */
	public function checkout_value( mixed $value, mixed $input = '' ): mixed {
		if ( null !== $value || ! is_string( $input ) || ! str_starts_with( $input, 'billing_' ) || ! $this->method->live_visit() ) {
			return $value;
		}
		try {
			$visit    = $this->plugin->current_session();
			$customer = null !== $visit ? ( $this->account )( $visit->user_id ) : null;
			$getter   = 'get_' . $input;
			if ( null === $customer || ! is_callable( [ $customer, $getter ] ) ) { return $value; }
			$saved = $customer->{$getter}();
			return is_string( $saved ) && '' !== $saved ? $saved : $value;
		} catch ( \Throwable $error ) {
			return $value;
		}
	}
}
