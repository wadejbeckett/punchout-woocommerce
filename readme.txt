=== PunchOut for WooCommerce ===
Contributors: noiz
Tags: punchout, cxml, procurement, b2b, woocommerce
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.2
Stable tag: 0.1.0
License: AGPLv3 or later
License URI: https://www.gnu.org/licenses/agpl-3.0.html

Make your WooCommerce store a cXML PunchOut supplier site for enterprise procurement buyers (Microsoft Dynamics 365 first).

== Description ==

PunchOut for WooCommerce lets enterprise buyers "punch out" from their procurement system (Microsoft Dynamics 365 Finance & Operations / Supply Chain Management first; any cXML direct-punchout buyer by configuration) into your WooCommerce store, shop the catalogue at their own prices, and send the basket back into their purchasing workflow as requisition/RFQ lines.

**Multi-tenant by design.** Any number of trading partners, each configured independently: shared secret (sealed at rest), cXML identities, cXML version, cart-return encoding, optional IP allowlist, optional B2BKing company/group mapping, and a per-partner exit mode:

* **Requisition only** (default) — buyers can only send the basket back for approval; checkout is blocked inside their punchout sessions.
* **Dual exit** — the "send for approval" button renders *alongside* the completely untouched standard WooCommerce checkout, so buyers can also pay directly.

**Additive, never invasive.** The plugin adds endpoints, a role, and a cart button. It does not override, replace or filter the WooCommerce checkout or any payment gateway.

**Security first.** Constant-time secret comparison, sodium-sealed secrets (wp-config key), single-use hashed StartPage tokens, per-partner rate limiting and IP allowlists, XXE-hardened XML parsing with no runtime DTD fetches, no-store/noindex on every punchout response, a full audit trail with secrets redacted, and session teardown at every exit.

**Placement flexibility.** The RFQ exit button is available as the `[punchout_return_button]` shortcode, the `pow_return_button()` PHP helper, and automatic cart-page injection — with theme-overridable templates and filters for every string.

== Installation ==

1. Upload the `punchout-woocommerce` folder to `/wp-content/plugins/`, or install the zip via Plugins > Add New > Upload.
2. Activate the plugin. WooCommerce 8.0+ must be active.
3. (Recommended) Add a sealing key to `wp-config.php` before storing any partner secrets: run `wp punchout generate-key` and paste the line it prints.
4. Configure at **WooCommerce > PunchOut**: add a trading partner, then flip the master switch on the Settings tab.
5. Give the buyer's admin your setup URL (`https://your-store.example/punchout/setup`), your To/From identities and the generated shared secret.

== Frequently Asked Questions ==

= Which procurement systems are supported? =

Any buyer that speaks direct cXML PunchOut over HTTPS with shared-secret authentication. The parser and response envelopes are tuned for the Microsoft Dynamics 365 F&O dialect (cXML 1.2.008, optional DOCTYPE, its documented parser fragilities). Ariba-network hops, CredentialMac and digital signatures are not implemented.

= Does it change my checkout? =

No. For dual-exit partners the standard checkout is untouched; the plugin only listens (order meta tagging, session lifecycle). For requisition-only partners, checkout is blocked *inside that partner's punchout sessions only* — ordinary customers are never affected.

= Where does the returned basket go? =

To the URL the buyer's system supplies in each setup request (`BrowserFormPost`), as an auto-submitting browser form POST carrying the cXML PunchOutOrderMessage in a `cxml-base64` (or `cxml-urlencoded`) hidden field.

= Is B2BKing required? =

No. If a partner row is configured with a B2BKing company account and/or customer group and B2BKing is active, buyers are attached as subaccounts and assigned the group through B2BKing's own helper. Without B2BKing the plugin runs on plain WooCommerce.

== Changelog ==

= 0.1.0 =
* Initial release: multi-tenant partner registry, setup/start/return endpoints, ProfileRequest support, one-time login, cart-to-POOM RFQ exit, empty-POOM close-out, pay-exit session lifecycle, ALL-CAPS outbound transform, SKU map CSV import, audit log, WP-CLI tooling, unit-tested codec.
