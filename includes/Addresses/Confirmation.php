<?php
/** Buyer consent over authoritative destination, mapped merchandise and native delivery. @package POW @license AGPL-3.0-or-later */
declare( strict_types = 1 );
namespace POW\Addresses;

use POW\Partners\Partner;
use POW\Partners\Registry;
use POW\Sessions\Session;
use POW\Sessions\Store;
use POW\Sessions\ConsentFence;
use POW\Cart\PoomMapper;
use POW\Cart\SessionKey;
use POW\Cart\NativeSessionGuard;
use POW\Settings;
use POW\Logger;

defined( 'ABSPATH' ) || exit;

final class Confirmation implements ReturnConfirmation {
	private PoomMapper $mapper;
	private int $refresh_depth = 0;
	private NativeSessionGuard $native;
	private ConsentFence $fence;

	/** Chooser's external mutation hooks use this same instance; internal quote refresh still undergoes the complete locked guard. */
	public function is_refreshing(): bool { return $this->refresh_depth > 0; }

	/** The optional mapper shares Plugin's configured instance; five-argument callers need no booted global plugin. */
	public function __construct( private Registry $registry, private Store $sessions, private QuoteAddress $addresses, private DeliveryEstimate $estimate, private Resolver $resolver, ?PoomMapper $mapper = null, ?NativeSessionGuard $native = null ) {
		$settings = new Settings();
		$this->mapper = $mapper ?? new PoomMapper( $settings, new Logger( $settings ) );
		$this->native = $native ?? new NativeSessionGuard( $registry, $sessions );
		$this->fence = new ConsentFence( $sessions, $registry );
	}

	/** Stable view contract, including a bounded error when authorization/preparation fails. */
	public function prepare( Session $session, Partner $partner ): array {
		$view = $this->preview( $session, $partner, [] );
		return $view instanceof \WP_Error ? self::empty_view( $view ) : $view;
	}

	/** Review may update this buyer's native address/method state, but never stores consent. HTTP owns POST/nonce. */
	public function preview( Session $session, Partner $partner, array $input ): array|\WP_Error {
		return $this->build( $session, $partner, $input, true );
	}

	/** Only identity, offered rate IDs, notes, the optional preferred delivery date, an explicit unknown acknowledgement and the shown digest are input. review_digest covers authoritative facts, excluding buyer-authored notes and date; the stored cart_fingerprint additionally binds the sanitized submitted notes and a non-null date. A missing preferred_delivery_date field means no preference; the 14-day default is display-only. */
	public function confirm( Session $session, Partner $partner, array $input ): array|\WP_Error {
		try {
			if ( ! isset( $input['review_digest'] ) || ! is_string( $input['review_digest'] ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $input['review_digest'] ) ) { return self::error( 'delivery_review_required' ); }
			$input += [ 'preferred_delivery_date' => null ];
			$view = $this->build( $session, $partner, $input, true, true );
			if ( $view instanceof \WP_Error ) { return $view; }
			if ( $view['error'] instanceof \WP_Error ) { return $view['error']; }
			if ( ! $view['can_confirm'] || ! hash_equals( $view['review_digest'], $input['review_digest'] ) ) { return self::error( 'delivery_review_required' ); }
			if ( $view['requires_unknown_acknowledgement'] && ! in_array( $input['acknowledge_unknown'] ?? null, [ true, '1' ], true ) ) { return self::error( 'delivery_acknowledgement_required' ); }
			// buyer_user_id is the connection's bound login, identical for every concurrent visit of this company: session_id is the only discriminator left in the record. Naming the person who agreed belongs to the visit's stored buyer identity, not here, because this JSON's field set is validated exactly.
			// Schema 2 only when a date was chosen: a dateless consent stays schema 1, which 0.4.7 and earlier can still read after a rollback.
			$date = $view['preferred_delivery_date'];
			$confirmation = [ 'schema' => null === $date ? 1 : DeliveryData::CONFIRMATION_SCHEMA, 'session_id' => $session->id, 'buyer_user_id' => $session->user_id, 'choice_hash' => DeliveryData::fingerprint( $view['selected_choice'] ), 'cart_fingerprint' => $view['_confirmation_fingerprint'], 'policy_fingerprint' => $view['_guard']['policy'], 'delivery' => $view['delivery'], 'notes' => $view['notes'] ];
			if ( null !== $date ) { $confirmation['preferred_delivery_date'] = $date; }
			$confirmation['confirmed_at'] = time();
			// Decode our own candidate before any write; virtual alone uses a NULL choice.
			$confirmation = DeliveryData::confirmation( json_encode( $confirmation, JSON_THROW_ON_ERROR ), $session->id, $session->user_id, $view['selected_choice'] );
			return $this->registry->with_partner_lock( $partner->id, function () use ( $session, $partner, $view, $confirmation ) {
				$state = $this->authorized_locked( $session, $partner );
				if ( $state instanceof \WP_Error ) { return $state; }
				[ $fresh, $company ] = $state;
				$valid = $this->validate_view_locked( $fresh, $company, $view );
				if ( true !== $valid ) { return $valid; }
				if ( ! $this->native->commit_locked( $fresh, $view['_guard']['native'] ) ) { return self::error( 'delivery_review_required' ); }
				if ( ! $this->sessions->save_delivery( $fresh->id, $fresh->user_id, $fresh->wp_session_token, $view['_guard']['choice_json'], $view['_guard']['confirmation_json'], $view['selected_choice'] ?? [], $confirmation ) ) {
					// False can follow a committed SQL UPDATE. Never leave that candidate usable after failed readback.
					return $this->clear_locked( $fresh ) ? self::error( 'delivery_save_failed' ) : self::error( 'delivery_recovery_failed' );
				}
				$after = $this->authorized_locked( $fresh, $company );
				if ( $after instanceof \WP_Error ) {
					return $this->clear_locked( $fresh ) ? self::error() : self::error( 'delivery_recovery_failed' );
				}
				$stored = $after[0];
				if ( DeliveryData::fingerprint( $stored->delivery_choice() ) !== DeliveryData::fingerprint( $view['selected_choice'] ) || DeliveryData::fingerprint( $stored->delivery_confirmation() ) !== DeliveryData::fingerprint( $confirmation ) ) {
					return $this->clear_locked( $fresh ) ? self::error( 'delivery_save_failed' ) : self::error( 'delivery_recovery_failed' );
				}
				$view['delivery_choice'] = $stored->delivery_choice();
				$view['delivery_confirmation'] = $stored->delivery_confirmation();
				$view['_guard']['choice_json'] = $stored->delivery_choice_json;
				$view['_guard']['confirmation_json'] = $stored->delivery_confirmation_json;
				if ( true !== $this->validate_view_locked( $stored, $after[1], $view ) ) { return $this->clear_locked( $fresh ) ? self::error() : self::error( 'delivery_recovery_failed' ); }
				return $view;
			} );
		} catch ( \Throwable $error ) { return $this->failed( $session, $partner ); }
	}

	/** Requote/remap outside the mutex. No winner transition, freight ItemIn, order, rendering or mail belongs here. */
	public function for_return( Session $session, Partner $partner ): array|\WP_Error {
		try {
			$state = $this->registry->with_partner_lock( $partner->id, fn() => $this->authorized_locked( $session, $partner ) );
			if ( $state instanceof \WP_Error ) { return $state; }
			[ $fresh, $company ] = $state;
			$accepted = $fresh->delivery_confirmation();
			if ( null === $accepted ) { return self::error( 'delivery_review_required' ); }
			$view = $this->build( $fresh, $company, [ 'notes' => $accepted['notes'], 'preferred_delivery_date' => $accepted['preferred_delivery_date'] ?? null ], false );
			if ( $view instanceof \WP_Error ) { return $view; }
			if ( $view['error'] instanceof \WP_Error ) { return $view['error']; }
			if ( ! $view['can_confirm'] || $accepted['cart_fingerprint'] !== $view['_confirmation_fingerprint'] || $accepted['policy_fingerprint'] !== $view['_guard']['policy'] || DeliveryData::fingerprint( $accepted['delivery'] ) !== DeliveryData::fingerprint( $view['delivery'] ) ) { return $this->failed( $fresh, $company ); }
			$prepared = [ 'items' => $view['items'], 'merchandise_total_cents' => $view['merchandise_total_cents'], 'total_cents' => $view['total_cents'], 'currency' => $view['currency'], 'skipped' => $view['skipped'], 'delivery_destination' => $view['delivery_destination'], 'delivery_choice' => $view['selected_choice'], 'delivery_confirmation' => $accepted, 'delivery' => $accepted['delivery'], 'delivery_notes' => $accepted['notes'], 'delivery_preferred_date' => $accepted['preferred_delivery_date'] ?? null, '_guard' => $view['_guard'] ];
			$valid = $this->registry->with_partner_lock( $company->id, fn() => $this->validate_prepared_locked( $fresh, $company, $prepared ) );
			return true === $valid ? $prepared : $valid;
		} catch ( \Throwable $error ) { return $this->failed( $session, $partner ); }
	}

	/** Caller holds the existing partner mutex immediately before its exact-expected-confirmation winner UPDATE. */
	public function validate_prepared_locked( Session $session, Partner $partner, array $prepared ): bool|\WP_Error {
		try {
			$state = $this->authorized_locked( $session, $partner );
			if ( $state instanceof \WP_Error ) { return $state; }
			[ $fresh, $company ] = $state;
			$accepted = $fresh->delivery_confirmation();
			if ( null === $accepted || ! isset( $prepared['_guard'], $prepared['delivery_confirmation'] ) || ! array_key_exists( 'delivery_choice', $prepared ) ) { return self::error(); }
			if ( DeliveryData::fingerprint( $accepted ) !== DeliveryData::fingerprint( $prepared['delivery_confirmation'] ) || DeliveryData::fingerprint( $fresh->delivery_choice() ) !== DeliveryData::fingerprint( $prepared['delivery_choice'] ) ) { return self::error(); }
			$view = [ 'selected_choice' => $prepared['delivery_choice'], '_guard' => $prepared['_guard'] ];
			$valid = $this->validate_view_locked( $fresh, $company, $view );
			if ( true !== $valid ) { return $valid; }
			if ( DeliveryData::fingerprint( QuoteAddress::payload( $prepared['delivery_choice'] ) ) !== DeliveryData::fingerprint( $prepared['delivery_destination'] ) || $accepted['notes'] !== $prepared['delivery_notes'] || ( $accepted['preferred_delivery_date'] ?? null ) !== ( $prepared['delivery_preferred_date'] ?? null ) || DeliveryData::fingerprint( $accepted['delivery'] ) !== DeliveryData::fingerprint( $prepared['delivery'] ) ) { return self::error(); }
			$mapped = [ 'items' => $prepared['items'], 'total_cents' => $prepared['merchandise_total_cents'], 'currency' => $prepared['currency'], 'skipped' => $prepared['skipped'] ];
			if ( self::total( $mapped['total_cents'], $prepared['delivery'] ) !== $prepared['total_cents'] || $accepted['cart_fingerprint'] !== self::digest( $mapped, $prepared['delivery_choice'], $prepared['delivery'], $prepared['delivery_notes'], $prepared['_guard'], $prepared['delivery_preferred_date'] ?? null ) || $accepted['policy_fingerprint'] !== $prepared['_guard']['policy'] ) { return self::error(); }
			return $this->native->commit_locked( $fresh, $prepared['_guard']['native'] ) ? true : self::error( 'delivery_review_required' );
		} catch ( \Throwable $error ) { return self::error(); }
	}

	/**
	 * Native read-only guard for mutation hooks and the final locked claim. No get_cart(), get_cart_hash(), get_data() metadata loading, view getters, mapping or shipping calculations. Native edit getters include unsaved core changes without filters; rate jsonSerialize() exposes raw data without invoking filtered cost/tax getters. The mapped digest separately binds every actual emitted unit price and field.
	 */
	public static function readonly_cart_fingerprint(): string {
		// Option filters run before copying the native in-memory facts they might affect.
		return self::native_cart_fingerprint( get_option( 'woocommerce_currency' ) );
	}

	/** Resolved currency is passed in so the final snapshot cannot invoke an option callback after protected comparisons. The live handler's cart key is part of the snapshot, so a fingerprint taken in one visit can never match another visit's basket. */
	private static function native_cart_fingerprint( mixed $currency ): string {
		$wc = WC();
		if ( ! $wc->cart || ! $wc->customer || ! $wc->session || ! is_array( $wc->cart->cart_contents ) ) { throw new \DomainException( 'Cart unavailable.' ); }
		$lines = [];
		foreach ( $wc->cart->cart_contents as $key => $line ) {
			$product = $line['data'] ?? null;
			if ( ! is_object( $product ) || ! is_callable( [ $product, 'get_virtual' ] ) ) { throw new \DomainException( 'Cart unavailable.' ); }
			$data = [ 'id' => $product->get_id() ];
			foreach ( [ 'sku', 'name', 'price', 'regular_price', 'sale_price', 'virtual', 'weight', 'length', 'width', 'height', 'tax_status', 'tax_class', 'shipping_class_id' ] as $field ) { $data[$field] = $product->{ 'get_' . $field }( 'edit' ); }
			// Extension cart data may determine negotiated pricing. Retain every scalar/array field, not just Woo's core subset.
			$lines[$key] = $line;
			unset( $lines[$key]['data'] );
			$lines[$key]['product'] = $data;
		}
		$packages = [];
		foreach ( $wc->shipping()->get_packages() as $key => $package ) {
			$rates = [];
			foreach ( $package['rates'] as $id => $rate ) { $rates[$id] = $rate->jsonSerialize()['data']; }
			$packages[$key] = [ 'destination' => $package['destination'] ?? null, 'rates' => $rates ];
		}
		$state = [ 'visit' => self::live_cart_key(), 'lines' => $lines, 'totals' => $wc->cart->get_totals(), 'coupons' => $wc->cart->applied_coupons, 'shipping' => $wc->customer->get_shipping( 'edit' ), 'chosen' => $wc->session->get( 'chosen_shipping_methods', [] ), 'packages' => $packages, 'currency' => $currency ];
		self::scalar_tree( $state );
		return DeliveryData::fingerprint( $state );
	}

	/** The key of the basket this request is actually holding, which is what a visit's consent has to be about. */
	private static function live_cart_key(): string { return (string) WC()->session->get_customer_id(); }

	/** $consent is true only for confirm(): an invalid date then refuses outright, while a review still applies the posted address and rates and shows the date error. */
	private function build( Session $session, Partner $partner, array $input, bool $invalidate, bool $consent = false ): array|\WP_Error {
		++$this->refresh_depth;
		try {
			$previous_notes = '';
			// false: no previous confirmation. null: none chosen (schema 1 or cleared).
			$previous_date = false;
			$native_before = $this->native->prepare( $session );
			$state = $this->registry->with_partner_lock( $partner->id, function () use ( $session, $partner, $invalidate, $native_before, &$previous_notes, &$previous_date ) {
				$state = $this->authorized_locked( $session, $partner );
				if ( $state instanceof \WP_Error ) { return $state; }
				if ( ! $this->native->check_locked( $state[0], $native_before ) ) { return self::error( 'delivery_review_required' ); }
				try { $previous = $state[0]->delivery_confirmation(); $previous_notes = $previous['notes'] ?? ''; if ( null !== $previous ) { $previous_date = $previous['preferred_delivery_date'] ?? null; } } catch ( \Throwable $error ) { /* Corrupt prior consent grants nothing; explicit review can replace it. */ }
				if ( $invalidate && null !== $state[0]->delivery_confirmation_json ) {
					if ( ! $this->clear_locked( $state[0] ) ) { return self::error( 'delivery_recovery_failed' ); }
					return $this->authorized_locked( $state[0], $state[1] );
				}
				return $state;
			} );
			if ( $state instanceof \WP_Error ) { return $state; }
			[ $fresh, $company ] = $state;
			$notes = self::notes( array_key_exists( 'notes', $input ) ? $input['notes'] : $previous_notes );
			if ( $notes instanceof \WP_Error ) { return $notes; }
			$policy = $this->policy_fingerprint( $fresh, $company );
			$choices = $this->resolver->choices_for_session( $fresh, $company );
			if ( $choices instanceof \WP_Error ) { return $choices; }
			// QuoteAddress's existing filter -> inbound -> acting-buyer precedence supplies one explicitly labelled candidate. Clearing this COPY only allows offering reselection; it never makes invalid persisted selection eligible for fallback.
			$candidate_session = new Session( ...array_replace( get_object_vars( $fresh ), [ 'delivery_choice_json' => null, 'delivery_confirmation_json' => null ] ) );
			$candidate = $this->addresses->resolve_destination( $candidate_session, $company );
			$state = $this->registry->with_partner_lock( $company->id, fn() => $this->authorized_locked( $fresh, $company ) );
			if ( $state instanceof \WP_Error ) { return $state; }
			if ( null !== $candidate ) {
				$provider = match ( $candidate['source'] ) { 'ship_to' => 'inbound', 'customer' => 'customer', 'filter' => 'filter', default => throw new \DomainException() };
				$choices[] = [ 'schema' => 1, 'partner_id' => $company->id, 'storage_user_id' => $company->owner_user_id, 'provider' => $provider, 'key' => 'candidate', 'code' => $candidate['code'], 'address' => $candidate['address'], 'label' => match ( $provider ) { 'inbound' => __( 'Purchasing system destination', 'punchout-woocommerce' ), 'customer' => __( 'Company address', 'punchout-woocommerce' ), default => __( 'Suggested delivery address', 'punchout-woocommerce' ) }, 'source' => $candidate['source'], 'book_revision' => null, 'entry_fingerprint' => null ];
			}
			$physical = false;
			foreach ( WC()->cart->get_cart() as $line ) { if ( ! isset( $line['data'] ) || ! is_callable( [ $line['data'], 'needs_shipping' ] ) ) { throw new \DomainException(); } if ( $line['data']->needs_shipping() ) { $physical = true; } }
			// The tomorrow minimum applies only while reviewing and confirming; a return checks the format, so consent given before midnight still returns after it.
			if ( array_key_exists( 'preferred_delivery_date', $input ) ) { $date_input = $input['preferred_delivery_date']; }
			elseif ( ! $invalidate ) { $date_input = null; }
			elseif ( false !== $previous_date && ( null === $previous_date || $previous_date >= self::date_from_today( 1 ) ) ) { $date_input = $previous_date; }
			else { $date_input = self::date_from_today( 14 ); }
			$date = self::preferred_date( $date_input, $invalidate );
			// A review ('Update delivery options') keeps the posted address and rates and reports the date on the view; consent and a return refuse outright.
			$date_error = null;
			if ( $date instanceof \WP_Error ) {
				if ( $consent || ! $invalidate ) { return $date; }
				$date_error = $date; $date = null;
			}
			if ( ! $physical ) { $date = null; }
			$view = self::empty_view(); $view['choices'] = $choices; $view['notes'] = $notes; $view['preferred_delivery_date'] = $date; $view['preferred_delivery_date_min'] = self::date_from_today( 1 );
			$choice = null;
			if ( $physical ) {
				if ( array_key_exists( 'provider', $input ) || array_key_exists( 'key', $input ) ) {
					if ( ! is_string( $input['provider'] ?? null ) || ! is_string( $input['key'] ?? null ) ) { return self::error( 'address_unavailable' ); }
					foreach ( $choices as $entry ) { if ( $entry['provider'] === $input['provider'] && $entry['key'] === $input['key'] ) { $choice = $entry; break; } }
				} elseif ( null !== $fresh->delivery_choice_json ) {
					try { $choice = $fresh->delivery_choice(); } catch ( \Throwable $error ) { $view['error'] = self::error( 'address_unavailable' ); }
				} else { $choice = $choices[0] ?? null; }
				if ( null === $choice ) { $view['error'] ??= self::error( 'address_unavailable' ); }
				else {
					$valid = $this->registry->with_partner_lock( $company->id, fn() => $this->choice_valid_locked( $fresh, $company, $choice ) );
					if ( true !== $valid ) { $view['error'] = $valid; $choice = null; }
				}
			}
			$view['selected_choice'] = $choice;
			$view['delivery_destination'] = QuoteAddress::payload( $choice );
			if ( null !== $view['error'] ) {
				// Selection errors still show authoritative choices and merchandise for explicit reselection.
				$mapped = $this->map( $company );
				$view = array_replace( $view, $mapped, [ 'merchandise_total_cents' => $mapped['total_cents'] ] );
				$state = $this->registry->with_partner_lock( $company->id, fn() => $this->authorized_locked( $fresh, $company ) );
				return $state instanceof \WP_Error ? $state : $view;
			}
			$quote = $this->estimate->quote( $fresh, $company, $view['delivery_destination'] );
			if ( array_key_exists( 'rates', $input ) ) {
				$selected = self::offered_rates( $input['rates'], $quote['packages'] );
				if ( $selected instanceof \WP_Error ) { return $selected; }
				$state = $this->registry->with_partner_lock( $company->id, fn() => $this->authorized_locked( $fresh, $company ) );
				if ( $state instanceof \WP_Error ) { return $state; }
				WC()->session->set( 'chosen_shipping_methods', $selected );
				$quote = $this->estimate->quote( $fresh, $company, $view['delivery_destination'] );
				// A callback cannot silently replace the buyer's explicit offered selection.
				foreach ( $quote['packages'] as $package ) { if ( ( $selected[$package['package_key']] ?? null ) !== $package['selected_rate_id'] && null !== $package['selected_rate_id'] ) { return self::error( 'delivery_review_required' ); } }
			}
			$state = $this->registry->with_partner_lock( $company->id, fn() => $this->authorized_locked( $fresh, $company ) );
			if ( $state instanceof \WP_Error ) { return $state; }
			if ( null !== $choice ) { self::one_destination( $choice['address'] ); }
			$before_map = self::readonly_cart_fingerprint();
			$mapped = $this->map( $company );
			$native = $this->native->prepare( $fresh );
			$guard = [ 'cart' => self::readonly_cart_fingerprint(), 'policy' => $policy, 'choice_json' => $fresh->delivery_choice_json, 'confirmation_json' => $fresh->delivery_confirmation_json, 'native' => $native ];
			if ( $before_map !== $guard['cart'] || 'ZAR' !== $quote['delivery']['currency'] ) { return self::error(); }
			$view = array_replace( $view, $mapped, $quote, [ 'merchandise_total_cents' => $mapped['total_cents'], 'total_cents' => self::total( $mapped['total_cents'], $quote['delivery'] ), '_guard' => $guard ] );
			$view['collection'] = DeliveryData::is_collection( $quote['delivery'] );
			// After the rate block: set before the selection-error return above, it would skip the rates.
			if ( null !== $date_error ) { $view['error'] = $date_error; $view['can_confirm'] = false; }
			// The date stays out of the review digest, so changing it needs no 'Update delivery options'; the stored fingerprint binds it.
			$view['review_digest'] = self::digest( $mapped, $choice, $quote['delivery'], null, $guard );
			$view['_confirmation_fingerprint'] = self::digest( $mapped, $choice, $quote['delivery'], $notes, $guard, $date );
			$valid = $this->registry->with_partner_lock( $company->id, function () use ( $fresh, $company, $view ) {
				$state = $this->authorized_locked( $fresh, $company );
				if ( $state instanceof \WP_Error ) { return $state; }
				$valid = $this->validate_view_locked( $state[0], $state[1], $view );
				if ( true !== $valid ) { return $valid; }
				return $this->native->commit_locked( $state[0], $view['_guard']['native'] ) ? true : self::error( 'delivery_review_required' );
			} );
			return true === $valid ? $view : $valid;
		} catch ( \Throwable $error ) { return $this->failed( $session, $partner ); }
		finally { --$this->refresh_depth; $this->native->flush_cache(); }
	}

	private function authorized_locked( Session $session, Partner $partner ): array|\WP_Error {
		try {
			$fresh = $this->sessions->find( $session->id ); $company = $this->registry->find( $partner->id );
			// The visit is proved by its own WP session token and its own cart key. Comparing account ids proves nothing: every visit of this connection is signed in as the same customer.
			if ( ! $fresh || ! $company || ! $company->is_active() || $company->owner_user_id <= 0 || $company->owner_user_id !== $partner->owner_user_id || $fresh->partner_id !== $company->id || $session->partner_id !== $company->id || Session::ACTIVE !== $fresh->status || ! $fresh->expires || $fresh->expires <= gmdate( 'Y-m-d H:i:s' ) || '' === $fresh->wp_session_token || ! hash_equals( $fresh->wp_session_token, wp_get_session_token() ) || ! hash_equals( $fresh->wp_session_token, $session->wp_session_token ) || ! $this->sessions->login_valid_checked( $fresh ) ) { return self::error( 'delivery_forbidden' ); }
			$key = SessionKey::for_session( $fresh );
			$associated = $this->resolver->partner_for_user( $fresh->user_id );
			if ( ! $associated || $associated->id !== $company->id || $associated->owner_user_id !== $company->owner_user_id || $fresh->user_id !== $company->owner_user_id || ! hash_equals( $key, (string) ( $session->wc_session_key ?? '' ) ) || ! WC()->cart || ! WC()->customer || ! WC()->session || (int) WC()->customer->get_id() !== $fresh->user_id || ! hash_equals( $key, self::live_cart_key() ) ) { return self::error( 'delivery_forbidden' ); }
			return [ $fresh, $company ];
		} catch ( \Throwable $error ) { return self::error( 'delivery_forbidden' ); }
	}

	private function validate_view_locked( Session $fresh, Partner $company, array $view ): bool|\WP_Error {
		$guard = $view['_guard'];
		// Complete the currency callback before the last native snapshot and fresh protected reads. The raw policy inputs below also detect changes made by later choice callbacks without rerunning filtered options.
		$currency = get_option( 'woocommerce_currency' );
		$choice_valid = $this->choice_valid_locked( $fresh, $company, $view['selected_choice'] );
		if ( true !== $choice_valid ) { return $choice_valid; }
		// Resolver applies today's country validation. Recheck authorization, persisted consent and native facts AFTER that boundary too.
		$state = $this->authorized_locked( $fresh, $company );
		if ( $state instanceof \WP_Error ) { return $state; }
		[ $fresh, $company ] = $state;
		// Choice validation can itself apply native capability callbacks. Reload protected rows after those callbacks.
		$current = $this->sessions->find( $fresh->id ); $current_company = $this->registry->find( $company->id );
		if ( ! $current || ! $current_company || DeliveryData::fingerprint( get_object_vars( $current ) ) !== DeliveryData::fingerprint( get_object_vars( $fresh ) ) || DeliveryData::fingerprint( get_object_vars( $current_company ) ) !== DeliveryData::fingerprint( get_object_vars( $company ) ) ) { return self::error(); }
		if ( $fresh->delivery_choice_json !== $guard['choice_json'] || $fresh->delivery_confirmation_json !== $guard['confirmation_json'] || $guard['policy'] !== $this->policy_fingerprint( $fresh, $company ) || $guard['cart'] !== self::native_cart_fingerprint( $currency ) ) { return self::error( 'delivery_review_required' ); }
		if ( ! isset( $guard['native'] ) || ! $this->native->check_locked( $fresh, $guard['native'] ) ) { return self::error( 'delivery_review_required' ); }
		return true;
	}

	private function choice_valid_locked( Session $session, Partner $partner, ?array $choice ): bool|\WP_Error {
		if ( null !== $choice ) { return $this->resolver->validate_active_choice_locked( $session, $partner, $choice ); }
		// Virtuality is read directly from native data inside the mutex, never through a filtered product getter.
		foreach ( WC()->cart->cart_contents as $line ) {
			if ( true !== $line['data']->get_virtual( 'edit' ) ) { return self::error( 'address_unavailable' ); }
		}
		return true;
	}

	/** Exit policy is not an input: there is one exit, so including it would make every stored confirmation depend on a value that cannot change the review. */
	private function policy_fingerprint( Session $session, Partner $partner ): string {
		$fields = [ 'id', 'owner_user_id', 'cxml_version', 'deployment_mode', 'return_encoding', 'allcaps_transform', 'emit_ship_to', 'emit_delivery_code', 'emit_delivery_line', 'delivery_code_extrinsic_name', 'delivery_unknown_policy', 'delivery_notes_policy', 'freight_supplier_part_id', 'freight_uom', 'freight_classification_domain', 'freight_classification', 'from_domain', 'from_identity', 'to_domain', 'to_identity' ];
		return DeliveryData::fingerprint( [ 'partner' => array_intersect_key( get_object_vars( $partner ), array_flip( $fields ) ), 'raw_inputs' => $this->raw_policy_inputs(), 'session_dialect' => $session->cxml_version, 'session_mode' => $session->deployment_mode ] );
	}

	/** Bound database reads bypass option caches/filters. Hash raw settings rather than unserializing arbitrary extension objects; a settings change conservatively requires review again. */
	private function raw_policy_inputs(): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name, option_value FROM ' . $wpdb->options . ' WHERE option_name IN (%s,%s) LIMIT 3', Settings::OPTION_KEY, 'woocommerce_currency' ), ARRAY_A );
		if ( ! is_array( $rows ) || count( $rows ) > 2 || '' !== ( $wpdb->last_error ?? '' ) ) { throw new \RuntimeException( 'Policy state unavailable.' ); }
		$values = [ Settings::OPTION_KEY => null, 'woocommerce_currency' => null ]; $seen = [];
		foreach ( $rows as $row ) {
			$key = $row['option_name'] ?? null;
			if ( ! is_string( $key ) || ! array_key_exists( $key, $values ) || isset( $seen[$key] ) || ! is_string( $row['option_value'] ?? null ) ) { throw new \RuntimeException( 'Policy state unavailable.' ); }
			$seen[$key] = true; $values[$key] = hash( 'sha256', $row['option_value'] );
		}
		return [ 'options' => $values ];
	}

	/** Native package filters may split contents, but this confirmation accepts exactly one postal destination. */
	private static function one_destination( array $address ): void {
		foreach ( WC()->shipping()->get_packages() as $package ) {
			$destination = $package['destination'] ?? null;
			if ( ! is_array( $destination ) ) { throw new \DomainException(); }
			foreach ( [ 'country', 'state', 'postcode', 'city', 'address_1', 'address_2' ] as $field ) {
				$value = 'address_1' === $field ? ( $destination['address_1'] ?? $destination['address'] ?? null ) : ( $destination[$field] ?? null );
				if ( $value !== $address[$field] ) { throw new \DomainException(); }
			}
			if ( isset( $destination['address'] ) && $destination['address'] !== $address['address_1'] ) { throw new \DomainException(); }
		}
	}

	private function map( Partner $partner ): array {
		$mapped = $this->mapper->from_cart( $partner );
		self::scalar_tree( $mapped );
		if ( ! isset( $mapped['items'], $mapped['total_cents'], $mapped['currency'], $mapped['skipped'] ) || ! is_array( $mapped['items'] ) || ! array_is_list( $mapped['items'] ) || ! is_array( $mapped['skipped'] ) || ! is_int( $mapped['total_cents'] ) || $mapped['total_cents'] < 0 || 'ZAR' !== $mapped['currency'] ) { throw new \DomainException(); }
		$sum = 0;
		foreach ( $mapped['items'] as $line ) {
			if ( ! is_array( $line ) || ! is_int( $line['unit_price_cents'] ?? null ) || $line['unit_price_cents'] < 0 || ! is_numeric( $line['quantity'] ?? null ) || ! is_finite( (float) $line['quantity'] ) || (float) $line['quantity'] <= 0 ) { throw new \DomainException(); }
			$amount = round( $line['unit_price_cents'] * (float) $line['quantity'] );
			if ( ! is_finite( $amount ) || $amount >= PHP_INT_MAX || $sum > PHP_INT_MAX - (int) $amount ) { throw new \DomainException(); }
			$sum += (int) $amount;
		}
		if ( $sum !== $mapped['total_cents'] ) { throw new \DomainException(); }
		return array_intersect_key( $mapped, array_flip( [ 'items', 'total_cents', 'currency', 'skipped' ] ) );
	}

	/** A null date hashes exactly as before schema 2, so schema-1 and dateless confirmations keep their stored fingerprint. */
	private static function digest( array $mapped, ?array $choice, array $delivery, ?string $notes, array $guard, ?string $preferred_date = null ): string {
		$data = [ 'merchandise' => $mapped, 'choice' => $choice, 'delivery' => $delivery, 'native_cart' => $guard['cart'], 'policy' => $guard['policy'] ];
		if ( null !== $notes ) { $data['notes'] = $notes; }
		if ( null !== $preferred_date ) { $data['preferred_delivery_date'] = $preferred_date; }
		return DeliveryData::fingerprint( $data );
	}

	/** Refuse recursive/opaque extension state without allowing json_encode to invoke arbitrary JsonSerializable callbacks under the mutex. */
	private static function scalar_tree( mixed $value, int $depth = 0 ): void {
		if ( $depth >= 16 ) { throw new \DomainException(); }
		if ( is_array( $value ) ) { foreach ( $value as $item ) { self::scalar_tree( $item, $depth + 1 ); } return; }
		if ( null !== $value && ! is_scalar( $value ) ) { throw new \DomainException(); }
		if ( is_float( $value ) && ! is_finite( $value ) ) { throw new \DomainException(); }
	}

	private static function total( int $merchandise, array $delivery ): int {
		$freight = $delivery['emit'] ? $delivery['amount_cents'] : 0;
		if ( ! is_int( $freight ) || $freight < 0 || $merchandise > PHP_INT_MAX - $freight ) { throw new \DomainException(); }
		return $merchandise + $freight;
	}

	private static function offered_rates( mixed $posted, array $packages ): array|\WP_Error {
		if ( ! is_array( $posted ) ) { return self::error( 'delivery_rate_invalid' ); }
		$offered = []; $selected = [];
		foreach ( $packages as $package ) { $offered[$package['package_key']] = array_column( $package['rates'], 'rate_id' ); if ( null !== $package['selected_rate_id'] ) { $selected[$package['package_key']] = $package['selected_rate_id']; } }
		foreach ( $posted as $key => $id ) { if ( ! is_string( $id ) || ! isset( $offered[$key] ) || ! in_array( $id, $offered[$key], true ) ) { return self::error( 'delivery_rate_invalid' ); } $selected[$key] = $id; }
		return $selected;
	}

	private static function notes( mixed $input ): string|\WP_Error {
		if ( ! is_scalar( $input ) || 1 !== preg_match( '//u', (string) $input ) ) { return self::error( 'delivery_notes_invalid' ); }
		$notes = sanitize_textarea_field( (string) $input );
		if ( ! is_string( $notes ) || strlen( $notes ) > 8000 || 1 !== preg_match( '//u', $notes ) || preg_match_all( '/./us', $notes ) > 2000 ) { return self::error( 'delivery_notes_invalid' ); }
		return $notes;
	}

	/** Y-m-d in the site timezone. Never wc_string_to_datetime(), which resolves 'today' in UTC. */
	private static function date_from_today( int $days ): string {
		return ( new \DateTimeImmutable( 'today', wp_timezone() ) )->modify( '+' . $days . ' days' )->format( 'Y-m-d' );
	}

	/** Empty means no preference. The minimum (tomorrow) applies only while reviewing and confirming. */
	private static function preferred_date( mixed $value, bool $enforce_minimum ): string|null|\WP_Error {
		if ( null === $value || '' === $value ) { return null; }
		if ( ! is_string( $value ) || ! DeliveryData::date( $value ) || ( $enforce_minimum && $value < self::date_from_today( 1 ) ) ) { return self::error( 'delivery_date_invalid' ); }
		return $value;
	}

	private function clear_locked( Session $session ): bool { return $this->fence->clear_locked( $session ); }

	private function failed( Session $session, Partner $partner ): \WP_Error {
		try {
			$native = $this->native->prepare( $session );
			$cleared = $this->registry->with_partner_lock( $partner->id, function () use ( $session, $native ) {
				// A failed old request must not revoke another request's newly accepted cart.
				if ( ! $this->native->check_locked( $session, $native ) ) { return true; }
				return $this->clear_locked( $session );
			} );
		} catch ( \Throwable $error ) { return self::error( 'delivery_review_required' ); }
		return self::error( $cleared ? 'delivery_review_required' : 'delivery_recovery_failed' );
	}

	private static function empty_view( ?\WP_Error $error = null ): array {
		return [ 'choices' => [], 'selected_choice' => null, 'delivery_destination' => null, 'packages' => [], 'delivery' => null, 'notes' => '', 'currency' => 'ZAR', 'items' => [], 'skipped' => [], 'merchandise_total_cents' => 0, 'total_cents' => 0, 'can_confirm' => false, 'requires_unknown_acknowledgement' => false, 'review_digest' => '', 'preferred_delivery_date' => null, 'preferred_delivery_date_min' => '', 'collection' => false, 'error' => $error ];
	}

	private static function error( string $code = 'delivery_review_required' ): \WP_Error {
		$message = match ( $code ) {
			'delivery_forbidden' => __( 'This delivery review is no longer available for your login.', 'punchout-woocommerce' ),
			'address_unavailable' => __( 'Select an available delivery destination and review it again.', 'punchout-woocommerce' ),
			'delivery_notes_invalid' => __( 'Use valid delivery notes of at most 2,000 characters and 8,000 bytes.', 'punchout-woocommerce' ),
			'delivery_date_invalid' => __( 'Choose a preferred delivery date from tomorrow onwards, or leave it empty.', 'punchout-woocommerce' ),
			'delivery_acknowledgement_required' => __( 'Acknowledge that delivery is excluded and will be quoted separately.', 'punchout-woocommerce' ),
			'delivery_recovery_failed' => __( 'Delivery state could not be verified. Reload before continuing.', 'punchout-woocommerce' ),
			default => __( 'Delivery changed or could not be verified. Review the destination, methods and totals and confirm again.', 'punchout-woocommerce' ),
		};
		return new \WP_Error( $code, $message );
	}
}
