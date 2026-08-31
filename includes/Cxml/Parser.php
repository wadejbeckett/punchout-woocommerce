<?php
/**
 * Inbound cXML parser.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Cxml;

defined( 'ABSPATH' ) || exit;

/**
 * DOM-based parser for PunchOutSetupRequest / ProfileRequest documents,
 * hardened per scope §7/§9.6:
 *
 * - XXE: any internal DTD subset declaring entities is rejected outright;
 *   LIBXML_NONET forbids network fetches; LIBXML_NOENT is never set (it
 *   would SUBSTITUTE entities); the DTD in the DOCTYPE is never loaded.
 * - Validation is structural (required nodes present), never against the
 *   DTD at runtime.
 * - D365 dialect tolerances: a DOCTYPE line is NOT required; the version is
 *   taken from the root attribute or the DOCTYPE system id, whichever
 *   exists; timestamps are captured verbatim (with or without a +00:00
 *   offset — PUNCHOUTTZ); extrinsics are accepted anywhere inside the
 *   request; an empty BuyerCookie element is accepted (the Validate
 *   settings probe can send one).
 *
 * Pure PHP + ext-dom; no WordPress dependencies.
 */
final class Parser {

	/**
	 * Parse an inbound document.
	 *
	 * @param string $xml Raw request body (already size-capped upstream).
	 * @throws ParseException With cxml_status 406 (invalid) or 450 (unsupported).
	 */
	public function parse( string $xml ): SetupMessage {
		$xml = trim( $xml );

		if ( '' === $xml ) {
			throw new ParseException( 'Empty request body', 406 );
		}

		// XXE hard line: no entity declarations, full stop. cXML documents
		// have no legitimate use for an internal DTD subset.
		if ( false !== stripos( $xml, '<!ENTITY' ) ) {
			throw new ParseException( 'Entity declarations are not accepted', 406 );
		}

		$previous = libxml_use_internal_errors( true );
		$doc      = new \DOMDocument();
		$loaded   = $doc->loadXML( $xml, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( false === $loaded || null === $doc->documentElement ) {
			throw new ParseException( 'Unparseable XML', 406 );
		}

		$root = $doc->documentElement;

		if ( 'cXML' !== $root->tagName ) {
			throw new ParseException( 'Root element is not cXML', 406 );
		}

		$version = $root->getAttribute( 'version' );

		if ( '' === $version && null !== $doc->doctype && '' !== (string) $doc->doctype->systemId ) {
			if ( preg_match( '#/cXML/(\d+\.\d+\.\d+)/#', (string) $doc->doctype->systemId, $m ) ) {
				$version = $m[1];
			}
		}

		$header = $this->child( $root, 'Header' );

		if ( null === $header ) {
			throw new ParseException( 'Missing Header', 406 );
		}

		[ $from_domain, $from_identity ]                    = $this->credential( $header, 'From' );
		[ $to_domain, $to_identity ]                        = $this->credential( $header, 'To' );
		[ $sender_domain, $sender_identity, $shared_secret ] = $this->sender_credential( $header );

		if ( '' === $sender_identity ) {
			throw new ParseException( 'Missing Sender credential', 406 );
		}

		$request = $this->child( $root, 'Request' );

		if ( null === $request ) {
			throw new ParseException( 'Missing Request element', 406 );
		}

		$common = [
			'payload_id'      => $root->getAttribute( 'payloadID' ),
			'timestamp'       => $root->getAttribute( 'timestamp' ),
			'version'         => $version,
			'lang'            => $root->getAttribute( 'xml:lang' ) ?: 'en-US',
			'deployment_mode' => $request->getAttribute( 'deploymentMode' ) ?: 'production',
			'from_domain'     => $from_domain,
			'from_identity'   => $from_identity,
			'to_domain'       => $to_domain,
			'to_identity'     => $to_identity,
			'sender_domain'   => $sender_domain,
			'sender_identity' => $sender_identity,
			'shared_secret'   => $shared_secret,
			'user_agent'      => $this->text( $header, 'Sender', 'UserAgent' ),
		];

		if ( null !== $this->child( $request, 'ProfileRequest' ) ) {
			return new SetupMessage( ...$common, kind: SetupMessage::KIND_PROFILE );
		}

		$setup = $this->child( $request, 'PunchOutSetupRequest' );

		if ( null === $setup ) {
			$name = 'unknown';

			foreach ( $request->childNodes as $node ) {
				if ( $node instanceof \DOMElement ) {
					$name = $node->tagName;
					break;
				}
			}

			throw new ParseException( "Unsupported request: {$name}", 450 );
		}

		$buyer_cookie = $this->child( $setup, 'BuyerCookie' );

		if ( null === $buyer_cookie ) {
			throw new ParseException( 'Missing BuyerCookie', 406 );
		}

		$browser_form_post = $this->text( $setup, 'BrowserFormPost', 'URL' );

		if ( '' === $browser_form_post ) {
			throw new ParseException( 'Missing BrowserFormPost URL', 406 );
		}

		$extrinsics = [];

		foreach ( $setup->getElementsByTagName( 'Extrinsic' ) as $node ) {
			$name = $node->getAttribute( 'name' );

			if ( '' !== $name ) {
				$extrinsics[ $name ] = trim( $node->textContent );
			}
		}

		$contact_email = null;
		$contact       = $this->child( $setup, 'Contact' );

		if ( null !== $contact ) {
			$email = $this->child( $contact, 'Email' );

			if ( null !== $email && '' !== trim( $email->textContent ) ) {
				$contact_email = trim( $email->textContent );
			}
		}

		$ship_to_xml = null;
		$ship_to     = $this->child( $setup, 'ShipTo' );

		if ( null !== $ship_to ) {
			$ship_to_xml = $doc->saveXML( $ship_to ) ?: null;
		}

		$selected_item = null;
		$selected      = $this->child( $setup, 'SelectedItem' );

		if ( null !== $selected ) {
			$item_id = $this->child( $selected, 'ItemID' );

			if ( null !== $item_id ) {
				$selected_item = [
					'supplier_part_id' => $this->text( $item_id, 'SupplierPartID' ),
					'aux_id'           => $this->text( $item_id, 'SupplierPartAuxiliaryID' ),
				];
			}
		}

		$item_out = [];

		foreach ( $setup->childNodes as $node ) {
			if ( ! $node instanceof \DOMElement || 'ItemOut' !== $node->tagName ) {
				continue;
			}

			$item_id = $this->child( $node, 'ItemID' );

			$item_out[] = [
				'quantity'         => $node->getAttribute( 'quantity' ),
				'supplier_part_id' => null !== $item_id ? $this->text( $item_id, 'SupplierPartID' ) : '',
				'aux_id'           => null !== $item_id ? $this->text( $item_id, 'SupplierPartAuxiliaryID' ) : '',
			];
		}

		return new SetupMessage(
			...$common,
			kind: SetupMessage::KIND_SETUP,
			operation: $setup->getAttribute( 'operation' ) ?: 'create',
			buyer_cookie: trim( $buyer_cookie->textContent ),
			browser_form_post: $browser_form_post,
			extrinsics: $extrinsics,
			contact_email: $contact_email,
			ship_to_xml: $ship_to_xml,
			selected_item: $selected_item,
			item_out: $item_out,
		);
	}

	/**
	 * First direct child element with the given tag name, or null.
	 */
	private function child( \DOMElement $parent, string $tag ): ?\DOMElement {
		foreach ( $parent->childNodes as $node ) {
			if ( $node instanceof \DOMElement && $node->tagName === $tag ) {
				return $node;
			}
		}

		return null;
	}

	/**
	 * Trimmed text content of a nested child path, or '' when absent.
	 */
	private function text( \DOMElement $parent, string ...$path ): string {
		$node = $parent;

		foreach ( $path as $tag ) {
			$node = $this->child( $node, $tag );

			if ( null === $node ) {
				return '';
			}
		}

		return trim( $node->textContent );
	}

	/**
	 * Domain + identity from a From/To credential.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function credential( \DOMElement $header, string $party ): array {
		$node = $this->child( $header, $party );

		if ( null === $node ) {
			return [ '', '' ];
		}

		$credential = $this->child( $node, 'Credential' );

		if ( null === $credential ) {
			return [ '', '' ];
		}

		return [
			$credential->getAttribute( 'domain' ),
			$this->text( $credential, 'Identity' ),
		];
	}

	/**
	 * Domain + identity + shared secret from the Sender credential.
	 *
	 * @return array{0: string, 1: string, 2: ?string}
	 */
	private function sender_credential( \DOMElement $header ): array {
		$sender = $this->child( $header, 'Sender' );

		if ( null === $sender ) {
			return [ '', '', null ];
		}

		$credential = $this->child( $sender, 'Credential' );

		if ( null === $credential ) {
			return [ '', '', null ];
		}

		$secret_node = $this->child( $credential, 'SharedSecret' );

		return [
			$credential->getAttribute( 'domain' ),
			$this->text( $credential, 'Identity' ),
			null !== $secret_node ? $secret_node->textContent : null,
		];
	}
}
