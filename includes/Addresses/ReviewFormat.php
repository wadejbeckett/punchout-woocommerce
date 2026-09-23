<?php
/** Review-page presentation only: nothing here enters the review digest, the confirmation JSON or the cXML. @package POW @license AGPL-3.0-or-later */
declare( strict_types = 1 );
namespace POW\Addresses;

use POW\Cxml\Money;

defined( 'ABSPATH' ) || exit;

final class ReviewFormat {
	/** Store-formatted plain text (separators, symbol and position from the store settings), always two decimals so it matches the cXML cents. */
	public static function money( int $cents, string $currency ): string {
		try {
			$html = wc_price( $cents / 100, [ 'currency' => $currency, 'decimals' => 2 ] );
			$text = trim( html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			if ( '' !== $text ) { return $text; }
		} catch ( \Throwable ) {
			// Fall through to the cXML amount.
		}
		return $currency . ' ' . Money::format( $cents );
	}

	/** Display-only line total, rounded exactly as Confirmation::map sums merchandise_total_cents. */
	public static function line_total_cents( array $item ): int {
		return (int) round( $item['unit_price_cents'] * (float) $item['quantity'] );
	}

	/** @return list<string> The address in stored order, phone and empty values dropped, province and country codes shown as names where the store knows them. */
	public static function address_lines( array $address ): array {
		$countries = null;
		try { $countries = WC()->countries ?? null; } catch ( \Throwable ) { $countries = null; }
		$country = is_string( $address['country'] ?? null ) ? $address['country'] : '';
		$lines = [];
		foreach ( $address as $field => $value ) {
			if ( 'phone' === $field || ! is_string( $value ) || '' === $value ) { continue; }
			if ( 'state' === $field ) { $value = self::state_name( $countries, $country, $value ); }
			elseif ( 'country' === $field ) { $value = self::country_name( $countries, $value ); }
			$lines[] = $value;
		}
		return $lines;
	}

	/** The site's own custom logo markup, or '' when none is set. */
	public static function logo_html(): string {
		try {
			$logo = get_custom_logo();
			return is_string( $logo ) && '' !== $logo ? $logo : '';
		} catch ( \Throwable ) {
			return '';
		}
	}

	private static function state_name( mixed $countries, string $country, string $state ): string {
		try {
			if ( ! is_object( $countries ) || '' === $country ) { return $state; }
			$states = $countries->get_states( $country );
			$name = is_array( $states ) && is_string( $states[ $state ] ?? null ) ? self::plain( $states[ $state ] ) : '';
			return '' !== $name ? $name : $state;
		} catch ( \Throwable ) {
			return $state;
		}
	}

	private static function country_name( mixed $countries, string $country ): string {
		try {
			if ( ! is_object( $countries ) ) { return $country; }
			$names = $countries->get_countries();
			$name = is_array( $names ) && is_string( $names[ $country ] ?? null ) ? self::plain( $names[ $country ] ) : '';
			return '' !== $name ? $name : $country;
		} catch ( \Throwable ) {
			return $country;
		}
	}

	private static function plain( string $name ): string {
		return trim( html_entity_decode( wp_strip_all_tags( $name ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}
}
