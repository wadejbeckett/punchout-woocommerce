<?php
/**
 * Immutable destination mapping and unconfirmed native address candidates.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */
declare( strict_types = 1 );

namespace POW\Addresses;

use POW\Orders\QuoteOrder;
use POW\Partners\Partner;
use POW\Sessions\Session;

defined( 'ABSPATH' ) || exit;

final class QuoteAddress {

	/** Snapshot bounds match Shape, without calling mutable Woo validation on accepted values. */
	private const ADDRESS_LIMITS = [ 'first_name' => 190, 'last_name' => 190, 'company' => 190, 'address_1' => 190, 'address_2' => 190, 'city' => 190, 'state' => 190, 'postcode' => 32, 'country' => 2, 'phone' => 100 ];
	/** 'filter' stays decodable for snapshots taken while the removed extension filter still produced candidates; nothing produces it now. */
	private const SOURCES = [ 'company_book', 'filter', 'ship_to', 'customer' ];

	public function __construct( private Resolver $resolver ) {}

	/**
	 * Project a full choice or prepared destination onto the local Quote contract. Null alone is absence; malformed selected values require reselection. Keep the complete choice/confirmation separately in the mapped return data.
	 *
	 * @return array{address: array<string,string>, code: string, source: string}|null
	 */
	public static function payload( ?array $entry ): ?array {
		if ( null === $entry ) { return null; }
		$address = self::address( $entry );
		if ( ! self::text( $entry['code'] ?? null, 32 ) || ! in_array( $entry['source'] ?? null, self::SOURCES, true ) ) { throw self::invalid(); }
		return [ 'address' => $address, 'code' => $entry['code'], 'source' => $entry['source'] ];
	}

	/** Postal wire shape only. Code emission is separate; the phone stays in the local ten-field snapshot. Builder owns escaping and exact dialect support. */
	public static function to_cxml( array $entry ): array {
		$address = self::address( $entry );
		$person = trim( $address['first_name'] . ' ' . $address['last_name'] );
		$name = '' !== $address['company'] ? $address['company'] : $person;
		$streets = array_values( array_filter( [ $address['address_1'], $address['address_2'] ], static fn( string $line ): bool => '' !== $line ) );
		if ( '' === trim( $name ) || [] === $streets ) { throw self::invalid(); }
		foreach ( $streets as $street ) { if ( '' === trim( $street ) ) { throw self::invalid(); } }
		return [
			'name' => $name,
			'deliver_to' => '' !== $person ? [ $person ] : [],
			'street' => $streets,
			'city' => $address['city'],
			'state' => $address['state'],
			'postal_code' => $address['postcode'],
			'iso_country' => $address['country'],
		];
	}

	/**
	 * Accepted snapshot first, otherwise inbound or company-profile candidates for explicit confirmation. This method never persists or confirms a choice, reads the company master, or grants current eligibility. Confirmation must call Resolver's active guard under the partner mutex immediately before the return claim. The winning Quote consumes payload() directly, never this resolver again.
	 */
	public function resolve_destination( Session $session, Partner $partner ): ?array {
		try {
			// Under one shared login every open visit of this connection has the same user_id, so the row — not the signed-in user — has to be this request's visit.
			if ( $session->partner_id !== $partner->id || $session->user_id <= 0 || ! $this->resolver->is_request_visit( $session ) ) { throw self::invalid(); }
			$selected = $this->resolver->selected_snapshot( $session );
			if ( null !== $selected ) {
				if ( $selected['storage_user_id'] !== $partner->owner_user_id ) { throw self::invalid(); }
				return self::payload( $selected );
			}
			$associated = $this->resolver->partner_for_user( $session->user_id );
			if ( ! $associated || $associated->id !== $partner->id || $associated->owner_user_id !== $partner->owner_user_id ) { throw self::invalid(); }

			$candidate = self::candidate( null !== $session->ship_to ? QuoteOrder::address_from_ship_to( $session->ship_to ) : null, 'ship_to' );
			if ( null !== $candidate ) { return $candidate; }

			// The last candidate is the connection account's own profile address overlaid by this visit's session snapshot, so on a fresh visit it is the company's default destination, not one employee's. It is a suggestion the delivery review still has to accept.
			$customer = WC()->customer;
			if ( null === $customer || (int) $customer->get_id() !== $session->user_id ) { return null; }
			return self::candidate( [ 'address' => $customer->get_shipping(), 'code' => '' ], 'customer' );
		} catch ( \Throwable $error ) {
			// A broken stored selection or association must never unlock fallback, nor expose extension exception text.
			throw self::invalid();
		}
	}

	/** Only new candidates use current country validation. Invalid/empty candidates are absent, not repaired snapshots. */
	private static function candidate( mixed $candidate, string $source ): ?array {
		if ( ! is_array( $candidate ) || ! isset( $candidate['address'] ) || ! is_array( $candidate['address'] ) || [] === $candidate['address'] ) { return null; }
		$address = Shape::normalise( $candidate['address'] );
		if ( $address instanceof \WP_Error ) { return null; }
		// An unusable optional identifier cannot discard a valid full postal destination.
		$code = $candidate['code'] ?? '';
		if ( ! self::text( $code, 32 ) ) { $code = ''; }
		try { return self::payload( [ 'address' => $address, 'code' => $code, 'source' => $source ] ); }
		catch ( \DomainException $error ) { return null; }
	}

	/** Structural validation only: never sanitize, fill missing fields or consult today's country configuration. */
	private static function address( array $entry ): array {
		$address = $entry['address'] ?? null;
		if ( ! is_array( $address ) || array_diff_key( $address, self::ADDRESS_LIMITS ) || array_diff_key( self::ADDRESS_LIMITS, $address ) ) { throw self::invalid(); }
		foreach ( self::ADDRESS_LIMITS as $field => $limit ) {
			if ( ! self::text( $address[$field], $limit ) ) { throw self::invalid(); }
		}
		if ( 1 !== preg_match( '/\A[A-Z]{2}\z/', $address['country'] ) ) { throw self::invalid(); }
		return $address;
	}

	private static function text( mixed $value, int $limit ): bool {
		return is_string( $value ) && strlen( $value ) <= 4 * $limit && 1 === preg_match( '//u', $value ) && 0 === preg_match( '/[\x00-\x1f\x7f\x{FFFE}\x{FFFF}]/u', $value ) && preg_match_all( '/./us', $value ) <= $limit;
	}

	private static function invalid(): \DomainException {
		return new \DomainException( __( 'The selected delivery address must be selected again.', 'punchout-woocommerce' ) );
	}
}
