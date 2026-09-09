<?php
/**
 * Documentation samples, produced by the live codec.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Docs;

use POW\Cxml\Builder;
use POW\Cxml\Money;
use POW\Cxml\Parser;
use POW\Cxml\SetupMessage;
use POW\Http\SetupEndpoint;

defined( 'ABSPATH' ) || exit;

/**
 * Every sample on the documentation page comes from here, and every
 * sample here comes from the classes that serve real traffic: the
 * inbound fixture is parsed by Cxml\Parser (its annotations are read off
 * the parse result, so a fixture that stops parsing fails the suite and
 * shows an error on the page rather than lying), and every outbound
 * document is built by Cxml\Builder.
 *
 * payloadID and timestamp are fixed so the page is byte-stable between
 * requests and diffable between releases.
 */
final class Samples {

	public const PAYLOAD_ID = '1757318400.1.100001@shop.example.com';
	public const TIMESTAMP  = '2026-09-08T09:00:00+00:00';
	public const VERSION    = '1.2.008';
	public const CURRENCY   = 'ZAR';

	private static ?SetupMessage $parsed = null;

	/**
	 * The annotated inbound fixture. Invented identities only.
	 */
	public static function setup_request(): string {
		return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE cXML SYSTEM "http://xml.cxml.org/schemas/cXML/1.2.008/cXML.dtd">
<cXML payloadID="933695160821@example.com" timestamp="2026-09-08T09:00:00+00:00" xml:lang="en-US">
 <Header>
  <From><Credential domain="NetworkId"><Identity>AN01000000123-T</Identity></Credential></From>
  <To><Credential domain="DUNS"><Identity>SUPPLIER-DUNS</Identity></Credential></To>
  <Sender>
   <Credential domain="NetworkId"><Identity>AN01000000123-T</Identity><SharedSecret>your-shared-secret</SharedSecret></Credential>
   <UserAgent>Buyer procurement system 10.0</UserAgent>
  </Sender>
 </Header>
 <Request deploymentMode="test">
  <PunchOutSetupRequest operation="create">
   <BuyerCookie>1CX26RCJDQ9OS</BuyerCookie>
   <Extrinsic name="UserEmail">buyer@example.com</Extrinsic>
   <Extrinsic name="UniqueName">buyer@example.com</Extrinsic>
   <BrowserFormPost><URL>https://buyer.example.com/punchout/receive</URL></BrowserFormPost>
   <Contact role="endUser"><Name xml:lang="en">A Buyer</Name><Email>buyer@example.com</Email></Contact>
   <ShipTo>
    <Address addressID="LEMA-001" addressIDDomain="supplier">
     <Name xml:lang="en">Head office</Name>
     <PostalAddress name="default">
      <DeliverTo>Receiving</DeliverTo>
      <Street>1 Example Road</Street>
      <City>Johannesburg</City>
      <State>GP</State>
      <PostalCode>2196</PostalCode>
      <Country isoCountryCode="ZA">South Africa</Country>
     </PostalAddress>
    </Address>
   </ShipTo>
  </PunchOutSetupRequest>
 </Request>
</cXML>
XML;
	}

	/**
	 * The fixture through the live parser. Throws ParseException if the
	 * fixture ever stops being a document we accept.
	 */
	public static function parsed(): SetupMessage {
		return self::$parsed ??= ( new Parser() )->parse( self::setup_request() );
	}

	/**
	 * Field-by-field annotations, read off the parse result so the page
	 * can never document a value the parser does not actually take.
	 *
	 * @return array<string, string>
	 */
	public static function annotations(): array {
		$message = self::parsed();

		return [
			'payloadID'                       => $message->payload_id,
			'timestamp'                       => $message->timestamp,
			'cXML version'                    => $message->version,
			'Request/@deploymentMode'         => $message->deployment_mode,
			'From/Credential/Identity'        => $message->from_domain . ' / ' . $message->from_identity,
			'Sender/Credential/Identity'      => $message->sender_domain . ' / ' . $message->sender_identity,
			'PunchOutSetupRequest/@operation' => $message->operation,
			'BuyerCookie'                     => $message->buyer_cookie,
			'BrowserFormPost/URL'             => $message->browser_form_post,
			'Extrinsic names honoured'        => implode( ', ', array_keys( $message->extrinsics ) ),
			'Contact/Email'                   => (string) $message->contact_email,
			'ShipTo'                          => null !== $message->ship_to_xml ? 'present — stored on the session' : 'absent',
		];
	}

	public static function setup_response( string $start_url ): string {
		return ( new Builder() )->setup_response( self::VERSION, self::PAYLOAD_ID, self::TIMESTAMP, $start_url );
	}

	public static function status( int $code ): string {
		return ( new Builder() )->status(
			self::VERSION,
			self::PAYLOAD_ID,
			self::TIMESTAMP,
			$code,
			SetupEndpoint::STATUS_REASONS[ $code ] ?? ''
		);
	}

	public static function profile_response( string $setup_url ): string {
		return ( new Builder() )->profile_response( self::VERSION, self::PAYLOAD_ID, self::TIMESTAMP, $setup_url );
	}

	/**
	 * The basket we post back, built by the real Builder from a two-line
	 * cart in the same shape PoomMapper produces.
	 */
	public static function poom(): string {
		$items = [
			[
				'quantity'         => 2,
				'supplier_part_id' => 'SKU-1001',
				'aux_id'           => '412|0',
				'unit_price_cents' => 12500,
				'description'      => 'Example product, 5 L',
				'short_name'       => 'Example product',
				'uom'              => 'EA',
				'classification'   => '47131700',
			],
			[
				'quantity'         => 1,
				'supplier_part_id' => 'SKU-2002',
				'aux_id'           => '511|518',
				'unit_price_cents' => 34900,
				'description'      => 'Example product, 20 L',
				'short_name'       => 'Example product 20 L',
				'uom'              => 'EA',
				'classification'   => '47131700',
			],
		];

		$total = 0;

		foreach ( $items as $item ) {
			$total += (int) round( $item['unit_price_cents'] * $item['quantity'] );
		}

		return ( new Builder() )->poom(
			[
				'version'             => self::VERSION,
				'payload_id'          => self::PAYLOAD_ID,
				'timestamp'           => self::TIMESTAMP,
				'deployment_mode'     => 'test',
				'from'                => [
					'domain'   => 'DUNS',
					'identity' => 'SUPPLIER-DUNS',
				],
				'to'                  => [
					'domain'   => 'NetworkId',
					'identity' => 'AN01000000123-T',
				],
				'sender'              => [
					'domain'   => 'DUNS',
					'identity' => 'SUPPLIER-DUNS',
				],
				'user_agent'          => 'PunchOut for WooCommerce',
				'buyer_cookie'        => self::parsed()->buyer_cookie,
				'operation_allowed'   => 'create',
				'currency'            => self::CURRENCY,
				'total_cents'         => $total,
				'supplier_order_info' => null,
				'items'               => $items,
			]
		);
	}

	/** Formatted total of the sample basket, for the page's prose. */
	public static function poom_total(): string {
		return Money::format( 12500 * 2 + 34900 );
	}
}
