<?php
/**
 * Outbound cXML document builder.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Cxml;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the four document shapes the plugin emits: PunchOutSetupResponse,
 * ProfileResponse, generic Status, and PunchOutOrderMessage (full and
 * empty/cancel variants).
 *
 * Envelope rules enforced here, not by convention (scope §6.2):
 *
 * - Response documents carry no Header at all.
 * - The POOM travels as a Message through the buyer's browser: its Header
 *   carries From/To REVERSED from the setup request and a Sender containing
 *   identity only. This class has no code path that writes a SharedSecret
 *   node anywhere — the codec test suite asserts its absence on both POOM
 *   variants.
 * - No newlines inside element content (D365's REPLACENEWLINE fragility):
 *   every text value is flattened before it reaches the document.
 * - The DOCTYPE names the partner-configured cXML version; the DTD is never
 *   fetched (it is a SYSTEM identifier the receiver may resolve from its
 *   own cache).
 *
 * Pure PHP + ext-dom; no WordPress dependencies.
 */
final class Builder {

	/**
	 * Spec-format payloadID: datetime.processid.random@host (cxml-protocol §1).
	 */
	public static function payload_id( string $host ): string {
		return sprintf( '%d.%d.%d@%s', time(), getmypid() ?: 0, random_int( 100000, 999999 ), $host );
	}

	/**
	 * ISO-8601 UTC timestamp with an explicit +00:00 offset.
	 */
	public static function timestamp( ?int $time = null ): string {
		return gmdate( 'Y-m-d\TH:i:s', $time ?? time() ) . '+00:00';
	}

	/**
	 * PunchOutSetupResponse wrapping the one-time StartPage URL.
	 */
	public function setup_response( string $version, string $payload_id, string $timestamp, string $start_url ): string {
		[ $doc, $root ] = $this->envelope( $version, $payload_id, $timestamp );

		$response = $this->el( $doc, $root, 'Response' );
		$status   = $this->el( $doc, $response, 'Status' );
		$status->setAttribute( 'code', '200' );
		$status->setAttribute( 'text', 'success' );

		$setup = $this->el( $doc, $response, 'PunchOutSetupResponse' );
		$page  = $this->el( $doc, $setup, 'StartPage' );
		$this->el( $doc, $page, 'URL', $start_url );

		return $this->serialise( $doc );
	}

	/**
	 * Generic Status document ("cXML failure inside HTTP 200").
	 */
	public function status( string $version, string $payload_id, string $timestamp, int $code, string $text ): string {
		[ $doc, $root ] = $this->envelope( $version, $payload_id, $timestamp );

		$response = $this->el( $doc, $root, 'Response' );
		$status   = $this->el( $doc, $response, 'Status' );
		$status->setAttribute( 'code', (string) $code );
		$status->setAttribute( 'text', $this->flatten( $text ) );

		return $this->serialise( $doc );
	}

	/**
	 * ProfileResponse listing the one transaction we serve (mandatory for
	 * all cXML 1.1+ servers — cxml-protocol §5).
	 */
	public function profile_response( string $version, string $payload_id, string $timestamp, string $setup_url ): string {
		[ $doc, $root ] = $this->envelope( $version, $payload_id, $timestamp );

		$response = $this->el( $doc, $root, 'Response' );
		$status   = $this->el( $doc, $response, 'Status' );
		$status->setAttribute( 'code', '200' );
		$status->setAttribute( 'text', 'success' );

		$profile = $this->el( $doc, $response, 'ProfileResponse' );
		$profile->setAttribute( 'effectiveDate', $timestamp );

		$transaction = $this->el( $doc, $profile, 'Transaction' );
		$transaction->setAttribute( 'requestName', 'PunchOutSetupRequest' );
		$this->el( $doc, $transaction, 'URL', $setup_url );

		$option = $this->el( $doc, $transaction, 'Option', 'create' );
		$option->setAttribute( 'name', 'operationAllowed' );

		return $this->serialise( $doc );
	}

	/**
	 * PunchOutOrderMessage — the cart return (full) or the cancel/close-out
	 * (empty item list, optionally carrying SupplierOrderInfo with the paid
	 * Woo order reference — scope §5.4).
	 *
	 * @param array{
	 *   version: string,
	 *   payload_id: string,
	 *   timestamp: string,
	 *   lang?: string,
	 *   deployment_mode?: string,
	 *   from: array{domain: string, identity: string},
	 *   to: array{domain: string, identity: string},
	 *   sender: array{domain: string, identity: string},
	 *   user_agent?: string,
	 *   buyer_cookie: string,
	 *   operation_allowed?: string,
	 *   currency: string,
	 *   total_cents: int,
	 *   supplier_order_info?: array{order_id: string, order_date: string}|null,
	 *   ship_to?: array{name:string,deliver_to?:list<string>,street:list<string>,city:string,state?:string,postal_code?:string,iso_country:string}|null,
	 *   emit_ship_to?: bool,
	 *   emit_delivery_code?: bool,
	 *   delivery_code?: string,
	 *   delivery_code_extrinsic_name?: string,
	 *   delivery_notes?: string,
	 *   delivery_notes_policy?: string,
	 *   items: list<array{
	 *     line_type?: string,
	 *     quantity: float|int,
	 *     supplier_part_id: string,
	 *     aux_id: string,
	 *     unit_price_cents: int,
	 *     description: string,
	 *     short_name?: string,
	 *     uom: string,
	 *     classification_domain?: string,
	 *     classification: string,
	 *   }>,
	 * } $args POOM content. From/To must already be reversed by the caller
	 *         (we are the originator of this document).
	 */
	public function poom( array $args ): string {
		$lang = $args['lang'] ?? 'en-US';
		$version = $this->version( $args['version'] );
		// Positive proof for these exact official DTDs; no guessed version threshold.
		$postal_and_notes = in_array( $version, [ '1.2.008', '1.2.071' ], true );
		$extended = '1.2.071' === $version;
		$code = $args['delivery_code'] ?? '';
		$code = is_string( $code ) && 1 === preg_match( '/\A[A-Z0-9_-]{1,32}\z/', $code ) ? $code : '';

		[ $doc, $root ] = $this->envelope( $version, $args['payload_id'], $args['timestamp'], $lang );

		// Header: identity only. There is deliberately no SharedSecret
		// parameter to this method — the DTD forbids authentication in
		// browser-transported Messages and the secret must never transit
		// the buyer's browser into the buyer's own cart message log (scope §6.2).
		$header = $this->el( $doc, $root, 'Header' );

		foreach ( [ 'From', 'To', 'Sender' ] as $party ) {
			$key        = strtolower( $party );
			$node       = $this->el( $doc, $header, $party );
			$credential = $this->el( $doc, $node, 'Credential' );
			$credential->setAttribute( 'domain', $args[ $key ]['domain'] );
			$this->el( $doc, $credential, 'Identity', $args[ $key ]['identity'] );

			if ( 'Sender' === $party ) {
				$this->el( $doc, $node, 'UserAgent', $args['user_agent'] ?? 'PunchOut for WooCommerce' );
			}
		}

		$message = $this->el( $doc, $root, 'Message' );

		if ( 'test' === ( $args['deployment_mode'] ?? '' ) ) {
			$message->setAttribute( 'deploymentMode', 'test' );
		}

		$poom = $this->el( $doc, $message, 'PunchOutOrderMessage' );
		$this->el( $doc, $poom, 'BuyerCookie', $args['buyer_cookie'] );

		$poom_header = $this->el( $doc, $poom, 'PunchOutOrderMessageHeader' );
		$poom_header->setAttribute( 'operationAllowed', $args['operation_allowed'] ?? 'create' );

		$total = $this->el( $doc, $poom_header, 'Total' );
		$money = $this->el( $doc, $total, 'Money', Money::format( $args['total_cents'] ) );
		$money->setAttribute( 'currency', $args['currency'] );

		// Optional header order is Total, ShipTo, then the newer order reference.
		// Freight is an ItemIn supplied by the native estimate mapper, never header Shipping.
		if ( $postal_and_notes && true === ( $args['emit_ship_to'] ?? false ) && null !== ( $args['ship_to'] ?? null ) ) {
			$this->ship_to( $doc, $poom_header, $args['ship_to'], $code, $extended, $lang );
		}
		if ( $extended && ! empty( $args['supplier_order_info'] ) ) {
			$info = $this->el( $doc, $poom_header, 'SupplierOrderInfo' );
			$info->setAttribute( 'orderID', $args['supplier_order_info']['order_id'] );

			if ( '' !== ( $args['supplier_order_info']['order_date'] ?? '' ) ) {
				$info->setAttribute( 'orderDate', $args['supplier_order_info']['order_date'] );
			}
		}

		foreach ( $args['items'] as $item ) {
			$item_in = $this->el( $doc, $poom, 'ItemIn' );
			$item_in->setAttribute( 'quantity', $this->quantity( $item['quantity'] ) );

			$item_id = $this->el( $doc, $item_in, 'ItemID' );
			$this->el( $doc, $item_id, 'SupplierPartID', $item['supplier_part_id'] );

			if ( '' !== $item['aux_id'] ) {
				$this->el( $doc, $item_id, 'SupplierPartAuxiliaryID', $item['aux_id'] );
			}

			$detail = $this->el( $doc, $item_in, 'ItemDetail' );

			$unit_price = $this->el( $doc, $detail, 'UnitPrice' );
			$line_money = $this->el( $doc, $unit_price, 'Money', Money::format( $item['unit_price_cents'] ) );
			$line_money->setAttribute( 'currency', $args['currency'] );

			$description = $this->el( $doc, $detail, 'Description' );
			$description->setAttribute( 'xml:lang', $lang );

			if ( '' !== ( $item['short_name'] ?? '' ) ) {
				$this->el( $doc, $description, 'ShortName', $item['short_name'] );
			}

			$description->appendChild( $doc->createTextNode( $this->flatten( $item['description'] ) ) );

			$this->el( $doc, $detail, 'UnitOfMeasure', $item['uom'] );

			$classification = $this->el( $doc, $detail, 'Classification', $item['classification'] );
			$classification->setAttribute( 'domain', $item['classification_domain'] ?? 'UNSPSC' );

			// The producer marks freight explicitly; a product can legitimately have SKU DELIVERY.
			if ( 'freight' !== ( $item['line_type'] ?? 'merchandise' ) && $postal_and_notes && 'item_detail_extrinsic' === ( $args['delivery_notes_policy'] ?? 'off' ) && '' !== ( $args['delivery_notes'] ?? '' ) ) {
				$notes = $this->xml_text( $args['delivery_notes'], 2000 );
				$extrinsic = $this->el( $doc, $detail, 'Extrinsic', $notes );
				$extrinsic->setAttribute( 'name', 'DeliveryInstructions' );
			}
			if ( $extended && true === ( $args['emit_delivery_code'] ?? false ) && '' !== $code ) {
				$name = $this->xml_text( $args['delivery_code_extrinsic_name'] ?? 'DeliveryAddressCode', 64 );
				if ( '' === trim( $name ) ) { throw new \DomainException( 'Invalid delivery XML value.' ); }
				$extrinsic = $this->el( $doc, $item_in, 'Extrinsic', $code );
				$extrinsic->setAttribute( 'name', $name );
			}
		}

		return $this->serialise( $doc );
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/** Prepared postal data only; no Woo lookup, rate calculation or master-address mutation. */
	private function ship_to( \DOMDocument $doc, \DOMElement $parent, array $address, string $code, bool $extended, string $lang ): void {
		$name = $this->xml_text( $address['name'] ?? null, 381 );
		$country = $address['iso_country'] ?? '';
		$streets = $address['street'] ?? null;
		$recipients = $address['deliver_to'] ?? [];
		if ( '' === trim( $name ) || ! is_string( $country ) || 1 !== preg_match( '/\A[A-Z]{2}\z/', $country ) || ! is_array( $streets ) || ! array_is_list( $streets ) || count( $streets ) < 1 || count( $streets ) > 2 || ! is_array( $recipients ) || ! array_is_list( $recipients ) || count( $recipients ) > 2 ) {
			throw new \DomainException( 'Invalid delivery XML address.' );
		}
		$ship = $this->el( $doc, $parent, 'ShipTo' );
		$node = $this->el( $doc, $ship, 'Address' );
		if ( '' !== $code ) {
			$node->setAttribute( 'addressID', $code );
			if ( $extended ) { $node->setAttribute( 'addressIDDomain', 'supplier' ); }
		}
		$this->el( $doc, $node, 'Name', $name )->setAttribute( 'xml:lang', $lang );
		$postal = $this->el( $doc, $node, 'PostalAddress' );
		foreach ( $recipients as $recipient ) { $this->el( $doc, $postal, 'DeliverTo', $this->xml_text( $recipient, 381 ) ); }
		foreach ( $streets as $street ) {
			$street = $this->xml_text( $street, 190 );
			if ( '' === trim( $street ) ) { throw new \DomainException( 'Invalid delivery XML address.' ); }
			$this->el( $doc, $postal, 'Street', $street );
		}
		$this->el( $doc, $postal, 'City', $this->xml_text( $address['city'] ?? null, 190 ) );
		foreach ( [ 'state' => 'State', 'postal_code' => 'PostalCode' ] as $key => $tag ) {
			$value = $this->xml_text( $address[ $key ] ?? '', 'state' === $key ? 190 : 32 );
			if ( '' !== $value ) { $this->el( $doc, $postal, $tag, $value ); }
		}
		$this->el( $doc, $postal, 'Country', $country )->setAttribute( 'isoCountryCode', $country );
	}

	/** Reject malformed new optional values instead of emitting invalid XML or truncating identifiers. */
	private function xml_text( mixed $text, int $limit ): string {
		if ( ! is_string( $text ) || strlen( $text ) > $limit * 4 || 1 !== preg_match( '/\A[\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]{0,' . $limit . '}\z/u', $text ) ) {
			throw new \DomainException( 'Invalid delivery XML value.' );
		}
		return $text;
	}

	private function version( string $version ): string {
		return preg_match( '/^\d+\.\d+\.\d+$/', $version ) ? $version : '1.2.008';
	}

	/**
	 * Document + cXML root with DOCTYPE, payloadID, timestamp.
	 *
	 * @return array{0: \DOMDocument, 1: \DOMElement}
	 */
	private function envelope( string $version, string $payload_id, string $timestamp, string $lang = 'en-US' ): array {
		$version = $this->version( $version );

		$impl = new \DOMImplementation();
		$dtd  = $impl->createDocumentType( 'cXML', '', "http://xml.cxml.org/schemas/cXML/{$version}/cXML.dtd" );
		$doc  = $impl->createDocument( '', '', $dtd );

		$doc->xmlVersion   = '1.0';
		$doc->encoding     = 'UTF-8';
		$doc->formatOutput = false;

		$root = $doc->createElement( 'cXML' );
		$root->setAttribute( 'payloadID', $this->flatten( $payload_id ) );
		$root->setAttribute( 'timestamp', $timestamp );
		$root->setAttribute( 'xml:lang', $lang );
		$doc->appendChild( $root );

		return [ $doc, $root ];
	}

	private function el( \DOMDocument $doc, \DOMElement $parent, string $tag, ?string $text = null ): \DOMElement {
		$node = $doc->createElement( $tag );

		if ( null !== $text ) {
			$node->appendChild( $doc->createTextNode( $this->flatten( $text ) ) );
		}

		$parent->appendChild( $node );

		return $node;
	}

	/**
	 * Collapse any newlines/tabs in a text value to single spaces — D365's
	 * REPLACENEWLINE workaround flag exists because supplier documents with
	 * newlines inside element content break its handling (scope §9.6).
	 */
	private function flatten( string $text ): string {
		return trim( (string) preg_replace( '/[\r\n\t]+/', ' ', $text ) );
	}

	/**
	 * Serialise a quantity without float noise (3 not 3.0, 1.5 kept).
	 */
	private function quantity( float|int $quantity ): string {
		$as_float = (float) $quantity;

		if ( floor( $as_float ) === $as_float ) {
			return (string) (int) $as_float;
		}

		return rtrim( rtrim( sprintf( '%.4F', $as_float ), '0' ), '.' );
	}

	private function serialise( \DOMDocument $doc ): string {
		$xml = $doc->saveXML();

		if ( false === $xml ) {
			throw new \RuntimeException( 'cXML serialisation failed' );
		}

		return $xml;
	}
}
