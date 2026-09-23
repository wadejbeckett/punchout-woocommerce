<?php
/**
 * The My Account endpoints a connection lets its visits open.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Account;

defined( 'ABSPATH' ) || exit;

/**
 * One connection column, `visit_endpoints`: a comma-separated list of
 * WooCommerce My Account endpoint names a punchout visit may open. Empty is
 * the default and means what it always meant — the whole account area is
 * outside a visit's remit.
 *
 * The list exists for third-party My Account endpoints (a quick-order form, a
 * saved-list screen) that a store has registered with WooCommerce in the
 * usual way. It can never open the account surfaces that belong to the shared
 * login rather than to the visit: every buyer of a connection is signed in as
 * the same account, so its order history, addresses, account details,
 * password, payment methods and logout are every colleague's at once. Those
 * are HARD_DENY, fixed here, checked on the way in and again on every
 * request, and no setting or hook reaches them.
 *
 * Pure: no WordPress call, so the admin form, the registry and the route
 * guard all ask the same function the same question.
 */
final class VisitEndpoints {

	/**
	 * Endpoints a visit never opens, whatever a connection lists.
	 *
	 * '' is the account dashboard, and 'dashboard' is the name WooCommerce's
	 * account menu gives that same page.
	 *
	 * 'order-pay' and 'order-received' are WooCommerce's checkout endpoints.
	 * They are in its endpoint map but have no account content, so on the
	 * account page WooCommerce renders the dashboard for them. The guard
	 * refuses any endpoint without account content anyway; listing them here
	 * as well means the form refuses them by name.
	 *
	 * @var list<string>
	 */
	public const HARD_DENY = [
		'',
		'dashboard',
		'order-pay',
		'order-received',
		'orders',
		'view-order',
		'downloads',
		'edit-address',
		'edit-account',
		'payment-methods',
		'add-payment-method',
		'delete-payment-method',
		'set-default-payment-method',
		'lost-password',
		'customer-logout',
		IntegrationTab::ENDPOINT,
	];

	/** The column is VARCHAR(255). */
	public const MAX_LENGTH = 255;

	/** Lowercase letters, digits, hyphen and underscore, starting with a letter or digit. */
	private const SLUG = '/\A[a-z0-9][a-z0-9_-]{0,63}\z/';

	/**
	 * Read what an administrator typed.
	 *
	 * `endpoints` is what would be stored, in the order given, without
	 * duplicates. `rejected` names every entry that is not a lowercase slug or
	 * is on the hard-deny list; `too_long` says the accepted list does not fit
	 * the column. A form save is refused unless both are empty.
	 *
	 * @return array{endpoints: list<string>, rejected: list<string>, too_long: bool}
	 */
	public static function parse( string $raw ): array {
		$endpoints = [];
		$rejected  = [];

		foreach ( preg_split( '/[,\r\n]+/', $raw ) ?: [] as $entry ) {
			$entry = trim( $entry );

			if ( '' === $entry ) {
				continue;
			}

			if ( 1 !== preg_match( self::SLUG, $entry ) || in_array( $entry, self::HARD_DENY, true ) ) {
				$rejected[] = $entry;
				continue;
			}

			if ( ! in_array( $entry, $endpoints, true ) ) {
				$endpoints[] = $entry;
			}
		}

		return [
			'endpoints' => $endpoints,
			'rejected'  => array_values( array_unique( $rejected ) ),
			'too_long'  => strlen( implode( ',', $endpoints ) ) > self::MAX_LENGTH,
		];
	}

	/**
	 * The stored form: accepted entries only, comma-joined, cut at the last
	 * whole entry that fits the column. Used on every write and every read,
	 * so a hand-edited row can hold nothing the form would refuse.
	 */
	public static function normalise( string $raw ): string {
		$stored = '';

		foreach ( self::parse( $raw )['endpoints'] as $endpoint ) {
			$next = '' === $stored ? $endpoint : $stored . ',' . $endpoint;

			if ( strlen( $next ) > self::MAX_LENGTH ) {
				break;
			}

			$stored = $next;
		}

		return $stored;
	}

	/**
	 * The column as a list, after the same normalisation as a write.
	 *
	 * @return list<string>
	 */
	public static function listed( string $stored ): array {
		$normalised = self::normalise( $stored );

		return '' === $normalised ? [] : explode( ',', $normalised );
	}

	/**
	 * Whether a visit of a connection allowing $allowed may open $endpoint.
	 *
	 * @param list<string> $allowed The connection's list.
	 */
	public static function allows( array $allowed, string $endpoint ): bool {
		return ! in_array( $endpoint, self::HARD_DENY, true ) && in_array( $endpoint, $allowed, true );
	}
}
