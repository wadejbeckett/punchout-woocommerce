<?php
/**
 * The delivery-estimate note (0.4.19): one sentence that travels with every delivery estimate — on the review page,
 * in the returned cart (the freight line's description and the DeliveryInstructions extrinsic) and in the
 * "PunchOut order received" e-mail — so the buyer's approvers see that the charge is an estimate.
 *
 * @package POW
 */

declare( strict_types = 1 );

namespace POW\Addresses;

use POW\Settings;

defined( 'ABSPATH' ) || exit;

final class EstimateNote {
	public const SETTING = 'delivery_estimate_note';
	public const FILTER  = 'punchout_delivery_estimate_note';

	/** The site's sentence: the saved setting, else the translated default; blank after the filter means "no note". */
	public static function text( ?Settings $settings = null ): string {
		$default = __( 'The delivery charge is an estimate; the supplier confirms the final amount before dispatch.', 'punchout-woocommerce' );
		$saved   = null !== $settings ? $settings->get( self::SETTING, '' ) : '';
		$note    = Settings::resolve_label( $saved, $default );
		if ( function_exists( 'apply_filters' ) ) { $note = (string) apply_filters( self::FILTER, $note ); }
		return trim( $note );
	}

	/**
	 * The DeliveryInstructions text: the lead (the "Deliver to" line), then the estimate note, then the buyer's notes,
	 * within $limit characters (the builder refuses longer text). Only the buyer's notes are ever shortened.
	 */
	public static function compose( string $note, string $buyer_notes, string $lead = '', int $limit = 2000 ): string {
		$head = implode( "\n", array_values( array_filter( [ trim( $lead ), trim( $note ) ], static fn( string $part ): bool => '' !== $part ) ) );
		$buyer_notes = trim( $buyer_notes );
		if ( '' === $buyer_notes ) { return self::cut( $head, $limit ); }
		if ( '' === $head ) { return self::cut( $buyer_notes, $limit ); }
		$room = $limit - mb_strlen( $head ) - 1;
		return $room < 1 ? self::cut( $head, $limit ) : $head . "\n" . self::cut( $buyer_notes, $room );
	}

	/** "Deliver to: …" on one line from a review destination; blank when there is no address. */
	public static function deliver_to( mixed $destination ): string {
		if ( ! is_array( $destination ) || ! is_array( $destination['address'] ?? null ) ) { return ''; }
		$lines = array_values( array_filter( array_map( static fn( $line ): string => trim( (string) $line ), ReviewFormat::address_lines( $destination['address'] ) ), static fn( string $line ): bool => '' !== $line ) );
		if ( [] === $lines ) { return ''; }
		return ( function_exists( '__' ) ? __( 'Deliver to:', 'punchout-woocommerce' ) : 'Deliver to:' ) . ' ' . implode( ', ', $lines );
	}

	private static function cut( string $text, int $limit ): string {
		return mb_strlen( $text ) <= $limit ? $text : mb_substr( $text, 0, max( 0, $limit - 1 ) ) . '…';
	}

	/** The freight line's description with the note appended, within the wire limit. */
	public static function line_description( string $label, string $note, int $limit = 250 ): string {
		$note = trim( $note );
		if ( '' === $note ) { return $label; }
		$joined = $label . ' — ' . $note;
		return mb_strlen( $joined ) <= $limit ? $joined : mb_substr( $joined, 0, $limit - 1 ) . '…';
	}
}
