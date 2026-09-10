<?php
/** Extend Woo's cookie handler without replacing its authentication or storage format. @package POW @license AGPL-3.0-or-later */
declare( strict_types = 1 );
namespace POW\Cart;
use POW\Sessions\Session;
defined( 'ABSPATH' ) || exit;

final class NativeSessionHandler extends \WC_Session_Handler {
	private ?NativeSessionRow $row = null;
	private ?Session $bound = null;
	private bool $blocked = false;
	private bool $changed = false;
	private bool $cache_dirty = false;
	private ?string $cache_key = null;
	private bool $initializing = false;
	public function init() {
		$this->initializing = true;
		try {
			parent::init();
			if ( ! $this->blocked && ! $this->row && NativeSessionGuard::registered()->protected_key( (string) $this->get_customer_id() ) ) {
				// Woo has completed guest/token migration and established the destination identity, but cart hydration has not started. Punchout never imports an ordinary guest basket; its existing create/edit seed policy consumes this buyer's own native data.
				$this->_data = $this->get_session( $this->get_customer_id(), [] );
				$this->_dirty = false;
			}
		}
		catch ( \Throwable $error ) {
			$this->refuse();
			wp_die( esc_html__( 'This cart session could not be protected. Open the catalog again through your purchasing system.', 'punchout-woocommerce' ), '', [ 'response' => 409 ] );
		} finally { $this->initializing = false; }
	}
	public function get_session( $customer_id, $default_value = false ) {
		$guard = NativeSessionGuard::registered();
		$key = (string) $customer_id;
		if ( ! $guard->protected_key( $key ) ) { return parent::get_session( $customer_id, $default_value ); }
		try {
			// Incidental reads of another native row do not establish this handler's baseline.
			if ( $key !== (string) $this->get_customer_id() ) { return self::decode( ( new NativeSessionRow( $key ) )->load(), $default_value ); }
			if ( ! $this->row ) {
				try { $this->bound = $guard->login( $key ); }
				catch ( \Throwable $error ) {
					$this->refuse();
					// Woo restores a numeric cookie before checking whether it belongs to a logged-out/expired login. Give that initialization no cart data or write authority, but let native cookie cleanup and the later Start route run. Only a new request may bind the newly redeemed login; this handler stays blocked through shutdown.
					if ( $this->initializing ) { return []; }
					throw $error;
				}
				$this->row = new NativeSessionRow( $key );
				if ( class_exists( '\\WC_Cache_Helper' ) && defined( 'WC_SESSION_CACHE_GROUP' ) ) { $this->cache_key = \WC_Cache_Helper::get_cache_prefix( WC_SESSION_CACHE_GROUP ) . $key; }
			}
			return self::decode( $this->row->load(), $default_value );
		} catch ( \Throwable $error ) { $this->refuse(); throw $error; }
	}
	private static function decode( ?string $raw, mixed $default ): mixed {
		if ( null === $raw ) { return $default; }
		// Native values are serialized once at the row level and may be serialized per key too. Do not instantiate extension objects from storage.
		$data = unserialize( $raw, [ 'allowed_classes' => false ] );
		if ( ! is_array( $data ) ) { throw new \RuntimeException( 'Native cart storage invalid.' ); }
		self::scalar_tree( $data );
		return $data;
	}
	public function is_protected(): bool { return null !== $this->row || $this->blocked || NativeSessionGuard::registered()->protected_key( (string) $this->get_customer_id() ); }
	public function refuse(): void { $this->blocked = true; $this->row?->refuse(); }
	public function mark_changed(): void { $this->changed = true; }
	/** All serialization and native authentication happens before acquiring the partner mutex. */
	public function stage(): array {
		if ( $this->blocked || ! $this->row || ! $this->bound || ( WC()->session ?? null ) !== $this || (string) $this->bound->user_id !== (string) $this->get_customer_id() ) { $this->refuse(); throw new \RuntimeException( 'Native cart snapshot unavailable.' ); }
		$identity = NativeSessionGuard::registered()->stage_identity( $this->bound );
		self::scalar_tree( $this->_data );
		return [ 'handler' => spl_object_id( $this ), 'identity' => $identity, 'data' => $this->_data, 'value' => serialize( $this->_data ), 'expiry' => (int) $this->_session_expiration ];
	}
	/** No parent save, native/authentication callback, serialization or cache write here. */
	public function check_locked( array $prepared ): bool {
		if ( $this->blocked || ! $this->row || ( WC()->session ?? null ) !== $this || ( $prepared['handler'] ?? null ) !== spl_object_id( $this ) || ( $prepared['data'] ?? null ) !== $this->_data || ( $prepared['expiry'] ?? null ) !== (int) $this->_session_expiration ) { $this->refuse(); return false; }
		$guard = NativeSessionGuard::registered();
		$fresh = $guard->identity_locked( $prepared['identity'] );
		if ( ! $fresh || ! $this->row->matches_locked() ) { $this->refuse(); return false; }
		return true;
	}
	public function commit_locked( array $prepared ): bool {
		if ( ! $this->check_locked( $prepared ) ) { return false; }
		$guard = NativeSessionGuard::registered();
		$fresh = $guard->identity_locked( $prepared['identity'] );
		if ( ! $fresh ) { $this->refuse(); return false; }
		// A pending NULL invalidation still participates in the same commit order. Failed clear blocks the write; existing recovery remains Chooser/Store-owned.
		if ( $this->changed && ! $guard->invalidate_locked( $fresh ) ) { $this->refuse(); return false; }
		if ( ! $this->row->commit_locked( $prepared['value'], $prepared['expiry'] ) ) { $this->refuse(); return false; }
		if ( ( WC()->session ?? null ) !== $this || $prepared['data'] !== $this->_data || ! $guard->identity_locked( $prepared['identity'] ) ) { $this->refuse(); return false; }
		$this->_dirty = false;
		$this->changed = false;
		$this->cache_dirty = true;
		return true;
	}
	public function save_checked(): bool {
		if ( ! $this->is_protected() ) { parent::save_data(); return true; }
		try { return NativeSessionGuard::registered()->save( $this, $this->stage() ); }
		catch ( \Throwable $error ) { $this->refuse(); return false; }
		finally { $this->flush_cache(); }
	}
	public function save_data( $old_session_key = '' ) {
		if ( ! $this->is_protected() ) { parent::save_data( $old_session_key ); return; }
		// Migration/clone callbacks precede destination hydration. Let native initialization finish its cookie/authentication work, then load the destination row in init(); never copy guest data over it.
		if ( $this->initializing && ! $this->row ) { return; }
		$this->save_checked();
	}
	public function flush_cache(): void {
		if ( $this->cache_dirty && null !== $this->cache_key ) { wp_cache_delete( $this->cache_key, WC_SESSION_CACHE_GROUP ); }
		$this->cache_dirty = false;
	}
	public function delete_session( $customer_id ) {
		// Native expiry GC remains inherited. An old request must never delete a buyer row belonging to a newer login.
		if ( ( $this->bound && (string) $this->bound->user_id === (string) $customer_id ) || NativeSessionGuard::registered()->protected_key( (string) $customer_id ) ) {
			// Native init restores data before discovering an expired/mismatched cookie. That read is not deletion authority over a replacement buyer cart.
			if ( ! $this->initializing && ! $this->blocked && $this->bound && (string) $this->bound->user_id === (string) $customer_id ) { NativeSessionGuard::registered()->cleanup( $this, $this->bound, false ); }
			$this->refuse(); $this->flush_cache(); return;
		}
		parent::delete_session( $customer_id );
	}
	public function delete_locked(): bool {
		if ( $this->blocked || ! $this->row || ( WC()->session ?? null ) !== $this ) { $this->refuse(); return false; }
		$removed = $this->row->delete_locked();
		$this->cache_dirty = $removed;
		$this->refuse();
		return $removed;
	}
	public function cleanup_terminal(): void {
		if ( ! $this->blocked && $this->bound ) { NativeSessionGuard::registered()->cleanup( $this, $this->bound, true ); $this->flush_cache(); }
	}
	public function update_session_timestamp( $customer_id, $timestamp ) {
		// Protected expiry advances with a verified value commit, not an independent unguarded update.
		if ( NativeSessionGuard::registered()->protected_key( (string) $customer_id ) ) { return; }
		parent::update_session_timestamp( $customer_id, $timestamp );
	}
	private static function scalar_tree( mixed $value, int $depth = 0 ): void {
		if ( $depth > 32 || is_object( $value ) || is_resource( $value ) ) { throw new \RuntimeException( 'Unsupported native session data.' ); }
		if ( is_array( $value ) ) { foreach ( $value as $entry ) { self::scalar_tree( $entry, $depth + 1 ); } }
	}
}
