<?php
/**
 * Duplicate-payloadID (replay) semantics.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Sessions;

defined( 'ABSPATH' ) || exit;

/**
 * State-dependent replay rules, written down and unit-tested (scope §7):
 *
 * - A duplicate payloadID with an IDENTICAL body while the session is still
 *   `pending` is a legitimate retry (payloadID must not change on retries,
 *   cXML basics) → replay the stored response byte-identically.
 * - Once the StartPage token has been redeemed (`active` or later), ANY
 *   duplicate — identical body or not — is a cXML 409: replaying the stored
 *   response would hand the buyer a dead single-use URL.
 * - A duplicate with a DIFFERENT body is 409 in every state.
 *
 * Pure: the decision takes the existing row's status/body-hash and the new
 * request's body hash, nothing else.
 */
final class ReplayPolicy {

	public const DECISION_NEW      = 'new';      // No existing row — process normally.
	public const DECISION_REPLAY   = 'replay';   // Return the stored response verbatim.
	public const DECISION_CONFLICT = 'conflict'; // cXML 409.

	public static function decide( ?string $existing_status, ?string $existing_body_hash, string $body_hash ): string {
		if ( null === $existing_status ) {
			return self::DECISION_NEW;
		}

		if ( Session::PENDING === $existing_status && null !== $existing_body_hash && hash_equals( $existing_body_hash, $body_hash ) ) {
			return self::DECISION_REPLAY;
		}

		return self::DECISION_CONFLICT;
	}
}
