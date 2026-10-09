<?php
/** The winning return's handoff, kept briefly for the same browser's repeated Submit (0.4.22). @package POW @license AGPL-3.0-or-later */
declare( strict_types = 1 );
namespace POW\Sessions;

defined( 'ABSPATH' ) || exit;

/**
 * A double click on "Submit" sends two POSTs. The browser keeps only the response to the last one,
 * but the first one wins the return (the visit's single-winner transition) and ends the login, so the second one
 * used to get an error page and the cart never reached the purchasing system.
 *
 * The winning request now keeps its handoff page for TTL seconds, inside the connection lock and before the
 * transition, so any request that sees the visit returned also finds it (a request that lost the transition drops
 * its record again). A later request is answered with that same page only when it presents both the same login
 * token (the visit's own auth cookie; the key is a hash of it) and the same return nonce as the winner (the same
 * review form), asks for the same mode, and the visit row the record names has really left `active` that way with
 * that login (ReturnEndpoint::replay()). Nothing else can read it, nothing new is built, and no second quote,
 * e-mail or transition happens.
 *
 * The record is a transient: the page carries the returned cart for its short life, as the audit log's
 * archived copy of the same cXML already does for longer.
 */
final class HandoffReplay {
	/** Seconds the winner's handoff stays available to a repeated Submit. */
	public const TTL = 120;
	private const PREFIX = 'pow_handoff_';

	/** The transient name for a login token, or '' for none. Only a hash of the token is ever stored. */
	public static function key( string $login_token ): string {
		return '' === $login_token ? '' : self::PREFIX . hash( 'sha256', 'pow-handoff|' . $login_token );
	}

	/** Keep the winner's handoff for this login and form. False when there is nothing to keep or the write failed. */
	public static function remember( string $login_token, string $nonce, int $session_id, string $mode, string $markup ): bool {
		$key = self::key( $login_token );
		if ( '' === $key || '' === $nonce || $session_id <= 0 || '' === trim( $markup ) ) { return false; }
		try {
			return true === set_transient( $key, [ 'session_id' => $session_id, 'mode' => $mode, 'nonce' => hash( 'sha256', $nonce ), 'markup' => $markup ], self::TTL );
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/**
	 * The kept handoff for this login and form, or null.
	 *
	 * @return array{session_id: int, mode: string, markup: string}|null
	 */
	public static function find( string $login_token, string $nonce ): ?array {
		$key = self::key( $login_token );
		if ( '' === $key || '' === $nonce ) { return null; }
		try { $record = get_transient( $key ); } catch ( \Throwable $error ) { return null; }
		if ( ! is_array( $record ) || ! is_string( $record['nonce'] ?? null ) || ! is_string( $record['markup'] ?? null ) || '' === $record['markup'] || ! is_string( $record['mode'] ?? null ) || ! is_int( $record['session_id'] ?? null ) ) { return null; }
		if ( ! hash_equals( $record['nonce'], hash( 'sha256', $nonce ) ) ) { return null; }
		return [ 'session_id' => $record['session_id'], 'mode' => $record['mode'], 'markup' => $record['markup'] ];
	}

	/** Drop the record a request kept before a transition it then lost. */
	public static function forget( string $login_token ): void {
		$key = self::key( $login_token );
		if ( '' === $key ) { return; }
		try {
			if ( function_exists( 'delete_transient' ) ) { delete_transient( $key ); }
		} catch ( \Throwable $error ) {
			// The record expires on its own.
		}
	}
}
