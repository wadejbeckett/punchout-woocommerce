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
 * WordPress — the site URL, our To credential and a legacy display hint —
 * are passed in by Docs\Page, which keeps this class pure
 * and unit-testable. Translation happens at call time, not at load time,
 * for the same reason.
 */
final class Reference {

	/**
	 * @param string $home_url               Site URL, no trailing slash.
	 * @param string $to_domain              Our To credential domain; read by the page.
	 * @param string $to_identity            Our To credential identity; read by the page.
	 * @param bool   $delivery_codes_enabled Legacy custom-template display hint; not a connection policy.
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
			__( 'Handoff (the page that posts the placed order to your BrowserFormPost; ours, served once)', 'punchout-woocommerce' ) => $this->home_url . '/punchout/handoff',
			__( 'Cancel and return (the empty PunchOutOrderMessage; BrowserFormPost target is yours, this is ours)', 'punchout-woocommerce' ) => $this->home_url . '/punchout/return',
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
			SetupEndpoint::STATUS_INTERNAL     => [ __( 'Something failed on our side, or your connection is not finished being configured here: no store account is bound to it yet, or too many of your shopping visits are still open.', 'punchout-woocommerce' ), __( 'Retry once. If it repeats it is a configuration fault on our side and retrying will not clear it — contact the store with the payloadID and timestamp.', 'punchout-woocommerce' ) ],
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
			__( 'cXML envelopes use the connection cxml_version (1.2.008 by default). Optional postal and note fields are verified for exactly 1.2.008 and 1.2.071; additional fields below require 1.2.071. Other configured versions do not inherit those optional capabilities.', 'punchout-woocommerce' ),
			__( 'PunchOutSetupRequest with operation="create".', 'punchout-woocommerce' ),
			__( 'ProfileRequest.', 'punchout-woocommerce' ),
			__( 'PunchOutOrderMessage returned over your BrowserFormPost URL as cxml-base64 (default) or cxml-urlencoded (per-connection switch).', 'punchout-woocommerce' ),
			__( 'Identity extrinsics on the setup request: UserEmail, UniqueUsername, UniqueName or Contact/Email name the buyer, and UserPrintableName, UserFullName or User supply their display name. They are read as attribution and to separate one buyer\'s basket from another\'s; they never create or select a store account.', 'punchout-woocommerce' ),
			__( 'An inbound ShipTo on the setup request, stored against the visit.', 'punchout-woocommerce' ),
			__( 'A company-owned delivery book in WordPress/WooCommerce, one store account per connection with a separate basket and delivery selection per punchout visit, and mandatory delivery review before physical cart return.', 'punchout-woocommerce' ),
			__( 'Native WooCommerce shipping estimates, optional freight and destination export, and an optional non-payable Punchout Quote recording the accepted return.', 'punchout-woocommerce' ),
		];
	}

	/** @return list<string> */
	public function setup_template_steps(): array {
		return [
			__( 'Copy the company-specific XML into the purchasing system’s cXML setup template. Its From, Sender, To, Request deploymentMode and SupplierSetup/URL values are the static connection values issued by the supplier.', 'punchout-woocommerce' ),
			__( 'Replace only the SharedSecret placeholder, inside the pasted cXML text, with the issued company credential. Dynamics 365 has no separate credential field for PunchOut; do not put the secret in the order-properties SHARED_SECRET row. The download never contains a stored secret.', 'punchout-woocommerce' ),
			__( 'The payloadID, timestamp, BuyerCookie and BrowserFormPost/URL fields are intentionally blank. Leave them blank: the purchasing system fills these runtime values itself when a user punches out, and Dynamics 365 has no setting for them. Configure UserEmail through the separate extrinsics mapping; the downloaded XML does not duplicate that element. BrowserFormPost is not the supplier setup URL.', 'punchout-woocommerce' ),
			__( 'Validate the catalogue configuration, then run a real launch and cart return with buyer IT. A valid template or supplier self-test does not prove receiver acceptance.', 'punchout-woocommerce' ),
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
			__( 'Different delivery addresses per merchandise line. One ShipTo, the order’s shipping address, is sent for the basket.', 'punchout-woocommerce' ),
			__( 'Currency conversion. The returned cart is priced in the store’s currency; a cart priced in another currency is refused at checkout.', 'punchout-woocommerce' ),
		];
	}

	/**
	 * Delivery address fields, documented independently of any connection's flags.
	 *
	 * @return list<array{field: string, source: string, note: string}>
	 */
	public function address_fields(): array {
		return [
			[
				'field'  => 'ShipTo',
				'source' => __( 'PunchOutOrderMessageHeader (outbound); PunchOutSetupRequest (inbound)', 'punchout-woocommerce' ),
				'note'   => __( 'Inbound ShipTo is recorded on the visit. Outbound, the order’s shipping address (the saved address the buyer chose at checkout, or the one-off address typed there) is sent as the full postal address while the connection sends ShipTo; it does not require an address code. Verified in cXML 1.2.008 and 1.2.071.', 'punchout-woocommerce' ),
			],
			[
				'field'  => 'Address/@addressID',
				'source' => __( 'the delivery code on the chosen address (orders returned before 0.5.0)', 'punchout-woocommerce' ),
				'note'   => __( 'Optional company address identifier, for example BUYER-001. A shop administrator manages it. Issued codes are unique per company and never reassigned to another address.', 'punchout-woocommerce' ),
			],
			[
				'field'  => 'Address/@addressIDDomain',
				'source' => __( 'fixed value "supplier"', 'punchout-woocommerce' ),
				'note'   => __( 'Identifies a supplier address code. Emitted only with a usable addressID under verified cXML 1.2.071; omitted for 1.2.008 and unverified versions.', 'punchout-woocommerce' ),
			],
			[
				'field'  => 'Address/PostalAddress',
				'source' => __( 'the chosen delivery address', 'punchout-woocommerce' ),
				'note'   => __( 'DeliverTo, Street, City, State, PostalCode and Country@isoCountryCode come from the order’s shipping address. They are escaped as XML text.', 'punchout-woocommerce' ),
			],
			[
				'field'  => 'ItemIn/Extrinsic name="DeliveryAddressCode"',
				'source' => __( 'the same delivery code, per line', 'punchout-woocommerce' ),
				'note'   => __( 'Separately enabled direct line metadata with a configurable name, verified only for cXML 1.2.071. Omitted for 1.2.008 and unverified versions; never moved to another element automatically.', 'punchout-woocommerce' ),
			],
			[
				'field' => 'ItemDetail/Extrinsic name="DeliveryInstructions"',
				'source' => __( 'the order note typed at checkout, then the connection’s “Order details sent”', 'punchout-woocommerce' ),
				'note' => __( 'Local only by default. Explicit item_detail_extrinsic policy copies the note onto every merchandise line, excluding freight. Verified for 1.2.008 and 1.2.071; the receiver must agree to use this mapping.', 'punchout-woocommerce' ),
			],
			[
				'field' => 'ItemIn (freight)',
				'source' => __( 'the order’s WooCommerce shipping total, excluding tax', 'punchout-woocommerce' ),
				'note' => __( 'Sent only while the connection sends delivery cost: a quantity-one line with configured supplier ID, unit and classification, named after the shipping method with the estimate sentence, independent of address export. Otherwise the shipping line stays on the store’s order and the Total is the merchandise. No header Shipping is added.', 'punchout-woocommerce' ),
			],
		];
	}

	/** @return list<string> */
	public function delivery_workflow(): array {
		return [
			__( 'A visit shops the ordinary WooCommerce cart and leaves through the shop’s classic checkout, where PunchOut is the only payment method offered. Its Submit builds the PunchOutOrderMessage from the placed order (its lines, its shipping address, its note) and posts it to the visit’s BrowserFormPost from the handoff page. The order stays on the store as a Punchout Quote; it is never paid here, and the plugin adds no delivery or tax wording of its own.', 'punchout-woocommerce' ),
			__( 'Checkout refuses, before any order exists, a line without a SKU, a cart priced in another currency than the store’s, and a note cXML cannot carry. A Submit after the visit has returned is sent to the Sent state and posts nothing again. The cancel control (Cancel and return) posts the empty message instead.', 'punchout-woocommerce' ),
			__( 'During a visit checkout saves nothing to the bound account: not its billing or shipping details, and not an address-book plugin’s entries. Billing opens on the account’s saved billing profile; the buyer chooses one of the account’s saved shipping addresses, or types a one-off address that stays on that order. Shop administrators keep those saved addresses on the account. The connection’s company delivery book (earlier releases’ address list) remains an administrator surface on the customer administration screen and the source of the wp punchout migrate-addresses command; no punchout visit reads it.', 'punchout-woocommerce' ),
			__( 'ShipTo is the order’s shipping address, sent as the full postal address while the connection sends ShipTo. DeliveryInstructions carries the order note typed at checkout, then each of the connection’s “Order details sent” with a value, as “label: value”, joined by “ | ”, within 2,000 characters; only the note is shortened. It travels on every merchandise ItemDetail while delivery_notes_policy is item_detail_extrinsic. Agree this repetition with the receiver before enabling it.', 'punchout-woocommerce' ),
			__( 'Delivery cost is WooCommerce’s: the order keeps its normal shipping line, the store’s own estimate. Only while the connection sends delivery cost does the message add one quantity-one freight ItemIn, the order’s shipping total before tax, named after the shipping method with the estimate sentence; otherwise the Total is the merchandise and no freight is sent. An empty close-out adds no freight.', 'punchout-woocommerce' ),
			__( 'Codes are company-scoped references, not URLs, registered receiver records or an address-list API. Codes allow A–Z, 0–9, underscore and hyphen, up to 32 characters; an optional prefix is limited to 24, and labels to 190 characters. Changed or removed issued codes retain their claims permanently. Orders returned before 0.5.0 may carry a code; the order’s cXML sends none.', 'punchout-woocommerce' ),
		];
	}

	/** Exact connection keys and defaults, displayed without exposing any connection values. @return array<string,array{default:bool|string,note:string}> */
	public function delivery_settings(): array {
		return [
			'emit_ship_to' => [ 'default' => false, 'note' => __( 'Send ShipTo: export the order’s shipping address as the full postal ShipTo, independently of address code and charge.', 'punchout-woocommerce' ) ],
			'emit_delivery_code' => [ 'default' => false, 'note' => __( 'Export a usable delivery code in the exact-version fields below (orders returned before 0.5.0 only; the order’s cXML sends none). Does not enable full ShipTo or freight.', 'punchout-woocommerce' ) ],
			'emit_delivery_line' => [ 'default' => false, 'note' => __( 'Send delivery cost: include one freight line, the order’s shipping total before tax, with the estimate sentence. Off, the Total is the merchandise.', 'punchout-woocommerce' ) ],
			'delivery_unknown_policy' => [ 'default' => 'require_rate', 'note' => __( 'Retired with the review page (0.5.0): WooCommerce’s checkout decides the shipping method. Kept on the row for orders returned before 0.5.0.', 'punchout-woocommerce' ) ],
			'delivery_notes_policy' => [ 'default' => 'off', 'note' => __( 'off keeps notes local; item_detail_extrinsic exports DeliveryInstructions on merchandise ItemDetail for the verified dialects.', 'punchout-woocommerce' ) ],
			'delivery_code_prefix' => [ 'default' => '', 'note' => __( 'Optional prefix for generated company address codes; empty leaves a new blank code uncoded.', 'punchout-woocommerce' ) ],
			'delivery_code_extrinsic_name' => [ 'default' => 'DeliveryAddressCode', 'note' => __( 'Name of the optional direct ItemIn/Extrinsic, available only for 1.2.071.', 'punchout-woocommerce' ) ],
			'freight_supplier_part_id' => [ 'default' => 'DELIVERY', 'note' => __( 'SupplierPartID of the typed freight line; agree its interpretation with the receiver.', 'punchout-woocommerce' ) ],
			'freight_uom' => [ 'default' => 'EA', 'note' => __( 'UnitOfMeasure of the quantity-one freight line.', 'punchout-woocommerce' ) ],
			'freight_classification_domain' => [ 'default' => 'supplier', 'note' => __( 'Classification domain for freight, independent of merchandise classification.', 'punchout-woocommerce' ) ],
			'freight_classification' => [ 'default' => 'freight', 'note' => __( 'Classification value for freight.', 'punchout-woocommerce' ) ],
			'line_description' => [ 'default' => 'name', 'note' => __( 'name: each line’s Description is the product name. name_short_description: the name, then the product’s short description as plain text (tags stripped, entities decoded, whitespace collapsed, left out when it only repeats the name), the whole at most 2,000 characters. ShortName is always the name. Applies to the cXML built from a WooCommerce order.', 'punchout-woocommerce' ) ],
			'order_details' => [ 'default' => '', 'note' => __( 'Order details sent: up to 10 order meta keys, each with a label and a format (text, or unix_date for a Unix timestamp sent as its UTC calendar day, YYYY-MM-DD). Each one with a value follows the buyer’s note in DeliveryInstructions as “label: value”, the parts joined by “ | ”, while delivery_notes_policy is item_detail_extrinsic.', 'punchout-woocommerce' ) ],
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
	 * Legacy custom-template display hint. Actual connection emission uses
	 * emit_ship_to, emit_delivery_code and emit_delivery_line independently.
	 */
	public function delivery_codes_enabled(): bool {
		return $this->delivery_codes_enabled;
	}
}
