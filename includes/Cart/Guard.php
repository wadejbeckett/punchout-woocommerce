<?php
/**
 * Cart-level guards for punchout visits.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Cart;

use POW\Logger;
use POW\Plugin;
use POW\Sessions\Store;
use POW\Audit\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Four cart behaviours scoped strictly to active punchout visits
 * (scope §5.3/§6.1); nothing here touches ordinary shoppers:
 *
 * - Persistent cart disabled: one visit's basket must never reach another
 *   visit of the same bound account, and last week's basket must never
 *   resurrect into a fresh visit.
 * - WooCommerce's saved-cart merge flag hidden and kept: the second
 *   user-meta key the persistent-cart filter does not cover.
 * - The cart is emptied once, on the first authenticated request of a
 *   `create` visit (deferred from the login request, where WC()->cart
 *   still belongs to the guest session).
 * - Add-to-cart range validation: hiding a product is display filtering,
 *   not access control — ?wc-ajax=add_to_cart accepts an arbitrary
 *   product id, so the server rejects products outside the buyer's
 *   visible range here (leak surface L7).
 *
 * Every question here is answered from the visit the resolver handed this
 * request, never from the account id: one customer account holds every
 * buyer's login, so the account distinguishes nothing.
 */
final class Guard {

	/**
	 * WooCommerce's "merge the saved cart on the next hydration" flag.
	 *
	 * wc_user_logged_in() sets it on every wp_login of the bound account,
	 * and WC_Cart_Session::get_cart_from_session() reads it and deletes it
	 * OUTSIDE the woocommerce_persistent_cart_enabled guard — so without
	 * the two filters below a visit would merge the account holder's own
	 * saved basket into its own and then delete a flag that is theirs.
	 */
	private const MERGE_SAVED_CART = '_woocommerce_load_saved_cart_after_login';

	public function __construct(
		private Plugin $plugin,
		private Store $sessions,
		private Log $audit,
		private Logger $logger,
	) {}

	public function register(): void {
		add_filter( 'woocommerce_persistent_cart_enabled', [ $this, 'disable_persistent_cart' ] );
		add_filter( 'get_user_metadata', [ $this, 'hide_saved_cart_merge_flag' ], 10, 4 );
		add_filter( 'delete_user_metadata', [ $this, 'keep_saved_cart_merge_flag' ], 10, 3 );
		// Woo restores at 10 and handles native add-to-cart forms at 20.
		add_action( 'wp_loaded', [ $this, 'seed_cart' ], 15 );
		add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'validate_add_to_cart' ], 20, 2 );
	}

	/**
	 * Turn WooCommerce's persistent cart off for the whole of a visit.
	 *
	 * The persistent cart lives in one user-meta row per account, and its
	 * three core sites — WC_Cart_Session::get_saved_cart(),
	 * ::persistent_cart_update() and ::persistent_cart_destroy() — are all
	 * keyed on get_current_user_id(), i.e. on the account every buyer of
	 * this connection shares. Returning false means a visit neither reads,
	 * writes nor deletes it: visit A's basket cannot appear in visit B, and
	 * the account holder's own saved basket survives every punch-in.
	 *
	 * This filter is the single point of failure for basket isolation, and
	 * it fails OPEN. With no visit resolved the incoming value is returned
	 * untouched and core resumes merging and writing that one shared row —
	 * which is why Plugin::current_session() must not cache a resolution
	 * that could not run, and is not gated on the master switch.
	 *
	 * @param mixed $enabled Incoming filter value.
	 * @return mixed
	 */
	public function disable_persistent_cart( $enabled ) {
		return null !== $this->plugin->current_session() ? false : $enabled;
	}

	/**
	 * Hide WooCommerce's saved-cart merge flag from a visit.
	 *
	 * A returned value short-circuits get_metadata_raw() before the
	 * database, so the flag an ordinary password login of the bound account
	 * left behind is not read inside a visit and cannot merge that
	 * account's saved basket into this visit's.
	 *
	 * @param mixed  $value     Short-circuit value; null reads normally.
	 * @param mixed  $object_id The user the meta row belongs to.
	 * @param mixed  $meta_key  The meta key being read.
	 * @param mixed  $single    Whether one value was asked for.
	 * @return mixed
	 */
	public function hide_saved_cart_merge_flag( $value, $object_id, $meta_key, $single = false ) {
		if ( ! $this->hides_merge_flag( (string) $meta_key, (int) $object_id ) ) {
			return $value;
		}

		// '' for a single read and an empty list otherwise: both are
		// non-null, so core stops before the query, and both mean "unset".
		return $single ? '' : [];
	}

	/**
	 * Keep that flag instead of letting a visit delete it.
	 *
	 * Core deletes the flag whenever it takes its merge branch, and every
	 * visit's first hydration takes it: the visit's own session row starts
	 * with no cart at all. Hiding the read is therefore not enough — each
	 * punch-in would still DELETE a user-meta row of the shared account,
	 * discarding the account holder's pending merge and writing to a row
	 * other live visits fingerprint. Reporting success leaves it untouched.
	 *
	 * @param mixed $delete    Short-circuit value; null deletes normally.
	 * @param mixed $object_id The user the meta row belongs to.
	 * @param mixed $meta_key  The meta key being deleted.
	 * @return mixed
	 */
	public function keep_saved_cart_merge_flag( $delete, $object_id, $meta_key ) {
		return $this->hides_merge_flag( (string) $meta_key, (int) $object_id ) ? true : $delete;
	}

	/**
	 * Whether this meta row is the merge flag of an account inside a visit.
	 *
	 * The account id is compared because a meta row belongs to a user row —
	 * it says which row this is, not whose basket it is. Basket ownership is
	 * the visit key's question and is never asked here. The key is tested
	 * first so that every other user-meta read on the site costs one string
	 * comparison and never resolves a visit.
	 */
	private function hides_merge_flag( string $meta_key, int $object_id ): bool {
		if ( self::MERGE_SAVED_CART !== $meta_key ) {
			return false;
		}

		$session = $this->plugin->current_session();

		return null !== $session && $object_id === $session->user_id;
	}

	/**
	 * Empty the cart exactly once per create-visit (scope §6.1).
	 *
	 * The latch is the `cart_ready` column of the visit's own row, so it is
	 * already per visit: a colleague punching in on the same account cannot
	 * re-arm or spend this visit's one-shot.
	 *
	 * Ordering is load-bearing. This runs on 'wp_loaded' at 15 — after
	 * WooCommerce hydrated WC()->cart at 10 and before it handles native
	 * add-to-cart forms at 20 — and the basket it empties must already be
	 * the one hydrated from THIS visit's session row.
	 *
	 * empty_cart( true ) also calls WC_Cart_Session::persistent_cart_destroy();
	 * disable_persistent_cart() above is the only thing stopping that from
	 * deleting the bound account's own saved basket on every punch-in.
	 */
	public function seed_cart(): void {
		$session = $this->plugin->current_session();

		if ( null === $session || $session->cart_ready || 'create' !== $session->operation ) {
			return;
		}

		// Latch only after the empty actually ran: on requests where the
		// cart is not loaded (REST/admin), leaving cart_ready=0 keeps the
		// one-shot armed for the first real storefront request.
		if ( function_exists( 'WC' ) && null !== WC()->cart ) {
			WC()->cart->empty_cart( true );
			if ( ! $this->sessions->update( $session->id, [ 'cart_ready' => 1 ] ) ) {
				// Do not accept additions that the next request would clear again.
				wp_die(
					__( 'Your catalog cart could not be initialized. Please try again.', 'punchout-woocommerce' ),
					'',
					[ 'response' => 503 ]
				);
			}
		}
	}

	/**
	 * Refuse products outside the visit's visible range.
	 *
	 * WooCommerce's own visibility chain plus purchasability is the whole
	 * rule: whatever decides what this account may see decides what it may
	 * add, and the plugin offers no seam of its own to widen or narrow it.
	 *
	 * @param bool $passed     Prior validation result.
	 * @param int  $product_id Product being added.
	 */
	public function validate_add_to_cart( $passed, $product_id ): bool {
		$session = $this->plugin->current_session();

		if ( null === $session || ! $passed ) {
			return (bool) $passed;
		}

		$product = wc_get_product( $product_id );

		if ( $product instanceof \WC_Product && $product->is_visible() && $product->is_purchasable() ) {
			return true;
		}

		$this->audit->write(
			'cart_add_reject',
			[
				'partner_id' => $session->partner_id,
				'session_id' => $session->id,
				'user_id'    => $session->user_id,
				'result'     => 'reject',
				'detail'     => [ 'product_id' => (int) $product_id ],
			]
		);

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( __( 'That product is not available in this catalog.', 'punchout-woocommerce' ), 'error' );
		}

		return false;
	}
}
