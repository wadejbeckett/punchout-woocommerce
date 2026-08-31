<?php
/**
 * Inbound parser: D365 dialect tolerances, structural validation, XXE
 * hardening (scope §9.6/§10).
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Cxml\ParseException;
use POW\Cxml\Parser;
use POW\Cxml\SetupMessage;

final class ParserTest extends TestCase {

	private Parser $parser;

	protected function setUp(): void {
		if ( ! extension_loaded( 'dom' ) ) {
			self::markTestSkipped( 'ext-dom not available' );
		}

		$this->parser = new Parser();
	}

	/**
	 * The observed D365 F&O generated template: version attribute, NO
	 * DOCTYPE line, extrinsics between BuyerCookie and BrowserFormPost.
	 */
	private function d365_setup_request( string $timestamp = '2026-08-31T08:00:00' ): string {
		return <<<XML
<?xml version="1.0" encoding="utf-8"?>
<cXML payloadID="pid-123@d365" timestamp="{$timestamp}" version="1.2.008" xml:lang="en-US">
  <Header>
    <From><Credential domain="DUNS"><Identity>ACME-ZA</Identity></Credential></From>
    <To><Credential domain="DUNS"><Identity>SUPPLIER-1</Identity></Credential></To>
    <Sender>
      <Credential domain="NetworkID"><Identity>buyer-sender</Identity><SharedSecret>s3cret</SharedSecret></Credential>
      <UserAgent>Dynamics 365</UserAgent>
    </Sender>
  </Header>
  <Request deploymentMode="test">
    <PunchOutSetupRequest operation="create">
      <BuyerCookie>Buyercookie admin 610</BuyerCookie>
      <Extrinsic name="UniqueUsername">jdoe</Extrinsic>
      <Extrinsic name="UserEmail">jdoe@example.com</Extrinsic>
      <BrowserFormPost><URL>https://buyer.example.com/?mi=catExternalCatalogBasketWizard</URL></BrowserFormPost>
      <SupplierSetup><URL>https://shop.example.com/punchout/setup</URL></SupplierSetup>
    </PunchOutSetupRequest>
  </Request>
</cXML>
XML;
	}

	public function test_parses_d365_template_without_doctype(): void {
		$message = $this->parser->parse( $this->d365_setup_request() );

		self::assertSame( SetupMessage::KIND_SETUP, $message->kind );
		self::assertSame( 'pid-123@d365', $message->payload_id );
		self::assertSame( '1.2.008', $message->version );
		self::assertSame( 'create', $message->operation );
		self::assertSame( 'test', $message->deployment_mode );
		self::assertSame( 'Buyercookie admin 610', $message->buyer_cookie );
		self::assertSame( 'https://buyer.example.com/?mi=catExternalCatalogBasketWizard', $message->browser_form_post );
		self::assertSame( 'NetworkID', $message->sender_domain );
		self::assertSame( 'buyer-sender', $message->sender_identity );
		self::assertSame( 's3cret', $message->shared_secret );
		self::assertSame( 'ACME-ZA', $message->from_identity );
		self::assertSame( 'jdoe@example.com', $message->extrinsic( 'UserEmail' ) );
		self::assertSame( 'jdoe', $message->extrinsic( 'uniqueusername' ), 'extrinsic lookup is case-insensitive' );
	}

	public function test_version_falls_back_to_doctype_system_id(): void {
		$xml = str_replace(
			[ '<?xml version="1.0" encoding="utf-8"?>', 'version="1.2.008" ' ],
			[ '<?xml version="1.0" encoding="utf-8"?><!DOCTYPE cXML SYSTEM "http://xml.cxml.org/schemas/cXML/1.2.014/cXML.dtd">', '' ],
			$this->d365_setup_request()
		);

		self::assertSame( '1.2.014', $this->parser->parse( $xml )->version );
	}

	public function test_timestamps_accepted_with_and_without_offset(): void {
		// PUNCHOUTTZ off: bare UTC timestamp; on: +00:00 suffix. Both are
		// captured verbatim, never strictly validated (d365-buyer-side §2).
		self::assertSame( '2026-08-31T08:00:00', $this->parser->parse( $this->d365_setup_request( '2026-08-31T08:00:00' ) )->timestamp );
		self::assertSame( '2026-08-31T08:00:00+00:00', $this->parser->parse( $this->d365_setup_request( '2026-08-31T08:00:00+00:00' ) )->timestamp );
	}

	public function test_empty_buyer_cookie_element_is_accepted(): void {
		$xml = str_replace( '<BuyerCookie>Buyercookie admin 610</BuyerCookie>', '<BuyerCookie/>', $this->d365_setup_request() );

		self::assertSame( '', $this->parser->parse( $xml )->buyer_cookie );
	}

	public function test_missing_buyer_cookie_is_406(): void {
		$xml = str_replace( '<BuyerCookie>Buyercookie admin 610</BuyerCookie>', '', $this->d365_setup_request() );

		try {
			$this->parser->parse( $xml );
			self::fail( 'Expected ParseException' );
		} catch ( ParseException $e ) {
			self::assertSame( 406, $e->cxml_status );
		}
	}

	public function test_missing_browser_form_post_is_406(): void {
		$xml = (string) preg_replace( '#<BrowserFormPost>.*</BrowserFormPost>#s', '', $this->d365_setup_request() );

		try {
			$this->parser->parse( $xml );
			self::fail( 'Expected ParseException' );
		} catch ( ParseException $e ) {
			self::assertSame( 406, $e->cxml_status );
		}
	}

	public function test_missing_sender_credential_is_406(): void {
		$xml = (string) preg_replace( '#<Sender>.*</Sender>#s', '', $this->d365_setup_request() );

		try {
			$this->parser->parse( $xml );
			self::fail( 'Expected ParseException' );
		} catch ( ParseException $e ) {
			self::assertSame( 406, $e->cxml_status );
		}
	}

	public function test_unsupported_request_name_is_450(): void {
		$xml = str_replace( [ 'PunchOutSetupRequest operation="create"', '</PunchOutSetupRequest>' ], [ 'OrderRequest', '</OrderRequest>' ], $this->d365_setup_request() );

		try {
			$this->parser->parse( $xml );
			self::fail( 'Expected ParseException' );
		} catch ( ParseException $e ) {
			self::assertSame( 450, $e->cxml_status );
		}
	}

	public function test_entity_declarations_are_rejected(): void {
		$xml = str_replace(
			'<?xml version="1.0" encoding="utf-8"?>',
			'<?xml version="1.0"?><!DOCTYPE cXML [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>',
			$this->d365_setup_request()
		);

		try {
			$this->parser->parse( $xml );
			self::fail( 'Expected ParseException' );
		} catch ( ParseException $e ) {
			self::assertSame( 406, $e->cxml_status );
		}
	}

	public function test_garbage_is_406(): void {
		foreach ( [ '', 'not xml at all', '<unclosed', '<root/>' ] as $garbage ) {
			try {
				$this->parser->parse( $garbage );
				self::fail( 'Expected ParseException for: ' . $garbage );
			} catch ( ParseException $e ) {
				self::assertSame( 406, $e->cxml_status );
			}
		}
	}

	public function test_profile_request_recognised(): void {
		$xml = (string) preg_replace(
			'#<PunchOutSetupRequest.*</PunchOutSetupRequest>#s',
			'<ProfileRequest/>',
			$this->d365_setup_request()
		);

		$message = $this->parser->parse( $xml );

		self::assertSame( SetupMessage::KIND_PROFILE, $message->kind );
		self::assertSame( 'buyer-sender', $message->sender_identity );
	}

	public function test_ship_to_selected_item_and_item_out_captured(): void {
		$extra = <<<XML
<ShipTo><Address><PostalAddress><City>Johannesburg</City></PostalAddress></Address></ShipTo>
<SelectedItem><ItemID><SupplierPartID>SKU-9</SupplierPartID><SupplierPartAuxiliaryID>42|0</SupplierPartAuxiliaryID></ItemID></SelectedItem>
<ItemOut quantity="3"><ItemID><SupplierPartID>SKU-1</SupplierPartID><SupplierPartAuxiliaryID>7|9</SupplierPartAuxiliaryID></ItemID></ItemOut>
XML;
		$xml = str_replace( '<SupplierSetup>', $extra . '<SupplierSetup>', str_replace( 'operation="create"', 'operation="edit"', $this->d365_setup_request() ) );

		$message = $this->parser->parse( $xml );

		self::assertSame( 'edit', $message->operation );
		self::assertNotNull( $message->ship_to_xml );
		self::assertStringContainsString( 'Johannesburg', (string) $message->ship_to_xml );
		self::assertSame( [ 'supplier_part_id' => 'SKU-9', 'aux_id' => '42|0' ], $message->selected_item );
		self::assertCount( 1, $message->item_out );
		self::assertSame( '3', $message->item_out[0]['quantity'] );
		self::assertSame( '7|9', $message->item_out[0]['aux_id'] );
	}
}
