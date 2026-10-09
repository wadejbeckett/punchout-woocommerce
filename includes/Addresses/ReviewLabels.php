<?php
/** The review page's own words as settings (0.4.23). @package POW @license AGPL-3.0-or-later */
declare( strict_types = 1 );
namespace POW\Addresses;

use POW\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Three words on the review page are the site's to choose: the Submit button, the heading over the item lines and
 * the label of the total. Each is a setting with a neutral translated default ("Submit", "Items", "Total"), resolved
 * like the exit-control labels: a non-blank saved value wins, anything else falls back, and a filter runs last
 * (`punchout_review_submit_label`, `punchout_review_items_heading`, `punchout_review_total_label`). A blank filter
 * result keeps the default, because the page never draws an empty button or label.
 */
final class ReviewLabels {
	public const SUBMIT = 'review_submit_label';
	public const ITEMS  = 'review_items_heading';
	public const TOTAL  = 'review_total_label';

	/** Setting => its filter. */
	public const FILTERS = [
		self::SUBMIT => 'punchout_review_submit_label',
		self::ITEMS  => 'punchout_review_items_heading',
		self::TOTAL  => 'punchout_review_total_label',
	];

	/** The longest label kept from a setting. */
	public const MAX_LENGTH = 100;

	/** Template key => setting. */
	private const KEYS = [ 'submit' => self::SUBMIT, 'items' => self::ITEMS, 'total' => self::TOTAL ];

	/**
	 * The translated defaults, by template key.
	 *
	 * @return array{submit: string, items: string, total: string}
	 */
	public static function defaults(): array {
		return [
			'submit' => __( 'Submit', 'punchout-woocommerce' ),
			'items'  => __( 'Items', 'punchout-woocommerce' ),
			'total'  => __( 'Total', 'punchout-woocommerce' ),
		];
	}

	/**
	 * The site's labels: each saved setting, else its default, then its filter.
	 *
	 * @return array{submit: string, items: string, total: string}
	 */
	public static function resolve( ?Settings $settings = null ): array {
		$labels = [];
		foreach ( self::defaults() as $key => $default ) {
			$label = Settings::resolve_label( null !== $settings ? $settings->get( self::KEYS[ $key ], '' ) : '', $default );
			if ( function_exists( 'apply_filters' ) ) { $label = apply_filters( self::FILTERS[ self::KEYS[ $key ] ], $label ); }
			$labels[ $key ] = Settings::resolve_label( $label, $default );
		}
		return $labels;
	}

	/**
	 * What a template draws: the labels it was handed, each blank or missing one completed from resolve().
	 *
	 * @return array{submit: string, items: string, total: string}
	 */
	public static function complete( mixed $labels ): array {
		$resolved = self::resolve();
		if ( ! is_array( $labels ) ) { return $resolved; }
		foreach ( $resolved as $key => $fallback ) { $resolved[ $key ] = Settings::resolve_label( $labels[ $key ] ?? '', $fallback ); }
		return $resolved;
	}

	/** A setting as saved: plain text, at most MAX_LENGTH characters ('' keeps the default). */
	public static function sanitise( mixed $raw ): string {
		$text = is_scalar( $raw ) ? (string) $raw : '';
		$text = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $text ) : trim( $text );
		return mb_substr( $text, 0, self::MAX_LENGTH );
	}
}
