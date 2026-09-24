<?php
/**
 * Private company delivery book. All mutations share the native partner mutex.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Addresses;

use POW\Audit\Log;
use POW\Partners\{Partner, Registry};
use POW\Sessions\Current;

defined( 'ABSPATH' ) || exit;

final class CompanyBook {
	/** Persisted schema bounds are stable; current Woo country/required-field policy belongs to new saves and active selection. */
	private const ADDRESS_LIMITS = [ 'first_name' => 190, 'last_name' => 190, 'company' => 190, 'address_1' => 190, 'address_2' => 190, 'city' => 190, 'state' => 190, 'postcode' => 32, 'country' => 2, 'phone' => 100 ];

	/** How many entries a book may hold before a buyer's add is refused; the owner's editor is not bound by it. */
	private const BUYER_ADD_LIMIT = 100;

	/** Reject synchronous metadata-hook reentry across instances; Registry itself is reentrant. */
	private static array $mutating = [];

	public function __construct( private Registry $registry, private Log $log, private Current $visits ) {}

	/** Management view includes disabled entries. $actor must be the actual logged-in editor. */
	public function read( int $partner_id, int $actor ): array|\WP_Error {
		try {
			return $this->registry->with_partner_lock( $partner_id, function () use ( $partner_id, $actor ) {
				$partner = $this->editor( $partner_id, $actor );
				return $partner instanceof \WP_Error ? $partner : $this->load( $partner->owner_user_id, $partner_id )['book'];
			} );
		} catch ( \Throwable $error ) { return self::unavailable(); }
	}

	/**
	 * Read-only consumer boundary for Resolver's active-choice check. Caller owns the partner mutex and visit/session authorization; do not acquire it again here. Reread the association and raw metadata even when its revision appears unchanged. This is the selection read and works inside a visit; it grants no management access, which editor() alone decides.
	 */
	public function read_for_partner_locked( Partner $partner ): array|\WP_Error {
		try {
			$fresh = $this->registry->find( $partner->id );
			if ( ! $fresh || $fresh->owner_user_id !== $partner->owner_user_id || ! $this->ordinary_user( $fresh->owner_user_id ) ) { return self::unavailable(); }
			return $this->load( $fresh->owner_user_id, $fresh->id )['book'];
		} catch ( \Throwable $error ) { return self::unavailable(); }
	}

	/**
	 * Fingerprint a checked canonical entry and immutable key, never the aggregate revision. Resolver/confirmation consumers share DeliveryData's ordering and encoding rules. Reordered maps are equivalent; noncanonical content refuses instead of being silently repaired.
	 *
	 * @throws \DomainException If the persisted key/entry shape is not canonical. Current shipping policy cannot change this historical fingerprint.
	 */
	public static function entry_fingerprint( string $key, array $entry ): string {
		try {
			if ( ! self::key( $key ) || ! self::stored_entry( $entry ) ) { throw new \DomainException(); }
			return DeliveryData::fingerprint( [ 'key' => $key ] + $entry );
		} catch ( \Throwable $error ) { throw new \DomainException( 'Invalid delivery entry fingerprint input.' ); }
	}

	/**
	 * Accept unslashed {label,address,code,use_for_punchout}; the HTTP boundary unslashes once. Omitted code preserves an existing claim; omitted enablement preserves existing state and defaults to disabled for new entries. Imports call this with enablement false until a separate explicit enable action.
	 *
	 * @return array|\WP_Error Success is {revision,key,entry,changed}; no-op preserves revision.
	 */
	public function save( int $partner_id, int $actor, int $expected_revision, ?string $key, array $fields ): array|\WP_Error {
		return $this->mutate( $partner_id, $actor, $expected_revision, $key, $fields );
	}

	public function remove( int $partner_id, int $actor, int $expected_revision, string $key ): bool|\WP_Error {
		$result = $this->mutate( $partner_id, $actor, $expected_revision, $key, null );
		return $result instanceof \WP_Error ? $result : true;
	}

	/**
	 * The one write a punchout visit can make: add a new entry to its connection's book, when the connection allows buyers to.
	 *
	 * The buyer supplies only a label and an address. The key is always new, the code always comes from the connection prefix, and the entry is always enabled. buyer_adder() decides under the partner lock whether this visit may add at all; the expected revision is the one read under that lock, so a buyer never races the form. Who added it is recorded in the audit log, never in the entry, whose shape stays fixed.
	 *
	 * @return array|\WP_Error Success is {revision,key,entry,changed}.
	 */
	public function add_for_visit( \POW\Sessions\Session $visit, string $label, array $address ): array|\WP_Error {
		return $this->mutate( $visit->partner_id, $visit->user_id, 0, null, [ 'label' => $label, 'address' => $address, 'code' => '', 'use_for_punchout' => true ], $visit );
	}

	private function mutate( int $partner_id, int $actor, int $expected_revision, ?string $key, ?array $fields, ?\POW\Sessions\Session $visit = null ): array|\WP_Error {
		if ( isset( self::$mutating[$partner_id] ) ) { return self::unavailable(); }
		self::$mutating[$partner_id] = true;
		try {
			$result = $this->registry->with_partner_lock( $partner_id, function () use ( $partner_id, $actor, $expected_revision, $key, $fields, $visit ) {
				$partner = null === $visit ? $this->editor( $partner_id, $actor ) : $this->buyer_adder( $partner_id, $visit );
				if ( $partner instanceof \WP_Error ) { return $partner; }
				$stored = $this->load( $partner->owner_user_id, $partner_id );
				$book = $stored['book'];
				if ( null !== $visit ) {
					// Add only, and against the revision read under this lock, never one from a form.
					if ( null !== $key || null === $fields ) { return self::invalid(); }
					if ( count( $book['addresses'] ) >= self::BUYER_ADD_LIMIT ) {
						return new \WP_Error( 'address_book_full', __( 'Your company’s delivery book is full. Ask your company administrator to add this address.', 'punchout-woocommerce' ) );
					}
					$expected_revision = $book['revision'];
				}
				if ( $expected_revision < 0 || $expected_revision !== $book['revision'] ) {
					return new \WP_Error( 'address_book_stale', __( 'The delivery book changed. Reload it before saving.', 'punchout-woocommerce' ) );
				}
				if ( null !== $key && ( ! self::key( $key ) || ! isset( $book['addresses'][$key] ) ) ) {
					return new \WP_Error( 'address_unavailable', __( 'This delivery address is unavailable. Reload the delivery book.', 'punchout-woocommerce' ) );
				}
				$old = null === $key ? null : $book['addresses'][$key];
				if ( null === $fields ) {
					if ( null === $old ) { return self::invalid(); }
					if ( '' !== $old['code'] ) { $book['claims'][$old['code']]['retired'] = true; }
					unset( $book['addresses'][$key] );
					$entry = null;
				} else {
					$entry = self::entry( $fields, $old );
					if ( $entry instanceof \WP_Error ) { return $entry; }
					if ( null === $old ) {
						if ( PHP_INT_MAX === $book['next_sequence'] ) { return self::invalid(); }
						// Random immutable keys do not expose the count or recycle removed uncoded IDs. next_sequence counts issued entries, independently of per-prefix code sequences.
						$key = bin2hex( random_bytes( 16 ) );
						if ( isset( $book['addresses'][$key] ) || in_array( $key, array_column( $book['claims'], 'key' ), true ) ) { return self::unavailable(); }
						++$book['next_sequence'];
						if ( '' === $entry['code'] ) {
							try {
								// PHP coerces all-digit map keys to integers; Codes correctly requires canonical strings.
								$entry['code'] = Codes::next( array_map( 'strval', array_keys( $book['claims'] ) ), $partner->delivery_code_prefix );
							} catch ( \InvalidArgumentException | \OverflowException $error ) { return self::invalid(); }
						}
					}
					if ( '' !== $entry['code'] && $entry['code'] !== ( $old['code'] ?? '' ) ) {
						// Retired means permanently spent, including for the same entry. No claim is ever reassigned or resurrected.
						if ( array_key_exists( $entry['code'], $book['claims'] ) ) { return self::invalid(); }
						$book['claims'][$entry['code']] = [ 'key' => $key, 'retired' => false ];
					}
					if ( null !== $old && $old['code'] !== $entry['code'] && '' !== $old['code'] ) { $book['claims'][$old['code']]['retired'] = true; }
					if ( $old === $entry ) { return [ 'revision' => $book['revision'], 'key' => $key, 'entry' => $entry, 'changed' => false ]; }
					$book['addresses'][$key] = $entry;
				}
				if ( PHP_INT_MAX === $book['revision'] ) { return self::invalid(); }
				++$book['revision'];
				$meta_key = self::meta_key( $partner_id );
				// WordPress unslashes the NEW metadata value, not the previous-value predicate. Slash exactly once here to preserve the already-unslashed canonical payload.
				$written = $stored['exists']
					? update_user_meta( $partner->owner_user_id, $meta_key, wp_slash( $book ), $stored['book'] )
					: add_user_meta( $partner->owner_user_id, $meta_key, wp_slash( $book ), true );
				if ( ! $written ) { return self::unavailable(); }
				$after = $this->load( $partner->owner_user_id, $partner_id );
				if ( ! $after['exists'] || $after['book'] !== $book ) { return self::unavailable(); }
				return [ 'revision' => $book['revision'], 'key' => $key, 'entry' => $entry, 'changed' => true ];
			} );
			if ( is_array( $result ) && $result['changed'] ) {
				// No external callbacks inside the mutex. An optional diagnostic failure cannot undo a verified aggregate or invite a duplicate retry.
				try {
					if ( null !== $visit ) {
						// Which visit added it, by session and a short identity hash: never the raw buyer identity.
						$buyer_name = (string) $visit->buyer_name;
						$this->log->write( 'address_book_buyer_added', [ 'partner_id' => $partner_id, 'session_id' => $visit->id, 'user_id' => $actor, 'direction' => 'internal', 'result' => 'ok', 'detail' => [ 'revision' => $result['revision'], 'key' => $result['key'], 'buyer_hash' => substr( (string) $visit->buyer_identity_hash, 0, 12 ) ] + ( '' !== $buyer_name ? [ 'buyer_name' => $buyer_name ] : [] ) ] );
					} else {
						$this->log->write( null === $fields ? 'address_book_removed' : 'address_book_saved', [ 'partner_id' => $partner_id, 'user_id' => $actor, 'direction' => 'internal', 'result' => 'ok', 'detail' => [ 'revision' => $result['revision'], 'key' => $result['key'] ] ] );
					}
				} catch ( \Throwable $error ) { /* Persisted state remains authoritative; no address content enters the audit. */ }
			}
			return $result;
		} catch ( \Throwable $error ) { return self::unavailable(); }
		finally { unset( self::$mutating[$partner_id] ); }
	}

	/**
	 * The one book-editor gate: a shop-wide grant or the connection's own account, acting for itself, outside any visit.
	 *
	 * A visit is signed in as the account the book belongs to, so ownership
	 * cannot separate "the customer managing their addresses" from "an
	 * employee shopping through the punchout catalogue". The live visit can:
	 * inside one, the master book is read-only, which is what keeps a buyer
	 * from editing the company's addresses from a catalogue session.
	 * read_for_partner_locked() is deliberately not gated this way — that is
	 * the selection read, and selecting a delivery address is the whole point
	 * of the visit. add_for_visit() is the one write a visit can make, and only
	 * when its connection allows buyers to add addresses; buyer_adder() gates
	 * it, and this gate never opens for a visit.
	 */
	private function editor( int $partner_id, int $actor ): Partner|\WP_Error {
		$partner = $this->registry->find( $partner_id );
		$user = $actor > 0 && $actor === get_current_user_id() && null === $this->visits->visit() ? $this->ordinary_user( $actor ) : false;
		if ( ! $partner || ! $user || ! $this->ordinary_user( $partner->owner_user_id ) || ! ( user_can( $user, 'manage_woocommerce' ) || $partner->is_owned_by( $actor ) ) ) {
			return new \WP_Error( 'address_forbidden', __( 'You cannot manage this company delivery book.', 'punchout-woocommerce' ) );
		}
		return $partner;
	}

	/**
	 * The buyer-add gate, asked under the partner lock: the connection is active and allows buyer adds, the visit is the bound account's, and this request is inside that very visit, still active, on its own basket.
	 *
	 * The visit passed in is only a claim. The live visit is resolved again here from the login, and must be the same row with the same per-visit basket key. That row and the claim are read the same way, so the key must also be the basket this request actually holds (WooCommerce's session customer id), as Chooser::invalidate() and Confirmation::live_cart_key() require; no loaded session refuses. The shared account id alone proves nothing, because every visit of the connection signs in as it.
	 */
	private function buyer_adder( int $partner_id, \POW\Sessions\Session $visit ): Partner|\WP_Error {
		$partner = $this->registry->find( $partner_id );
		$live = $this->visits->visit();
		$key = null !== $live ? (string) $live->wc_session_key : '';
		$basket = null !== $live ? (string) ( WC()->session?->get_customer_id() ?? '' ) : '';
		if (
			! $partner
			|| ! $partner->is_active()
			|| ! $partner->buyer_addresses
			|| $partner->owner_user_id <= 0
			|| $partner->owner_user_id !== $visit->user_id
			|| get_current_user_id() !== $partner->owner_user_id
			|| null === $live
			|| $live->id !== $visit->id
			|| $live->partner_id !== $partner->id
			|| \POW\Sessions\Session::ACTIVE !== $live->status
			|| ! \POW\Cart\SessionKey::is_visit_key( $key )
			|| ! hash_equals( $key, (string) $visit->wc_session_key )
			|| ! hash_equals( $key, $basket )
			|| ! $this->ordinary_user( $partner->owner_user_id )
		) {
			return new \WP_Error( 'address_add_forbidden', __( 'Adding a delivery address is not available for this visit. Ask your company administrator to add it.', 'punchout-woocommerce' ) );
		}
		return $partner;
	}

	/** Refresh actor and owner after acquiring the mutex; a stale capability cannot grant access. A read failure is not a missing grant. */
	private function ordinary_user( int $id ): object|false {
		global $wpdb;
		if ( $id <= 0 ) { return false; }
		$wpdb->last_error = '';
		wp_cache_delete( $id, 'users' );
		wp_cache_delete( $id, 'user_meta' );
		$user = get_userdata( $id );
		if ( '' !== $wpdb->last_error ) { throw new \RuntimeException( 'Delivery actor unavailable.' ); }
		if ( ! $user ) { return false; }
		$allowed = user_can( $user, 'read' );
		// Loading the account's capabilities is itself a metadata read.
		if ( '' !== $wpdb->last_error ) { throw new \RuntimeException( 'Delivery actor capabilities unavailable.' ); }
		return $allowed ? $user : false;
	}

	/** Native raw read bypasses metadata cache and short-circuit filters; zero rows is distinct from failure or corruption. */
	private function load( int $owner, int $partner_id ): array {
		global $wpdb;
		// LIMIT 2 bounds duplicate detection. Native metadata APIs do not expose SQL read failure separately from a missing value.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT meta_value FROM ' . $wpdb->usermeta . ' WHERE user_id = %d AND meta_key = %s LIMIT 2', $owner, self::meta_key( $partner_id ) ), ARRAY_A );
		if ( '' !== ( $wpdb->last_error ?? '' ) || ! is_array( $rows ) || count( $rows ) > 1 ) { throw new \RuntimeException( 'Delivery state unavailable.' ); }
		if ( [] === $rows ) { return [ 'exists' => false, 'book' => [ 'schema' => 1, 'revision' => 0, 'addresses' => [], 'claims' => [], 'next_sequence' => 1 ] ]; }
		$raw = $rows[0]['meta_value'] ?? null;
		if ( ! is_string( $raw ) ) { throw new \RuntimeException( 'Delivery state unavailable.' ); }
		// Do not instantiate objects from malformed stored metadata. Invalid serialization is a bounded refusal, never an empty replacement book.
		$book = @unserialize( $raw, [ 'allowed_classes' => false ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		if ( ! $this->valid_book( $book ) ) { throw new \RuntimeException( 'Delivery state unavailable.' ); }
		return [ 'exists' => true, 'book' => $book ];
	}

	private function valid_book( mixed $book ): bool {
		if ( ! is_array( $book ) || ! self::keys( $book, [ 'schema', 'revision', 'addresses', 'claims', 'next_sequence' ] ) || 1 !== $book['schema'] || ! is_int( $book['revision'] ) || $book['revision'] < 1 || ! is_int( $book['next_sequence'] ) || $book['next_sequence'] < 1 || ! is_array( $book['addresses'] ) || ! is_array( $book['claims'] ) ) { return false; }
		foreach ( $book['addresses'] as $key => $entry ) {
			if ( ! self::key( (string) $key ) || ! is_array( $entry ) || ! self::stored_entry( $entry ) ) { return false; }
			if ( '' !== $entry['code'] && ( $book['claims'][$entry['code']] ?? null ) !== [ 'key' => (string) $key, 'retired' => false ] ) { return false; }
		}
		foreach ( $book['claims'] as $code => $claim ) {
			$code = (string) $code;
			if ( '' === $code || Codes::sanitise( $code ) !== $code || ! is_array( $claim ) || ! self::keys( $claim, [ 'key', 'retired' ] ) || ! is_string( $claim['key'] ) || ! self::key( $claim['key'] ) || ! is_bool( $claim['retired'] ) ) { return false; }
			if ( ! $claim['retired'] && ( $book['addresses'][$claim['key']]['code'] ?? null ) !== $code ) { return false; }
		}
		return true;
	}

	/** Decode stored structure without reapplying mutable Woo rules to every historical entry. */
	private static function stored_entry( array $entry ): bool {
		if ( ! self::keys( $entry, [ 'label', 'address', 'code', 'use_for_punchout' ] ) || ! self::text( $entry['label'], 190 ) || '' === $entry['label'] || sanitize_text_field( $entry['label'] ) !== $entry['label'] || ! is_bool( $entry['use_for_punchout'] ) || ! self::text( $entry['code'], 32 ) || Codes::sanitise( $entry['code'] ) !== $entry['code'] || ! is_array( $entry['address'] ) || ! self::keys( $entry['address'], array_keys( self::ADDRESS_LIMITS ) ) ) { return false; }
		foreach ( self::ADDRESS_LIMITS as $field => $limit ) {
			if ( ! self::text( $entry['address'][$field], $limit ) || sanitize_text_field( $entry['address'][$field] ) !== $entry['address'][$field] ) { return false; }
		}
		return 1 === preg_match( '/\A[A-Z]{2}\z/', $entry['address']['country'] );
	}

	private static function entry( array $fields, ?array $old ): array|\WP_Error {
		if ( array_diff_key( $fields, array_flip( [ 'label', 'address', 'code', 'use_for_punchout' ] ) ) || ! isset( $fields['label'], $fields['address'] ) || ! is_string( $fields['label'] ) || ! is_array( $fields['address'] ) ) { return self::invalid(); }
		if ( ! self::text( $fields['label'], 190 ) ) { return self::invalid(); }
		$label = sanitize_text_field( $fields['label'] );
		$code = $fields['code'] ?? '';
		$enabled = $fields['use_for_punchout'] ?? ( $old['use_for_punchout'] ?? false );
		if ( ! self::text( $label, 190 ) || '' === $label || ! is_string( $code ) || ! is_bool( $enabled ) || ( array_key_exists( 'code', $fields ) && null === $fields['code'] ) || ( array_key_exists( 'use_for_punchout', $fields ) && null === $fields['use_for_punchout'] ) ) { return self::invalid(); }
		try { $code = Codes::sanitise( $code ); } catch ( \InvalidArgumentException $error ) { return self::invalid(); }
		if ( '' === $code && null !== $old ) { $code = $old['code']; }
		$address = Shape::normalise( $fields['address'] );
		if ( $address instanceof \WP_Error ) { return $address; }
		return [ 'label' => $label, 'address' => $address, 'code' => $code, 'use_for_punchout' => $enabled ];
	}

	private static function keys( array $value, array $keys ): bool { return count( $value ) === count( $keys ) && ! array_diff_key( $value, array_flip( $keys ) ); }
	private static function key( string $key ): bool { return 1 === preg_match( '/\A[A-Za-z0-9_-]{1,190}\z/', $key ); }
	private static function text( mixed $value, int $limit ): bool { return is_string( $value ) && strlen( $value ) <= 4 * $limit && 1 === preg_match( '//u', $value ) && 0 === preg_match( '/[\x00-\x1f\x7f]/', $value ) && preg_match_all( '/./us', $value ) <= $limit; }
	private static function meta_key( int $partner_id ): string { return '_pow_delivery_book_' . $partner_id; }
	private static function invalid(): \WP_Error { return new \WP_Error( 'address_book_invalid', __( 'Supply a valid delivery address, label and unused delivery code within the allowed lengths.', 'punchout-woocommerce' ) ); }
	private static function unavailable(): \WP_Error { return new \WP_Error( 'address_state_unavailable', __( 'The delivery book could not be verified. Reload it before trying again.', 'punchout-woocommerce' ) ); }
}
