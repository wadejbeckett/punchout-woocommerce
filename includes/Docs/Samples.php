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
	 * Values are the parsed values themselves or machine tokens
	 * ('present'/'absent'); no prose and no translation happens here, so
	 * the renderer owns every user-visible string.
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
			'ShipTo'                          => null !== $message->ship_to_xml ? 'present' : 'absent',
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
	 * The two sample cart lines, in the shape PoomMapper produces.
	 *
	 * One source for both the document and the prose total, so a line
	 * edited here can never leave the two disagreeing.
	 *
	 * @return list<array{quantity: int, supplier_part_id: string, aux_id: string, unit_price_cents: int, description: string, short_name: string, uom: string, classification: string}>
	 */
	private static function items(): array {
		return [
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
	}

	/**
	 * Sample basket total in cents, summed from items().
	 */
	private static function total_cents( ?array $items = null ): int {
		$total = 0;

		foreach ( $items ?? self::items() as $item ) {
			$total += (int) round( $item['unit_price_cents'] * $item['quantity'] );
		}

		return $total;
	}

	/**
	 * The basket we post back, built by the real Builder from the two-line
	 * cart in items().
	 */
	public static function poom(): string {
		return ( new Builder() )->poom( self::poom_args() );
	}

	/** Neutral opted-in example; receipt still requires the purchasing system's agreed mapping. */
	public static function delivery_poom( string $version = self::VERSION ): string {
		$args = self::poom_args();
		$args['version'] = $version;
		$args['ship_to'] = [ 'name' => 'Example Company Receiving', 'deliver_to' => [ 'Example Buyer' ], 'street' => [ '1 Example Road' ], 'city' => 'Cape Town', 'state' => 'WC', 'postal_code' => '8001', 'iso_country' => 'ZA' ];
		$args['delivery_code'] = 'BUYER-001';
		$args['emit_ship_to'] = true;
		$args['emit_delivery_code'] = true;
		$args['delivery_notes'] = 'Please deliver to the receiving desk.';
		$args['delivery_notes_policy'] = 'item_detail_extrinsic';
		$args['items'][] = [ 'line_type' => 'freight', 'quantity' => 1, 'supplier_part_id' => 'DELIVERY', 'aux_id' => '', 'unit_price_cents' => 3500, 'description' => 'Delivery estimate', 'uom' => 'EA', 'classification_domain' => 'supplier', 'classification' => 'freight' ];
		$args['total_cents'] = self::total_cents( $args['items'] );
		return ( new Builder() )->poom( $args );
	}

	/** Shared deterministic envelope and merchandise, never parsed from generated XML. */
	private static function poom_args(): array {
		return [
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
			'total_cents'         => self::total_cents(),
			'supplier_order_info' => null,
			'items'               => self::items(),
		];
	}

	/** Formatted total of the sample basket, for the page's prose. */
	public static function poom_total(): string {
		return Money::format( self::total_cents() );
	}
}
