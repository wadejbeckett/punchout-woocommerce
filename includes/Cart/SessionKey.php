<?php
/** The per-visit WooCommerce session key. @package POW @license AGPL-3.0-or-later */
declare( strict_types = 1 );
namespace POW\Cart;
use POW\Sessions\Session;
defined( 'ABSPATH' ) || exit;

/**
 * One visit's basket, named.
 *
 * A connection has a single bound customer account, so every buyer of that
 * customer punches in as the same WordPress user and WooCommerce's own key —
 * the user id — would hand every concurrent visit the same basket. The visit
 * therefore carries its own key, minted when its login is bound and stored
 * UNIQUE on the session row. It is the only thing that answers "is this
 * basket mine": the user id cannot, and neither can the account's
 * capabilities.
 *
 * The key is 32 characters in total — `pow_` plus 28 hexadecimal digits, 112
 * bits of randomness — because the column it has to fit,
 * wp_woocommerce_sessions.session_key, is char(32) with a UNIQUE index. A
 * longer key raises "Data too long" under STRICT_TRANS_TABLES and is
 * truncated without it, which makes a byte-exact readback of a successful
 * INSERT report failure. WooCommerce's own guest key is exactly 32
 * characters for the same reason.
 */
final class SessionKey {

	/** Characters a visit key has, exactly: the width of WooCommerce's column. */
	public const LENGTH = 32;

	/** The shape, anchored: a `pow_` prefix and 28 lowercase hexadecimal digits. */
	private const SHAPE = '/\Apow_[0-9a-f]{28}\z/';

	/**
	 * A fresh key for one visit.
	 *
	 * @throws \RuntimeException When the platform cannot produce a key of the stored width.
	 */
	public static function mint(): string {
		$key = 'pow_' . bin2hex( random_bytes( 14 ) );

		// A key that does not fit the column would fail closed at the first
		// cart write, after the visit had already been handed to the browser.
		if ( self::LENGTH !== strlen( $key ) || ! self::is_visit_key( $key ) ) {
			throw new \RuntimeException( 'Visit cart key could not be minted.' );
		}

		return $key;
	}

	/**
	 * Whether a WooCommerce session key is a punchout visit's own.
	 *
	 * Shape alone decides. A numeric key is an ordinary shopper's, a `t_` key
	 * is a guest's, and neither is ours — no user, role or meta lookup can
	 * tell them apart once the account is shared.
	 */
	public static function is_visit_key( string $key ): bool {
		return 1 === preg_match( self::SHAPE, $key );
	}

	/**
	 * The key of the visit this session row is.
	 *
	 * @throws \RuntimeException When the row carries no key of its own, which
	 *                          is every row whose login was never bound. The
	 *                          message names no key.
	 */
	public static function for_session( Session $session ): string {
		$key = (string) ( $session->wc_session_key ?? '' );

		if ( ! self::is_visit_key( $key ) ) {
			throw new \RuntimeException( 'Visit cart key unavailable.' );
		}

		return $key;
	}
}
