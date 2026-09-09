<?php
/**
 * Canonical native WooCommerce shipping address validation, without persistence.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Addresses;

use WC_Validation;

defined( 'ABSPATH' ) || exit;

final class Shape {

	/**
	 * Stable fingerprint/setter order. Postal fields have native TEXT storage; these shorter application bounds keep snapshots bounded. Phone also fits Woo's HPOS varchar(100). Character bounds allow up to four UTF-8 bytes per character.
	 */
	private const LIMITS = [
		'first_name' => 190,
		'last_name'  => 190,
		'company'    => 190,
		'address_1'  => 190,
		'address_2'  => 190,
		'city'       => 190,
		'state'      => 190,
		'postcode'   => 32,
		'country'    => 2,
		'phone'      => 100,
	];

	/**
	 * Accept an unslashed address map with unprefixed native shipping keys only. The HTTP boundary owns wp_unslash; doing it again here would corrupt literal backslashes. Book label/code/use_for_punchout must be separated by the caller.
	 *
	 * Missing optional fields become empty strings so applying the complete result clears old buyer values. Required flags and stricter maxlength values come from Woo's filtered country fields. Country must always identify a real, currently allowed shipping destination. No customer/session/order is loaded or saved here.
	 *
	 * @return array|\WP_Error Ten canonical string fields or a bounded, translatable refusal.
	 */
	public static function normalise( array $input ): array|\WP_Error {
		if ( array_diff_key( $input, self::LIMITS ) ) {
			return self::invalid();
		}

		try {
			$address = [];
			foreach ( self::LIMITS as $key => $limit ) {
				$value = array_key_exists( $key, $input ) ? $input[ $key ] : '';
				if ( ! is_scalar( $value ) || ( is_float( $value ) && ! is_finite( $value ) ) ) {
					return self::invalid();
				}
				$value = (string) $value;
				// Bound work before sanitization; never let malformed UTF-8 or controls disappear into a valid optional blank.
				if ( strlen( $value ) > 4096 || 1 !== preg_match( '//u', $value ) || preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value ) ) {
					return self::invalid();
				}
				$address[ $key ] = sanitize_text_field( $value );
				if ( ! self::bounded( $address[ $key ], $limit ) ) {
					return self::invalid();
				}
			}

			$address['country'] = wc_strtoupper( $address['country'] );
			$country            = $address['country'];
			$countries          = WC()->countries;
			if ( 1 !== preg_match( '/\A[A-Z]{2}\z/', $country ) || ! $countries->country_exists( $country ) || ! array_key_exists( $country, $countries->get_shipping_countries() ) ) {
				return self::invalid();
			}
			$fields = $countries->get_address_fields( $country, 'shipping_' );
			if ( ! is_array( $fields ) || [] === $fields ) {
				return self::unavailable();
			}
			foreach ( $fields as $key => $field ) {
				if ( ! is_string( $key ) || ! is_array( $field ) ) {
					return self::unavailable();
				}
				// A required extension field cannot be silently accepted by a native-only address contract.
				if ( ! empty( $field['required'] ) && ( ! str_starts_with( $key, 'shipping_' ) || ! array_key_exists( substr( $key, 9 ), self::LIMITS ) ) ) {
					return self::unavailable();
				}
			}

			if ( '' !== $address['state'] ) {
				$states = $countries->get_states( $country );
				if ( false !== $states && ! is_array( $states ) ) {
					return self::unavailable();
				}
				if ( is_array( $states ) && [] !== $states ) {
					// Match Woo checkout: accept a listed state code or name, compare using Woo's extension-optional uppercase helper, then retain the canonical code.
					$state = wc_strtoupper( $address['state'] );
					$found = false;
					foreach ( $states as $code => $name ) {
						if ( $state === wc_strtoupper( (string) $code ) || $state === wc_strtoupper( $name ) ) {
							$address['state'] = (string) $code;
							$found            = true;
							break;
						}
					}
					if ( ! $found ) {
						return self::invalid();
					}
				}
			}

			// Formatting an absent optional postcode can introduce separators in some locales.
			if ( '' !== $address['postcode'] ) {
				$address['postcode'] = wc_format_postcode( $address['postcode'], $country );
				if ( ! self::bounded( $address['postcode'], self::LIMITS['postcode'] ) || ! WC_Validation::is_postcode( $address['postcode'], $country ) ) {
					return self::invalid();
				}
			}
			if ( '' !== $address['phone'] && ! WC_Validation::is_phone( $address['phone'], $country ) ) {
				return self::invalid();
			}

			foreach ( self::LIMITS as $key => $limit ) {
				$field = $fields[ 'shipping_' . $key ] ?? [];
				if ( ! empty( $field['required'] ) && '' === $address[ $key ] ) {
					return self::invalid();
				}
				$maxlength = $field['maxlength'] ?? false;
				if ( ! is_scalar( $maxlength ) ) {
					return self::unavailable();
				}
				// wc_form_field defaults maxlength to false; a truthy explicit value overrides its custom attribute.
				if ( ! $maxlength ) {
					$maxlength = $field['custom_attributes']['maxlength'] ?? false;
				}
				if ( ! is_scalar( $maxlength ) ) {
					return self::unavailable();
				}
				if ( $maxlength ) {
					if ( 1 !== preg_match( '/\A[0-9]+\z/', (string) $maxlength ) || (int) $maxlength < 1 ) {
						return self::unavailable();
					}
					$limit = min( $limit, (int) $maxlength );
				}
				if ( ! self::bounded( $address[ $key ], $limit ) ) {
					return self::invalid();
				}
			}

			return $address;
		} catch ( \Throwable $error ) {
			// Native filters/dependencies may fail. Never expose their exception or posted content to callers.
			return self::unavailable();
		}
	}

	private static function bounded( mixed $value, int $limit ): bool {
		return is_string( $value ) && strlen( $value ) <= 4 * $limit && 1 === preg_match( '//u', $value ) && 0 === preg_match( '/[\x00-\x1f\x7f]/', $value ) && preg_match_all( '/./us', $value ) <= $limit;
	}

	private static function invalid(): \WP_Error {
		return new \WP_Error( 'address_invalid', __( 'Please supply a valid delivery address within the allowed field lengths.', 'punchout-woocommerce' ) );
	}

	private static function unavailable(): \WP_Error {
		return new \WP_Error( 'address_validation_unavailable', __( 'Delivery address validation is unavailable. Please try again.', 'punchout-woocommerce' ) );
	}
}
