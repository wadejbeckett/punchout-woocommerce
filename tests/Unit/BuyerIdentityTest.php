<?php
/**
 * Buyer identity extraction from a parsed setup request.
 *
 * The value object is pure — no wpdb, no user functions, no hooks — so the
 * whole precedence chain is provable without WordPress and without ext-dom:
 * the tests hand it a SetupMessage built by hand rather than parsed.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Buyers\Identity;
use POW\Cxml\SetupMessage;

final class BuyerIdentityTest extends TestCase {

	/**
	 * @param array<string, string> $extrinsics
	 */
	private function message( array $extrinsics = [], ?string $contact_email = null ): SetupMessage {
		return new SetupMessage(
			kind: SetupMessage::KIND_SETUP,
			payload_id: 'pid-1',
			timestamp: '2026-09-21T08:00:00',
			version: '1.2.008',
			lang: 'en-US',
			deployment_mode: 'production',
			from_domain: 'DUNS',
			from_identity: 'ACME-ZA',
			to_domain: 'DUNS',
			to_identity: 'SUPPLIER-1',
			sender_domain: 'NetworkID',
			sender_identity: 'buyer-sender',
			shared_secret: 'secret',
			user_agent: 'Dynamics 365',
			extrinsics: $extrinsics,
			contact_email: $contact_email,
		);
	}

	public function test_identity_chain_prefers_useremail_then_uniqueusername_then_uniquename_then_contact(): void {
		$all = Identity::from_message(
			$this->message(
				[ 'UserEmail' => 'first@example.test', 'UniqueUsername' => 'jdoe', 'UniqueName' => 'J Doe' ],
				'contact@example.test'
			)
		);
		self::assertSame( 'first@example.test', $all->identity );

		$no_email = Identity::from_message(
			$this->message( [ 'UniqueUsername' => 'jdoe', 'UniqueName' => 'J Doe' ], 'contact@example.test' )
		);
		self::assertSame( 'jdoe', $no_email->identity );

		$only_unique_name = Identity::from_message(
			$this->message( [ 'UniqueName' => 'J Doe' ], 'contact@example.test' )
		);
		self::assertSame( 'j doe', $only_unique_name->identity );

		$contact_only = Identity::from_message( $this->message( [], 'contact@example.test' ) );
		self::assertSame( 'contact@example.test', $contact_only->identity );
	}

	public function test_identity_lookup_is_case_insensitive_on_the_extrinsic_name(): void {
		// D365 administrators type the extrinsic names; SetupMessage::extrinsic already tolerates the case.
		self::assertSame( 'jdoe@example.test', Identity::from_message( $this->message( [ 'useremail' => 'jdoe@example.test' ] ) )->identity );
	}

	public function test_identity_is_lowercased_and_trimmed(): void {
		self::assertSame( 'jdoe@example.test', Identity::from_message( $this->message( [ 'UserEmail' => "  JDoe@Example.TEST \n" ] ) )->identity );
	}

	public function test_an_empty_extrinsic_falls_through_instead_of_swallowing_the_rest_of_the_chain(): void {
		// <Extrinsic name="UserEmail"/> parses to '', which is present but unusable: it must not discard UniqueUsername.
		$blank_email = Identity::from_message(
			$this->message( [ 'UserEmail' => '', 'UniqueUsername' => 'jdoe' ], 'contact@example.test' )
		);
		self::assertSame( 'jdoe', $blank_email->identity );

		$whitespace_only = Identity::from_message(
			$this->message( [ 'UserEmail' => '   ', 'UniqueUsername' => '  ', 'UniqueName' => '' ], 'contact@example.test' )
		);
		self::assertSame( 'contact@example.test', $whitespace_only->identity );
	}

	public function test_no_usable_identity_is_allowed_and_empty(): void {
		$anonymous = Identity::from_message( $this->message( [ 'UserEmail' => '' ], '   ' ) );
		self::assertSame( '', $anonymous->identity );
		self::assertSame( '', $anonymous->name );
	}

	public function test_name_chain_prefers_printable_then_full_then_unique_username_then_user(): void {
		self::assertSame(
			'Zoë Buyer',
			Identity::from_message( $this->message( [ 'UserPrintableName' => ' Zoë Buyer ', 'UserFullName' => 'Zoe B', 'UniqueUsername' => 'jdoe', 'User' => 'jd' ] ) )->name
		);
		self::assertSame(
			'Zoe B',
			Identity::from_message( $this->message( [ 'UserPrintableName' => '', 'UserFullName' => 'Zoe B', 'UniqueUsername' => 'jdoe' ] ) )->name
		);
		self::assertSame(
			'jdoe',
			Identity::from_message( $this->message( [ 'UniqueUsername' => 'jdoe', 'User' => 'jd' ] ) )->name
		);
		self::assertSame( 'jd', Identity::from_message( $this->message( [ 'User' => 'jd' ] ) )->name );
	}

	public function test_name_keeps_its_case_and_is_never_taken_from_the_contact_email(): void {
		$identity = Identity::from_message( $this->message( [], 'Contact@Example.TEST' ) );
		self::assertSame( 'contact@example.test', $identity->identity );
		self::assertSame( '', $identity->name, 'A contact e-mail is an identity, never a display name' );
	}

	public function test_hash_is_stable_and_scoped_to_the_connection(): void {
		$identity = Identity::from_message( $this->message( [ 'UserEmail' => 'jdoe@example.test' ] ) );
		$same     = Identity::from_message( $this->message( [ 'UserEmail' => 'JDOE@example.test' ] ) );

		self::assertSame( $identity->hash( 7 ), $same->hash( 7 ) );
		self::assertNotSame( $identity->hash( 7 ), $identity->hash( 8 ) );
		self::assertSame( hash( 'sha256', '7|jdoe@example.test' ), $identity->hash( 7 ) );
		self::assertSame( 64, strlen( $identity->hash( 7 ) ), 'buyer_identity_hash is CHAR(64)' );
	}

	public function test_an_anonymous_visit_has_no_hash_at_all(): void {
		// An empty identity must not produce a hash every anonymous visit of one connection shares, or they would supersede each other.
		self::assertSame( '', Identity::from_message( $this->message() )->hash( 7 ) );
	}

	public function test_the_raw_identity_is_carried_verbatim_and_is_not_hashed_in_place(): void {
		$identity = Identity::from_message( $this->message( [ 'UserEmail' => 'jdoe@example.test' ] ) );
		self::assertSame( 'jdoe@example.test', $identity->identity );
		self::assertStringNotContainsString( $identity->identity, $identity->hash( 7 ) );
	}
}
