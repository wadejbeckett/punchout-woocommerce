<?php
/**
 * A connection's "Order details sent" (schema 10, 0.5.0).
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Partners;

defined( 'ABSPATH' ) || exit;

/**
 * The order details a connection adds to DeliveryInstructions after the buyer's note: each entry is an order meta
 * key, the label sent before its value ("Label: value") and a format, `text` or `unix_date` (a Unix timestamp sent as
 * its UTC calendar day). Stored in `partners.order_details` as canonical JSON, '' for none.
 *
 * normalise() is the floor under every writer and reader: a malformed, hand-edited or filtered list keeps only its
 * well-formed entries, at most MAX_ENTRIES, one per meta key, none of the plugin's own keys (RESERVED_PREFIX).
 * parse_lines() and lines() are the connection form's text: one entry per line, "meta key | label | format".
 */
final class OrderDetails {

	public const FORMAT_TEXT      = 'text';
	public const FORMAT_UNIX_DATE = 'unix_date';
	public const FORMATS          = [ self::FORMAT_TEXT, self::FORMAT_UNIX_DATE ];

	public const MAX_ENTRIES   = 10;
	public const KEY_LIMIT     = 190;
	public const LABEL_LIMIT   = 100;
	private const SEPARATOR    = '|';

	/**
	 * The plugin's own order keys (the cXML copy, the visit, the connection, the buyer's identity and cookie) are
	 * never order details: a key beginning with this prefix, in any case, is refused wherever a list is written or
	 * read (security review, 11 October).
	 */
	public const RESERVED_PREFIX = '_pow_';

	/**
	 * The well-formed entries of $value (a stored JSON string or a list), in order.
	 *
	 * @return list<array{meta_key: string, label: string, format: string}>
	 */
	public static function normalise( mixed $value ): array {
		if ( is_string( $value ) ) {
			$value = '' === trim( $value ) ? [] : json_decode( $value, true );
		}
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) { return []; }

		$entries = [];
		$keys    = [];
		foreach ( $value as $raw ) {
			$entry = self::entry( $raw );
			if ( null === $entry || isset( $keys[ $entry['meta_key'] ] ) ) { continue; }
			$keys[ $entry['meta_key'] ] = true;
			$entries[] = $entry;
			if ( count( $entries ) >= self::MAX_ENTRIES ) { break; }
		}
		return $entries;
	}

	/** The stored form: canonical JSON of the normalised list, '' for none. */
	public static function encode( mixed $value ): string {
		$entries = self::normalise( $value );
		if ( [] === $entries ) { return ''; }
		$json = json_encode( $entries, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : '';
	}

	/**
	 * The connection form's text: one entry per line, "meta key | label | format". The format may be left out (text)
	 * and so may the label (the key). Blank lines are ignored. A line with more than three parts, an empty or unusable
	 * key, an unknown format or a key already listed is refused and returned as typed; more than MAX_ENTRIES entries
	 * set too_many.
	 *
	 * @return array{entries: list<array{meta_key: string, label: string, format: string}>, rejected: list<string>, too_many: bool}
	 */
	public static function parse_lines( string $text ): array {
		$entries  = [];
		$rejected = [];
		$keys     = [];
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) ?: [] as $line ) {
			$line = trim( $line );
			if ( '' === $line ) { continue; }
			$parts = array_map( 'trim', explode( self::SEPARATOR, $line ) );
			if ( count( $parts ) > 3 ) { $rejected[] = $line; continue; }
			$format = $parts[2] ?? self::FORMAT_TEXT;
			$entry  = in_array( $format, self::FORMATS, true ) ? self::entry( [ 'meta_key' => $parts[0], 'label' => $parts[1] ?? '', 'format' => $format ] ) : null;
			if ( null === $entry || isset( $keys[ $entry['meta_key'] ] ) ) { $rejected[] = $line; continue; }
			$keys[ $entry['meta_key'] ] = true;
			$entries[] = $entry;
		}
		return [ 'entries' => $entries, 'rejected' => $rejected, 'too_many' => count( $entries ) > self::MAX_ENTRIES ];
	}

	/** The connection form's text for a list, the inverse of parse_lines(). */
	public static function lines( mixed $value ): string {
		return implode( "\n", array_map( static fn( array $entry ): string => implode( ' ' . self::SEPARATOR . ' ', [ $entry['meta_key'], $entry['label'], $entry['format'] ] ), self::normalise( $value ) ) );
	}

	/**
	 * One well-formed entry, or null. The key is kept as typed (trimmed): it is only ever read through the order's
	 * own meta API, never placed in SQL or markup. The label is plain text; an empty one reads as the key.
	 *
	 * @return array{meta_key: string, label: string, format: string}|null
	 */
	private static function entry( mixed $raw ): ?array {
		if ( ! is_array( $raw ) || ! is_string( $raw['meta_key'] ?? null ) || ! is_string( $raw['format'] ?? null ) ) { return null; }
		$key = trim( $raw['meta_key'] );
		if ( '' === $key || mb_strlen( $key ) > self::KEY_LIMIT || str_contains( $key, self::SEPARATOR ) || 1 !== preg_match( '/\A[^\x00-\x1F\x7F]+\z/u', $key ) || self::reserved( $key ) ) { return null; }
		if ( ! in_array( $raw['format'], self::FORMATS, true ) ) { return null; }
		$label = is_string( $raw['label'] ?? null ) ? self::label( $raw['label'] ) : null;
		if ( null === $label ) { return null; }
		return [ 'meta_key' => $key, 'label' => '' !== $label ? $label : $key, 'format' => $raw['format'] ];
	}

	/** Whether a key is one of the plugin's own (RESERVED_PREFIX, in any case). */
	public static function reserved( string $key ): bool {
		return str_starts_with( strtolower( $key ), self::RESERVED_PREFIX );
	}

	/** A label as plain text on one line: tags and control characters removed, spaces collapsed, at most LABEL_LIMIT. */
	private static function label( string $label ): string {
		$clean = (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', wp_strip_all_tags( $label ) );
		$clean = trim( (string) preg_replace( '/[\s\p{Z}]+/u', ' ', $clean ) );
		return str_replace( self::SEPARATOR, '/', mb_substr( $clean, 0, self::LABEL_LIMIT ) );
	}
}
