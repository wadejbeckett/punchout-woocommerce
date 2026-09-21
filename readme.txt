=== PunchOut for WooCommerce ===
Contributors: noiz
Tags: punchout, cxml, procurement, b2b, woocommerce
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.4.2
License: AGPLv3 or later
License URI: https://www.gnu.org/licenses/agpl-3.0.html

Add cXML PunchOut to WooCommerce for enterprise procurement buyers (Microsoft Dynamics 365 F&O/SCM first).

== Description ==

PunchOut for WooCommerce lets enterprise buyers "punch out" from their procurement system (Microsoft Dynamics 365 Finance & Operations / Supply Chain Management first; any cXML direct-punchout buyer by configuration) into your WooCommerce store, shop the catalogue at their own prices, and send the cart back into their purchasing workflow as requisition/RFQ lines.

**Multi-tenant by design.** Any number of customers, each connection configured independently: shared secret (sealed at rest), cXML identities, cXML version, cart-return encoding and an optional IP allowlist.

**One store account per customer.** Each connection is bound to one WooCommerce customer account, which you create and group yourself, once; buyers punch in as that account and see exactly what it sees, at whatever prices and visibility you gave it. Every punchout visit still gets its own basket, delivery selection and quote order, and the buyer name and e-mail their purchasing system sends are recorded on the visit and stamped on the quote.

**PunchOut only.** Buyers send the cart back for approval; checkout is blocked inside a punchout visit and nowhere else. Ordinary shoppers, including anyone who signs into the bound account with a password, are never affected.

**Additive, never invasive.** The plugin adds endpoints and a cart button. It never creates, renames or deletes users, and it does not override, replace or filter the WooCommerce checkout or any payment gateway.

**Security first.** Constant-time secret comparison, sodium-sealed secrets (wp-config key), single-use hashed StartPage tokens, per-customer rate limiting and IP allowlists, XXE-hardened XML parsing with no runtime DTD fetches, no-store/noindex on every punchout response, a full audit trail with secrets redacted, and session teardown at every exit.

**One visit, one basket.** Every punchout visit gets its own WordPress auth cookie, its own session token and its own WooCommerce session row, so two employees of one customer shopping at the same moment never see each other's basket or delivery selection. Inside a visit the request is also refused at wp-admin, the users REST routes, application passwords, the account-details and password screens, another visit's basket and another visit's quote order.

**Placement flexibility.** The RFQ exit button is available as the `[punchout_return_button]` shortcode, the `pow_return_button()` PHP helper, and automatic cart-page injection — with theme-overridable templates and filters for every string.

== Installation ==

1. Upload the `punchout-woocommerce` folder to `/wp-content/plugins/`, or install the zip via Plugins > Add New > Upload.
2. Activate the plugin. WooCommerce 11.1+ must be active: a punchout visit gets its own WooCommerce session row by standing on session-handler internals this release was verified against on 11.1, and it refuses the visit rather than guess on an older one.
3. (Recommended) Add a sealing key to `wp-config.php` before storing any customer secrets: run `wp punchout generate-key` and paste the line it prints.
4. Configure at **WooCommerce > PunchOut**: add a customer.
5. Create (or pick) the one WooCommerce customer account that customer's buyers will shop as, put it in whatever pricing or visibility group it should have, and bind it to the connection. A connection with no bound account refuses `PunchOutSetupRequest` with cXML Status 500.
6. Flip the master switch on the Settings tab.
7. Give the buyer's admin your setup URL (`https://your-store.example/punchout/setup`), your To/From identities and the generated shared secret.

== Frequently Asked Questions ==

= Which procurement systems are supported? =

Any buyer that speaks direct cXML PunchOut over HTTPS with shared-secret authentication. The parser and response envelopes are tuned for the Microsoft Dynamics 365 F&O dialect (cXML 1.2.008, optional DOCTYPE, its documented parser fragilities). Ariba-network hops, CredentialMac and digital signatures are not implemented.

= Does it change my checkout? =

No. Checkout is blocked inside a punchout visit only — ordinary shoppers, including the bound account when someone signs into it with a password, are never affected. The plugin does not override, replace or filter the checkout or any payment gateway.

= Where does the returned cart go? =

To the URL the buyer's system supplies in each setup request (`BrowserFormPost`), as an auto-submitting browser form POST carrying the cXML PunchOutOrderMessage in a `cxml-base64` (or `cxml-urlencoded`) hidden field.

= How many store accounts do I need per customer? =

One. You create one ordinary WooCommerce customer account per connection, group and price it yourself once, and bind it on the connection screen. Every buyer at that customer punches in as that account through a single-use StartPage link and sees exactly what it sees. The plugin never creates, renames or deletes users. Each punchout visit still gets its own basket, delivery selection and quote order, and the buyer's name and e-mail from the cXML request are recorded on the visit and stamped on the quote.

== Changelog ==

= 0.4.2 =
* Fix: inside a punchout visit every link to the checkout page is hidden, including buttons a page builder or theme placed in the cart layout or mini-cart outside any hook. Checkout itself was already refused.

= 0.4.1 =
* Fix: inside a punchout visit the cart page and the mini-cart show no checkout button, whichever theme or plugin rendered it; the return control is the only exit. Checkout itself was already refused; this removes the button.

= 0.4.0 =
* Breaking: a connection is now bound to one WooCommerce customer account that you create and group yourself. Buyers punch in as that account and see exactly what it sees. The plugin no longer creates, renames or deletes any user. A connection with no bound account refuses PunchOutSetupRequest with cXML Status 500, writes an audit row and raises a persistent admin notice.
* Breaking: automatic buyer provisioning, the punchout_buyer role, ephemeral buyer identities, the identity mutex, latest-punchout-wins per account and the dormant-buyer cron with its "Buyer inactivity" setting are removed. Uninstall no longer removes the role, so a site upgraded from an earlier line keeps a stray role definition and any account still holding it keeps its capabilities.
* Breaking: the pow_buyer_provisioned and pow_buyer_deactivated hooks are removed. Assign the bound account's group and pricing by hand, once, in whatever B2B or pricing plugin you use; no site glue is needed or supported.
* Breaking: the site-glue filters pow_buyer_identity, pow_route_guard, pow_product_in_range, pow_quote_shipping_address, pow_poom_unit_price_cents, pow_poom_lines, pow_closeout_copy and pow_template_closeout-button are removed. A theme override of templates/docs/page.php or templates/account/integration.php copied from an earlier release must be re-copied: the delivery-exit accessor and the secret and delivery-address view variables they used are gone.
* Breaking: the "PunchOut and checkout" (dual exit) mode, the pay-exit path, the per-buyer exit policy and the global/company/buyer exit hierarchy are removed. PunchOut is the only exit; checkout is blocked inside a punchout visit.
* Breaking: the front-end connection application and every My Account action are removed. Connections, credentials and the delivery book are managed at WooCommerce > PunchOut. My Account > Punchout integration is now a read-only setup-XML download for the account holder, unreachable during a punchout visit.
* New: one WooCommerce session row per punchout visit, keyed per visit, so two employees of one customer shopping at the same moment keep separate baskets and separate delivery selections on the one account. A new visit by the same buyer identity supersedes that buyer's own earlier visit and nobody else's, and open visits per connection are capped.
* New: the buyer's name and e-mail from the cXML request are recorded on the visit and stamped on the Punchout Quote as _pow_buyer_identity and _pow_buyer_name, as an order note and as a "Bought by" line on the order screen. Orders stay owned by the bound account. No e-mail is ever sent to a buyer.
* Security: inside a punchout visit the request cannot reach wp-admin, /wp/v2/users, application passwords, account details or password change, another visit's basket, or another visit's quote order. Every "is this mine" check compares the per-visit key, never the account.
* Change: the bound account is an ordinary customer account, so it keeps its own password login and password reset. A stolen password for it is a full store login for that account outside any visit, and changing or resetting its password invalidates every outstanding cookie and therefore ends every live punchout visit of that customer mid-basket. Treat the account as a shared credential and keep it out of the hands of people who should not shop as the customer.
* Change: that account may now check out normally when no punchout visit is live. Earlier releases blocked ordinary checkout for a requisition-only company's own account; only visits are blocked now.
* Breaking: WooCommerce 11.1 or newer is required. One basket per punchout visit is built on WooCommerce's own session handler internals, and this release was verified against 11.1; on a store whose handler is shaped differently every punchout visit is refused with HTTP 409 instead of quietly sharing a basket. Ordinary shopping is untouched either way.
* Upgrade: no migration tooling ships. Upgrading expires every open punchout session and destroys its login; existing punchout buyer users are left alone and are no longer used. Bind each connection to its customer account by hand afterwards, and expect the first punch-in to refuse with cXML Status 500 until you do: an unbound connection refuses every setup request until its account is chosen.
* Upgrade: the buyer accounts earlier releases created are now ordinary WordPress users. The password-login and password-reset denials that used to cover them are gone with the provisioning they belonged to, so each of those accounts can sign in and reset its own password like any customer. Delete the ones you do not want — this plugin never deletes a user — and sweep the _pow_identity, _pow_ephemeral, _pow_deactivated and _pow_last_seen user meta they leave behind.

= 0.3.0 =
* Note: lema.co.za runs commit 3196586 of this line, deployed 2026-09-15 with the header still labelled 0.2.4; the header now matches the changelog.
* Fix: a Punchout buyer's cart quantity change could be lost silently when another request saved the same session first. The guarded save now reloads the cart, replays only the buyer's change and retries once; any final refusal is logged at warning level with a hashed session key instead of being dropped without trace.
* Fix: refusal log entries name the throwable behind an "exception" refusal, and a request refused at initialisation no longer logs a spurious refusal at shutdown.
* Fix: an active connection saved with only its name and Sender credential no longer breaks the Integration docs page for administrators; its row explains which identities are still missing.
* Fix: a company owner whose connection is not yet fully configured no longer sees a download button that answers "reload and try again"; My Account explains what the store still has to complete, and the download itself says so instead of reporting an outage.
* Fix: the setup-template instructions no longer refer to a Dynamics 365 "private credential field" or runtime settings that do not exist; the secret goes inside the pasted cXML text and the blank runtime fields are filled by Dynamics itself.
* New: every cart return now leaves a WooCommerce order behind, in its own "Punchout Quote" status — the lines, quantities and ex-tax prices the buyer was quoted, the delivery address, and the returned cXML basket stored on the order. No stock movement, no customer email, excluded from Analytics revenue. An order-screen action converts a quote to a real order (Pending payment, Processing or On hold; settings), and the hourly housekeeping job cancels — never deletes — quotes nobody converted within the retention window. Both are configured at WooCommerce > PunchOut > Settings.
* New: the integration documentation page. Add [punchout_docs] to a page and send buyers the URL — endpoints, identities, annotated request and response samples generated by the live codec, the full status-code table, delivery-address fields, rate limits and secret rotation, plus a self-test that answers a pasted setup request with the cXML Status the live endpoint would return. Store administrators see a complete setup template per active connection; company owners download their own template with an HTTPS supplier URL, blank runtime fields and no stored secret from My Account.
* New: a company-owned delivery book managed by the owner and authorised shop administrators, with explicit preview/copy of normal WooCommerce billing or shipping addresses into disabled entries. Company buyers select enabled addresses using separate logins and carts; no third-party address-book plugin or address-list API is required.
* New: mandatory same-buyer destination, native shipping method and notes review before physical cart return, through /punchout/confirm and [punchout_delivery_confirmation]. Selected-entry and cart/rate/policy changes require fresh review; completed return and Quote snapshots remain unchanged.
* New: independent emit_ship_to, emit_delivery_code and emit_delivery_line settings, off by default. Native shipping preserves an offered buyer choice or uses WooCommerce's configured default. require_rate refuses unavailable charge estimates; quote_separately allows explicitly acknowledged omission. Known zero remains distinct from unavailable.
* New: one optional quantity-one freight ItemIn equals the native package shipping sum, with matching shipping items in the optional winner Quote exactly once. Destination and notes stay recorded locally when export is off; notes can additionally use delivery_notes_policy=item_detail_extrinsic on merchandise lines. Generated examples use the real codec and exact offline 1.2.008/1.2.071 DTDs; receiver consumption requires separate verification.
* New: global/company/buyer exit hierarchy using inherit, punchout_only and punchout_and_checkout. Shop administrators control entitlement; buyer restrictions cannot grant checkout above the company permission, and ordinary shopping is unaffected.

= 0.2.4 =
* New: the two exit controls' labels are editable at WooCommerce > PunchOut > Settings — "Punchout button label" and "Cancel button label". Blank keeps the default; the pow_return_button_label / pow_abandon_button_label filters still run last.
* Change: the cancel control's default label is now "Return without a cart" (was "Return without a basket").
* Change: buyer- and admin-facing wording says "cart" throughout, matching WooCommerce's own terminology.

= 0.2.2 =
* New: [punchout_abandon_button] / pow_abandon_button() — the mid-session "return without a basket" control (empty PunchOutOrderMessage), previously only available on the pay path's close-out.
* Fix: the exit controls are styled by the active theme when placed in a global header or footer, outside the .woocommerce wrapper. New pow_button_classes filter.
* New: pow_abandon_button_label filter, templates/abandon-button.php.

= 0.2.1 =
* Security/correctness pass from the pre-certification adversarial review:
* Deleting a customer connection now expires that customer's live sessions immediately, and a session whose connection is gone fails closed (requisition-only) instead of open.
* Renaming a customer no longer orphans its buyers: returning buyers are matched by their stable identity, not just the name-derived login.
* Latest-punchout-wins now runs after the new session exists, so a concurrent duplicate setup can no longer strand the buyer with nothing to redeem.
* Fixed cxml-urlencoded cart returns being corrupted by attribute escaping (base64 was never affected).
* POOM Total now always equals the sum of the emitted line prices.
* Blocks (Store API) checkout: order tagging/session linkage now fire, and requisition-only sessions are blocked there too.
* Punchout buyer accounts can no longer use password login or password reset — the one-time StartPage link is their only door.
* Turning the master switch off now expires all live punchout sessions immediately.
* Unknown-sender rate limiting is enforced; the pre-auth audit archive is capped at 64 KB per request.
* Secret redaction in archived XML tolerates closing-tag whitespace and case.
* The one-shot punchout cart empty no longer disarms on requests where the cart is not loaded.

= 0.2.0 =
* Buyer group/pricing mapping is now the pow_buyer_provisioned / pow_buyer_deactivated hooks — the plugin ships no third-party plugin glue and works with any (or no) B2B/pricing stack.
* Removed the SKU map: returned lines always carry the store's own SKU; buyer-side part-number mapping belongs in the buyer's procurement system. Sites that must rewrite lines have the pow_poom_lines filter.
* "View details" modal on the Plugins screen, rendered from this readme.
* The RFQ exit button now auto-injects on the blocks cart as well as the classic cart.

= 0.1.0 =
* Initial release: multi-tenant partner registry, setup/start/return endpoints, ProfileRequest support, one-time login, cart-to-POOM RFQ exit, empty-POOM close-out, pay-exit session lifecycle, ALL-CAPS outbound transform, audit log, WP-CLI tooling, unit-tested codec.
