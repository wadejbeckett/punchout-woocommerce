<?php
/** Enabled core company addresses for the connection's account and its visits. @package POW @license AGPL-3.0-or-later */
declare( strict_types = 1 );
namespace POW\Addresses;

use POW\Partners\Registry;
use POW\Sessions\Current;
use POW\Sessions\Session;

defined( 'ABSPATH' ) || exit;

final class NativeProvider implements Provider {
	public function __construct( private Registry $registry, private CompanyBook $book, private Current $visits ) {}

	/** Read failures throw; a missing or unauthorized association simply offers no options. */
	public function list_for_user( int $user_id ): array {
		$actor = get_current_user_id();
		try {
			if ( ! $this->can_view( $actor, $user_id ) ) { return []; }
			$resolver = new Resolver( $this->registry, $this->book, $this->visits );
			$partner = $resolver->partner_for_user( $user_id );
			if ( ! $partner || ! $partner->is_active() ) { return []; }
			return $this->registry->with_partner_lock( $partner->id, function () use ( $resolver, $partner, $user_id, $actor ) {
				// A caller can lose its cross-user capability while waiting for this lock.
				if ( ! $this->can_view( $actor, $user_id ) ) { return []; }
				$fresh = $resolver->partner_for_user( $user_id );
				if ( ! $fresh || ! $fresh->is_active() || $fresh->id !== $partner->id || $fresh->owner_user_id !== $partner->owner_user_id ) { return []; }
				$book = $this->book->read_for_partner_locked( $fresh );
				if ( $book instanceof \WP_Error ) { throw new \DomainException(); }
				$entries = [];
				foreach ( $book['addresses'] as $key => $entry ) {
					if ( true === $entry['use_for_punchout'] ) { $entries[] = [ 'key' => (string) $key, 'label' => $entry['label'], 'address' => $entry['address'], 'code' => $entry['code'] ]; }
				}
				return $entries;
			} );
		} catch ( \Throwable $error ) { throw new \DomainException( 'Company delivery addresses are unavailable.' ); }
	}

	/**
	 * A fresh cross-user grant is required on both sides of mutex acquisition.
	 *
	 * Reading the account's own enabled addresses is how a buyer picks a
	 * delivery destination, so it stays available inside a visit. Reaching
	 * another account's book is management and does not: inside a visit the
	 * request holds whatever the shared customer account holds, and the
	 * capability test alone can no longer separate the two.
	 */
	private function can_view( int $actor, int $target ): bool {
		if ( $actor <= 0 || $actor !== get_current_user_id() ) { return false; }
		// The resolver refreshes the target's association before reading its book.
		if ( $actor === $target ) { return true; }
		return $this->manages( $actor );
	}

	/** A shop-wide grant, re-read past the request caches, and never honoured inside a visit. */
	private function manages( int $actor ): bool {
		global $wpdb;
		if ( null !== $this->visits->visit() ) { return false; }
		$wpdb->last_error = '';
		wp_cache_delete( $actor, 'users' );
		wp_cache_delete( $actor, 'user_meta' );
		$user = get_userdata( $actor );
		if ( '' !== $wpdb->last_error ) { throw new \RuntimeException( 'Address viewer unavailable.' ); }
		$allowed = $user && user_can( $user, 'manage_woocommerce' );
		if ( '' !== $wpdb->last_error ) { throw new \RuntimeException( 'Address viewer unavailable.' ); }
		return $allowed;
	}

	public function selected_for_session( Session $session ): ?array {
		return ( new Resolver( $this->registry, $this->book, $this->visits ) )->selected_snapshot( $session );
	}

	/**
	 * Existing adapter API delegates every mutation to the actual editor and current book revision.
	 *
	 * Editing a delivery code is management, not shopping. A visit is signed
	 * in as the very account that owns the book, so the only thing that can
	 * still refuse it is the visit itself.
	 */
	public function set_code( int $user_id, string $key, string $code ): bool {
		try {
			$actor = get_current_user_id();
			if ( $actor <= 0 || null !== $this->visits->visit() ) { return false; }
			$resolver = new Resolver( $this->registry, $this->book, $this->visits );
			$partner = $resolver->partner_for_user( $user_id );
			if ( ! $partner || ( $actor !== $partner->owner_user_id && ! $this->manages( $actor ) ) ) { return false; }
			return $this->registry->with_partner_lock( $partner->id, function () use ( $partner, $resolver, $user_id, $actor, $key, $code ) {
				$fresh = $resolver->partner_for_user( $user_id );
				if ( ! $fresh || $fresh->id !== $partner->id || $fresh->owner_user_id !== $partner->owner_user_id ) { return false; }
				$book = $this->book->read( $fresh->id, $actor );
				if ( $book instanceof \WP_Error || ! isset( $book['addresses'][ $key ] ) ) { return false; }
				$result = $this->book->save( $fresh->id, $actor, $book['revision'], $key, array_replace( $book['addresses'][ $key ], [ 'code' => $code ] ) );
				return ! $result instanceof \WP_Error;
			} );
		} catch ( \Throwable $error ) { return false; }
	}
}
