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
			__( 'Delivery confirmation (buyer review; GET and nonced POST)', 'punchout-woocommerce' ) => $this->home_url . '/punchout/confirm',
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
			__( 'cXML envelopes use the connection cxml_version (1.2.008 by default). Optional postal and note fields are verified for exactly 1.2.008 and 1.2.071; additional fields below require 1.2.071. Other configured versions do not inherit those optional capabilities.', 'punchout-woocommerce' ),
			__( 'PunchOutSetupRequest with operation="create".', 'punchout-woocommerce' ),
			__( 'ProfileRequest.', 'punchout-woocommerce' ),
			__( 'PunchOutOrderMessage returned over your BrowserFormPost URL as cxml-base64 (default) or cxml-urlencoded (per-connection switch).', 'punchout-woocommerce' ),
			__( 'Identity extrinsics UserEmail and UniqueName on the setup request.', 'punchout-woocommerce' ),
			__( 'An inbound ShipTo on the setup request, stored against the session.', 'punchout-woocommerce' ),
			__( 'A company-owned delivery book in WordPress/WooCommerce, automatic separate buyer accounts, and mandatory delivery review before physical cart return.', 'punchout-woocommerce' ),
			__( 'Native WooCommerce shipping estimates, optional freight and destination export, and an optional non-payable Punchout Quote recording the accepted return.', 'punchout-woocommerce' ),
		];
	}

	/** @return list<string> */
	public function setup_template_steps(): array {
		return [
			__( 'Copy the company-specific XML into the purchasing system’s cXML setup template. Its From, Sender, To, Request deploymentMode and SupplierSetup/URL values are the static connection values issued by the supplier.', 'punchout-woocommerce' ),
			__( 'Replace only the SharedSecret placeholder with the issued company credential, using the purchasing system’s private credential field. The download never contains a stored secret.', 'punchout-woocommerce' ),
			__( 'The payloadID, timestamp, BuyerCookie and BrowserFormPost/URL fields are intentionally blank. Configure them as purchasing-system runtime values, and configure UserEmail through the separate extrinsics mapping; the downloaded XML does not duplicate that element. BrowserFormPost is not the supplier setup URL.', 'punchout-woocommerce' ),
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
			__( 'Different delivery addresses per merchandise line. One destination is confirmed for the basket.', 'punchout-woocommerce' ),
			__( 'Currencies other than ZAR. Cart confirmation currently requires the WooCommerce store and returned cart to use ZAR; currency conversion is not provided.', 'punchout-woocommerce' ),
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
				'note'   => __( 'Inbound ShipTo is session context for explicit buyer confirmation. Outbound full postal address is separately enabled and does not require an address code. Verified in cXML 1.2.008 and 1.2.071.', 'punchout-woocommerce' ),
			],
			[
				'field'  => 'Address/@addressID',
				'source' => __( 'the delivery code on the chosen address', 'punchout-woocommerce' ),
				'note'   => __( 'Optional company address identifier, for example BUYER-001. The company owner or shop administrator manages it. Issued codes are unique per company and never reassigned to another address.', 'punchout-woocommerce' ),
			],
			[
				'field'  => 'Address/@addressIDDomain',
				'source' => __( 'fixed value "supplier"', 'punchout-woocommerce' ),
				'note'   => __( 'Identifies a supplier address code. Emitted only with a usable addressID under verified cXML 1.2.071; omitted for 1.2.008 and unverified versions.', 'punchout-woocommerce' ),
			],
			[
				'field'  => 'Address/PostalAddress',
				'source' => __( 'the chosen delivery address', 'punchout-woocommerce' ),
				'note'   => __( 'DeliverTo, Street, City, State, PostalCode and Country@isoCountryCode come from the confirmed destination snapshot. They are escaped as XML text.', 'punchout-woocommerce' ),
			],
			[
				'field'  => 'ItemIn/Extrinsic name="DeliveryAddressCode"',
				'source' => __( 'the same delivery code, per line', 'punchout-woocommerce' ),
				'note'   => __( 'Separately enabled direct line metadata with a configurable name, verified only for cXML 1.2.071. Omitted for 1.2.008 and unverified versions; never moved to another element automatically.', 'punchout-woocommerce' ),
			],
			[
				'field' => 'ItemDetail/Extrinsic name="DeliveryInstructions"',
				'source' => __( 'the confirmed basket note', 'punchout-woocommerce' ),
				'note' => __( 'Local only by default. Explicit item_detail_extrinsic policy copies the note onto every merchandise line, excluding freight. Verified for 1.2.008 and 1.2.071; the receiver must agree to use this mapping.', 'punchout-woocommerce' ),
			],
			[
				'field' => 'ItemIn (freight)',
				'source' => __( 'the sum of the selected native WooCommerce package rates, excluding tax', 'punchout-woocommerce' ),
				'note' => __( 'Separately enabled quantity-one line with configured supplier ID, unit and classification, independent of address export. An unavailable rate is never represented as free delivery. No header Shipping is added.', 'punchout-woocommerce' ),
			],
		];
	}

	/** @return list<string> */
	public function delivery_workflow(): array {
		return [
			__( 'The company owner and authorised shop administrators manage Company delivery addresses on the account integration or customer administration screen. Each company has its own private book. Automatically provisioned buyers select all enabled entries from that company using their own login and cart; they do not edit the master book.', 'punchout-woocommerce' ),
			__( 'Add an address manually, or preview and explicitly copy the owner’s normal WooCommerce billing or shipping address. New entries and imports start disabled; review and enable them separately with use_for_punchout. Normal address saves do not synchronise this book. Inbound ShipTo and a buyer’s saved shipping address can be reviewed as session candidates; neither silently overwrites the company book.', 'punchout-woocommerce' ),
			__( 'Every physical cart return requires the same signed-in buyer to review and confirm the full destination, native shipping method for each package and optional notes. This applies to pickup, a single available address or method, and all export flags being off. Open /punchout/confirm from the cart control, or place [punchout_delivery_confirmation] on a page; both use the same review and protected POST route. A direct /punchout/return POST cannot bypass confirmation. Virtual-only baskets record delivery as not_required.', 'punchout-woocommerce' ),
			__( 'A valid existing native shipping choice is preserved. Otherwise WooCommerce chooses its configured native default in the current cart context; the plugin adds no cheapest-rate or pickup preference. When emit_delivery_line is enabled, require_rate refuses unavailable rates. quote_separately permits return only after the buyer acknowledges the missing estimate; neither a freight line nor a Quote shipping charge is added. With charge export off, the available estimate or unavailable state is still reviewed and stored locally. A quoted zero is a real rate; unavailable is null, never zero.', 'punchout-woocommerce' ),
			__( 'Confirmation binds the current cart, destination, rates, configuration and notes to that buyer’s session. Changing those facts requires a fresh review. A selected company entry must still exist and be enabled at confirmation and final return: removal or disablement requires reselection, and changes to its address, label or code require reconfirmation. An unrelated book revision does not invalidate an unchanged selected entry. Completed return and Quote snapshots remain unchanged by later master edits.', 'punchout-woocommerce' ),
			__( 'Notes are sanitised plain text, limited to 2,000 characters and 8,000 bytes. They default to local confirmation storage. delivery_notes_policy=item_detail_extrinsic additionally copies the basket note to DeliveryInstructions on every merchandise ItemDetail, excluding freight, for the exact supported DTDs. Agree this repetition with the receiver before enabling it.', 'punchout-woocommerce' ),
			__( 'If enabled and available, delivery is one quantity-one freight ItemIn equal to the sum of the selected native package rates. The optional winner Quote uses native shipping items per package with the same sum, once. Prices and totals use ex-tax integer cents; merchandise stays separate. With export off or an acknowledged unavailable estimate, the Quote retains delivery metadata and an estimate note without a shipping charge. Empty and paid-order closeouts add no freight.', 'punchout-woocommerce' ),
			__( 'Codes are company-scoped references, not URLs, registered receiver records or an address-list API. Codes allow A–Z, 0–9, underscore and hyphen, up to 32 characters; an optional prefix is limited to 24, and labels to 190 characters. Changed or removed issued codes retain their claims permanently. No external address-book plugin, additional credential store or directory integration is required.', 'punchout-woocommerce' ),
		];
	}

	/** Exact connection keys and defaults, displayed without exposing any connection values. @return array<string,array{default:bool|string,note:string}> */
	public function delivery_settings(): array {
		return [
			'emit_ship_to' => [ 'default' => false, 'note' => __( 'Export the full confirmed ShipTo, independently of address code and charge.', 'punchout-woocommerce' ) ],
			'emit_delivery_code' => [ 'default' => false, 'note' => __( 'Export a usable selected code in the exact-version fields below. Does not enable full ShipTo or freight.', 'punchout-woocommerce' ) ],
			'emit_delivery_line' => [ 'default' => false, 'note' => __( 'Include one available native freight total in the cart return and matching optional Quote shipping items.', 'punchout-woocommerce' ) ],
			'delivery_unknown_policy' => [ 'default' => 'require_rate', 'note' => __( 'require_rate refuses missing charge estimates; quote_separately allows acknowledged omission when charge export is enabled.', 'punchout-woocommerce' ) ],
			'delivery_notes_policy' => [ 'default' => 'off', 'note' => __( 'off keeps notes local; item_detail_extrinsic exports DeliveryInstructions on merchandise ItemDetail for the verified dialects.', 'punchout-woocommerce' ) ],
			'delivery_code_prefix' => [ 'default' => '', 'note' => __( 'Optional prefix for generated company address codes; empty leaves a new blank code uncoded.', 'punchout-woocommerce' ) ],
			'delivery_code_extrinsic_name' => [ 'default' => 'DeliveryAddressCode', 'note' => __( 'Name of the optional direct ItemIn/Extrinsic, available only for 1.2.071.', 'punchout-woocommerce' ) ],
			'freight_supplier_part_id' => [ 'default' => 'DELIVERY', 'note' => __( 'SupplierPartID of the typed freight line; agree its interpretation with the receiver.', 'punchout-woocommerce' ) ],
			'freight_uom' => [ 'default' => 'EA', 'note' => __( 'UnitOfMeasure of the quantity-one freight line.', 'punchout-woocommerce' ) ],
			'freight_classification_domain' => [ 'default' => 'supplier', 'note' => __( 'Classification domain for freight, independent of merchandise classification.', 'punchout-woocommerce' ) ],
			'freight_classification' => [ 'default' => 'freight', 'note' => __( 'Classification value for freight.', 'punchout-woocommerce' ) ],
		];
	}

	/** @return list<string> */
	public function exit_policy(): array {
		return [
			__( 'Shop administrators set exit_policy globally and per company, then may restrict an existing company buyer. The values are inherit, punchout_only and punchout_and_checkout. A company inherits the global default unless explicitly configured; global inherit resolves to punchout_only. The resulting company entitlement is the buyer’s upper bound: a buyer restriction can remove checkout, but cannot grant it when the company does not allow it.', 'punchout-woocommerce' ),
			__( 'Company owners and buyers cannot grant themselves checkout entitlement. Punchout only blocks classic, Blocks and direct payment entry points within the punchout session. Punchout and checkout permits the native WooCommerce checkout alongside the reviewed cart return. Ordinary shoppers are unaffected. A changed or unavailable entitlement is checked again before return or payment.', 'punchout-woocommerce' ),
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
