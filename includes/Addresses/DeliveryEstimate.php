<?php
/**
 * Native shipping offers and immutable ex-tax delivery snapshots.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */
declare( strict_types = 1 );

namespace POW\Addresses;

use POW\Cxml\Money;
use POW\Partners\Partner;
use POW\Sessions\Session;
use POW\Settings;
use WC_Customer_Data_Store_Session;
use WC_Shipping_Rate;

defined( 'ABSPATH' ) || exit;

final class DeliveryEstimate {
	public function __construct( private Settings $settings ) {}

	/**
	 * Returns {delivery,packages,can_confirm,requires_unknown_acknowledgement}. Only delivery belongs in DeliveryData's existing confirmation schema. Package options include native display_label HTML for the view to render through wp_kses_post. Neither a default method nor can_confirm records consent; the HTTP confirmation boundary owns offered-ID validation and explicit unknown acknowledgement.
	 *
	 * Native customer changes use its session data store only. All rate callbacks run outside the partner mutex. On failure this service refuses and attempts to restore prior request/session state; the caller must invalidate confirmation, including when rollback itself cannot be verified.
	 */
	public function quote( Session $session, Partner $partner, ?array $destination ): array {
		$rollback = null;
		try {
			$wc = WC();
			if ( $session->partner_id !== $partner->id || ! $partner->is_active() || Session::ACTIVE !== $session->status || $session->user_id <= 0 || get_current_user_id() !== $session->user_id || ! $wc->cart || ! $wc->customer || ! $wc->session || (int) $wc->customer->get_id() !== $session->user_id || (string) $wc->session->get_customer_id() !== (string) $session->user_id ) { throw self::invalid(); }
			$context = self::native_context();
			$currency = get_woocommerce_currency();
			if ( ! is_string( $currency ) || 1 !== preg_match( '/\A[A-Z]{3}\z/', $currency ) ) { throw self::invalid(); }
			$freight = [ 'supplier_part_id' => $partner->freight_supplier_part_id, 'uom' => $partner->freight_uom, 'classification_domain' => $partner->freight_classification_domain, 'classification' => $partner->freight_classification ];
			$delivery = [ 'status' => 'not_required', 'amount_cents' => null, 'currency' => $currency, 'code' => '', 'emit' => false, 'rates' => [], 'freight' => $freight ];
			// Cart::needs_shipping() is also false when shipping is disabled or no methods are configured. That must not turn physical goods into a virtual-only confirmation.
			$physical = false;
			foreach ( $wc->cart->get_cart() as $item ) {
				if ( ! isset( $item['data'] ) || ! is_callable( [ $item['data'], 'needs_shipping' ] ) ) { throw self::invalid(); }
				if ( $item['data']->needs_shipping() ) { $physical = true; }
			}
			if ( ! $physical ) {
				if ( $currency !== get_woocommerce_currency() || $context !== self::native_context() ) { throw self::invalid(); }
				return self::result( $delivery, [], $partner );
			}

			$destination = QuoteAddress::payload( $destination );
			if ( null === $destination ) { throw self::invalid(); }
			$address = Shape::normalise( $destination['address'] );
			if ( $address instanceof \WP_Error || DeliveryData::fingerprint( $address ) !== DeliveryData::fingerprint( $destination['address'] ) ) { throw self::invalid(); }
			$store = $wc->customer->get_data_store()->get_current_class_name();
			if ( ! is_a( $store, WC_Customer_Data_Store_Session::class, true ) ) { throw self::invalid(); }
			$before_chosen = $wc->session->get( 'chosen_shipping_methods', [] );
			if ( ! is_array( $before_chosen ) ) { throw self::invalid(); }
			$rollback = [ 'address' => $wc->customer->get_shipping( 'edit' ), 'calculated' => $wc->customer->has_calculated_shipping(), 'customer' => $wc->session->get( 'customer' ), 'chosen' => $before_chosen, 'counts' => $wc->session->get( 'shipping_method_counts' ), 'previous' => $wc->session->get( 'previous_shipping_methods' ), 'totals' => $wc->cart->get_totals() ];
			self::set_address( $wc->customer, $address );
			$wc->customer->set_calculated_shipping( true );
			if ( (int) $wc->customer->save() !== $session->user_id || DeliveryData::fingerprint( $wc->customer->get_shipping( 'edit' ) ) !== DeliveryData::fingerprint( $address ) ) { throw self::invalid(); }
			$saved_customer = $wc->session->get( 'customer' );
			foreach ( $address as $field => $value ) { if ( ! is_array( $saved_customer ) || ( $saved_customer[ 'shipping_' . $field ] ?? null ) !== $value ) { throw self::invalid(); } }

			// Clear only this buyer's native package caches as well as in-memory packages. A changed carrier offer must be recalculated even if the package hash is unchanged.
			$wc->shipping()->reset_shipping();
			foreach ( $wc->session->get_session_data() as $key => $value ) { if ( is_string( $key ) && str_starts_with( $key, 'shipping_for_package_' ) ) { $wc->session->set( $key, false ); } }
			// get_session_data() rereads storage, so also clear current packages created earlier in this request but not yet persisted.
			foreach ( $wc->cart->get_shipping_packages() as $key => $package ) { $wc->session->set( 'shipping_for_package_' . $key, false ); }
			$wc->session->set( 'chosen_shipping_methods', $before_chosen );
			$wc->cart->calculate_totals();
			$packages = $wc->cart->needs_shipping() ? $wc->shipping()->get_packages() : [];
			if ( ! is_array( $packages ) ) { throw self::invalid(); }
			$before_defaults = self::native_offer_state( $packages );
			$chosen = [];
			foreach ( $packages as $key => $package ) {
				self::package( $key, $package, $currency );
				$previous = $before_chosen[ $key ] ?? '';
				if ( ! is_string( $previous ) ) { throw self::invalid(); }
				// Native recalculation can reset a valid buyer choice when method counts change. Preserve that offered ID; only a missing choice delegates to the current native default helper with its unchanged classic/Blocks context.
				$selected = '' !== $previous && isset( $package['rates'][ $previous ] ) ? $previous : wc_get_default_shipping_method_for_package( $key, $package, $previous );
				if ( ! is_string( $selected ) ) { throw self::invalid(); }
				$chosen[ $key ] = isset( $package['rates'][ $selected ] ) ? $selected : '';
			}
			if ( $before_defaults !== self::native_offer_state() ) { throw self::invalid(); }
			if ( $chosen !== $wc->session->get( 'chosen_shipping_methods', [] ) ) {
				$wc->session->set( 'chosen_shipping_methods', $chosen );
				$wc->cart->calculate_totals();
				$fresh = $wc->cart->needs_shipping() ? $wc->shipping()->get_packages() : [];
				if ( ! is_array( $fresh ) || array_keys( $fresh ) !== array_keys( $packages ) ) { throw self::invalid(); }
				$packages = $fresh;
			}
			// Bind this exact package array before any option callback can replace the live objects behind it.
			$native_before = self::native_offer_state( $packages );
			if ( $currency !== get_woocommerce_currency() || get_current_user_id() !== $session->user_id || (int) $wc->customer->get_id() !== $session->user_id || (string) $wc->session->get_customer_id() !== (string) $session->user_id || DeliveryData::fingerprint( $wc->customer->get_shipping( 'edit' ) ) !== DeliveryData::fingerprint( $address ) ) { throw self::invalid(); }
			$current = $wc->session->get( 'chosen_shipping_methods', [] );
			$options = []; $rates = []; $sum = 0; $complete = [] !== $packages;
			foreach ( $packages as $key => $package ) {
				self::package( $key, $package, $currency );
				$selected = $chosen[ $key ];
				if ( ( $current[ $key ] ?? '' ) !== $selected || ( '' !== $selected && ! isset( $package['rates'][ $selected ] ) ) ) { throw self::invalid(); }
				$offered = [];
				foreach ( $package['rates'] as $id => $rate ) {
					$mapped = self::rate( $key, $id, $rate );
					$display = wc_cart_totals_shipping_method_label( $rate );
					if ( ! is_string( $display ) || strlen( $display ) > 8192 || 1 !== preg_match( '//u', $display ) ) { throw self::invalid(); }
					$offered[] = $mapped + [ 'display_label' => $display ];
					if ( $id === $selected ) {
						if ( $sum > PHP_INT_MAX - $mapped['amount_cents'] ) { throw self::invalid(); }
						$sum += $mapped['amount_cents']; $rates[] = $mapped;
					}
				}
				if ( '' === $selected ) { $complete = false; }
				$label = $package['package_name'] ?? __( 'Shipping package', 'punchout-woocommerce' );
				if ( ! self::text( $label, 190 ) ) { throw self::invalid(); }
				$options[] = [ 'package_key' => $key, 'label' => $label, 'selected_rate_id' => '' === $selected ? null : $selected, 'rates' => $offered ];
			}
			// Rate getters, labels and the final currency read can invoke extensions. Compare live raw state only after all such reads; filtered amounts may legitimately differ from raw costs.
			if ( $currency !== get_woocommerce_currency() || $context !== self::native_context() || $native_before !== self::native_offer_state() ) { throw self::invalid(); }
			$delivery['status'] = ! $partner->emit_delivery_line ? 'disabled' : ( $complete ? 'quoted' : 'unknown' );
			$delivery['amount_cents'] = $complete ? $sum : null;
			$delivery['code'] = $destination['code'];
			$delivery['emit'] = $partner->emit_delivery_line && $complete;
			$delivery['rates'] = $rates;
			return self::result( $delivery, $options, $partner );
		} catch ( \Throwable $error ) {
			if ( null !== $rollback ) {
				// No rates, master writes or locks during recovery. Failure still refuses even when an extension prevents restoration.
				try { self::set_address( $wc->customer, $rollback['address'] ); $wc->customer->set_calculated_shipping( $rollback['calculated'] ); } catch ( \Throwable $ignored ) {}
				try { $wc->shipping()->reset_shipping(); } catch ( \Throwable $ignored ) {}
				foreach ( [ 'customer' => 'customer', 'chosen_shipping_methods' => 'chosen', 'shipping_method_counts' => 'counts', 'previous_shipping_methods' => 'previous' ] as $key => $saved ) { try { $wc->session->set( $key, $rollback[ $saved ] ); } catch ( \Throwable $ignored ) {} }
				try { $wc->cart->set_totals( $rollback['totals'] ); } catch ( \Throwable $ignored ) {}
			}
			throw self::invalid();
		}
	}

	/** Return stored values only after current destination, offers, amounts, taxes, currency and delivery emission/configuration agree. Confirmation owns the broader cart/policy fingerprint, exact login, acknowledgement, active-book and CAS guard. */
	public function for_return( Session $session, Partner $partner, ?array $destination ): array {
		try {
			$confirmation = $session->delivery_confirmation();
			if ( null === $confirmation || DeliveryData::fingerprint( QuoteAddress::payload( $session->delivery_choice() ) ) !== DeliveryData::fingerprint( QuoteAddress::payload( $destination ) ) ) { throw self::invalid(); }
			$fresh = $this->quote( $session, $partner, $destination );
			if ( ! $fresh['can_confirm'] || DeliveryData::fingerprint( $confirmation['delivery'] ) !== DeliveryData::fingerprint( $fresh['delivery'] ) ) { throw self::invalid(); }
			return $confirmation['delivery'];
		} catch ( \Throwable $error ) { throw self::invalid(); }
	}

	/** One typed, quantity-one freight line; never a product or native fee. Quote item integration consumes the same rates separately. */
	public static function poom_line( array $delivery ): ?array {
		self::validate_delivery( $delivery );
		if ( ! $delivery['emit'] ) { return null; }
		return [ 'line_type' => 'freight', 'quantity' => 1, 'supplier_part_id' => $delivery['freight']['supplier_part_id'], 'aux_id' => '', 'unit_price_cents' => $delivery['amount_cents'], 'description' => __( 'Delivery', 'punchout-woocommerce' ), 'uom' => $delivery['freight']['uom'], 'classification_domain' => $delivery['freight']['classification_domain'], 'classification' => $delivery['freight']['classification'] ];
	}

	private static function result( array $delivery, array $packages, Partner $partner ): array {
		self::validate_delivery( $delivery );
		$unknown = 'unknown' === $delivery['status'];
		return [ 'delivery' => $delivery, 'packages' => $packages, 'can_confirm' => ! $unknown || 'quote_separately' === $partner->delivery_unknown_policy, 'requires_unknown_acknowledgement' => $unknown && 'quote_separately' === $partner->delivery_unknown_policy ];
	}

	private static function set_address( object $customer, array $address ): void { foreach ( $address as $field => $value ) { $customer->{ 'set_shipping_' . $field }( $value ); } }

	/** The WP actor is already initialized by quote's entry check. These native identity reads do not run rate or formatting filters. */
	private static function native_context(): array {
		$actor = get_current_user_id();
		$wc = WC();
		return [ $actor, spl_object_id( $wc ), spl_object_id( $wc->cart ), spl_object_id( $wc->customer ), spl_object_id( $wc->session ), $wc->customer->get_id(), $wc->session->get_customer_id() ];
	}

	/** Copy live native values, never filtered rate getters or the earlier package array. Object identity also detects replacement by a callback. */
	private static function native_offer_state( ?array $expected_packages = null ): string {
		$context = self::native_context();
		$wc = WC();
		$shipping = $wc->shipping();
		$packages = self::native_packages( $shipping->get_packages() );
		if ( null !== $expected_packages && $packages !== self::native_packages( $expected_packages ) ) { throw self::invalid(); }
		$data = [ 'context' => $context, 'shipping' => spl_object_id( $shipping ), 'packages' => $packages, 'address' => $wc->customer->get_shipping( 'edit' ), 'customer' => $wc->session->get( 'customer' ), 'chosen' => $wc->session->get( 'chosen_shipping_methods', [] ), 'totals' => $wc->cart->get_totals() ];
		self::scalar_state( $data );
		return DeliveryData::fingerprint( $data );
	}

	private static function native_packages( array $native ): array {
		$packages = [];
		foreach ( $native as $key => $package ) {
			if ( ! is_array( $package ) || ! isset( $package['rates'] ) || ! is_array( $package['rates'] ) ) { throw self::invalid(); }
			$rates = [];
			foreach ( $package['rates'] as $id => $rate ) {
				if ( ! $rate instanceof WC_Shipping_Rate ) { throw self::invalid(); }
				$serialized = $rate->jsonSerialize();
				if ( ! isset( $serialized['data'] ) || ! is_array( $serialized['data'] ) ) { throw self::invalid(); }
				$rates[$id] = [ 'object' => spl_object_id( $rate ), 'data' => $serialized['data'] ];
			}
			$packages[$key] = [ 'destination' => $package['destination'] ?? null, 'currency' => $package['currency'] ?? null, 'name' => $package['package_name'] ?? null, 'rates' => $rates ];
		}
		return $packages;
	}

	/** Reject recursive/object values before JSON can invoke an extension's serializer. */
	private static function scalar_state( mixed $value, int $depth = 0 ): void {
		if ( $depth >= 14 ) { throw self::invalid(); }
		if ( is_array( $value ) ) { foreach ( $value as $item ) { self::scalar_state( $item, $depth + 1 ); } }
		elseif ( null !== $value && ! is_scalar( $value ) ) { throw self::invalid(); }
	}

	private static function package( int|string $key, mixed $package, string $currency ): void {
		if ( ( ! is_int( $key ) || $key < 0 ) && ! self::text( $key, 190 ) ) { throw self::invalid(); }
		if ( ! is_array( $package ) || ! isset( $package['rates'] ) || ! is_array( $package['rates'] ) || ( isset( $package['currency'] ) && $package['currency'] !== $currency ) ) { throw self::invalid(); }
	}

	private static function rate( int|string $key, mixed $id, mixed $rate ): array {
		if ( ! $rate instanceof WC_Shipping_Rate || ! self::text( $id, 190 ) || $rate->get_id() !== $id ) { throw self::invalid(); }
		$mapped = [ 'package_key' => $key, 'rate_id' => $id, 'method_id' => $rate->get_method_id(), 'instance_id' => $rate->get_instance_id(), 'label' => $rate->get_label(), 'amount_cents' => self::cents( $rate->get_cost() ), 'taxes' => $rate->get_taxes() ];
		self::validate_rate( $mapped );
		return $mapped;
	}

	private static function cents( mixed $value ): int {
		// Keep the existing Money rounding boundary. Reject non-finite/negative/oversized native input before it can overflow integer arithmetic or enter an error message.
		if ( ! ( is_int( $value ) || is_float( $value ) || is_string( $value ) ) || ! is_numeric( $value ) || ! is_finite( (float) $value ) || (float) $value < 0 || (float) $value >= ( PHP_INT_MAX - 100 ) / 100 || ( is_string( $value ) && strlen( $value ) > 128 ) ) { throw self::invalid(); }
		try { return Money::to_cents( is_int( $value ) ? (string) $value : $value ); }
		catch ( \Throwable $error ) { throw self::invalid(); }
	}

	/** Match the existing stored delivery schema; view-only package options never enter it. */
	private static function validate_delivery( array $delivery ): void {
		if ( ! self::fields( $delivery, [ 'status', 'amount_cents', 'currency', 'code', 'emit', 'rates', 'freight' ] ) || ! in_array( $delivery['status'], [ 'quoted', 'unknown', 'not_required', 'disabled' ], true ) || ! is_bool( $delivery['emit'] ) || ! is_string( $delivery['currency'] ) || 1 !== preg_match( '/\A[A-Z]{3}\z/', $delivery['currency'] ) || ! self::text( $delivery['code'], 32, true ) || ! is_array( $delivery['rates'] ) || ! array_is_list( $delivery['rates'] ) ) { throw self::invalid(); }
		if ( $delivery['emit'] && 'quoted' !== $delivery['status'] ) { throw self::invalid(); }
		$amount = $delivery['amount_cents'];
		if ( null !== $amount && ( ! is_int( $amount ) || $amount < 0 ) ) { throw self::invalid(); }
		if ( in_array( $delivery['status'], [ 'unknown', 'not_required' ], true ) && null !== $amount ) { throw self::invalid(); }
		if ( 'not_required' === $delivery['status'] && [] !== $delivery['rates'] ) { throw self::invalid(); }
		if ( 'quoted' === $delivery['status'] && ( null === $amount || [] === $delivery['rates'] ) ) { throw self::invalid(); }
		$sum = 0; $keys = [];
		foreach ( $delivery['rates'] as $rate ) {
			self::validate_rate( $rate );
			$key = (string) $rate['package_key'];
			if ( isset( $keys[ $key ] ) || $sum > PHP_INT_MAX - $rate['amount_cents'] ) { throw self::invalid(); }
			$keys[ $key ] = true; $sum += $rate['amount_cents'];
		}
		if ( null !== $amount && $amount !== $sum ) { throw self::invalid(); }
		$limits = [ 'supplier_part_id' => 190, 'uom' => 8, 'classification_domain' => 64, 'classification' => 64 ];
		if ( ! self::fields( $delivery['freight'], array_keys( $limits ) ) ) { throw self::invalid(); }
		foreach ( $limits as $field => $limit ) { if ( ! self::text( $delivery['freight'][ $field ], $limit ) ) { throw self::invalid(); } }
	}

	private static function validate_rate( mixed $rate ): void {
		if ( ! self::fields( $rate, [ 'package_key', 'rate_id', 'method_id', 'instance_id', 'label', 'amount_cents', 'taxes' ] ) || ! is_int( $rate['instance_id'] ) || $rate['instance_id'] < 0 || ! is_int( $rate['amount_cents'] ) || $rate['amount_cents'] < 0 || ! is_array( $rate['taxes'] ) ) { throw self::invalid(); }
		if ( ( ! is_int( $rate['package_key'] ) || $rate['package_key'] < 0 ) && ! self::text( $rate['package_key'], 190 ) ) { throw self::invalid(); }
		foreach ( [ 'rate_id', 'method_id', 'label' ] as $field ) { if ( ! self::text( $rate[ $field ], 190 ) ) { throw self::invalid(); } }
		foreach ( $rate['taxes'] as $tax ) { self::cents( $tax ); }
	}

	private static function fields( mixed $value, array $fields ): bool { return is_array( $value ) && ! array_diff( array_keys( $value ), $fields ) && ! array_diff( $fields, array_keys( $value ) ); }
	private static function text( mixed $value, int $limit, bool $empty = false ): bool { return is_string( $value ) && ( $empty || '' !== trim( $value ) ) && strlen( $value ) <= 4 * $limit && 1 === preg_match( '/\A[\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]{0,' . $limit . '}\z/u', $value ); }
	private static function invalid(): \DomainException { return new \DomainException( __( 'Delivery could not be verified. Review the destination and shipping methods and confirm again.', 'punchout-woocommerce' ) ); }
}
