<?php
/**
 * The company setup template a purchasing system administrator pastes into
 * their external-catalog configuration: built from the connection's saved
 * identities, never from a secret, and accepted by this plugin's own parser
 * once the purchasing system has filled in its runtime values.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Cxml\Parser;
use POW\Cxml\SetupMessage;
use POW\Docs\Samples;
use POW\Docs\SetupTemplate;
use POW\Partners\Partner;

final class SetupTemplateTest extends TestCase {

	private const URL = 'https://shop.example.com/punchout/setup';

	protected function setUp(): void {
		if ( ! extension_loaded( 'dom' ) ) {
			self::markTestSkipped( 'ext-dom not available' );
		}
		unset( $GLOBALS['pow_test_environment_type'] );
	}

	private static function partner( array $over = [] ): Partner {
		return Partner::from_row( array_replace( [
			'id'              => 20,
			'name'            => 'Example Buyer',
			'status'          => 'active',
			'from_domain'     => 'NetworkId',
			'from_identity'   => 'BUYER-ONE',
			'sender_domain'   => 'NetworkId',
			'sender_identity' => 'SENDER-ONE',
			'to_domain'       => 'DUNS',
			'to_identity'     => '123456789',
			'secret_current'  => 'SEALED-PRIVATE',
			'cxml_version'    => '1.2.008',
			'deployment_mode' => 'test',
		], $over ) );
	}

	/** @return array{doc: DOMDocument, xpath: DOMXPath} */
	private static function parsed( string $xml ): array {
		$doc = new DOMDocument();
		self::assertTrue( $doc->loadXML( $xml, LIBXML_NONET ), 'Generated template must be well-formed XML.' );
		return [ 'doc' => $doc, 'xpath' => new DOMXPath( $doc ) ];
	}

	/**
	 * Fills the template the way a purchasing system does at runtime: payloadID,
	 * timestamp, BuyerCookie, BrowserFormPost, plus the UserEmail extrinsic the
	 * Dynamics 365 catalog configuration adds. Element order is left exactly as
	 * generated.
	 */
	private static function filled( string $template ): string {
		$filled = str_replace(
			[ 'payloadID="" timestamp=""', '<BuyerCookie />', '<BrowserFormPost><URL /></BrowserFormPost>' ],
			[ 'payloadID="1758140000.1234@dynamics" timestamp="2026-09-17T22:30:00+02:00"', '<BuyerCookie>COOKIE-1</BuyerCookie>', '<BrowserFormPost><URL>https://buyer.example/return</URL></BrowserFormPost>' ],
			$template
		);
		return str_replace( '<BuyerCookie>', '<Extrinsic name="UserEmail">buyer.one@example.com</Extrinsic><BuyerCookie>', $filled );
	}

	/**
	 * The element order inside PunchOutSetupRequest deliberately mirrors what
	 * Dynamics 365 for Operations actually sends (SupplierSetup, Extrinsic,
	 * BuyerCookie, BrowserFormPost: every live request from the certified
	 * buyer, audit rows setup_rx 548-602 on 11-15 September 2026), not the
	 * DTD's content model. The plugin's parser accepts that order, and the
	 * working production configuration was pasted in it. Changing it would be
	 * an untested change to a certified integration.
	 */
	public function test_template_keeps_the_element_order_dynamics_emits(): void {
		$xml = SetupTemplate::for_partner( self::partner(), self::URL );
		preg_match_all( '/<(BuyerCookie|Extrinsic|BrowserFormPost|SupplierSetup)[ >\/]/', $xml, $found );
		self::assertSame( [ 'SupplierSetup', 'BuyerCookie', 'BrowserFormPost' ], $found[1] );
	}

	public function test_template_filled_in_by_the_purchasing_system_parses_for_every_supported_version(): void {
		foreach ( [ '1.2.008', '1.2.071' ] as $version ) {
			$partner = self::partner( [ 'cxml_version' => $version, 'deployment_mode' => 'production' ] );
			$message = ( new Parser() )->parse( self::filled( SetupTemplate::for_partner( $partner, self::URL ) ) );
			self::assertSame( SetupMessage::KIND_SETUP, $message->kind, $version );
			self::assertSame( $version, $message->version );
			self::assertSame( 'create', $message->operation );
			self::assertSame( 'production', $message->deployment_mode );
			self::assertSame( [ 'NetworkId', 'BUYER-ONE', 'DUNS', '123456789', 'NetworkId', 'SENDER-ONE' ], [ $message->from_domain, $message->from_identity, $message->to_domain, $message->to_identity, $message->sender_domain, $message->sender_identity ] );
			self::assertSame( SetupTemplate::SHARED_SECRET, $message->shared_secret, 'The placeholder travels where the issued secret will.' );
			self::assertSame( 'COOKIE-1', $message->buyer_cookie );
			self::assertSame( 'https://buyer.example/return', $message->browser_form_post );
			self::assertSame( 'buyer.one@example.com', $message->extrinsics['UserEmail'] ?? null );
			self::assertSame( 'Dynamics 365 for Operations', $message->user_agent );
		}
	}

	public function test_template_is_well_formed_and_declares_the_exact_dtd_of_its_version(): void {
		foreach ( [ '1.2.008', '1.2.071' ] as $version ) {
			$xml = SetupTemplate::for_partner( self::partner( [ 'cxml_version' => $version ] ), self::URL );
			[ 'doc' => $doc ] = self::parsed( $xml );
			self::assertSame( 'http://xml.cxml.org/schemas/cXML/' . $version . '/cXML.dtd', $doc->doctype->systemId );
			self::assertSame( $version, $doc->documentElement->getAttribute( 'version' ) );
		}
	}

	public function test_template_carries_the_connection_identities_and_supplier_url_and_no_secret(): void {
		$xml = SetupTemplate::for_partner( self::partner(), self::URL );
		[ 'xpath' => $x ] = self::parsed( $xml );

		self::assertSame( 'NetworkId', $x->evaluate( 'string(/cXML/Header/From/Credential/@domain)' ) );
		self::assertSame( 'BUYER-ONE', $x->evaluate( 'string(/cXML/Header/From/Credential/Identity)' ) );
		self::assertSame( 'DUNS', $x->evaluate( 'string(/cXML/Header/To/Credential/@domain)' ) );
		self::assertSame( '123456789', $x->evaluate( 'string(/cXML/Header/To/Credential/Identity)' ) );
		self::assertSame( 'NetworkId', $x->evaluate( 'string(/cXML/Header/Sender/Credential/@domain)' ) );
		self::assertSame( 'SENDER-ONE', $x->evaluate( 'string(/cXML/Header/Sender/Credential/Identity)' ) );
		self::assertSame( SetupTemplate::SHARED_SECRET, $x->evaluate( 'string(/cXML/Header/Sender/Credential/SharedSecret)' ) );
		self::assertSame( self::URL, $x->evaluate( 'string(/cXML/Request/PunchOutSetupRequest/SupplierSetup/URL)' ) );
		self::assertSame( 'create', $x->evaluate( 'string(/cXML/Request/PunchOutSetupRequest/@operation)' ) );
		self::assertStringNotContainsString( 'SEALED-PRIVATE', $xml );
	}

	public function test_runtime_fields_are_left_for_the_purchasing_system_to_fill(): void {
		$xml = SetupTemplate::for_partner( self::partner(), self::URL );
		[ 'xpath' => $x ] = self::parsed( $xml );

		self::assertSame( '', $x->evaluate( 'string(/cXML/@payloadID)' ) );
		self::assertSame( '', $x->evaluate( 'string(/cXML/@timestamp)' ) );
		self::assertSame( '', $x->evaluate( 'string(/cXML/Request/PunchOutSetupRequest/BuyerCookie)' ) );
		self::assertSame( '', $x->evaluate( 'string(/cXML/Request/PunchOutSetupRequest/BrowserFormPost/URL)' ) );
		self::assertSame( 0.0, $x->evaluate( 'count(//Extrinsic)' ), 'No invented substitution tokens or extrinsics.' );
		self::assertStringNotContainsString( self::URL, $x->evaluate( 'string(/cXML/Request/PunchOutSetupRequest/BrowserFormPost/URL)' ), 'The supplier URL is never reused as the callback.' );
	}

	public function test_deployment_mode_follows_the_connection(): void {
		foreach ( [ 'test', 'production' ] as $mode ) {
			$xml = SetupTemplate::for_partner( self::partner( [ 'deployment_mode' => $mode ] ), self::URL );
			[ 'xpath' => $x ] = self::parsed( $xml );
			self::assertSame( $mode, $x->evaluate( 'string(/cXML/Request/@deploymentMode)' ) );
		}
		$this->expectException( DomainException::class );
		SetupTemplate::for_partner( self::partner( [ 'deployment_mode' => 'staging' ] ), self::URL );
	}

	public function test_hostile_identity_text_is_escaped_and_the_document_stays_valid(): void {
		$hostile = 'ACME <Corp> & "Sons" \'Ltd\' ]]> <SharedSecret>x</SharedSecret>';
		$xml = SetupTemplate::for_partner( self::partner( [ 'from_identity' => $hostile, 'sender_identity' => $hostile, 'to_identity' => $hostile ] ), self::URL );
		[ 'xpath' => $x ] = self::parsed( $xml );
		$message = ( new Parser() )->parse( self::filled( $xml ) );
		self::assertSame( $hostile, $message->from_identity, 'The parser reads the hostile text back as text.' );
		self::assertSame( $hostile, $x->evaluate( 'string(/cXML/Header/From/Credential/Identity)' ), 'Round-trips as text, not markup.' );
		self::assertSame( 1.0, $x->evaluate( 'count(//SharedSecret)' ), 'Injected markup never becomes an element.' );
		self::assertSame( SetupTemplate::SHARED_SECRET, $x->evaluate( 'string(//SharedSecret)' ) );
	}

	public function test_control_characters_empty_values_and_oversize_values_are_refused(): void {
		foreach ( [ "BUYER\x00ONE", "BUYER\x1BONE", '', str_repeat( 'x', 191 ) ] as $bad ) {
			try {
				SetupTemplate::for_partner( self::partner( [ 'from_identity' => $bad ] ), self::URL );
				self::fail( 'Expected a refusal for ' . var_export( $bad, true ) );
			} catch ( DomainException $expected ) {
				self::assertSame( 'Invalid setup template value.', $expected->getMessage() );
			}
		}
	}

	public function test_supplier_url_must_be_https_unless_the_environment_is_local(): void {
		$this->expectException( DomainException::class );
		SetupTemplate::for_partner( self::partner(), 'http://shop.example.com/punchout/setup' );
	}

	public function test_samples_wrapper_allows_plain_http_only_in_local_and_development_environments(): void {
		foreach ( [ 'local', 'development' ] as $env ) {
			$GLOBALS['pow_test_environment_type'] = $env;
			$xml = Samples::setup_template( self::partner(), 'http://localhost:8888/punchout/setup' );
			self::assertStringContainsString( '<URL>http://localhost:8888/punchout/setup</URL>', $xml );
		}
		foreach ( [ 'staging', 'production' ] as $env ) {
			$GLOBALS['pow_test_environment_type'] = $env;
			try {
				Samples::setup_template( self::partner(), 'http://localhost:8888/punchout/setup' );
				self::fail( "Plain http accepted in {$env}" );
			} catch ( DomainException $expected ) {
				self::assertSame( 'Invalid supplier setup URL.', $expected->getMessage() );
			}
		}
	}

	public function test_unsupported_cxml_version_is_refused_before_any_output(): void {
		$this->expectException( DomainException::class );
		SetupTemplate::for_partner( self::partner( [ 'cxml_version' => '1.2' ] ), self::URL );
	}

	public function test_render_accepts_no_secret_slot(): void {
		$connection = [ 'version' => '1.2.008', 'deployment_mode' => 'test', 'from_domain' => 'NetworkId', 'from_identity' => 'A', 'sender_domain' => 'NetworkId', 'sender_identity' => 'B', 'to_domain' => 'DUNS', 'to_identity' => 'C', 'shared_secret' => 'LEAK-ME', 'secret_current' => 'LEAK-ME' ];
		$xml = SetupTemplate::render( $connection, self::URL );
		self::assertStringNotContainsString( 'LEAK-ME', $xml );
		self::assertStringContainsString( SetupTemplate::SHARED_SECRET, $xml );
	}
}
