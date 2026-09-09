<?php
/**
 * Address boundary tests with isolated native-service doubles, not native acceptance.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Tests\AddressShape {

	/** Only native bindings change; the production method bodies run unchanged. */
	function load_shape(): void {
		if ( class_exists( __NAMESPACE__ . '\\Shape', false ) ) {
			return;
		}
		$path = dirname( __DIR__, 2 ) . '/includes/Addresses/Shape.php';
		\PHPUnit\Framework\TestCase::assertTrue( is_file( $path ), 'Address Shape implementation must exist.' );
		$source = file_get_contents( $path );
		$source = str_replace( [ 'namespace POW\\Addresses;', 'use WC_Validation;' ], [ 'namespace POW\\Tests\\AddressShape;', 'use POW\\Tests\\AddressShape\\ValidationDouble as WC_Validation;' ], $source );
		eval( substr( $source, 5 ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- isolated dependency binding only.
	}

	final class Runtime {
		public static CountriesDouble $countries;
		public static bool $postcode_valid = true;
		public static bool $phone_valid = true;
		public static bool $unavailable = false;
		public static mixed $sanitize_result = null;
		public static mixed $postcode_result = null;
		public static array $calls = [];
		public static array $translations = [];
	}

	final class CountriesDouble {
		public array $countries = [ 'US' => 'United States', 'GB' => 'United Kingdom', 'AE' => 'United Arab Emirates', 'IE' => 'Ireland' ];
		public array $allowed = [ 'US' => 'United States', 'GB' => 'United Kingdom', 'AE' => 'United Arab Emirates', 'IE' => 'Ireland' ];
		public array $overrides = [];
		public array $removed = [];
		public mixed $states = [ 'US' => [ 'CA' => 'California', 'NY' => 'New York' ], 'GB' => false, 'AE' => [], 'IE' => [] ];

		public function country_exists( string $country ): bool { return isset( $this->countries[ $country ] ); }
		public function get_shipping_countries(): array { return $this->allowed; }
		public function get_states( string $country ): mixed { return $this->states[ $country ]; }
		public function get_address_fields( string $country, string $prefix = 'billing_' ): array {
			Runtime::$calls[] = [ 'fields', $country, $prefix ];
			$fields = [
				'shipping_first_name' => [ 'required' => true ],
				'shipping_last_name' => [ 'required' => true ],
				'shipping_company' => [ 'required' => false ],
				'shipping_country' => [ 'required' => true, 'type' => 'country' ],
				'shipping_address_1' => [ 'required' => true ],
				'shipping_address_2' => [ 'required' => false ],
				'shipping_city' => [ 'required' => true ],
				'shipping_state' => [ 'required' => 'US' === $country, 'validate' => [ 'state' ] ],
				'shipping_postcode' => [ 'required' => ! in_array( $country, [ 'AE', 'IE' ], true ), 'validate' => [ 'postcode' ], 'hidden' => 'AE' === $country ],
				'shipping_phone' => [ 'required' => false, 'validate' => [ 'phone' ] ],
			];
			foreach ( $this->overrides as $key => $definition ) {
				$fields[ $key ] = array_replace( $fields[ $key ] ?? [], $definition );
			}
			foreach ( $this->removed as $key ) {
				unset( $fields[ $key ] );
			}
			return $fields;
		}
	}

	function WC(): object {
		if ( Runtime::$unavailable ) {
			throw new \RuntimeException( 'PRIVATE dependency failure' );
		}
		return (object) [ 'countries' => Runtime::$countries ];
	}
	function __( string $message, string $domain ): string {
		Runtime::$translations[] = [ $message, $domain ];
		return $message;
	}
	function sanitize_text_field( string $value ): mixed {
		return Runtime::$sanitize_result ?? \sanitize_text_field( $value );
	}
	function wc_strtoupper( string $value ): string { return strtoupper( $value ); }
	function wc_format_postcode( string $value, string $country ): mixed {
		Runtime::$calls[] = [ 'format_postcode', $value, $country ];
		return Runtime::$postcode_result ?? ( 'GB' === $country && 'sw1a1aa' === $value ? 'SW1A 1AA' : strtoupper( $value ) );
	}
	final class ValidationDouble {
		public static function is_postcode( string $value, string $country ): bool {
			Runtime::$calls[] = [ 'postcode', $value, $country ];
			return Runtime::$postcode_valid;
		}
		public static function is_phone( string $value, ?string $country = null ): bool {
			Runtime::$calls[] = [ 'phone', $value, $country ];
			return Runtime::$phone_valid;
		}
	}
}

namespace {
	use PHPUnit\Framework\TestCase;
	use POW\Tests\AddressShape\{CountriesDouble, Runtime, Shape};

	final class AddressShapeTest extends TestCase {
		protected function setUp(): void {
			\POW\Tests\AddressShape\load_shape();
			Runtime::$countries = new CountriesDouble();
			Runtime::$postcode_valid = true;
			Runtime::$phone_valid = true;
			Runtime::$unavailable = false;
			Runtime::$sanitize_result = null;
			Runtime::$postcode_result = null;
			Runtime::$calls = [];
			Runtime::$translations = [];
		}

		private function address( array $changes = [] ): array {
			return array_replace( [ 'first_name' => 'Ada', 'last_name' => "O’Neil", 'address_1' => '12 Main Street', 'city' => 'Oakland', 'state' => 'CA', 'postcode' => '94612', 'country' => 'US' ], $changes );
		}

		private function refused( array $input ): \WP_Error {
			$result = Shape::normalise( $input );
			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertTrue( strlen( $result->get_error_message() ) < 256 );
			self::assertStringNotContainsString( 'PRIVATE', $result->get_error_message() );
			self::assertTrue( count( Runtime::$translations ) > 0, 'Errors must use translation.' );
			self::assertSame( 'punchout-woocommerce', end( Runtime::$translations )[1] );
			return $result;
		}

		public function test_canonical_complete_shape_clears_missing_optional_fields(): void {
			$input = $this->address();
			self::assertSame( [ 'first_name' => 'Ada', 'last_name' => 'O’Neil', 'company' => '', 'address_1' => '12 Main Street', 'address_2' => '', 'city' => 'Oakland', 'state' => 'CA', 'postcode' => '94612', 'country' => 'US', 'phone' => '' ], Shape::normalise( array_reverse( $input, true ) ) );
			self::assertSame( $input, $this->address(), 'Input must not mutate.' );
			self::assertContains( [ 'fields', 'US', 'shipping_' ], Runtime::$calls );
		}

		public function test_sanitizes_markup_whitespace_and_preserves_unslashed_unicode_punctuation(): void {
			$result = Shape::normalise( $this->address( [ 'first_name' => "  <b>Zoë</b>\t李 ", 'company' => 'A&B / "Depot"', 'address_1' => "12 O'Neil \\ Yard", 'address_2' => "Unit 2\nWest" ] ) );
			self::assertSame( 'Zoë 李', $result['first_name'] );
			self::assertSame( 'A&B / "Depot"', $result['company'] );
			self::assertSame( "12 O'Neil \\ Yard", $result['address_1'] );
			self::assertSame( 'Unit 2 West', $result['address_2'] );
		}

		public function test_scalar_numbers_are_sanitized_as_text(): void {
			$result = Shape::normalise( $this->address( [ 'address_1' => 12, 'address_2' => 0, 'postcode' => 94612 ] ) );
			self::assertSame( '12', $result['address_1'] );
			self::assertSame( '0', $result['address_2'] );
			self::assertSame( '94612', $result['postcode'] );
		}

		public function test_rejects_nested_null_object_and_resource_fields_without_casting(): void {
			$resource = fopen( 'php://memory', 'r' );
			try {
				foreach ( [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' ] as $field ) {
					foreach ( [ [], [ 'PRIVATE' ], null, new \stdClass(), $resource, INF, NAN ] as $bad ) {
						$this->refused( $this->address( [ $field => $bad ] ) );
					}
				}
			} finally {
				fclose( $resource );
			}
		}

		public function test_rejects_unknown_fields_book_metadata_and_prefixed_input(): void {
			foreach ( [ 'label', 'code', 'use_for_punchout', 'shipping_country', 'billing_country', 'email', 'PRIVATE', 0 ] as $field ) {
				$this->refused( $this->address( [ $field => 'PRIVATE' ] ) );
			}
			$this->refused( [ $this->address() ] );
		}

		public function test_missing_required_fields_are_checked_after_sanitization(): void {
			foreach ( [ 'first_name', 'last_name', 'address_1', 'city', 'state', 'postcode', 'country' ] as $field ) {
				$input = $this->address();
				unset( $input[ $field ] );
				$this->refused( $input );
				$this->refused( $this->address( [ $field => '<b> </b>' ] ) );
			}
		}

		public function test_country_code_normalization_and_configured_shipping_allowance(): void {
			self::assertSame( 'US', Shape::normalise( $this->address( [ 'country' => ' us ' ] ) )['country'] );
			$this->refused( $this->address( [ 'country' => 'ZZ' ] ) );
			$this->refused( $this->address( [ 'country' => 'USA' ] ) );
			Runtime::$countries->allowed = [ 'GB' => 'United Kingdom' ];
			$this->refused( $this->address() );
			Runtime::$countries->allowed = [];
			$this->refused( $this->address() );
		}

		public function test_shipping_allowance_does_not_make_an_unknown_country_valid(): void {
			Runtime::$countries->allowed['ZZ'] = 'PRIVATE';
			$this->refused( $this->address( [ 'country' => 'ZZ' ] ) );
		}

		public function test_known_state_codes_and_names_normalize_but_invalid_states_refuse(): void {
			foreach ( [ 'ca', 'California', 'california' ] as $state ) {
				self::assertSame( 'CA', Shape::normalise( $this->address( [ 'state' => $state ] ) )['state'] );
			}
			$this->refused( $this->address( [ 'state' => 'PRIVATE invalid region' ] ) );
		}

		public function test_free_text_state_and_missing_optional_state_follow_country_rules(): void {
			$input = $this->address( [ 'country' => 'GB', 'postcode' => 'sw1a1aa', 'state' => 'Île / District' ] );
			self::assertSame( 'Île / District', Shape::normalise( $input )['state'] );
			unset( $input['state'] );
			self::assertSame( '', Shape::normalise( $input )['state'] );
		}

		public function test_country_without_postcode_or_state_accepts_both_absent(): void {
			$input = $this->address( [ 'country' => 'AE' ] );
			unset( $input['postcode'], $input['state'] );
			$result = Shape::normalise( $input );
			self::assertSame( '', $result['postcode'] );
			self::assertSame( '', $result['state'] );
			self::assertSame( [ [ 'fields', 'AE', 'shipping_' ] ], Runtime::$calls );
		}

		public function test_native_postcode_format_and_validation_receive_destination_country(): void {
			$result = Shape::normalise( $this->address( [ 'country' => 'GB', 'state' => '', 'postcode' => 'sw1a1aa' ] ) );
			self::assertSame( 'SW1A 1AA', $result['postcode'] );
			self::assertContains( [ 'postcode', 'SW1A 1AA', 'GB' ], Runtime::$calls );
			Runtime::$postcode_valid = false;
			$this->refused( $this->address() );
		}

		public function test_optional_postcode_still_validates_when_supplied_and_empty_stays_empty(): void {
			Runtime::$postcode_valid = false;
			$this->refused( $this->address( [ 'country' => 'IE', 'state' => '', 'postcode' => 'PRIVATE' ] ) );
			self::assertSame( '', Shape::normalise( $this->address( [ 'country' => 'IE', 'state' => '', 'postcode' => '' ] ) )['postcode'] );
		}

		public function test_native_phone_validation_receives_country_without_stripping_punctuation(): void {
			$phone = '+1 (510) 555-0100 / 2';
			self::assertSame( $phone, Shape::normalise( $this->address( [ 'phone' => $phone ] ) )['phone'] );
			self::assertContains( [ 'phone', $phone, 'US' ], Runtime::$calls );
			Runtime::$phone_valid = false;
			$this->refused( $this->address( [ 'phone' => 'PRIVATE invalid phone' ] ) );
			self::assertSame( '', Shape::normalise( $this->address() )['phone'] );
		}

		public function test_native_required_field_filters_can_tighten_or_relax_requirements(): void {
			foreach ( [ 'shipping_company', 'shipping_address_2', 'shipping_phone' ] as $field ) {
				Runtime::$countries->overrides = [ $field => [ 'required' => true ] ];
				$this->refused( $this->address() );
			}
			Runtime::$countries->overrides = [ 'shipping_first_name' => [ 'required' => false ], 'shipping_postcode' => [ 'required' => false ] ];
			$result = Shape::normalise( $this->address( [ 'first_name' => '', 'postcode' => '' ] ) );
			self::assertSame( '', $result['first_name'] );
			self::assertSame( '', $result['postcode'] );
		}

		public function test_hidden_does_not_override_an_explicit_required_field_filter(): void {
			Runtime::$countries->overrides = [ 'shipping_postcode' => [ 'hidden' => true, 'required' => true ] ];
			$this->refused( $this->address( [ 'country' => 'AE', 'state' => '', 'postcode' => '' ] ) );
		}

		public function test_removed_optional_fields_still_have_empty_canonical_keys(): void {
			Runtime::$countries->removed = [ 'shipping_phone', 'shipping_company', 'shipping_address_2' ];
			$result = Shape::normalise( $this->address() );
			self::assertSame( '', $result['phone'] );
			self::assertSame( '', $result['company'] );
			self::assertSame( '', $result['address_2'] );
		}

		public function test_unsupported_required_extension_field_refuses_without_importing_it(): void {
			Runtime::$countries->overrides = [ 'shipping_PRIVATE' => [ 'required' => true, 'label' => 'PRIVATE' ] ];
			$this->refused( $this->address() );
			Runtime::$countries->overrides = [ 'shipping_PRIVATE' => [ 'required' => false ] ];
			self::assertSame( 10, count( Shape::normalise( $this->address() ) ) );
		}

		public function test_text_limits_count_utf8_characters_without_truncating(): void {
			foreach ( [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state' ] as $field ) {
				$input = $this->address( [ 'country' => 'GB', 'postcode' => 'SW1A 1AA', 'state' => '', $field => str_repeat( '𐍈', 190 ) ] );
				self::assertSame( str_repeat( '𐍈', 190 ), Shape::normalise( $input )[ $field ] );
				$this->refused( array_replace( $input, [ $field => str_repeat( 'é', 191 ) ] ) );
				$this->refused( array_replace( $input, [ $field => str_repeat( 'a', 191 ) ] ) );
			}
		}

		public function test_postcode_and_native_phone_column_bounds(): void {
			self::assertSame( str_repeat( '1', 32 ), Shape::normalise( $this->address( [ 'postcode' => str_repeat( '1', 32 ) ] ) )['postcode'] );
			$this->refused( $this->address( [ 'postcode' => str_repeat( '1', 33 ) ] ) );
			self::assertSame( str_repeat( '1', 100 ), Shape::normalise( $this->address( [ 'phone' => str_repeat( '1', 100 ) ] ) )['phone'] );
			$this->refused( $this->address( [ 'phone' => str_repeat( '1', 101 ) ] ) );
		}

		public function test_native_filtered_maxlength_can_tighten_but_not_expand_bounds(): void {
			Runtime::$countries->overrides = [ 'shipping_company' => [ 'maxlength' => 3 ] ];
			self::assertSame( '李李李', Shape::normalise( $this->address( [ 'company' => '李李李' ] ) )['company'] );
			$this->refused( $this->address( [ 'company' => '李李李李' ] ) );
			Runtime::$countries->overrides = [ 'shipping_phone' => [ 'maxlength' => 1000 ] ];
			$this->refused( $this->address( [ 'phone' => str_repeat( '1', 101 ) ] ) );
		}

		public function test_native_unset_maxlength_values_keep_application_bounds(): void {
			foreach ( [ false, 0, '', null ] as $unset ) {
				Runtime::$countries->overrides = [ 'shipping_company' => [ 'maxlength' => $unset ] ];
				$result = Shape::normalise( $this->address( [ 'company' => 'Depot' ] ) );
				self::assertTrue( is_array( $result ), 'Native unset maxlength must allow a valid address.' );
				self::assertSame( 'Depot', $result['company'] );
			}
		}

		public function test_native_custom_attribute_maxlength_is_enforced(): void {
			Runtime::$countries->overrides = [ 'shipping_company' => [ 'custom_attributes' => [ 'maxlength' => '3' ] ] ];
			$this->refused( $this->address( [ 'company' => 'Depot' ] ) );
			// Woo's explicit maxlength overwrites the custom attribute when rendering the field.
			Runtime::$countries->overrides['shipping_company']['maxlength'] = 5;
			self::assertSame( 'Depot', Shape::normalise( $this->address( [ 'company' => 'Depot' ] ) )['company'] );
		}

		public function test_malformed_native_definitions_refuse_without_content_leaks(): void {
			foreach ( [ [], 'PRIVATE', -1 ] as $bad ) {
				Runtime::$countries->overrides = [ 'shipping_company' => [ 'maxlength' => $bad ] ];
				$this->refused( $this->address() );
			}
			Runtime::$countries->overrides = [];
			Runtime::$countries->states['US'] = 'PRIVATE';
			$this->refused( $this->address() );
		}

		public function test_invalid_utf8_controls_and_excessive_raw_input_refuse(): void {
			foreach ( [ "PRIVATE\xff", "PRIVATE\0", "PRIVATE\x01", "PRIVATE\x7f", str_repeat( ' ', 4097 ) ] as $bad ) {
				$this->refused( $this->address( [ 'company' => $bad ] ) );
			}
		}

		public function test_filtered_sanitizer_and_formatter_cannot_bypass_shape_bounds(): void {
			foreach ( [ [], "PRIVATE\xff", str_repeat( 'x', 191 ) ] as $bad ) {
				Runtime::$sanitize_result = $bad;
				$this->refused( $this->address() );
			}
			Runtime::$sanitize_result = null;
			foreach ( [ [], "PRIVATE\0", str_repeat( '1', 33 ) ] as $bad ) {
				Runtime::$postcode_result = $bad;
				$this->refused( $this->address() );
			}
		}

		public function test_dependency_failure_is_bounded_and_contains_no_exception_content(): void {
			Runtime::$unavailable = true;
			$this->refused( $this->address() );
		}

		public function test_canonical_result_is_idempotent_and_input_order_independent(): void {
			$input = $this->address( [ 'country' => 'us', 'state' => 'California', 'company' => ' <b>A&B</b> ' ] );
			$result = Shape::normalise( $input );
			self::assertSame( $result, Shape::normalise( $result ) );
			self::assertSame( $result, Shape::normalise( array_reverse( $input, true ) ) );
		}
	}
}
