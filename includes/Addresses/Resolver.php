<?php
/** Company ownership and current selected-address eligibility. @package POW @license AGPL-3.0-or-later */
declare( strict_types = 1 );
namespace POW\Addresses;

use POW\Cart\SessionKey;
use POW\Partners\Partner;
use POW\Partners\Registry;
use POW\Sessions\Current;
use POW\Sessions\Session;

defined( 'ABSPATH' ) || exit;

final class Resolver {
	public function __construct( private Registry $registry, private CompanyBook $book, private Current $visits ) {}

	public function current(): Provider { return new NativeProvider( $this->registry, $this->book, $this->visits ); }

	/** Full server-owned choices for confirmation. This producer owns its one book-read mutex; callers never supply revision, fingerprint or owner authority. Exact native login/session checks remain Confirmation's responsibility on both sides of its callbacks. */
	public function choices_for_session( Session $session, Partner $partner ): array|\WP_Error {
		try {
			if ( ! $this->is_request_visit( $session ) || Session::ACTIVE !== $session->status || $session->partner_id !== $partner->id ) { return self::error( 'address_unavailable' ); }
			$associated = $this->partner_for_user( $session->user_id );
			if ( ! self::bound_login( $session, $partner ) || ! $associated || ! $associated->is_active() || $associated->id !== $partner->id || $associated->owner_user_id !== $partner->owner_user_id ) { return self::error( 'address_unavailable' ); }
			return $this->registry->with_partner_lock( $partner->id, function () use ( $session, $partner ) {
				$fresh = $this->partner_for_user( $session->user_id );
				if ( ! $this->is_request_visit( $session ) || ! self::bound_login( $session, $partner ) || ! $fresh || ! $fresh->is_active() || $fresh->id !== $partner->id || $fresh->owner_user_id !== $partner->owner_user_id ) { return self::error( 'address_unavailable' ); }
				$book = $this->book->read_for_partner_locked( $fresh );
				if ( $book instanceof \WP_Error ) { return self::error( 'address_state_unavailable' ); }
				$choices = [];
				foreach ( $book['addresses'] as $key => $entry ) {
					if ( true !== $entry['use_for_punchout'] ) { continue; }
					$choices[] = [ 'schema' => 1, 'partner_id' => $fresh->id, 'storage_user_id' => $fresh->owner_user_id, 'provider' => 'native', 'key' => (string) $key, 'code' => $entry['code'], 'address' => $entry['address'], 'label' => $entry['label'], 'source' => 'company_book', 'book_revision' => $book['revision'], 'entry_fingerprint' => CompanyBook::entry_fingerprint( (string) $key, $entry ) ];
				}
				return $choices;
			} );
		} catch ( \Throwable $error ) { return self::error( 'address_state_unavailable' ); }
	}

	/**
	 * Whether this row is the visit the current request is inside.
	 *
	 * "The signed-in user is the session's user" proves nothing once the
	 * connection's single customer account is the login for every buyer: it
	 * is true of every open visit of that account at once. The proof is the
	 * row. Sessions\Current finds this request's visit by the WP session
	 * token minted for it, and the per-visit cart key — UNIQUE on the row —
	 * must then match byte for byte, so one employee cannot present another
	 * employee's selection as their own.
	 *
	 * A store that cannot answer is not an answer: Current throws, the
	 * callers' bounded catch turns it into a refusal.
	 */
	public function is_request_visit( Session $session ): bool {
		$visit = $this->visits->visit();
		if ( null === $visit || $visit->id !== $session->id ) { return false; }
		$key = (string) $visit->wc_session_key;
		return SessionKey::is_visit_key( $key ) && hash_equals( $key, (string) $session->wc_session_key );
	}

	/** Resolution alone grants neither book editing nor an active shopping session. */
	public function storage_user_id( int $user_id ): int {
		try { return $this->partner_for_user( $user_id )?->owner_user_id ?? 0; }
		catch ( \Throwable $error ) { return 0; }
	}

	/**
	 * Shared server-side association for provider reads and snapshot guards; SQL failure is not an empty book.
	 *
	 * One connection, one customer account: the account a connection belongs
	 * to is the account it is owned by, and nothing else. There is no second
	 * class of linked user to resolve through stored metadata.
	 */
	public function partner_for_user( int $user_id ): ?Partner {
		if ( null === $this->user_state( $user_id ) ) { return null; }
		$partner = $this->registry->find_by_owner( $user_id );
		if ( ! $partner || $partner->owner_user_id <= 0 || $partner->owner_user_id !== $user_id ) { return null; }
		// Re-read the owner past the caches the registry lookup may have warmed: the account must still exist and still be able to read the shop.
		if ( null === $this->user_state( $partner->owner_user_id ) ) { return null; }
		return $partner;
	}

	/** Decode accepted values without reading or rewriting the mutable company book. */
	public function selected_snapshot( Session $session ): ?array {
		try {
			$choice = $session->delivery_choice();
			if ( null === $choice ) { return null; }
			$partner = $this->partner_for_user( $session->user_id );
			// storage_user_id is the connection account, which every visit of that connection shares, so it cannot tell two visits apart on its own.
			if ( ! $this->is_request_visit( $session ) || ! $partner || $partner->id !== $session->partner_id || $partner->owner_user_id !== $choice['storage_user_id'] ) { throw new \DomainException(); }
			return $choice;
		} catch ( \Throwable $error ) { throw new \DomainException( 'The selected delivery address must be selected again.' ); }
	}

	/**
	 * Caller holds the partner mutex and owns exact-login/session/CAS checks. Never reacquire the lock or authorize from a cached aggregate revision. Historical completed Quote reads do not call this active guard.
	 */
	public function validate_active_choice_locked( Session $session, Partner $partner, array $choice ): bool|\WP_Error {
		try {
			$fresh = $this->registry->find( $partner->id );
			$associated = $this->partner_for_user( $session->user_id );
			if ( ! $this->is_request_visit( $session ) || ! $fresh || ! $fresh->is_active() || ! $associated || $session->partner_id !== $fresh->id || $associated->id !== $fresh->id || $partner->owner_user_id !== $fresh->owner_user_id || $associated->owner_user_id !== $fresh->owner_user_id ) { return self::error( 'address_unavailable' ); }
			$choice = DeliveryData::choice( json_encode( $choice, JSON_THROW_ON_ERROR ), $fresh->id );
			if ( $choice['storage_user_id'] !== $fresh->owner_user_id ) { return self::error( 'address_unavailable' ); }
			// Stored book decoding/fingerprints are policy-independent. Only this chosen destination is tested against today's Woo rules; history is never rewritten.
			$current_address = Shape::normalise( $choice['address'] );
			if ( $current_address instanceof \WP_Error ) { return self::error( 'address_invalid' === $current_address->get_error_code() ? 'address_unavailable' : 'address_state_unavailable' ); }
			if ( ! hash_equals( DeliveryData::fingerprint( $choice['address'] ), DeliveryData::fingerprint( $current_address ) ) ) { return self::error( 'address_changed' ); }
			if ( 'native' !== $choice['provider'] ) { return true; }
			if ( null === $choice['entry_fingerprint'] || null === $choice['book_revision'] ) { return self::error( 'address_changed' ); }
			$book = $this->book->read_for_partner_locked( $fresh );
			if ( $book instanceof \WP_Error ) { return self::error( 'address_state_unavailable' ); }
			$entry = $book['addresses'][ $choice['key'] ] ?? null;
			if ( ! $entry || true !== $entry['use_for_punchout'] ) { return self::error( 'address_unavailable' ); }
			$accepted = [ 'label' => $choice['label'], 'address' => $choice['address'], 'code' => $choice['code'], 'use_for_punchout' => true ];
			$accepted_hash = CompanyBook::entry_fingerprint( $choice['key'], $accepted );
			$current_hash = CompanyBook::entry_fingerprint( $choice['key'], $entry );
			if ( ! hash_equals( $choice['entry_fingerprint'], $accepted_hash ) || ! hash_equals( $accepted_hash, $current_hash ) ) { return self::error( 'address_changed' ); }
			return true;
		} catch ( \Throwable $error ) { return self::error( 'address_state_unavailable' ); }
	}

	/** The visit's row must name the connection's own bound login; a connection with none has nobody to resolve a book for. */
	private static function bound_login( Session $session, Partner $partner ): bool {
		return $partner->owner_user_id > 0 && $session->user_id === $partner->owner_user_id && $partner->is_active();
	}

	/** Fresh native account read, bypassing request-local user caches. */
	private function user_state( int $user_id ): ?object {
		global $wpdb;
		if ( $user_id <= 0 ) { return null; }
		$wpdb->last_error = '';
		wp_cache_delete( $user_id, 'users' );
		wp_cache_delete( $user_id, 'user_meta' );
		$user = get_userdata( $user_id );
		if ( '' !== $wpdb->last_error ) { throw new \RuntimeException( 'Address association unavailable.' ); }
		if ( ! $user ) { return null; }
		$allowed = user_can( $user, 'read' );
		// Loading capabilities is itself a metadata read; a failed read is not a missing grant.
		if ( '' !== $wpdb->last_error ) { throw new \RuntimeException( 'Address association unavailable.' ); }
		return $allowed ? $user : null;
	}

	private static function error( string $code ): \WP_Error {
		$text = match ( $code ) {
			'address_changed' => __( 'The selected address changed. Review and confirm it again.', 'punchout-woocommerce' ),
			'address_unavailable' => __( 'The selected address is no longer available. Select an enabled company address.', 'punchout-woocommerce' ),
			default => __( 'The delivery address could not be verified. Reload the page and try again.', 'punchout-woocommerce' ),
		};
		return new \WP_Error( $code, $text );
	}
}
