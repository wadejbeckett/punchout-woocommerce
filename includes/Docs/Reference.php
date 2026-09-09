<?php
/**
 * The reference model behind the integration documentation page.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Docs;

use POW\Http\SetupEndpoint;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the page states about the integration that is not a sample
 * document: the endpoints, the status table, what we do and do not
 * support, the delivery-address fields and the outside references.
 *
 * The status table is derived from SetupEndpoint::STATUS_REASONS rather
 * than retyped, so a code the endpoint stops emitting disappears from the
 * page and a code it starts emitting appears there undocumented-but-listed
 * (and the suite fails until someone writes the copy).
 *
 * No WordPress calls beyond __(): the values the page needs from
 * WordPress — the site URL, our To credential, whether delivery codes are
 * switched on — are passed in by Docs\Page, which keeps this class pure
 * and unit-testable. Translation happens at call time, not at load time,
 * for the same reason.
 */
final class Reference {

	/**
	 * @param string $home_url               Site URL, no trailing slash.
	 * @param string $to_domain              Our To credential domain; read by the page.
	 * @param string $to_identity            Our To credential identity; read by the page.
	 * @param bool   $delivery_codes_enabled Whether we emit delivery codes today.
	 */
	public function __construct(
		private readonly string $home_url,
		public readonly string $to_domain,
		public readonly string $to_identity,
		private readonly bool $delivery_codes_enabled
	) {}

	/** @return array<string, string> */
	public function endpoints(): array {
		return [
			__( 'Setup (PunchOutSetupRequest, ProfileRequest)', 'punchout-woocommerce' ) => $this->home_url . '/punchout/setup',
			__( 'Start page (one-time token, opened in the browser)', 'punchout-woocommerce' ) => $this->home_url . '/punchout/start/{token}',
			__( 'Return (BrowserFormPost target is yours; this is ours)', 'punchout-woocommerce' ) => $this->home_url . '/punchout/return',
			__( 'Inbound orders (answers 450 — not implemented)', 'punchout-woocommerce' ) => $this->home_url . '/punchout/order',
		];
	}

	/**
	 * The status table, keyed and ordered by the endpoint's own constants.
	 *
	 * @return array<int, array{code: int, text: string, meaning: string, action: string}>
	 */
	public function status_rows(): array {
		$copy = [
			SetupEndpoint::STATUS_OK           => [ __( 'Accepted. The response carries the one-time StartPage URL.', 'punchout-woocommerce' ), __( 'Open the StartPage URL in the user\'s browser.', 'punchout-woocommerce' ) ],
			SetupEndpoint::STATUS_AUTH_FAILED  => [ __( 'The Sender credential was not recognised, the shared secret did not match, or the request came from an address outside the agreed allowlist. We deliberately do not say which.', 'punchout-woocommerce' ), __( 'Check the Sender domain, identity and shared secret against the values we issued; if they are right, send us the source IP address.', 'punchout-woocommerce' ) ],
			SetupEndpoint::STATUS_INVALID      => [ __( 'The document could not be parsed, was not cXML, was over 2 MB, was not sent as POST with an XML content type, declared XML entities, or was missing a required element (Header, Sender credential, Request, BuyerCookie, BrowserFormPost URL).', 'punchout-woocommerce' ), __( 'Compare the document with the annotated sample above.', 'punchout-woocommerce' ) ],
			SetupEndpoint::STATUS_DUPLICATE    => [ __( 'A different document reused a payloadID we have already seen for your connection, or the same payloadID arrived after its session had already started.', 'punchout-woocommerce' ), __( 'Issue a fresh payloadID per request. An identical retry of an unredeemed request is replayed, not rejected.', 'punchout-woocommerce' ) ],
			SetupEndpoint::STATUS_UNSUPPORTED  => [ __( 'The request type or operation is not one we service: only PunchOutSetupRequest with operation="create" and ProfileRequest are. Inbound purchase orders at /punchout/order also answer 450.', 'punchout-woocommerce' ), __( 'Send operation="create". Send purchase orders through the agreed ordering channel instead.', 'punchout-woocommerce' ) ],
			SetupEndpoint::STATUS_INTERNAL     => [ __( 'Something failed on our side.', 'punchout-woocommerce' ), __( 'Retry once, then send us the payloadID and timestamp.', 'punchout-woocommerce' ) ],
			SetupEndpoint::STATUS_RATE_LIMITED => [ __( 'Too many setup requests from one connection and address within a rolling minute.', 'punchout-woocommerce' ), __( 'Back off and retry. Tell us if you need the limit raised for a load test.', 'punchout-woocommerce' ) ],
		];

		$rows = [];

		foreach ( SetupEndpoint::STATUS_REASONS as $code => $text ) {
			$rows[ $code ] = [
				'code'    => $code,
				'text'    => $text,
				'meaning' => $copy[ $code ][0] ?? '',
				'action'  => $copy[ $code ][1] ?? '',
			];
		}

		return $rows;
	}

	/** @return list<string> */
	public function supported(): array {
		return [
			__( 'cXML 1.2.x. We echo the version your connection is configured with (1.2.008 by default).', 'punchout-woocommerce' ),
			__( 'PunchOutSetupRequest with operation="create".', 'punchout-woocommerce' ),
			__( 'ProfileRequest.', 'punchout-woocommerce' ),
			__( 'PunchOutOrderMessage returned over your BrowserFormPost URL as cxml-base64 (default) or cxml-urlencoded (per-connection switch).', 'punchout-woocommerce' ),
			__( 'Identity extrinsics UserEmail and UniqueName on the setup request.', 'punchout-woocommerce' ),
			__( 'An inbound ShipTo on the setup request, stored against the session.', 'punchout-woocommerce' ),
		];
	}

	/** @return list<string> */
	public function not_supported(): array {
		return [
			__( 'Ariba Network hops. We speak direct cXML only — no Ariba Commerce Network relay.', 'punchout-woocommerce' ),
			__( 'CredentialMac authentication. Shared secret only.', 'punchout-woocommerce' ),
			__( 'Client certificates for mutual TLS.', 'punchout-woocommerce' ),
			__( 'XML signatures on any document.', 'punchout-woocommerce' ),
			__( 'operation="edit", "inspect" and "source" — all answered 450.', 'punchout-woocommerce' ),
			__( 'Inbound OrderRequest / cXML purchase orders. /punchout/order answers 450.', 'punchout-woocommerce' ),
			__( 'Per-line ShipTo. The delivery address is header-level, mirrored per line only as the LemaDeliveryCode extrinsic.', 'punchout-woocommerce' ),
			__( 'Multi-currency. One store currency per connection.', 'punchout-woocommerce' ),
		];
	}

	/**
	 * Delivery address fields. Documented unconditionally — a buyer's
	 * developer builds the receiving mapping before we switch emission on
	 * — with delivery_codes_enabled() telling the page whether to show
	 * the "available when delivery codes are enabled" banner.
	 *
	 * @return list<array{field: string, source: string, note: string}>
	 */
	public function address_fields(): array {
		return [
			[
				'field'  => 'ShipTo',
				'source' => __( 'PunchOutOrderMessageHeader (outbound); PunchOutSetupRequest (inbound)', 'punchout-woocommerce' ),
				'note'   => __( 'Inbound: a ShipTo you send in the setup request is stored verbatim against the session and used as the delivery address when the buyer has not chosen one. Outbound: the standard cXML ShipTo, carrying the chosen delivery address.', 'punchout-woocommerce' ),
			],
			[
				'field'  => 'Address/@addressID',
				'source' => __( 'the delivery code on the chosen address', 'punchout-woocommerce' ),
				'note'   => __( 'The code your ERP maps to. Default form PREFIX-NNN (for example LEMA-001); the buyer may overwrite it, and it is unique per connection.', 'punchout-woocommerce' ),
			],
			[
				'field'  => 'Address/@addressIDDomain',
				'source' => __( 'fixed value "supplier"', 'punchout-woocommerce' ),
				'note'   => __( 'Says the identifier is ours, not yours. Map it to your own site or delivery-address code on receipt.', 'punchout-woocommerce' ),
			],
			[
				'field'  => 'Address/PostalAddress',
				'source' => __( 'the chosen delivery address', 'punchout-woocommerce' ),
				'note'   => __( 'DeliverTo, Street, City, State, PostalCode and Country@isoCountryCode, exactly as held on the account.', 'punchout-woocommerce' ),
			],
			[
				'field'  => 'ItemIn/Extrinsic name="LemaDeliveryCode"',
				'source' => __( 'the same delivery code, per line', 'punchout-woocommerce' ),
				'note'   => __( 'Mirrors the header code on every line for receivers whose line mapping is easier to reach than the header. A header-level Extrinsic is not valid cXML and is never emitted.', 'punchout-woocommerce' ),
			],
		];
	}

	/** @return array<string, string> */
	public function links(): array {
		return [
			__( 'cXML User\'s Guide (current)', 'punchout-woocommerce' )                 => 'http://xml.cxml.org/current/cXMLUsersGuide.pdf',
			__( 'Dynamics 365: set up an external catalogue for punchout', 'punchout-woocommerce' ) => 'https://learn.microsoft.com/en-us/dynamics365/supply-chain/procurement/set-up-external-catalog-for-punchout',
			__( 'Dynamics 365: purchasing cXML enhancements', 'punchout-woocommerce' )   => 'https://learn.microsoft.com/en-us/dynamics365/supply-chain/procurement/purchasing-cxml-enhancements',
		];
	}

	/**
	 * Whether we currently emit delivery codes. The value is decided by the
	 * caller (Docs\Page passes the pow_delivery_codes_enabled filter's
	 * result) and merely carried here, so this class stays WordPress-free
	 * and unit-testable.
	 */
	public function delivery_codes_enabled(): bool {
		return $this->delivery_codes_enabled;
	}
}
