<?php
/**
 * Bounded persisted delivery data. Decoding does not grant current address eligibility.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */
declare( strict_types = 1 );

namespace POW\Addresses;

defined( 'ABSPATH' ) || exit;

final class DeliveryData {

	// Leave room below MySQL TEXT's byte limit; validate before decoding or writing.
	public const MAX_JSON_BYTES = 60000;

	private const ADDRESS_FIELDS = [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' ];
	private const SOURCES = [ 'native' => 'company_book', 'inbound' => 'ship_to', 'customer' => 'customer', 'filter' => 'filter' ];

	/** Schema written when the buyer chose a preferred_delivery_date (schema 1 plus that key). A dateless confirmation is written as schema 1 so 0.4.7 can still read it after a rollback; schema 2 with a null date (earlier 0.4.8 builds) is still read. */
	public const CONFIRMATION_SCHEMA = 2;

	/** WooCommerce core's zone Local Pickup ids and the Blocks Local Pickup id. Fixed, with no filter, so the review and the Quote note always agree on what counts as collection. */
	public const PICKUP_METHOD_IDS = [ 'local_pickup', 'legacy_local_pickup', 'pickup_location' ];

	/**
	 * Retain accepted values verbatim. Country-specific validation belongs to Shape at selection time; a later configuration change must not rewrite history.
	 *
	 * Legacy full book selections may lack the fingerprint. Their null fingerprint makes them unusable for active confirmation until explicitly selected again.
	 *
	 * @throws \DomainException On malformed or foreign stored data; no document content is included.
	 */
	public static function choice( string $json, int $partner_id ): array {
		$invalid = static function (): never { throw new \DomainException( 'Invalid stored delivery selection.' ); };
		if ( '' === $json || strlen( $json ) > self::MAX_JSON_BYTES ) { $invalid(); }
		try {
			$data = json_decode( $json, true, 16, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $e ) { $invalid(); }
		if ( ! is_array( $data ) || array_is_list( $data ) ) { $invalid(); }
		$fields = [ 'schema', 'partner_id', 'storage_user_id', 'provider', 'key', 'code', 'address', 'label', 'source' ];
		foreach ( $fields as $field ) { if ( ! array_key_exists( $field, $data ) ) { $invalid(); } }
		if ( array_diff( array_keys( $data ), [ ...$fields, 'book_revision', 'entry_fingerprint' ] ) ) { $invalid(); }
		if ( 1 !== $data['schema'] || $partner_id <= 0 || $data['partner_id'] !== $partner_id || ! is_int( $data['storage_user_id'] ) || $data['storage_user_id'] <= 0 ) { $invalid(); }
		if ( ! is_string( $data['provider'] ) || ! isset( self::SOURCES[ $data['provider'] ] ) || $data['source'] !== self::SOURCES[ $data['provider'] ] ) { $invalid(); }
		if ( ! is_string( $data['key'] ) || 1 !== preg_match( '/\A[A-Za-z0-9_-]{1,190}\z/', $data['key'] ) || ! self::text( $data['code'], 32 ) || ! self::text( $data['label'], 190 ) ) { $invalid(); }
		if ( ! is_array( $data['address'] ) || array_diff( array_keys( $data['address'] ), self::ADDRESS_FIELDS ) ) { $invalid(); }
		foreach ( self::ADDRESS_FIELDS as $field ) {
			if ( ! array_key_exists( $field, $data['address'] ) || ! is_string( $data['address'][ $field ] ) || str_contains( $data['address'][ $field ], "\0" ) ) { $invalid(); }
		}
		if ( 1 !== preg_match( '/\A[A-Z]{2}\z/', $data['address']['country'] ) ) { $invalid(); }
		$data['book_revision'] ??= null;
		$data['entry_fingerprint'] ??= null;
		if ( 'native' === $data['provider'] ) {
			try { if ( Codes::sanitise( $data['code'] ) !== $data['code'] ) { $invalid(); } }
			catch ( \InvalidArgumentException $e ) { $invalid(); }
			$legacy = null === $data['book_revision'] && null === $data['entry_fingerprint'];
			if ( ! $legacy && ( ! is_int( $data['book_revision'] ) || $data['book_revision'] < 1 || ! is_string( $data['entry_fingerprint'] ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $data['entry_fingerprint'] ) ) ) { $invalid(); }
		} elseif ( null !== $data['book_revision'] || null !== $data['entry_fingerprint'] ) { $invalid(); }
		return $data;
	}

	private static function text( mixed $value, int $max ): bool {
		return is_string( $value ) && ! str_contains( $value, "\0" ) && 1 === preg_match( '/\A.{0,' . $max . '}\z/us', $value );
	}

	/** Deterministic associative-key order, with meaningful list order retained. */
	public static function fingerprint( ?array $data ): string {
		$sort = static function ( mixed $value, int $depth = 0 ) use ( &$sort ): mixed {
			// Bound traversal before sorting: a recursive PHP array must not reach exhaustion before json_encode can refuse it.
			if ( $depth >= 16 ) { throw new \DomainException( 'Invalid delivery fingerprint input.' ); }
			if ( ! is_array( $value ) ) { return $value; }
			if ( ! array_is_list( $value ) ) { ksort( $value, SORT_STRING ); }
			foreach ( $value as $key => $item ) { $value[ $key ] = $sort( $item, $depth + 1 ); }
			return $value;
		};
		try { $json = json_encode( $sort( $data ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, 16 ); }
		catch ( \JsonException $e ) { throw new \DomainException( 'Invalid delivery fingerprint input.' ); }
		if ( strlen( $json ) > self::MAX_JSON_BYTES ) { throw new \DomainException( 'Invalid delivery fingerprint input.' ); }
		return hash( 'sha256', $json );
	}

	/**
	 * Decode structural and monetary invariants only. Fresh cart/rates/policy and company-book eligibility still require the active confirmation guard.
	 *
	 * @throws \DomainException When the stored confirmation is invalid or belongs to different session state.
	 */
	public static function confirmation( string $json, int $session_id, int $buyer_id, ?array $choice ): array {
		$invalid = static function (): never { throw new \DomainException( 'Invalid stored delivery confirmation.' ); };
		if ( '' === $json || strlen( $json ) > self::MAX_JSON_BYTES ) { $invalid(); }
		try { $data = json_decode( $json, true, 16, JSON_THROW_ON_ERROR ); }
		catch ( \JsonException $e ) { $invalid(); }
		$v1 = [ 'schema', 'session_id', 'buyer_user_id', 'choice_hash', 'cart_fingerprint', 'policy_fingerprint', 'delivery', 'notes', 'confirmed_at' ];
		// Each schema has an exact field set and the decoded array is returned as stored: never add the date to schema 1 or drop it from schema 2, or stored fingerprints stop matching.
		$fields = is_array( $data ) && 2 === ( $data['schema'] ?? null ) ? [ ...$v1, 'preferred_delivery_date' ] : $v1;
		// Both bindings stay, but their meanings have parted: buyer_user_id is the connection's bound login, the same value for every concurrent visit, so session_id is the term that actually decides whose consent this is. Never write a guard on buyer_user_id alone.
		if ( ! self::fields( $data, $fields ) || ! in_array( $data['schema'], [ 1, 2 ], true ) || $session_id <= 0 || $buyer_id <= 0 || $data['session_id'] !== $session_id || $data['buyer_user_id'] !== $buyer_id ) { $invalid(); }
		foreach ( [ 'choice_hash', 'cart_fingerprint', 'policy_fingerprint' ] as $key ) {
			if ( ! is_string( $data[ $key ] ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $data[ $key ] ) ) { $invalid(); }
		}
		if ( ! hash_equals( self::fingerprint( $choice ), $data['choice_hash'] ) || ! self::text( $data['notes'], 2000 ) || strlen( $data['notes'] ) > 8000 || ! is_int( $data['confirmed_at'] ) || $data['confirmed_at'] <= 0 ) { $invalid(); }
		// Format only: the tomorrow minimum applies when the buyer reviews, so a confirmation made before midnight still returns after it.
		if ( 2 === $data['schema'] && null !== $data['preferred_delivery_date'] && ! self::date( $data['preferred_delivery_date'] ) ) { $invalid(); }
		if ( null !== $choice && 'native' === $choice['provider'] && null === $choice['entry_fingerprint'] ) { $invalid(); }
		$d = $data['delivery'];
		if ( ! self::fields( $d, [ 'status', 'amount_cents', 'currency', 'code', 'emit', 'rates', 'freight' ] ) || ! in_array( $d['status'], [ 'quoted', 'unknown', 'not_required', 'disabled' ], true ) || ! is_bool( $d['emit'] ) || ! is_string( $d['currency'] ) || 1 !== preg_match( '/\A[A-Z]{3}\z/', $d['currency'] ) || ! self::text( $d['code'], 32 ) || ! is_array( $d['rates'] ) || ! array_is_list( $d['rates'] ) ) { $invalid(); }
		if ( $d['emit'] && 'quoted' !== $d['status'] ) { $invalid(); }
		if ( 'not_required' !== $d['status'] && null === $choice ) { $invalid(); }
		if ( 'not_required' === $d['status'] && [] !== $d['rates'] ) { $invalid(); }
		if ( in_array( $d['status'], [ 'unknown', 'not_required' ], true ) && null !== $d['amount_cents'] ) { $invalid(); }
		if ( null !== $d['amount_cents'] && ( ! is_int( $d['amount_cents'] ) || $d['amount_cents'] < 0 ) ) { $invalid(); }
		if ( 'quoted' === $d['status'] && ( null === $d['amount_cents'] || [] === $d['rates'] ) ) { $invalid(); }
		$sum = 0; $packages = [];
		foreach ( $d['rates'] as $rate ) {
			if ( ! self::fields( $rate, [ 'package_key', 'rate_id', 'method_id', 'instance_id', 'label', 'amount_cents', 'taxes' ] ) || ! is_int( $rate['amount_cents'] ) || $rate['amount_cents'] < 0 || $sum > PHP_INT_MAX - $rate['amount_cents'] || ! is_int( $rate['instance_id'] ) || $rate['instance_id'] < 0 ) { $invalid(); }
			if ( ! ( is_int( $rate['package_key'] ) && $rate['package_key'] >= 0 ) && ! ( self::text( $rate['package_key'], 190 ) && '' !== $rate['package_key'] ) ) { $invalid(); }
			foreach ( [ 'rate_id', 'method_id', 'label' ] as $key ) { if ( ! self::text( $rate[ $key ], 190 ) || '' === $rate[ $key ] ) { $invalid(); } }
			$package = (string) $rate['package_key'];
			if ( isset( $packages[ $package ] ) || ! is_array( $rate['taxes'] ) ) { $invalid(); }
			$packages[ $package ] = true; $sum += $rate['amount_cents'];
			foreach ( $rate['taxes'] as $tax ) {
				if ( ! ( is_int( $tax ) || is_float( $tax ) || is_string( $tax ) ) || ! is_numeric( $tax ) || ! is_finite( (float) $tax ) || (float) $tax < 0 ) { $invalid(); }
			}
		}
		if ( null !== $d['amount_cents'] && $d['amount_cents'] !== $sum ) { $invalid(); }
		$freight_fields = [ 'supplier_part_id' => 190, 'uom' => 8, 'classification_domain' => 64, 'classification' => 64 ];
		if ( ! self::fields( $d['freight'], array_keys( $freight_fields ) ) ) { $invalid(); }
		foreach ( $freight_fields as $key => $limit ) { if ( ! self::text( $d['freight'][ $key ], $limit ) || '' === $d['freight'][ $key ] ) { $invalid(); } }
		return $data;
	}

	/** A real calendar date written exactly as Y-m-d, from the year 2000. No minimum-day rule here. */
	public static function date( mixed $value ): bool {
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $parts ) ) { return false; }
		return (int) $parts[1] >= 2000 && checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] );
	}

	/** Collection is derived, never stored: true when every selected rate is a native Local Pickup method. method_id is already in the confirmation and both hashes. */
	public static function is_collection( ?array $delivery ): bool {
		$rates = $delivery['rates'] ?? null;
		if ( ! is_array( $rates ) || [] === $rates || ! array_is_list( $rates ) ) { return false; }
		foreach ( $rates as $rate ) {
			if ( ! is_array( $rate ) || ! in_array( $rate['method_id'] ?? null, self::PICKUP_METHOD_IDS, true ) ) { return false; }
		}
		return true;
	}

	/** The selected rate labels as plain text, in package order, joined with '; '. Pure PHP so every caller and test harness can use it. */
	public static function method_label( array $delivery ): string {
		$labels = [];
		foreach ( $delivery['rates'] ?? [] as $rate ) {
			$label = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( strip_tags( (string) ( $rate['label'] ?? '' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
			if ( '' !== $label ) { $labels[] = $label; }
		}
		return implode( '; ', $labels );
	}

	private static function fields( mixed $data, array $fields ): bool {
		return is_array( $data ) && ! array_diff( $fields, array_keys( $data ) ) && ! array_diff( array_keys( $data ), $fields );
	}
}
