<?php
/** Registration's applicant field boundary. @package POW */
declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Partners\Registration;

final class RegistrationFieldsTest extends TestCase {

	private function fields(): array {
		return [ 'name' => ' Example Buyer ', 'from_domain' => ' NetworkID ', 'from_identity' => ' BUYER ' ];
	}

	public function test_sender_defaults_and_trim(): void {
		self::assertTrue( class_exists( Registration::class ), 'Registration service must exist' );
		$fields = Registration::normalise( $this->fields() );
		self::assertSame( [ 'name' => 'Example Buyer', 'from_domain' => 'NetworkID', 'from_identity' => 'BUYER', 'sender_domain' => 'NetworkID', 'sender_identity' => 'BUYER', 'deployment_mode' => 'test', 'notes' => '' ], $fields );
		self::assertNull( Registration::validate( $fields ) );
	}

	public function test_explicit_sender_is_preserved_including_explicit_blank(): void {
		$fields = Registration::normalise( $this->fields() + [ 'sender_domain' => ' DUNS ', 'sender_identity' => ' SUPPLIER ' ] );
		self::assertSame( 'DUNS', $fields['sender_domain'] );
		self::assertSame( 'SUPPLIER', $fields['sender_identity'] );
		self::assertNull( Registration::validate( $fields ) );
		self::assertInstanceOf( WP_Error::class, Registration::validate( Registration::normalise( $this->fields() + [ 'sender_identity' => ' ' ] ) ) );
	}

	public function test_mode_defaults_without_accepting_non_scalar_input(): void {
		foreach ( [ 'test' => 'test', 'production' => 'production', ' production ' => 'production', 'live' => 'test', '' => 'test' ] as $posted => $expected ) {
			self::assertSame( $expected, Registration::normalise( $this->fields() + [ 'deployment_mode' => $posted ] )['deployment_mode'] );
		}
		self::assertInstanceOf( WP_Error::class, Registration::validate( Registration::normalise( $this->fields() + [ 'deployment_mode' => [] ] ) ) );
	}

	public function test_protected_fields_are_discarded(): void {
		$extras = [ 'owner_user_id' => 99, 'status' => 'active', 'secret_current' => 'forged', 'secret_previous' => 'forged', 'secret' => 'forged', 'company_profile' => '{"admin":true}', 'mode' => 'dual_exit', 'to_identity' => 'forged', 'allow_reentry' => 1 ];
		self::assertSame( Registration::normalise( $this->fields() ), Registration::normalise( $this->fields() + $extras ) );
	}

	public function test_required_and_schema_character_limits(): void {
		$valid = Registration::normalise( $this->fields() );
		foreach ( [ 'name', 'from_domain', 'from_identity', 'sender_domain', 'sender_identity' ] as $key ) {
			foreach ( [ '', ' ', str_repeat( 'x', 191 ), str_repeat( 'é', 191 ), "a\0b", "bad\xff" ] as $bad ) {
				self::assertInstanceOf( WP_Error::class, Registration::validate( array_replace( $valid, [ $key => $bad ] ) ), $key );
			}
			self::assertNull( Registration::validate( array_replace( $valid, [ $key => str_repeat( 'é', 190 ) ] ) ) );
		}
	}

	public function test_all_allowed_fields_reject_non_scalars(): void {
		foreach ( array_keys( Registration::normalise( $this->fields() ) ) as $key ) {
			foreach ( [ [], [ 'nested' ], new stdClass(), null ] as $bad ) {
				self::assertInstanceOf( WP_Error::class, Registration::validate( Registration::normalise( array_replace( $this->fields(), [ $key => $bad ] ) ) ), $key );
			}
		}
	}

	public function test_notes_must_fit_the_json_text_column_without_truncation(): void {
		$valid = Registration::normalise( $this->fields() + [ 'notes' => " First line\nSecond line " ] );
		self::assertSame( "First line\nSecond line", $valid['notes'] );
		self::assertNull( Registration::validate( $valid ) );
		self::assertInstanceOf( WP_Error::class, Registration::validate( array_replace( $valid, [ 'notes' => str_repeat( '"', 32768 ) ] ) ) );
		self::assertInstanceOf( WP_Error::class, Registration::validate( array_replace( $valid, [ 'notes' => "bad\xff" ] ) ) );
	}

	public function test_normalisation_does_not_erase_invalid_nul_bytes(): void {
		foreach ( [ 'name', 'from_domain', 'from_identity', 'sender_domain', 'sender_identity', 'notes' ] as $key ) {
			self::assertInstanceOf( WP_Error::class, Registration::validate( Registration::normalise( array_replace( $this->fields(), [ $key => "\0BUYER\0" ] ) ) ) );
		}
	}
}
