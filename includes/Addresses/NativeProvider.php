<?php
/** Enabled core company addresses for authorized owners and buyers. @package POW @license AGPL-3.0-or-later */
declare( strict_types = 1 );
namespace POW\Addresses;

use POW\Installer;
use POW\Partners\Registry;
use POW\Sessions\Session;

defined( 'ABSPATH' ) || exit;

final class NativeProvider implements Provider {
	public function __construct( private Registry $registry, private CompanyBook $book ) {}

	/** Read failures throw; a missing or unauthorized association simply offers no options. */
	public function list_for_user( int $user_id ): array {
		$actor = get_current_user_id();
		try {
			if ( ! $this->can_view( $actor, $user_id ) ) { return []; }
			$resolver = new Resolver( $this->registry, $this->book );
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

	/** A fresh cross-user grant is required on both sides of mutex acquisition. */
	private function can_view( int $actor, int $target ): bool {
		global $wpdb;
		if ( $actor <= 0 || $actor !== get_current_user_id() ) { return false; }
		// The resolver refreshes the target's role/association before reading its book.
		if ( $actor === $target ) { return true; }
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
		return ( new Resolver( $this->registry, $this->book ) )->selected_snapshot( $session );
	}

	/** Existing adapter API delegates every mutation to the actual editor and current book revision. */
	public function set_code( int $user_id, string $key, string $code ): bool {
		try {
			$actor = get_current_user_id();
			$user = get_userdata( $actor );
			if ( ! $user || in_array( Installer::ROLE, (array) $user->roles, true ) ) { return false; }
			$resolver = new Resolver( $this->registry, $this->book );
			$partner = $resolver->partner_for_user( $user_id );
			if ( ! $partner ) { return false; }
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
