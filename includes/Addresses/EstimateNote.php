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

	/** The buyer's notes with the estimate note first; either part may be blank. */
	public static function compose( string $note, string $buyer_notes ): string {
		$note = trim( $note ); $buyer_notes = trim( $buyer_notes );
		if ( '' === $note ) { return $buyer_notes; }
		return '' === $buyer_notes ? $note : $note . "\n" . $buyer_notes;
	}

	/** The freight line's description with the note appended, within the wire limit. */
	public static function line_description( string $label, string $note, int $limit = 250 ): string {
		$note = trim( $note );
		if ( '' === $note ) { return $label; }
		$joined = $label . ' — ' . $note;
		return mb_strlen( $joined ) <= $limit ? $joined : mb_substr( $joined, 0, $limit - 1 ) . '…';
	}
}
