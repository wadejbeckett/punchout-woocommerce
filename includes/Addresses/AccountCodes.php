<?php
/** The plugin's own (account, address key) → delivery code map for the account's saved addresses. Pure. @package POW @license AGPL-3.0-or-later */
declare( strict_types = 1 );
namespace POW\Addresses;

defined( 'ABSPATH' ) || exit;

/**
 * An address book that lives outside the plugin hands out keys like `a1`, `a2` and reuses a key
 * once its address is deleted. A delivery code must not follow the key onto a different address,
 * so the plugin keeps its own map: key → {code, fingerprint of the address}. When the address
 * behind a key changes, the old code is retired (its claim is kept so the sequence never reissues
 * it) and a fresh code is allocated from the connection's prefix; with an empty prefix codes are
 * '' and only the fingerprint moves.
 *
 * Shape: {schema: 1, entries: {key: {code, fingerprint}}, claims: {code: {key, retired}}}.
 */
final class AccountCodes {
	public const SCHEMA = 1;

	public static function empty(): array {
		return [ 'schema' => self::SCHEMA, 'entries' => [], 'claims' => [] ];
	}

	/** A stored map, or the empty map when the stored value is not one (never a partial repair). */
	public static function normalise( mixed $stored ): array {
		if ( ! is_array( $stored ) || self::SCHEMA !== ( $stored['schema'] ?? null ) || ! is_array( $stored['entries'] ?? null ) || ! is_array( $stored['claims'] ?? null ) ) { return self::empty(); }
		$map = self::empty();
		foreach ( $stored['entries'] as $key => $entry ) {
			if ( ! is_string( $key ) || ! self::key( $key ) || ! is_array( $entry ) || ! is_string( $entry['code'] ?? null ) || ! is_string( $entry['fingerprint'] ?? null ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $entry['fingerprint'] ) ) { return self::empty(); }
			try { if ( Codes::sanitise( $entry['code'] ) !== $entry['code'] ) { return self::empty(); } } catch ( \InvalidArgumentException $error ) { return self::empty(); }
			$map['entries'][ $key ] = [ 'code' => $entry['code'], 'fingerprint' => $entry['fingerprint'] ];
		}
		foreach ( $stored['claims'] as $code => $claim ) {
			if ( ! is_string( $code ) || '' === $code || ! is_array( $claim ) || ! is_string( $claim['key'] ?? null ) || ! is_bool( $claim['retired'] ?? null ) ) { return self::empty(); }
			$map['claims'][ $code ] = [ 'key' => $claim['key'], 'retired' => $claim['retired'] ];
		}
		return $map;
	}

	/**
	 * Bring the map in line with the addresses the account holds now.
	 *
	 * @param array<string, string> $fingerprints key => fingerprint of the current address behind it.
	 * @return array{map: array, codes: array<string, string>, changed: bool} The map to store (when changed), and each key's code.
	 * @throws \InvalidArgumentException|\OverflowException From the code sequence (a malformed prefix or an exhausted one).
	 */
	public static function reconcile( array $map, array $fingerprints, string $prefix ): array {
		$map = self::normalise( $map );
		$changed = false;
		$codes = [];
		foreach ( $fingerprints as $key => $fingerprint ) {
			$key = (string) $key;
			if ( ! self::key( $key ) || ! is_string( $fingerprint ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $fingerprint ) ) { throw new \InvalidArgumentException( 'Address key or fingerprint malformed.' ); }
			$entry = $map['entries'][ $key ] ?? null;
			if ( null !== $entry && hash_equals( $entry['fingerprint'], $fingerprint ) ) { $codes[ $key ] = $entry['code']; continue; }
			// A new key, or a key whose address is no longer the one its code was given for: the old code retires.
			if ( null !== $entry && '' !== $entry['code'] && isset( $map['claims'][ $entry['code'] ] ) ) { $map['claims'][ $entry['code'] ]['retired'] = true; }
			$code = Codes::next( array_map( 'strval', array_keys( $map['claims'] ) ), $prefix );
			if ( '' !== $code ) { $map['claims'][ $code ] = [ 'key' => $key, 'retired' => false ]; }
			$map['entries'][ $key ] = [ 'code' => $code, 'fingerprint' => $fingerprint ];
			$codes[ $key ] = $code;
			$changed = true;
		}
		return [ 'map' => $map, 'codes' => $codes, 'changed' => $changed ];
	}

	/**
	 * Record a code for a key as given (a migration carrying codes from elsewhere). The code must be canonical and unclaimed, or already this key's.
	 *
	 * @return array|\WP_Error The map to store.
	 */
	public static function assign( array $map, string $key, string $code, string $fingerprint ): array|\WP_Error {
		$map = self::normalise( $map );
		if ( ! self::key( $key ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $fingerprint ) ) { return new \WP_Error( 'address_codes_invalid', 'Address key or fingerprint malformed.' ); }
		try { $code = Codes::sanitise( $code ); } catch ( \InvalidArgumentException $error ) { return new \WP_Error( 'address_codes_invalid', 'Delivery code malformed.' ); }
		if ( '' !== $code && isset( $map['claims'][ $code ] ) && ( $map['claims'][ $code ]['key'] !== $key || $map['claims'][ $code ]['retired'] ) ) { return new \WP_Error( 'address_code_taken', 'Delivery code already claimed.' ); }
		$old = $map['entries'][ $key ]['code'] ?? '';
		if ( '' !== $old && $old !== $code && isset( $map['claims'][ $old ] ) ) { $map['claims'][ $old ]['retired'] = true; }
		if ( '' !== $code ) { $map['claims'][ $code ] = [ 'key' => $key, 'retired' => false ]; }
		$map['entries'][ $key ] = [ 'code' => $code, 'fingerprint' => $fingerprint ];
		return $map;
	}

	/** The fingerprint an address is remembered by: its ten canonical fields, nothing else. */
	public static function fingerprint( array $address ): string {
		return DeliveryData::fingerprint( $address );
	}

	private static function key( string $key ): bool { return 1 === preg_match( '/\A[A-Za-z0-9_-]{1,190}\z/', $key ); }
}
