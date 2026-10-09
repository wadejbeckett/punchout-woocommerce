<?php
/**
 * Checkout in a visit and the bound account: it reads the account's billing profile and writes nothing (0.5.0).
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
 * - **Billing opens on the account's saved billing profile.** WooCommerce opens a billing field on the visit's
 *   session customer, which holds whatever was typed earlier in this visit. During a live visit each billing field
 *   the account has a saved value for opens on that value instead, read from the account itself
 *   (`woocommerce_checkout_get_value`). A field the account leaves empty, a value another plugin already gave, and
 *   every other field are left to WooCommerce. PunchOut owns no field.
 *
 * Ordinary shoppers, including the bound account on its own password login, keep WooCommerce's behaviour.
 */
final class VisitAccount {

	/** @var \Closure(int): object Reads an account as WooCommerce stores it (a WC_Customer from the database). */
	private \Closure $account;

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
