<?php
/** Company ownership and current selected-address eligibility. @package POW @license AGPL-3.0-or-later */
declare( strict_types = 1 );
namespace POW\Addresses;

use POW\Installer;
use POW\Partners\Partner;
use POW\Partners\Registry;
use POW\Sessions\Session;

defined( 'ABSPATH' ) || exit;

final class Resolver {
	public function __construct( private Registry $registry, private CompanyBook $book ) {}

	public function current(): Provider { return new NativeProvider( $this->registry, $this->book ); }

	/** Resolution alone grants neither book editing nor an active shopping session. */
	public function storage_user_id( int $user_id ): int {
		try { return $this->partner_for_user( $user_id )?->owner_user_id ?? 0; }
		catch ( \Throwable $error ) { return 0; }
	}

	/** Shared server-side association for provider reads and snapshot guards; SQL failure is not an empty book. */
	public function partner_for_user( int $user_id ): ?Partner {
		$state = $this->user_state( $user_id );
		if ( null === $state ) { return null; }
		[ $user, $mapping ] = $state;
		$buyer = in_array( Installer::ROLE, (array) $user->roles, true );
		if ( $buyer ) {
			if ( null === $mapping ) { return null; }
			$partner = $this->registry->find( $mapping );
		} else {
			// A linked account whose role is missing must not become an ordinary owner by fallback.
			if ( null !== $mapping ) { return null; }
			$partner = $this->registry->find_by_owner( $user_id );
		}
		if ( ! $partner || $partner->owner_user_id <= 0 ) { return null; }
		$owner = $this->user_state( $partner->owner_user_id );
		if ( null === $owner || null !== $owner[1] || in_array( Installer::ROLE, (array) $owner[0]->roles, true ) ) { return null; }
		return $partner;
	}

	/** Decode accepted values without reading or rewriting the mutable company book. */
	public function selected_snapshot( Session $session ): ?array {
		try {
			$choice = $session->delivery_choice();
			if ( null === $choice ) { return null; }
			$partner = $this->partner_for_user( $session->user_id );
			if ( ! $partner || $partner->id !== $session->partner_id || $partner->owner_user_id !== $choice['storage_user_id'] ) { throw new \DomainException(); }
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
			if ( ! $fresh || ! $fresh->is_active() || ! $associated || $session->partner_id !== $fresh->id || $associated->id !== $fresh->id || $partner->owner_user_id !== $fresh->owner_user_id || $associated->owner_user_id !== $fresh->owner_user_id ) { return self::error( 'address_unavailable' ); }
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

	/** Fresh native role and exact zero/one association, bypassing request-local user caches. */
	private function user_state( int $user_id ): ?array {
		global $wpdb;
		if ( $user_id <= 0 ) { return null; }
		$wpdb->last_error = '';
		wp_cache_delete( $user_id, 'users' );
		wp_cache_delete( $user_id, 'user_meta' );
		$user = get_userdata( $user_id );
		if ( '' !== $wpdb->last_error ) { throw new \RuntimeException( 'Address association unavailable.' ); }
		if ( ! $user || ! user_can( $user, 'read' ) ) { return null; }
		$values = get_user_meta( $user_id, '_pow_partner_id', false );
		if ( '' !== $wpdb->last_error ) { throw new \RuntimeException( 'Address association unavailable.' ); }
		if ( in_array( Installer::ROLE, (array) $user->roles, true ) ) {
			$deactivated = get_user_meta( $user_id, '_pow_deactivated', true );
			if ( '' !== $wpdb->last_error ) { throw new \RuntimeException( 'Address association unavailable.' ); }
			if ( ! empty( $deactivated ) ) { return null; }
		}
		if ( ! is_array( $values ) || count( $values ) > 1 ) { return null; }
		$mapping = $values[0] ?? '';
		if ( '' === $mapping ) { return [ $user, null ]; }
		if ( is_int( $mapping ) && $mapping > 0 ) { return [ $user, $mapping ]; }
		if ( is_string( $mapping ) && 1 === preg_match( '/\A[1-9][0-9]*\z/', $mapping ) && (string) (int) $mapping === $mapping ) { return [ $user, (int) $mapping ]; }
		return null;
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
