<?php
/** Extend Woo's cookie handler without replacing its authentication or storage format. @package POW @license AGPL-3.0-or-later */
declare( strict_types = 1 );
namespace POW\Cart;
use POW\Cart\SessionKey;
use POW\Docs\SelfTest;
use POW\Sessions\Session;
defined( 'ABSPATH' ) || exit;

/**
 * One basket per visit, on WooCommerce's own storage.
 *
 * WooCommerce keys `wp_woocommerce_sessions` on the customer id, and for a
 * signed-in shopper that id is the WordPress user id. A connection has one
 * bound customer account, so every buyer of that connection punches in as the
 * same user and core would hand every concurrent visit the same row. The visit
 * therefore carries its own key (see Cart\SessionKey) and this handler makes
 * core address that key instead of the user id.
 *
 * ## Why init() does not call parent::init()
 *
 * Core's init() runs the private init_session(), and for a logged-in request
 * holding a `pow_` customer id that sequence destroys the visit twice over:
 *
 * 1. is_session_cookie_valid() compares get_current_user_id() to the customer
 *    id as its third test, so it returns false for a visit key. init_session_cookie()
 *    answers that by calling destroy_session(), i.e. delete_session( pow_key )
 *    plus wc_empty_cart() — the visit's row is deleted outright.
 * 2. `is_user_logged_in() && (string) get_current_user_id() !== $this->get_customer_id()`
 *    is then ALWAYS true, so migrate_guest_session_to_user_session() writes
 *    whatever data survived onto session_key = '<user id>' and re-cookies the
 *    browser with the shared numeric key. Two visits would collide on one row.
 * 3. init_session_from_request() honours `?session=` and clones a foreign
 *    basket onto this one.
 *
 * is_session_cookie_valid(), is_customer_guest() and migrate_guest_session_to_user_session()
 * are all private, so none of them can be overridden: bypassing init_session()
 * is the only lever. Inside a visit this handler therefore calls the protected
 * init_hooks() itself and then establishes the customer id, the expiry and the
 * data. init_hooks() is not optional — core registers the shutdown save at 20,
 * `wp`/maybe_set_customer_session_cookie at 99, template_redirect/destroy_session_if_empty
 * at 999, wp_logout/destroy_session and woocommerce_set_cart_cookies there, and
 * without it the visit's basket is never persisted at all.
 *
 * A request that is not inside a visit is an ordinary shopper — the bound
 * account's own browsing included — and runs parent::init() byte for byte.
 *
 * ## What must never reach the browser
 *
 * The visit key is a bearer credential: it names a basket and a Cart-Token
 * carrying it authenticates with no cookie and no capability. It arrives from
 * `sessions.wc_session_key` and it must never be written into a WooCommerce
 * session cookie, so set_customer_session_cookie(), maybe_set_customer_session_cookie()
 * and init_session_cookie() are unconditional no-ops inside a visit, and
 * get_session_cookie() answers false so nothing can re-key from one.
 *
 * ## Object-cache discipline
 *
 * Core's get_session() reads wp_cache_get first and wp_cache_add()s on a miss,
 * and its save_data() wp_cache_set()s, all under `get_cache_prefix() . $customer_id`
 * with no per-visit isolation — and get_cache_prefix() is private. A visit key
 * therefore must never reach an inherited path: get_session(), save_data(),
 * delete_session() and update_session_timestamp() all answer a visit key
 * themselves and never call parent, so no entry under a `pow_` key is ever
 * written and none can be read back. The only cache operation this handler
 * performs is flush_cache()'s wp_cache_delete(), which is why a refused commit
 * can never leave a value behind that a later read would trust.
 */
final class NativeSessionHandler extends \WC_Session_Handler {
	private ?NativeSessionRow $row = null;
	private ?Session $bound = null;
	/** Sticky for the whole request once init() resolved a visit; never cleared, so the cookie no-ops cannot be undone by state teardown. */
	private bool $visit = false;
	/** This visit's own WooCommerce session key, '' outside a visit. Kept beside _customer_id, which destroy_session() clears. */
	private string $key = '';
	private bool $blocked = false;
	private bool $changed = false;
	private bool $cache_dirty = false;
	private ?string $cache_key = null;
	private bool $initializing = false;
	/** Decoded native data at hydration; the retry merge applies only keys this request changed. */
	private array $baseline = [];
	private ?string $refusal = null;
	/** Throwable class and message behind an 'exception' refusal; never session data. */
	private ?string $refusal_detail = null;
	public const REFUSED_ROW_CONFLICT = 'row_conflict';
	public const REFUSED_IDENTITY = 'identity';
	public const REFUSED_CONSENT_INVALIDATION = 'consent_invalidation';
	public const REFUSED_SNAPSHOT = 'snapshot';
	public const REFUSED_EXCEPTION = 'exception';
	public function init() {
		$this->initializing = true;
		try {
			$visit = NativeSessionGuard::registered()->visit();
			if ( ! $visit ) {
				// Not inside a visit: an ordinary shopper, including the bound account's own browsing.
				parent::init();
				return;
			}
			// A subclass that depends on core internals must prove they are still there before it bypasses them.
			$shape = SelfTest::cart_handler_faults();
			if ( [] !== $shape ) { throw new \RuntimeException( 'Native cart handler shape changed: ' . implode( '; ', $shape ) ); }
			$this->init_hooks();
			$this->visit = true;
			$this->key = SessionKey::for_session( $visit );
			$this->_customer_id = $this->key;
			$this->apply_visit_expiration( $visit );
			// The primary hydration path: this visit's own row, read through the guarded storage boundary.
			$this->_data = $this->get_session( $this->key, [] );
			$this->_dirty = false;
		}
		catch ( \Throwable $error ) {
			$this->refuse();
			wp_die( esc_html__( 'This cart session could not be protected. Open the catalog again through your purchasing system.', 'punchout-woocommerce' ), '', [ 'response' => 409 ] );
		} finally { $this->initializing = false; }
	}
	/**
	 * Cap this request's session lifetime at the visit's own.
	 *
	 * set_session_expiration() is deliberately NOT overridden: core calls it
	 * from __construct(), long before any visit is resolvable, and again from
	 * destroy_session() and the migration path. The supported lever is the
	 * wc_session_expiration filter, which CartTokenUtils::get_cart_token_expiration()
	 * also reads — so one filter both stops a visit leaving a week-long orphan
	 * row behind and stops a Store API cart token outliving the visit it names.
	 * The filter is global and unscoped; that is accepted because nothing else
	 * computes an expiry inside a punchout request.
	 */
	private function apply_visit_expiration( Session $visit ): void {
		$ttl = self::visit_ttl( $visit );
		add_filter( 'wc_session_expiration', static fn(): int => $ttl, PHP_INT_MAX );
		$this->set_session_expiration();
	}
	/** Seconds the visit still has, floored so core's own `?: default` fallback can never fire. */
	private static function visit_ttl( Session $visit ): int {
		$expires = $visit->expires ? (int) strtotime( $visit->expires . ' UTC' ) : 0;
		return max( 60, $expires - time() );
	}
	/** True once init() resolved a visit for this request. */
	public function in_visit(): bool { return $this->visit; }
	/** Inside a visit the customer id is the visit's key, never the shared user id. */
	public function generate_customer_id() {
		return $this->visit ? $this->key : parent::generate_customer_id();
	}
	/**
	 * The only public entry into core's private migration and clone logic, and
	 * reachable from wc_set_customer_auth_cookie(), which any gateway may call
	 * mid-request. Inside a visit it does nothing at all: core would re-key
	 * this handler to the shared user id and copy the visit's basket there.
	 */
	public function init_session_cookie() {
		if ( $this->visit ) { return; }
		parent::init_session_cookie();
	}
	/** No cookie carries a visit key, so nothing may re-key from one. */
	public function get_session_cookie() {
		return $this->visit ? false : parent::get_session_cookie();
	}
	/** Unconditional no-op inside a visit: WC_Cart_Session registers maybe_set_cart_cookies on woocommerce_add_to_cart, on `wp` at 99 and on shutdown at 0, and the shutdown-0 registration runs before this plugin's own commit whatever else has happened. */
	public function set_customer_session_cookie( $set ) {
		if ( $this->visit ) { return; }
		parent::set_customer_session_cookie( $set );
	}
	/** Core calls this on `wp` at 99 for order-pay; inside a visit it must still write nothing. */
	public function maybe_set_customer_session_cookie() {
		if ( $this->visit ) { return; }
		parent::maybe_set_customer_session_cookie();
	}
	/** A visit always has a session: core's answer depends on a cookie this handler never writes. */
	public function has_session() {
		return $this->visit ? true : parent::has_session();
	}
	/** This visit's data as hydrated, never core's `has_session() ? get_session( get_customer_id() )` re-read. */
	public function get_session_data() {
		return $this->visit ? $this->_data : parent::get_session_data();
	}
	public function get_customer_unique_id() {
		return $this->visit ? $this->key : parent::get_customer_unique_id();
	}
	/** Remove this visit's own row and forget it; core would delete whatever key the handler happened to hold. */
	public function destroy_session() {
		if ( ! $this->visit ) { parent::destroy_session(); return; }
		$this->delete_session( $this->key );
		$this->forget_session();
	}
	/**
	 * Inside a visit there is no cookie to expire and no id to regenerate:
	 * core's generate_customer_id() would hand the visit key straight back, so
	 * the customer id is cleared instead of being left on a key whose row has
	 * just gone. wc_empty_cart() is deliberately not called — the row is
	 * already removed and this handler is refused, so emptying the in-memory
	 * cart would only fire cart hooks with nothing left to persist to.
	 */
	public function forget_session() {
		if ( ! $this->visit ) { parent::forget_session(); return; }
		$this->_data = [];
		$this->_dirty = false;
		$this->_customer_id = '';
		$this->_has_cookie = false;
	}
	public function get_session( $customer_id, $default_value = false ) {
		$key = (string) $customer_id;
		if ( ! SessionKey::is_visit_key( $key ) ) { return parent::get_session( $customer_id, $default_value ); }
		// A visit key that is not this request's names a colleague's basket. Core's
		// get_session() would decode it for anyone who can name it, and a Store API
		// route or a third-party call can name anything; refuse rather than read.
		if ( '' === $this->key || ! hash_equals( $this->key, $key ) ) { return $default_value; }
		try {
			if ( ! $this->row ) {
				try { $this->bound = NativeSessionGuard::registered()->login( $key ); }
				catch ( \Throwable $error ) {
					$this->refuse();
					// Woo restores a cookie before checking whether it belongs to a logged-out/expired login. Give that initialization no cart data or write authority, but let native cookie cleanup and the later Start route run. Only a new request may bind the newly redeemed login; this handler stays blocked through shutdown.
					if ( $this->initializing ) { return []; }
					throw $error;
				}
				$this->row = new NativeSessionRow( $key );
				if ( class_exists( '\\WC_Cache_Helper' ) && defined( 'WC_SESSION_CACHE_GROUP' ) ) { $this->cache_key = \WC_Cache_Helper::get_cache_prefix( WC_SESSION_CACHE_GROUP ) . $key; }
			}
			$data = self::decode( $this->row->load(), $default_value );
			$this->baseline = is_array( $data ) ? $data : [];
			return $data;
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
	/** Shape decides, so an ordinary shopper's numeric or `t_` key answers false and parent::save_data() keeps running for them. */
	public function is_protected(): bool { return null !== $this->row || $this->blocked || SessionKey::is_visit_key( (string) $this->get_customer_id() ); }
	public function refuse(): void { $this->blocked = true; $this->row?->refuse(); }
	/** First refusal reason of the current save attempt wins; later cascading refusals do not overwrite it. */
	private function refuse_for( string $reason, ?string $detail = null ): void { if ( null === $this->refusal ) { $this->refusal = $reason; $this->refusal_detail = $detail; } $this->refuse(); }
	/** A throwable escaping the guarded commit is a refusal that records what was thrown. */
	public function refuse_for_error( \Throwable $error ): void { $this->refuse_for( self::REFUSED_EXCEPTION, get_class( $error ) . ': ' . substr( $error->getMessage(), 0, 160 ) ); }
	/** Why the last save_checked() was refused (one of the REFUSED_* constants), or null after success. */
	public function last_refusal(): ?string { return $this->refusal; }
	public function mark_changed(): void { $this->changed = true; }
	/** All serialization and native authentication happens before acquiring the partner mutex. */
	public function stage(): array {
		if ( $this->blocked || ! $this->row || ! $this->bound || ( WC()->session ?? null ) !== $this || ! hash_equals( (string) ( $this->bound->wc_session_key ?? '' ), (string) $this->get_customer_id() ) ) { $this->refuse(); throw new \RuntimeException( 'Native cart snapshot unavailable.' ); }
		$identity = NativeSessionGuard::registered()->stage_identity( $this->bound );
		self::scalar_tree( $this->_data );
		return [ 'handler' => spl_object_id( $this ), 'identity' => $identity, 'data' => $this->_data, 'value' => serialize( $this->_data ), 'expiry' => (int) $this->_session_expiration ];
	}
	/** No parent save, native/authentication callback, serialization or cache write here. */
	public function check_locked( array $prepared ): bool {
		if ( $this->blocked || ! $this->row || ( WC()->session ?? null ) !== $this || ( $prepared['handler'] ?? null ) !== spl_object_id( $this ) || ( $prepared['data'] ?? null ) !== $this->_data || ( $prepared['expiry'] ?? null ) !== (int) $this->_session_expiration ) { $this->refuse_for( self::REFUSED_SNAPSHOT ); return false; }
		$guard = NativeSessionGuard::registered();
		$fresh = $guard->identity_locked( $prepared['identity'] );
		if ( ! $fresh ) { $this->refuse_for( self::REFUSED_IDENTITY ); return false; }
		if ( ! $this->row->matches_locked() ) { $this->refuse_for( self::REFUSED_ROW_CONFLICT ); return false; }
		return true;
	}
	public function commit_locked( array $prepared ): bool {
		if ( ! $this->check_locked( $prepared ) ) { return false; }
		$guard = NativeSessionGuard::registered();
		$fresh = $guard->identity_locked( $prepared['identity'] );
		if ( ! $fresh ) { $this->refuse_for( self::REFUSED_IDENTITY ); return false; }
		// A pending NULL invalidation still participates in the same commit order. Failed clear blocks the write (Store::invalidate_delivery false demands caller fencing, never an assumption that consent survived); existing recovery remains Chooser/Store-owned. The refusal is surfaced by save_checked().
		if ( $this->changed && ! $guard->invalidate_locked( $fresh ) ) { $this->refuse_for( self::REFUSED_CONSENT_INVALIDATION ); return false; }
		if ( ! $this->row->commit_locked( $prepared['value'], $prepared['expiry'] ) ) { $this->refuse_for( self::REFUSED_ROW_CONFLICT ); return false; }
		if ( ( WC()->session ?? null ) !== $this || $prepared['data'] !== $this->_data || ! $guard->identity_locked( $prepared['identity'] ) ) { $this->refuse_for( self::REFUSED_SNAPSHOT ); return false; }
		$this->_dirty = false;
		$this->changed = false;
		$this->cache_dirty = true;
		return true;
	}
	/** Protected saves never fall back to the unguarded native save. A compare-and-set loss to an overlapping request is retried once on the fresh row; every final refusal is logged. */
	public function save_checked(): bool {
		if ( ! $this->is_protected() ) { parent::save_data(); return true; }
		$guard = NativeSessionGuard::registered();
		$this->refusal = null;
		$this->refusal_detail = null;
		try {
			if ( $guard->save( $this, $this->stage() ) ) { return true; }
			if ( self::REFUSED_ROW_CONFLICT === $this->refusal && $this->rehydrate() ) {
				$this->refusal = null;
				if ( $guard->save( $this, $this->stage() ) ) { return true; }
				$this->refusal ??= self::REFUSED_ROW_CONFLICT;
			}
		}
		catch ( \Throwable $error ) { $this->refuse_for_error( $error ); }
		finally { $this->flush_cache(); }
		$this->refusal ??= self::REFUSED_EXCEPTION; // Guard::save() swallowed a throwable inside the mutex.
		$guard->log_refusal( '' !== $this->key ? $this->key : (string) $this->get_customer_id(), $this->refusal, $this->refusal_detail );
		return false;
	}
	/** Only after a row conflict: reload the native row and replay this request's own changes (keys that differ from its hydration baseline) on top of the other writer's value. Identity, consent and snapshot refusals are never retried. */
	private function rehydrate(): bool {
		if ( ! $this->row || ! $this->bound || ( WC()->session ?? null ) !== $this ) { return false; }
		$row = new NativeSessionRow( '' !== $this->key ? $this->key : (string) $this->get_customer_id() );
		$fresh = self::decode( $row->load(), [] );
		if ( ! is_array( $fresh ) ) { return false; }
		$merged = $fresh;
		foreach ( $this->_data as $key => $value ) { if ( ! array_key_exists( $key, $this->baseline ) || $this->baseline[ $key ] !== $value ) { $merged[ $key ] = $value; } }
		foreach ( array_keys( $this->baseline ) as $key ) { if ( ! array_key_exists( $key, $this->_data ) ) { unset( $merged[ $key ] ); } }
		$this->row = $row;
		$this->baseline = $fresh;
		$this->_data = $merged;
		$this->_dirty = true;
		$this->blocked = false;
		return true;
	}
	public function save_data( $old_session_key = '' ) {
		if ( ! $this->is_protected() ) { parent::save_data( $old_session_key ); return; }
		// $old_session_key only ever arrives from core's migrate_guest_session_to_user_session(), which init() no longer reaches, so inside a visit it is always ''. The early return stays because a superseded or refused handler may still be called at shutdown before it ever loaded a row.
		if ( $this->initializing && ! $this->row ) { return; }
		// A handler refused at init (e.g. the redeem request, whose new login cookie is not yet visible to itself) never loaded a row: there is nothing to commit, so no refusal to log.
		if ( $this->blocked && ! $this->row ) { return; }
		$this->save_checked();
	}
	public function flush_cache(): void {
		if ( $this->cache_dirty && null !== $this->cache_key ) { wp_cache_delete( $this->cache_key, WC_SESSION_CACHE_GROUP ); }
		$this->cache_dirty = false;
	}
	public function delete_session( $customer_id ) {
		$key = (string) $customer_id;
		// Native expiry GC remains inherited. Under one shared login a user-id test would
		// give every concurrent visit deletion authority over every other visit's basket,
		// so only this visit's own key may be removed and any other `pow_` key is refused
		// outright — never handed to parent::delete_session().
		if ( SessionKey::is_visit_key( $key ) ) {
			if ( '' === $this->key || ! hash_equals( $this->key, $key ) ) { return; }
			// Native init restores data before discovering an expired/mismatched cookie. That read is not deletion authority over a replacement buyer cart.
			if ( ! $this->initializing && ! $this->blocked && $this->bound ) { NativeSessionGuard::registered()->cleanup( $this, $this->bound, false ); }
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
		// Protected expiry advances with a verified value commit, not an independent unguarded update. Shape decides, so an ordinary key still reaches parent.
		if ( SessionKey::is_visit_key( (string) $customer_id ) ) { return; }
		parent::update_session_timestamp( $customer_id, $timestamp );
	}
	private static function scalar_tree( mixed $value, int $depth = 0 ): void {
		if ( $depth > 32 || is_object( $value ) || is_resource( $value ) ) { throw new \RuntimeException( 'Unsupported native session data.' ); }
		if ( is_array( $value ) ) { foreach ( $value as $entry ) { self::scalar_tree( $entry, $depth + 1 ); } }
	}
}
