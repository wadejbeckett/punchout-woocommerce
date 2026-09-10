# Protocol reference

The plugin implements selected direct cXML messages, not every cXML transaction. Use the [cXML Reference Guide](https://xml.cxml.org/current/cXMLReferenceGuide.pdf) for message definitions and the DTD matching the connection's declared version for syntax.

## Exchange and runtime values

| Stage | Current behavior |
|---|---|
| POST `/punchout/setup` | Receives ProfileRequest or create PunchOutSetupRequest; authenticates the company. |
| Setup response | Supplies a one-time StartPage URL. Opening it creates the buyer's authenticated shopping session. |
| Cart return | Browser posts PunchOutOrderMessage to the initiating request's BrowserFormPost URL. |
| POST `/punchout/order` | Unsupported; responds with cXML 450, not purchase-order acceptance. |

BuyerCookie and BrowserFormPost are transaction context supplied by the purchasing system. Do not substitute a destination chosen at transfer time. Return encoding is configured per connection: `cxml-base64` or `cxml-urlencoded`. Their transport encoding does not encrypt the document; deploy HTTPS throughout. The plugin requires HTTPS requests and HTTPS BrowserFormPost targets in production and staging, including when cXML deploymentMode is `test`. Only WordPress environments explicitly configured as `local` or `development` allow HTTP. Saved return targets are revalidated before handoff and are never silently rewritten. See [transport and deployment](transport-security.md).

Setup uses HTTP 200 for its cXML response envelope. Inspect the embedded status: 200 success, 401 authentication, 406 invalid document, 409 duplicate request, 450 unsupported, 500 internal failure, 550 rate limit. An edge-server HTTP failure can occur before this handler.

## Current line mapping

The [mapper](../includes/Cart/PoomMapper.php) emits Woo SKU, quantity, product name, product/variation correlation and discounted ex-tax unit price. Missing SKU skips a line and produces a notice. Unit is currently `EA`; classification comes from the global default, which may be blank. Agree usable units/classification with buyer IT. There is no built-in manufacturer mapping or per-product UOM editor.

Currency comes from Woo settings; arithmetic assumes two-decimal cents. Multi-currency conversion is not implemented. Uppercase output is an optional connection setting, not a protocol requirement.

## DTDs and provenance

A DTD specifies allowed structure, ordering and attributes. It is useful for offline test validation, not a separate integration service. Runtime uses DOM/libxml structural parsing with network fetching disabled; the bundled DTD is a reference, not a runtime network dependency.

The current [Builder](../includes/Cxml/Builder.php) emits `Description/ShortName`. Preserve the exact declared DTD when investigating the Microsoft attribute discrepancy. Do not validate a 1.2.008-labelled sample against 1.2.071 and call that proof of 1.2.008 support. Optional-field corrections remain development work.

The upstream DTD has separate [cXML licence terms](https://www.cxml.org/license.html), which require the licence to accompany distributed specification material. **Packaging work remains:** include the applicable full licence and verify that the distribution carries it. The embedded DTD licence URL alone does not establish that this requirement is satisfied. The DTD is not AGPL-authored plugin code; the plugin's own [AGPL licence](../LICENSE) remains unchanged.
