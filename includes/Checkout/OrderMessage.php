<?php
/**
 * Placed order -> PunchOutOrderMessage builder input (0.5.0).
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Checkout;

use POW\Cart\PoomMapper;
use POW\Cxml\Builder;
use POW\Cxml\Money;
use POW\Partners\OrderDetails;
use POW\Partners\Partner;
use POW\Sessions\Session;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the unchanged builder's input (Cxml\Builder::poom()) from the order WooCommerce checkout placed, so what
 * the buyer's purchasing system receives is the order the store keeps.
 *
 * - Lines: the product's SKU, the product and variation ids as the auxiliary id, the order's quantity, and the
 *   line total after discounts and before tax divided by the quantity as the unit price (the same rule as the
 *   cart mapper). Total is the sum of what the lines say. A line whose product has no SKU is left out and named.
 * - Text: the product's name, completed with the variation values the buyer chose, is the ShortName. The
 *   Description is that name too, or, when the connection's line description is `name_short_description`, the name,
 *   " - " and the product's short description (a variation's parent's) as plain text: tags stripped, entities
 *   decoded, characters cXML cannot carry removed, whitespace collapsed; left out when empty or only the name again,
 *   and shortened (never the name) so the whole fits DESCRIPTION_LIMIT, which the filter
 *   `punchout_line_description_limit` can lower. Capitals when the connection asks for them. UOM EA; the
 *   classification the caller passes.
 * - ShipTo: the order's shipping address, while the connection sends ShipTo and the address is usable.
 * - DeliveryInstructions: the buyer's order note, cleaned of characters cXML cannot carry, then each of the
 *   connection's "Order details sent" (Partners\OrderDetails; the filter `punchout_order_details_sent` can extend
 *   the list) that has a value, as "Label: value": text as plain text on one line (at most DETAIL_LIMIT), a Unix
 *   timestamp as its UTC calendar day (gmdate, Y-m-d), whatever the server's timezone. The parts are joined by
 *   " | " (the builder flattens line breaks), at most NOTE_LIMIT in all; only the note is shortened.
 * - Freight: only while the connection sends delivery cost (`emit_delivery_line`), the order has a shipping line and
 *   its shipping total before tax is more than zero: one quantity-one freight line, that total, named after its
 *   shipping method(s) with the estimate sentence (estimate_sentence(): a fixed translatable sentence, filter
 *   `punchout_delivery_estimate_note`, blank sends none), which then also leads DeliveryInstructions. Otherwise (off,
 *   no shipping line, or free shipping: no 0.00 line and no estimate sentence) the order's
 *   shipping line stays the store's estimate and never reaches the message, and the Total is the products'. The
 *   plugin adds no delivery wording of its own anywhere a buyer reads.
 * - Envelope: From and To reversed from the setup, Sender identity only (no shared secret exists here), the visit's
 *   BuyerCookie and deployment mode, the connection's dialect, "create" only, no SupplierOrderInfo.
 */
final class OrderMessage {

	/** Characters the builder refuses in text (everything outside the XML 1.0 character range). */
	private const NOT_XML = '/[^\x{09}\x{0A}\x{0D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u';

	/** The builder's own limit for DeliveryInstructions. */
	public const NOTE_LIMIT = 2000;

	/** The longest line Description sent with the short description, in characters (a filter can lower it). */
	public const DESCRIPTION_LIMIT        = 2000;
	public const DESCRIPTION_LIMIT_FILTER = 'punchout_line_description_limit';
	private const DESCRIPTION_SEPARATOR   = ' - ';

	/** The longest value one order detail sends, in characters. */
	public const DETAIL_LIMIT = 500;

	/**
	 * Between the parts of DeliveryInstructions. The builder flattens line breaks in element text to spaces (Dynamics
	 * breaks on newlines), so the parts are joined with a separator that survives onto the wire.
	 */
	public const INSTRUCTIONS_SEPARATOR = ' | ';

	/** Extends or changes a connection's "Order details sent" for one order ( $entries, $order, $partner ). */
	public const ORDER_DETAILS_FILTER = 'punchout_order_details_sent';

	/** Closing tags and line breaks that separate words once the tags are gone. */
	private const WORD_BREAK_TAGS = '#<(?:br|/p|/div|/li|/h[1-6]|/tr|/td|/th|/dt|/dd)\b[^>]*>#i';

	/** The fields of an address the cXML ShipTo mapping reads, in its order. */
	private const ADDRESS_FIELDS = [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' ];

	/** Bounds of a ShipTo field as the builder takes it (the same as the old address snapshot's). */
	private const ADDRESS_LIMITS = [ 'first_name' => 190, 'last_name' => 190, 'company' => 190, 'address_1' => 190, 'address_2' => 190, 'city' => 190, 'state' => 190, 'postcode' => 32, 'country' => 2, 'phone' => 100 ];

	/** Filter over the estimate sentence sent with a freight line; a blank result sends no sentence. */
	public const ESTIMATE_FILTER = 'punchout_delivery_estimate_note';

	/**
	 * The sentence that travels with a freight line, so the buyer's purchasing system sees that the charge is an
	 * estimate. Sent only while the connection sends delivery cost; never shown to the buyer by this plugin. The
	 * filter runs last and a blank result sends none. (Until 0.4.23 this was the "Delivery estimate note" setting.)
	 */
	public static function estimate_sentence(): string {
		$sentence = __( 'The delivery charge is an estimate; the supplier confirms the final amount before dispatch.', 'punchout-woocommerce' );
		if ( function_exists( 'apply_filters' ) ) { $sentence = (string) apply_filters( self::ESTIMATE_FILTER, $sentence ); }
		return trim( $sentence );
	}

	/**
	 * [ builder input, names of the lines left out for want of a SKU ].
	 *
	 * @param string|null $estimate_note The freight line's sentence; null = estimate_sentence().
	 * @return array{0: array<string, mixed>, 1: list<string>}
	 */
	public static function builder_args( \WC_Order $order, Partner $partner, Session $visit, string $classification, string $host, ?string $estimate_note = null ): array {
		[ $items, $total_cents, $skipped ] = self::lines( $order, $partner, $classification );

		$estimate_note ??= self::estimate_sentence();
		$freight = $partner->emit_delivery_line ? self::freight( $order, $partner, $estimate_note ) : null;
		if ( null !== $freight ) {
			$items[]      = $freight;
			$total_cents += $freight['unit_price_cents'];
		}

		return [
			[
				'version'                      => $partner->cxml_version,
				'payload_id'                   => Builder::payload_id( '' !== $host ? $host : 'localhost' ),
				'timestamp'                    => Builder::timestamp(),
				'deployment_mode'              => $visit->deployment_mode,
				// We originate this document: From and To are the setup's reversed, and Sender is identity only.
				'from'                         => [ 'domain' => $partner->to_domain, 'identity' => $partner->to_identity ],
				'to'                           => [ 'domain' => $partner->from_domain, 'identity' => $partner->from_identity ],
				'sender'                       => [ 'domain' => $partner->to_domain, 'identity' => $partner->to_identity ],
				'user_agent'                   => 'PunchOut for WooCommerce/' . ( defined( 'POW\\VERSION' ) ? \POW\VERSION : '' ),
				'buyer_cookie'                 => $visit->buyer_cookie,
				'operation_allowed'            => 'create',
				'currency'                     => (string) $order->get_currency(),
				'total_cents'                  => $total_cents,
				'supplier_order_info'          => null,
				'items'                        => $items,
				'ship_to'                      => self::ship_to( $order ),
				'emit_ship_to'                 => $partner->emit_ship_to,
				'emit_delivery_code'           => false,
				'delivery_code'                => '',
				'delivery_code_extrinsic_name' => $partner->delivery_code_extrinsic_name,
				'delivery_notes'               => self::instructions( $order, $partner, null !== $freight ? $estimate_note : '' ),
				'delivery_notes_policy'        => $partner->delivery_notes_policy,
			],
			$skipped,
		];
	}

	/**
	 * The order's product lines as builder items.
	 *
	 * @return array{0: list<array<string, mixed>>, 1: int, 2: list<string>} items, total in cents, skipped names
	 */
	private static function lines( \WC_Order $order, Partner $partner, string $classification ): array {
		$items   = [];
		$skipped = [];
		$total   = 0;

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product  = $item->get_product();
			$quantity = (float) $item->get_quantity();
			if ( $quantity <= 0 ) { continue; }

			$sku = $product ? (string) $product->get_sku() : '';
			if ( '' === $sku ) {
				$skipped[] = $product ? $product->get_name() : (string) $item->get_name();
				continue;
			}

			// One conversion through the Money boundary per line; the unit price is derived from it, and the Total
			// adds up what the lines say (100.00 over 3 is 33.33 each and 99.99 in all).
			$line_cents = Money::to_cents( (float) $item->get_total() );
			$unit_cents = (int) round( $line_cents / $quantity );
			$total     += (int) round( $unit_cents * $quantity );

			// Names and attribute terms are stored HTML-escaped ("&amp;"); the DOM escapes once more, so decode first.
			$name = trim( (string) preg_replace( self::NOT_XML, '', html_entity_decode( PoomMapper::line_name( $product->get_name(), self::variation( $item, $product ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );

			$items[] = [
				'quantity'         => $quantity,
				'supplier_part_id' => $sku,
				'aux_id'           => (int) $item->get_product_id() . '|' . (int) $item->get_variation_id(),
				'unit_price_cents' => $unit_cents,
				'description'      => self::description( $name, $product, $partner->line_description ),
				'short_name'       => $name,
				'uom'              => 'EA',
				'classification'   => $classification,
			];
		}

		return [ $partner->allcaps_transform ? PoomMapper::uppercase_items( $items ) : $items, $total, $skipped ];
	}

	/** A line's Description under the connection's line description mode. */
	private static function description( string $name, object $product, string $mode ): string {
		if ( Partner::LINE_DESCRIPTION_NAME_AND_SHORT !== $mode ) { return $name; }

		$short = self::plain_text( self::short_description( $product ) );
		$same  = static fn( string $text ): bool => mb_strtolower( $short ) === mb_strtolower( self::plain_text( $text ) );
		if ( '' === $short || $same( $name ) || $same( (string) $product->get_name() ) ) { return $name; }

		$limit = self::description_limit();
		$full  = $name . self::DESCRIPTION_SEPARATOR . $short;
		if ( mb_strlen( $full ) <= $limit ) { return $full; }
		$room = $limit - mb_strlen( $name . self::DESCRIPTION_SEPARATOR );
		return $room < 2 ? $name : $name . self::DESCRIPTION_SEPARATOR . rtrim( mb_substr( $short, 0, $room - 1 ) ) . '…';
	}

	/** The product's short description; a variation, which has none of its own, reads its parent's. */
	private static function short_description( object $product ): string {
		$short = method_exists( $product, 'get_short_description' ) ? (string) $product->get_short_description() : '';
		if ( '' === trim( $short ) && method_exists( $product, 'get_parent_id' ) && (int) $product->get_parent_id() > 0 && function_exists( 'wc_get_product' ) ) {
			$parent = wc_get_product( (int) $product->get_parent_id() );
			$short  = is_object( $parent ) && method_exists( $parent, 'get_short_description' ) ? (string) $parent->get_short_description() : '';
		}
		return $short;
	}

	/** DESCRIPTION_LIMIT, or less when the filter asks for less. */
	private static function description_limit(): int {
		$limit = function_exists( 'apply_filters' ) ? apply_filters( self::DESCRIPTION_LIMIT_FILTER, self::DESCRIPTION_LIMIT ) : self::DESCRIPTION_LIMIT;
		return is_int( $limit ) && $limit > 0 ? min( $limit, self::DESCRIPTION_LIMIT ) : self::DESCRIPTION_LIMIT;
	}

	/**
	 * Text as cXML carries it on one line: tags stripped (a closing block tag or a line break still separates words),
	 * entities decoded, characters outside XML removed, every run of whitespace one space.
	 */
	public static function plain_text( string $text ): string {
		$text = wp_strip_all_tags( (string) preg_replace( self::WORD_BREAK_TAGS, '$0 ', $text ) );
		$text = (string) preg_replace( self::NOT_XML, '', html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		return trim( (string) preg_replace( '/[\s\p{Z}]+/u', ' ', $text ) );
	}

	/**
	 * The freight line for the order's shipping, or null when the order has no shipping line or nothing to charge
	 * (a shipping total of zero: free shipping sends no line). Named after the shipping method(s) as plain text,
	 * joined with "; ", with the estimate sentence; capitals with the connection.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function freight( \WC_Order $order, Partner $partner, string $estimate_note ): ?array {
		$methods = method_exists( $order, 'get_shipping_methods' ) ? (array) $order->get_shipping_methods() : [];
		if ( [] === $methods || Money::to_cents( (float) $order->get_shipping_total() ) <= 0 ) { return null; }
		$labels = [];
		foreach ( $methods as $method ) {
			$label = is_object( $method ) && method_exists( $method, 'get_name' ) ? self::plain_text( (string) $method->get_name() ) : '';
			if ( '' !== $label ) { $labels[] = $label; }
		}
		$line = [
			'line_type'             => 'freight',
			'quantity'              => 1,
			'supplier_part_id'      => $partner->freight_supplier_part_id,
			'aux_id'                => '',
			'unit_price_cents'      => Money::to_cents( (float) $order->get_shipping_total() ),
			'description'           => self::freight_description( [] !== $labels ? implode( '; ', $labels ) : __( 'Delivery', 'punchout-woocommerce' ), self::note( $estimate_note ) ),
			'uom'                   => $partner->freight_uom,
			'classification_domain' => $partner->freight_classification_domain,
			'classification'        => $partner->freight_classification,
		];
		return $partner->allcaps_transform ? PoomMapper::uppercase_items( [ $line ] )[0] : $line;
	}

	/** The freight line's description with the estimate sentence appended, within the wire limit. */
	private static function freight_description( string $label, string $note, int $limit = 250 ): string {
		$note = trim( $note );
		if ( '' === $note ) { return $label; }
		$joined = $label . ' — ' . $note;
		return mb_strlen( $joined ) <= $limit ? $joined : mb_substr( $joined, 0, $limit - 1 ) . '…';
	}

	/**
	 * DeliveryInstructions: the lead (the estimate sentence, only with a freight line), the buyer's note, then each
	 * order detail with a value as "Label: value", joined by INSTRUCTIONS_SEPARATOR, within NOTE_LIMIT. Only the note
	 * is shortened.
	 */
	private static function instructions( \WC_Order $order, Partner $partner, string $lead ): string {
		$details = [];
		foreach ( self::order_details( $order, $partner ) as $entry ) {
			$value = self::detail_value( $order->get_meta( $entry['meta_key'], true ), $entry['format'] );
			if ( '' !== $value ) { $details[] = $entry['label'] . ': ' . $value; }
		}
		$join = self::INSTRUCTIONS_SEPARATOR;
		$lead = self::note( $lead );
		$tail = implode( $join, $details );
		$note = self::note( (string) $order->get_customer_note() );

		// The room left for the note: the limit less the fixed parts and one separator for each of them (the
		// note sits between or beside them, so each fixed part costs exactly one separator).
		$fixed = array_values( array_filter( [ $lead, $tail ], static fn( string $part ): bool => '' !== $part ) );
		$room  = self::NOTE_LIMIT - mb_strlen( implode( $join, $fixed ) ) - count( $fixed ) * mb_strlen( $join );
		$note  = $room > 0 && mb_strlen( $note ) > $room ? rtrim( mb_substr( $note, 0, $room ) ) : ( $room > 0 ? $note : '' );

		$text = implode( $join, array_values( array_filter( [ $lead, $note, $tail ], static fn( string $part ): bool => '' !== $part ) ) );
		return mb_strlen( $text ) > self::NOTE_LIMIT ? rtrim( mb_substr( $text, 0, self::NOTE_LIMIT ) ) : $text;
	}

	/**
	 * The connection's "Order details sent" for this order, after the filter, well-formed only.
	 *
	 * @return list<array{meta_key: string, label: string, format: string}>
	 */
	private static function order_details( \WC_Order $order, Partner $partner ): array {
		$entries = $partner->order_detail_list();
		if ( function_exists( 'apply_filters' ) ) { $entries = apply_filters( self::ORDER_DETAILS_FILTER, $entries, $order, $partner ); }
		return OrderDetails::normalise( is_array( $entries ) ? array_values( $entries ) : [] );
	}

	/** One order detail's value as sent; '' when the order has no usable value for it. */
	private static function detail_value( mixed $raw, string $format ): string {
		if ( OrderDetails::FORMAT_UNIX_DATE === $format ) {
			// Whole seconds since 1970 only: a date plugin stores midnight UTC of the chosen day, and gmdate() reads
			// it back as that day at any server timezone (date() or wp_date() would give the day before west of UTC).
			$stamp = is_int( $raw ) ? $raw : ( is_string( $raw ) && 1 === preg_match( '/\A\d{1,12}\z/', trim( $raw ) ) ? (int) trim( $raw ) : 0 );
			return $stamp > 0 ? gmdate( 'Y-m-d', $stamp ) : '';
		}
		if ( ! is_string( $raw ) && ! is_int( $raw ) && ! is_float( $raw ) ) { return ''; }
		$text = self::plain_text( (string) $raw );
		return mb_strlen( $text ) > self::DETAIL_LIMIT ? rtrim( mb_substr( $text, 0, self::DETAIL_LIMIT - 1 ) ) . '…' : $text;
	}

	/**
	 * A variation line's attribute values in the cart's shape (attribute_* => value): the value the buyer chose,
	 * kept on the order line, or the variation's own.
	 *
	 * @return array<string, string>
	 */
	private static function variation( object $item, object $product ): array {
		if ( (int) $item->get_variation_id() <= 0 || ! method_exists( $product, 'get_variation_attributes' ) ) {
			return [];
		}
		$values = [];
		foreach ( (array) $product->get_variation_attributes() as $key => $value ) {
			$chosen         = $item->get_meta( str_replace( 'attribute_', '', (string) $key ) );
			$values[ $key ] = is_scalar( $chosen ) && '' !== (string) $chosen ? (string) $chosen : (string) $value;
		}
		return $values;
	}

	/**
	 * The ShipTo the builder takes, from the order's shipping address; null when the order has no usable one.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function ship_to( \WC_Order $order ): ?array {
		$stored  = (array) $order->get_address( 'shipping' );
		$address = [];
		foreach ( self::ADDRESS_FIELDS as $field ) {
			$value             = $stored[ $field ] ?? '';
			$address[ $field ] = is_scalar( $value ) ? trim( (string) $value ) : '';
		}
		if ( '' === $address['address_1'] . $address['address_2'] ) { return null; }
		return self::to_cxml( $address );
	}

	/**
	 * The postal wire shape of a ten-field address, or null when cXML cannot carry it: a name (the company, else
	 * the person) and at least one street line are needed, every field within its bounds, the country an ISO
	 * code. The phone stays local; the builder owns escaping and the exact dialect.
	 *
	 * @param array<string, string> $address The ten address keys.
	 * @return array<string, mixed>|null
	 */
	public static function to_cxml( array $address ): ?array {
		foreach ( self::ADDRESS_LIMITS as $field => $limit ) {
			$value = $address[ $field ] ?? null;
			if ( ! is_string( $value ) || 1 !== preg_match( '//u', $value ) || 1 === preg_match( '/[\x00-\x1f\x7f\x{FFFE}\x{FFFF}]/u', $value ) || preg_match_all( '/./us', $value ) > $limit ) { return null; }
		}
		if ( 1 !== preg_match( '/\A[A-Z]{2}\z/', $address['country'] ) ) { return null; }
		$person  = trim( $address['first_name'] . ' ' . $address['last_name'] );
		$name    = '' !== $address['company'] ? $address['company'] : $person;
		$streets = array_values( array_filter( [ $address['address_1'], $address['address_2'] ], static fn( string $line ): bool => '' !== trim( $line ) ) );
		if ( '' === trim( $name ) || [] === $streets ) { return null; }
		return [
			'name'        => $name,
			'deliver_to'  => '' !== $person ? [ $person ] : [],
			'street'      => $streets,
			'city'        => $address['city'],
			'state'       => $address['state'],
			'postal_code' => $address['postcode'],
			'iso_country' => $address['country'],
		];
	}

	/** The buyer's note as cXML can carry it: characters outside XML removed, at most 2,000 characters. */
	public static function note( string $note ): string {
		return self::clean_note( $note ) ?? '';
	}

	/**
	 * The buyer's note made valid for cXML (control and other non-XML characters removed, trimmed, capped at 2,000
	 * characters), or null when it cannot be (it is not UTF-8). Checkout refuses such a note before any order exists.
	 */
	public static function clean_note( string $note ): ?string {
		$clean = preg_replace( self::NOT_XML, '', $note );
		if ( ! is_string( $clean ) ) { return null; }
		$clean = trim( $clean );
		return mb_strlen( $clean ) > self::NOTE_LIMIT ? rtrim( mb_substr( $clean, 0, self::NOTE_LIMIT ) ) : $clean;
	}
}
