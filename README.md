# PunchOut for WooCommerce

An AGPLv3 WordPress plugin that makes a WooCommerce store a **cXML PunchOut supplier site** for direct-cXML procurement buyers, configured independently per company.

A buyer opens the store from their procurement system. It sends a cXML `PunchOutSetupRequest`; the plugin authenticates the customer, opens a new visit on that customer's one bound WooCommerce account and returns a one-time StartPage login URL. The buyer shops the ordinary WooCommerce cart at that account's prices and leaves through the shop's classic checkout, where PunchOut is the only payment method offered: Submit places the order (a non-payable **Punchout Quote** on the store) and sends it to the purchasing system as a cXML `PunchOutOrderMessage` (requisition/RFQ lines). Nothing is paid inside a visit; no other payment method is offered there.

**Status: supplier implementation; release acceptance and receiver certification are separate gates.** The generated samples are checked against the exact offline cXML 1.2.008 and 1.2.071 DTDs. This does not establish consumption by a buyer system, including Dynamics 365. See [Receiver acceptance](#receiver-acceptance) before onboarding.

- **Licence:** AGPL-3.0-or-later (full text in `LICENSE`)
- **Requires:** PHP 8.2+, WordPress 6.4+, WooCommerce 11.1+
- **Dependencies:** none. No Composer, no vendor directory, no external packages. Action Scheduler is used for housekeeping when present (it ships inside WooCommerce), with a WP-Cron fallback.

---

## Why AGPL

The plugin makes its cXML integration and session model available as free software under AGPL-3.0-or-later. See `LICENSE` for the corresponding-source availability requirements and their conditions. This project does not require upstream contribution.

---

## Architecture

### The flow

```
Buyer's procurement system         The store (this plugin)              Buyer's browser
        │ 1. POST /punchout/setup        │                                     │
        │    text/xml PunchOutSetupReq   │                                     │
        │───────────────────────────────▶│ 2. authenticate customer            │
        │                                │    (hash_equals, dual-slot)         │
        │                                │    resolve the bound account        │
        │                                │    create visit row (pending) with  │
        │                                │    the buyer identity from the      │
        │                                │    request; its cart key is minted  │
        │                                │    at redeem                        │
        │ 3. 200 PunchOutSetupResponse   │    issue one-time token (hash only) │
        │◀───────────────────────────────│                                     │
        │ 4. opens the buyer's browser at the StartPage URL                    │
        │────────────────────────────────┼────────────────────────────────────▶│
        │                                │ 5. GET /punchout/start/{token}      │
        │                                │    → a page that POSTs itself back  │
        │                                │    (a scanner's GET uses nothing)   │
        │                                │◀────────────────────────────────────│
        │                                │ 6. on the POST: redeem the token    │
        │                                │    (single-use, atomic), log the    │
        │                                │    browser in, 302 → landing page   │
        │                                │ 7. shop: normal WC()->cart,         │
        │                                │    buyer-priced catalogue           │
        │                                │◀───────────────────────────────────▶│
        │                                │ 8. the way out: the cart's          │
        │                                │    checkout button ("Review"), the  │
        │                                │    classic checkout with PunchOut   │
        │                                │    as the only payment method,      │
        │                                │    "Submit" places the order and    │
        │                                │    → /punchout/handoff (served once)│
        │ 9. browser POSTs the cXML PunchOutOrderMessage (cxml-base64          │
        │    hidden field) top-level to the BrowserFormPost URL; this          │
        │    visit's own login token and WooCommerce session row are           │
        │    destroyed, and every other live visit of the account — a          │
        │    colleague shopping in the next room — is untouched                │
        │◀───────────────────────────────┼─────────────────────────────────────│
```

Steps 1–3 are server-to-server; steps 5+ are the buyer's browser. The one-time token links the pending visit to the login that redeems it. The mid-visit abandon control (Cancel and return) posts an **empty** PunchOutOrderMessage, the cXML cancel semantic, and adds no delivery charge. The builder can carry a `SupplierOrderInfo` order reference in verified cXML 1.2.071 and 1.2.008 omits it, but no path supplies one: a PunchOut order is never payable here, so no order of ours is a reference the purchasing system could use.

### Key design decisions

**Checkout with PunchOut, and nothing paid.** A visit leaves through WooCommerce's classic checkout with the `punchout` payment method, the only one offered there (0.5.0). Another method forced into the form, the Store API (block) checkout, order-pay, order-received and PunchOut outside a visit are refused server-side; a PunchOut order carries its visit's provenance from creation and is never payable by any route. During a visit checkout saves nothing to the bound account. Ordinary shoppers, including anyone who signs into the bound account with a password, keep WooCommerce's checkout exactly as it is.

**Multi-tenant registry, policy over code.** Every customer difference is a column, not a branch: identities, cXML version, return encoding, the bound customer account, TTLs, IP allowlist, ALL-CAPS outbound transform, delivery configuration, the My Account pages a visit may open. Adding a second buyer is a registry row plus a certification exercise. The one number that is not per customer is the open-visit cap (`Sessions\Store::MAX_OPEN_VISITS`, 50), deliberately a constant with no filter.

**cXML codec on DOMDocument.** The parser accepts `PunchOutSetupRequest` and `ProfileRequest`; the builder emits setup/profile responses, `PunchOutOrderMessage` and `Status`. Parsing rejects entity declarations, uses `LIBXML_NONET`, performs structural validation and never fetches a DTD at runtime. Tests validate generated samples against hash-verified offline fixtures for the exact declared 1.2.008 or 1.2.071 version. Optional field support uses those exact versions, not a guessed version threshold.

**Raw `parse_request` routing, no rewrites.** `/punchout/setup` must accept a raw `text/xml` POST whose body survives untouched. The router matches the raw request path at `parse_request` priority 0 and exits — no rewrite rules, no canonical processing (WP core cannot 301 a POST), no theme bootstrap. Webserver/CDN-level redirects remain a deployment concern: the URL given to buyers must be the exact canonical origin (see the runbook).

**cXML failures inside HTTP 200.** The setup endpoint answers `Status` codes (`401` auth, `406` invalid, `409` replay, `450` unsupported, `500`, `550` rate-limited) in an HTTP 200 — procurement clients read the envelope, and an HTTP 4xx alongside a valid response has broken real integrations.

**One bound account per customer, one session row per visit.** The connection's customer account is the login: `partners.owner_user_id`, an ordinary WooCommerce customer you create, group and price yourself. Every `PunchOutSetupRequest` gets its own WordPress auth cookie and `WP_Session_Tokens` entry for that account and its own `wp_woocommerce_sessions` row, keyed per visit (`pow_` + 28 hex = 32 characters, UNIQUE on the session row). Two employees shopping at once are the same WordPress user, two visits, two baskets, two delivery selections — so every "is this mine" question is answered by the per-visit key, never by the account. Buyer identity is data, not a user: the e-mail comes from `UserEmail`, then `UniqueUsername`, `UniqueName` or `Contact/Email`, the name from `UserPrintableName`, `UserFullName` or `User` — `UniqueUsername` is an identity and never a display name, because purchasing systems routinely put a raw address in it — both are stored on the visit and stamped on the quote, and both may legitimately be empty. A new visit by the same buyer identity supersedes that buyer's own earlier visit and nobody else's; open visits per connection are capped. The plugin never creates, renames or deletes a user.

**One cart per visit.** The live `WC()->cart` is the basket that is returned, so the POOM quotes exactly the prices the bound account sees in the cart — pricing plugins apply their prices at cart time and the mapper reads the cart's own line totals. The visit's own session row is the only basket it reads or writes: WooCommerce's persistent cart and its saved-cart merge flag are both neutralised for the whole of a visit, so the account holder's own saved basket never merges into a visit and no visit's basket can reach another. The cart is emptied once at the start of a create visit.

**Master switch off by default.** A fresh install exposes no pre-auth XML endpoint until an operator has configured a customer and enabled the feature.

### File tree

```
punchout-woocommerce/
├── punchout-woocommerce.php        Plugin header, constants, bootstrap, HPOS declare,
│                                   pow()/pow_is_punchout()/pow_abandon_button() helpers
├── uninstall.php                   Drops the tables, the options and the plugin's own
│                                   rows in the WooCommerce sessions table; no user is touched
├── readme.txt                      WordPress plugin directory format
├── bin/build-zip.sh                Builds the distributable zip (runtime files only)
├── phpunit.xml.dist
├── templates/                      Theme-overridable buyer-facing surfaces
│   ├── abandon-button.php          The "Cancel and return" control (and its leave question)
│   ├── handoff.php                 The auto-submitting cart-return page (Sending / Not sent)
│   ├── state.php                   [punchout_state]: Sent, Not sent, Link expired, Session ended
│   ├── account/dashboard.php       The My Account dashboard inside a visit (no logout link)
│   └── docs/                       The integration documentation page
│       ├── page.php                The page itself
│       └── self-test.php           The paste-a-document self-test box
├── tests/                          Pure-PHP unit suite (no WordPress) + shim runner
└── includes/
    ├── Autoloader.php              PSR-4-style spl_autoload, no Composer
    ├── Plugin.php                  Container / wiring, master-switch gating
    ├── Settings.php                The single option (global knobs only)
    ├── Installer.php               dbDelta schema (3 tables); version 7 adds the
    │                               per-visit cart key and the buyer identity columns
    ├── Logger.php                  wc_get_logger() wrapper, redacts credentials
    ├── Cron.php                    Visit GC (the only collector), quote and log retention
    ├── RouteGuard.php              Session-scoped access control (302s, checkout block)
    ├── Cxml/                       Pure codec: Parser, Builder, FormPack, Money (+DTD)
    ├── Docs/                       Integration documentation page: Page (shortcode),
    │                               Reference (endpoints/status/support), Samples
    │                               (generated by the live codec), SelfTest
    ├── Partners/                   Partner row, Registry (CRUD/auth), Secrets (sodium)
    ├── Sessions/                   Session row+state machine, Store, Current (the one
    │                               "which visit is this request inside" resolver),
    │                               Tokens, ReplayPolicy, HandoffReplay
    ├── Http/                       Router, Setup/Start/Return endpoints, StatePage,
    │                               EndedVisit, RateLimiter
    ├── Checkout/                   The PunchOut payment method, Submission (order → cXML,
    │                               the win, the handoff), OrderMessage, Validation,
    │                               Lifecycle, LateSubmit, Provenance, VisitAccount
    ├── Buyers/Identity.php         Who punched in, read off the request — a value
    │                               object, never an account
    ├── Cart/                       SessionKey (the per-visit key), NativeSessionGuard,
    │                               NativeSessionHandler/Row (one basket per visit),
    │                               Guard, Surface (cart buttons, cancel control),
    │                               LeaveConfirmation, PoomMapper (line naming)
    ├── Orders/                     Status (punchout-quote), QuoteOrder (convert, retention,
    │                               buyer attribution), QuoteCompatibility (old Quotes),
    │                               Attachment (old Quotes' files, read-only)
    ├── Addresses/                  Company delivery book (administrator surface, migration
    │                               source), the account book API, code maps and the
    │                               snapshot decoders old visits still need
    ├── Account/IntegrationTab.php  My Account: the setup-XML download (and the opt-in owner reset)
    ├── Account/VisitEndpoints.php  The My Account pages a visit may open: the form's groups, the dashboard entry, the payment rule, the pages that never open
    ├── Account/VisitDashboard.php  Inside a visit, swaps WooCommerce's dashboard template for the plugin's copy without the logout link
    ├── Audit/Log.php               Compliance trail (wp_pow_log)
    ├── Support/                    Ip (CIDR), Templates (theme-overridable rendering),
    │                               Transport (HTTPS policy)
    ├── Admin/                      Page (4 tabs, Settings API) + Actions (admin-post)
    └── CLI/Command.php             wp punchout …
```

### Data model

Three custom indexed tables (options/postmeta neither index nor GC well for per-request session lookups and an append-heavy audit trail):

| Table | Holds |
|---|---|
| `wp_pow_partners` | Company-connection registry: identities, sealed secrets (current+previous), cXML version, deployment mode, return encoding, delivery configuration, ALL-CAPS flag, IP allowlist, the bound customer account every buyer of this connection punches in as (`owner_user_id`), TTLs, the My Account pages its visits may open (`visit_endpoints`, `dashboard` for the account page itself), whether buyers may add delivery addresses to the company book (`buyer_addresses`, off by default) and the actions the bound account may take itself (`owner_settings`, empty by default; `reset_connection` is the only one). Schema 10 (0.5.0) adds what the cXML built from a WooCommerce order carries: each line's Description (`line_description`, `name` by default) and the "Order details sent" (`order_details`, JSON, empty by default). The open-visit cap is a constant, not a column |
| `wp_pow_sessions` | One row per PunchOutSetupRequest — a visit, not a person, so many open rows naming one `user_id` is the normal state: BuyerCookie, BrowserFormPost URL, the bound account, hashed one-time token, exact WP session token, the per-visit WooCommerce session key (`wc_session_key`, UNIQUE, NULL until the token is redeemed), the buyer identity, name and indexed identity hash read from the request, state machine (`pending → active → returned/closed/expired`), payloadID + body hash (replay), stored response (pending replay), captured ShipTo/SelectedItem/extrinsics, and the delivery choice and consent snapshot columns visits returned before 0.5.0 wrote (kept, read-only) |
| `wp_pow_log` | The audit/compliance trail: every transaction, full POOM XML archives, secrets redacted; retention-trimmed by cron |

---

## Connections and the bound account

Connections are created, configured, credentialled and bound at **WooCommerce ▸ PunchOut** by an administrator with `manage_woocommerce`. There is no front-end application, no approval queue and no owner self-service. The one account-holder surface that survives is the read-only setup-XML download described below, plus one opt-in action an administrator can switch on per connection: **Reset connection** (see *Account holder actions* below).

At **WooCommerce ▸ PunchOut ▸ Customers**, **Add customer** opens the edit form and a connection added there starts active, or disabled if you say so; nothing creates a pending row any more, so the ordinary path never passes through an approval step. The **Pending requests** list and its **Approve company** POST remain only for rows left by the removed front-end application flow: edit such a row's identity and configuration, save, then approve it explicitly. Saving a pending row never activates it or generates credentials.

Admin creation, replacement, rotation, approval and reset show any newly issued secret directly in the successful authorized POST response with a no-store policy. Copy it then: later GETs cannot retrieve it, and notices, emails and logs contain no plaintext credential. Approval emails carry no secret; arrange its handover out of band. Rotation retains the previous credential until **Close rotation**; another rotation is refused while that overlap is open.

Save identity changes before **Reset connection**. Reset uses the saved identity, immediately revokes both credentials and every recorded visit, then issues a replacement only after checked cleanup. Pending connections cannot reset. If cleanup or persistence fails, the admin notice reports the incomplete reset instead of claiming success; inspect the connection and retry after recovery. The bound store account and its delivery book survive a reset.

**Binding the store account.** Every connection needs one WooCommerce customer account bound to it — the account its buyers shop as, grouped and priced by you — and the edit screen's **Bind store account** POST is where that happens. Give it the user ID of an existing ordinary customer: the account must exist, be able to `read`, hold no privileged capability, carry no legacy punchout association and own no other connection. Matching is never inferred from an e-mail address, a name or a third-party company group. A binding cannot be transferred or cleared afterwards, and binding copies no addresses. An active connection with no usable bound account refuses `PunchOutSetupRequest` with cXML Status 500, writes a `setup_no_login` audit row, and raises an admin notice on every wp-admin screen until it is bound.

### My Account pages in a visit

Every buyer of a connection is signed in as its one account, so any My Account page they open is that account's page, shared by every colleague at once. Inside a visit the account area is closed except for the pages the connection ticks. A direct login to the account keeps every page.

**The list.** The connection's edit screen has a checkbox list, **My Account pages in a visit**, built live from the account pages the site registers. Each row shows the page's title where WooCommerce gives one, its endpoint name and a note. The rows are grouped: the dashboard (the account page itself, `/my-account/`) and WooCommerce's own pages first, then this plugin's Punchout integration tab, then **Added by other plugins**. The list is edited by the same users, with the same capability check, as every other connection field.

Where the list comes from. wp-admin sees fewer account pages than the shop does: some plugins add their pages to WooCommerce's endpoint map (`WC()->query->get_query_vars()`) only on the front end, or only for some accounts. Every page with an address of its own under `/my-account/` is also a WordPress rewrite endpoint, and a plugin registers those on every request, wp-admin included, or saving the permalink settings would drop its addresses. So the list is WooCommerce's endpoint map plus every rewrite endpoint registered for pages (but not for every address on the site, as an API address is), under the query var WordPress gives it. A page WooCommerce itself adds at run time, through one of its own features, is listed with WooCommerce's pages; it is recognised by its account content being defined inside WooCommerce. A single order's page (`view-order`) has no row of its own: it belongs with Orders.

A page the connection lists that the site does not register when the screen loads appears under **Ticked, but not found on this site right now**, still ticked, so saving the form keeps it; untick it to remove it. Below the list, **Add a page by name** takes one more page by the last part of its address (for example `page-name` for `/my-account/page-name/`), for a page no source above shows. It is checked like a ticked box.

The notes say what sharing a page means:

- Orders shows the order list, shared by every buyer of the connection. A single order's page stays closed in a visit unless it is that visit's own quote order.
- Downloads are shared by every buyer of the connection.
- Addresses and account details belong to the company admin, who should change them from a normal login. Account details hold the shared login's e-mail address and password.
- The four payment-method pages are shown disabled: nothing is paid in a visit, so no connection opens them.
- Log out, lost password, order-pay, order-received and the Punchout integration tab are shown disabled, marked "Stays closed in a visit". WooCommerce has no account content for the first four (order-pay and order-received are refused in every visit: a PunchOut order is never paid, and Submit lands on the handoff instead), and the tab shows nothing inside a visit.
- Pages added by other plugins are off by default. Creating users inside a visit is blocked, whatever those pages offer.

**Defaults.** A new connection ticks the dashboard and nothing else. WooCommerce's other pages and every page another plugin adds stay off until an administrator ticks them. An upgrade changes no existing connection's list: a list saved under 0.4.5 has no dashboard, so its dashboard stays closed until it is ticked.

**Storage.** The ticked pages are one column, `visit_endpoints` (`VARCHAR(255)`, comma-separated endpoint names), and `dashboard` stands for the account page itself. A save is refused whole, with a notice, when an entry is not a lowercase endpoint name, when a payment-method page is ticked on a punchout-only connection, or when the ticked pages do not fit the column. A page that never opens in a visit is left out of the list a visit reads whatever the row holds. Saves run under the connection lock and are audited as `partner_saved` with the list saved. The account holder sees the list, read-only, on **My Account → Punchout integration**.

**What opens.** Inside a visit an account page opens only when every endpoint the request names is ticked and has account content of its own (a `woocommerce_account_<endpoint>_endpoint` action) on that request. The account page naming no endpoint opens when the dashboard is ticked. Without content WooCommerce would show the dashboard in the page's place, so such a page stays closed and leaves the menu, even when the dashboard is ticked. Log out, lost password, order-pay, order-received and the Punchout integration tab never open in a visit, whatever the row holds, and neither does a page another plugin registers under the name `dashboard`, which this list uses for the account page itself. A name that is not registered opens nothing, and neither does an endpoint whose plugin shows its content only to some accounts until the bound account is one of them. The check runs at the start of `template_redirect` and again at its end. The account menu in a visit shows the dashboard when it is ticked and the other ticked pages that open, and nothing else. The dashboard's own text links to other account pages; those stay closed unless ticked. Its "not you? Log out" link does not show in a visit (since 0.4.7): logging out would end the visit and delete its basket, so inside a visit the dashboard is the plugin's `templates/account/dashboard.php`, WooCommerce's dashboard without that sentence, with the same dashboard hooks. Outside a visit WooCommerce's or the theme's dashboard renders as usual. Anything another plugin prints on the dashboard itself shows with it, so check the dashboard as the bound account before ticking it.

WooCommerce shows "Your account is using a temporary password" on the dashboard and on account details for an account whose password it generated (the `default_password_nag` user option, which WordPress also sets on self-registered accounts), with a Resend link that mails the account a new password-reset link. Inside a visit that option reads false, so neither the notice nor the link renders, and a request carrying WooCommerce's Resend action (`?wc-resend-set-password`) is refused before WooCommerce handles it. The account holder's own login keeps the notice.

A listed page runs as the shared account, so anything it saves per user is shared by every buyer of the connection, with no record of which buyer changed it. Check that before listing a page that stores data. Account links your theme or page builder places outside the account menu are not filtered; hide them in a visit with `POW\pow_is_punchout()`.

A visit never creates a WordPress user through WordPress's user insert, the routine that registration forms, the users REST route and other plugins' AJAX actions (a sub-account form, say) all use. Inside a visit it is refused and audited as `visit_user_create_refused`, once per request, with the script, AJAX action, `wc-ajax` action, REST route and path that asked. Code that writes the users table directly, without WordPress, is outside any hook and is not covered.

### Pages outside the shop in a visit (0.5.2)

A visit is a buyer from a purchasing system filling a cart, and the store's other pages (About, a contact form, a blog, marketing pages) are not part of that. Until 0.5.2 the plugin closed only My Account pages, orders and payment pages in a visit, so keeping a buyer off a content page meant hiding it page by page in the theme or page builder.

**The setting.** **Other pages in a visit** (`visit_pages`) on the Settings tab:

- **Every page** (`all`, the default): nothing is closed beyond the account, order and payment pages above. An upgrade changes nothing.
- **Shop pages only, plus the pages listed** (`shop`): inside a live visit, a GET or HEAD of any other page, post, attachment or archive (blog index, post categories and tags, dates, authors, another post type's pages) sends the buyer to the landing page, with the same notice every closed page gives.

What stays open with shop pages only: the shop, products, product categories, tags, attributes and brands (every taxonomy WooCommerce registers for products), search, the cart, checkout (order-pay and order-received keep their own refusals), the My Account pages the connection ticks, the landing page, the state page and any page holding `[punchout_state]`, pages holding `[punchout_abandon_button]` or `[punchout_cart_exits]`, the terms and privacy pages checkout links to (WooCommerce's terms page and WordPress's privacy page), the site's front page (which has its own redirect to the landing page), feeds and the 404 page. Administrators and shop managers, ordinary shoppers and the bound account outside a visit are never judged, and neither is a POST.

**Pages a visit may also open** (`visit_open_pages`) takes page IDs, separated by commas, that open besides the shop, for example a terms-of-trade page linked from the footer that WooCommerce does not know as its terms page. Any post ID works the same way; the blog index opens when its page is listed.

**The filter.** `punchout_visit_page_open` (`bool $open, $object, Session $visit`) has the last word on every page outside the shop in an active visit, in either mode: return true to open a page, false to close one. `$object` is the queried object (a `WP_Post` for a single page or post and for the blog index, a term, author or post type for other archives, else null). It is not asked for the shop's own pages or the visit's own pages above.

Theme and page-builder links to closed pages (menus, footers) are not removed; hide them in a visit with the `pow-visit` body class or `POW\pow_is_punchout()`, as for account links.

### Account holder actions (0.4.8)

The connection's edit screen has a row **Account holder actions** with one checkbox: **Reset connection from the My Account “Punchout integration” tab**. It is off by default, for new and existing connections; saving stores `reset_connection` in `owner_settings` and the save is audited as `partner_saved`.

When it is ticked and the connection is active, the bound account, signed in normally (not in a punchout visit), sees a **Reset connection** section on **My Account → Punchout integration**: a warning, a confirmation checkbox and a button, in a form of its own beside the setup-XML download. A reset:

- revokes the current and previous shared secrets and ends every open punchout visit of the connection at once (their baskets go with them);
- issues a new shared secret and shows it once, inside the account page, on the no-store response to that POST. It is not stored in plain text, not in a transient, not logged and not e-mailed; a reload cannot show it again, and resubmitting the form is refused ("reload and try again") because the form's nonce names the credential the reset replaced;
- keeps the saved identities, the bound account, the delivery book and every other setting;
- e-mails the account a notice without the secret, and is audited as `registration_owner_reset` (or `registration_owner_reset_failed` with a reason), with the account as the actor.

It is the administrator's reset (same fence, revoke, end-visits and issue sequence under the connection lock), never a rotation, and the purchasing system stops working until the new secret is pasted in. Registration re-checks under the lock that the request comes from the bound account, the connection is active and still grants the action, and the request is not inside a visit. Inside a visit the tab and the reset do not exist. With the box unticked the tab is exactly as before and a reset POST gets no answer.

The buyer-facing button labels are not offered here. They are plugin-wide settings (**Checkout button label** and the others below), not per-connection values, so there is nothing per connection to hand to the account holder.

## Delivery: the order's address, the account's saved addresses and the company book

**At checkout (0.5.0).** The buyer chooses the delivery address on WooCommerce's classic checkout: one of the bound account's saved shipping addresses (through the site's address-book plugin, when it has one), or a one-off address typed there, which stays on that order only. Billing opens on the account's saved billing profile. During a visit checkout **saves nothing to the bound account**: not its billing or shipping details, and not the entries of an address-book plugin that saves through WooCommerce's checkout customer-data filter (`Checkout\VisitAccount` answers `woocommerce_checkout_update_customer_data` false, first and last). Buyers cannot add or change saved addresses; shop administrators keep them on the account. The order's shipping address is what the cXML `ShipTo` carries while the connection sends ShipTo.

**The company delivery book** (0.3.0–0.4.23's address list, **Company delivery addresses** on the customer administration screen) stays an administrator surface and the source of `wp punchout migrate-addresses <connection>`, which copies its enabled entries into the bound account's saved addresses through the address-book API found by shape (`Addresses\AccountBook`: a function named `get_address_book( WC_Customer, type )` in any namespace, with `addresses()`, `default_key()` and `add()`; never a product name). Since 0.5.0 no visit reads the book, and the connection setting that let buyers add to it is retired (the column stays and reads off).

Address codes are company-scoped references, not URLs or registered receiver records. They allow `A–Z`, `0–9`, `_` and `-`, up to 32 characters; the optional prefix is limited to 24 characters and address labels to 190. Changed or removed issued codes leave their claims retired permanently, so another address cannot reuse them. Orders returned before 0.5.0 may carry a code (`_pow_delivery_code`); the order's cXML sends none.

**Delivery cost is WooCommerce's.** The order keeps its normal shipping line, the store's own estimate; the plugin adds no delivery or tax wording anywhere a buyer reads. Only while a connection's **Send delivery cost** is on does the message add one freight line (see below).

### Independent delivery configuration

These are actual connection keys; optional emission is off by default. The shop's checkout decides the shipping address and method, independently of receiver export.

| Key | Default | Effect |
|---|---|---|
| `emit_ship_to` | `false` | "Send ShipTo": export the order's shipping address as the full `ShipTo`, with or without a code |
| `emit_delivery_code` | `false` | Export a usable code through the fields allowed by the exact cXML version (orders returned before 0.5.0 only; the order's cXML sends none) |
| `emit_delivery_line` | `false` | "Send delivery cost": include one freight line, the order's shipping total before tax, with the estimate sentence |
| `delivery_unknown_policy` | `require_rate` | Retired with the review page (0.5.0): WooCommerce's checkout decides the method. Kept on the row for orders returned before 0.5.0 |
| `delivery_notes_policy` | `off` | Keep notes local; `item_detail_extrinsic` additionally exports repeated merchandise notes |
| `delivery_code_prefix` | empty | Optional prefix for generated company codes |
| `delivery_code_extrinsic_name` | `DeliveryAddressCode` | Name of the optional direct `ItemIn/Extrinsic` |
| `freight_supplier_part_id` | `DELIVERY` | Supplier ID of the typed freight line |
| `freight_uom` | `EA` | Freight unit of measure |
| `freight_classification_domain` | `supplier` | Freight classification domain |
| `freight_classification` | `freight` | Freight classification value |
| `line_description` | `name` | 0.5.0, cXML from the order: each line's Description is the product name; `name_short_description` adds " - " and the product's short description (a variation's parent's) as plain text: tags stripped, entities decoded, whitespace collapsed, left out when it only repeats the name, shortened so the whole fits 2,000 characters (`punchout_line_description_limit` can lower it). ShortName is always the name |
| `order_details` | empty | 0.5.0, cXML from the order: up to 10 order meta keys, each with a label and a format, `text` or `unix_date` (a Unix timestamp sent as its UTC calendar day, `YYYY-MM-DD`, through `gmdate`, whatever the server's timezone). Each one with a value follows the buyer's note in DeliveryInstructions as "Label: value"; the parts are joined by " \| " because the builder flattens line breaks, within 2,000 characters, shortening only the note. Text values are plain text on one line, at most 500 characters. Every value listed travels to the purchasing system; a key beginning with `_pow_` (the plugin's own) is refused wherever a list is written or read |

The connection screen edits `emit_delivery_line` ("Send delivery cost"), `emit_ship_to` ("Send ShipTo"), `line_description` ("Line description") and `order_details` ("Order details sent", one per line: `meta key | label | format`). In the cXML built from a WooCommerce order (0.5.0), "Send delivery cost" adds one freight line, the order's shipping total before tax named after its shipping method with the estimate sentence ("The delivery charge is an estimate; the supplier confirms the final amount before dispatch.", fixed and translatable, filter `punchout_delivery_estimate_note`; it then also leads DeliveryInstructions), only while it is on, the order has a shipping line and its shipping total before tax is more than zero; off, with no shipping line, or on a free-shipping order (no 0.00 line, no estimate sentence), the order keeps its shipping line in WooCommerce and the returned Total is the products'.

| Outbound field | cXML 1.2.008 | cXML 1.2.071 |
|---|---|---|
| Header `ShipTo` / full `PostalAddress` | Supported with `emit_ship_to` | Supported with `emit_ship_to` |
| `Address/@addressID` | Usable code with address and code export | Usable code with address and code export |
| `Address/@addressIDDomain="supplier"` | Omitted | With a usable emitted `addressID` |
| Direct `ItemIn/Extrinsic` code | Omitted; code-only export has no output | Supported with `emit_delivery_code`, independently of full address |
| `ItemDetail/Extrinsic` notes | Supported with `item_detail_extrinsic` | Supported with `item_detail_extrinsic` |
| One quantity-one freight `ItemIn` | Supported with `emit_delivery_line` and a known rate | Supported with `emit_delivery_line` and a known rate |
| `SupplierOrderInfo` | Omitted | Supported when an order reference is supplied |

Unverified versions do not gain optional postal, code or note fields through version-number comparisons, and unsupported fields are not relocated to invented elements. Codes, freight and full address do not enable one another. The integration docs page generates a flags-off example and full enabled examples for both exact DTDs through the real Parser/Builder, with totals derived from the emitted lines. Exact-DTD validation proves document structure; it does not prove receiver field consumption.

## Security model

- **Shared secrets** are sealed at rest with `sodium_crypto_secretbox` under a wp-config key (`POW_SECRET_KEY`; a documented auth-salt-derived fallback keeps the key out of the database either way), compared with `hash_equals`, write-only in the admin UI, and rotated dual-slot: current and previous both verify during an overlap window, the audit log records which slot matched, and the window is closed explicitly (admin POST form or `wp punchout close-rotation`).
- **Outbound baskets never carry a shared secret.** The builder has no code path that writes a `SharedSecret` node, and the unit suite asserts its absence on both POOM variants (browser-transported Messages carry an identity-only Sender by DTD rule).
- **StartPage tokens** are 256-bit random, URL-safe, single-use (an atomic one-query status flip), short-TTL, and stored only as SHA-256. Invalid/expired/used all produce the same detail-free 403.
- **Start links and mail scanners** (0.4.23): only a POST to `/punchout/start/{token}` redeems. A GET or HEAD answers with a small theme-free page (no lookup, no write, no audit row; `no-store`, `noindex`, `nosniff`, `Referrer-Policy: no-referrer`) whose form posts back to the same address by script, with a visible button for a browser without scripting. A malformed token is refused for every method before any lookup, and any other method gets 405. The setting **Login link needs a click** (off by default) leaves out the automatic submit, for scanners that also run scripts. The page sends its form once (the button is disabled after the automatic step or the first press, and a second submit is ignored), runs only its own inline script and style (`Content-Security-Policy: default-src 'none'; script-src 'sha256-…'; style-src 'sha256-…'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'`; the script is `assets/js/start-link.js`, inlined), cannot be framed (`X-Frame-Options: DENY`) and may not be rewritten by a proxy (`Cache-Control: private, no-store, no-transform`). Its button label is the setting **Login link button** (filter `punchout_start_link_button_label`). A repeat POST from the browser that redeemed the link, still signed in to that visit with the visit's own login token, is sent to the landing page again (nothing is redeemed, written or logged a second time); every other repeat POST gets the expired page. A `pow_start_redirect` filter that sends the buyer to another host is blocked by `form-action 'self'` in browsers that apply it to redirects. The setup response and the StartPage URL format are unchanged.
- **Replay**: `UNIQUE(partner_id, payloadID)` plus written-down semantics — a duplicate with an identical body while `pending` replays the stored response byte-identically (legitimate retry); any duplicate after token redemption is a cXML 409.
- **Visits**: the exact WP session token created at auto-login is recorded, so the return destroys *that* login only and a colleague's stays live; the visit also owns one `wp_woocommerce_sessions` row under its own key, which is the only basket its requests read or write and which a foreign key is refused for. Auth-cookie expiry is the customer's session TTL (default 4 h), the WooCommerce session and any Store API cart token are capped at what the visit has left, and cron reaps stragglers — it is the only collector of abandoned visits.
- **Checkout**: blocked at classic checkout, the Store API and direct payment boundaries inside a punchout visit, and nowhere else. A password login to the bound account shops and checks out normally; a Punchout Quote is not payable anywhere.
- **Pre-auth surface** is `/punchout/setup` alone: 2 MB body cap, content-type check, XXE hardening, per-(customer, IP) rate limiting, optional per-customer CIDR allowlist, generic 401s.
- **Anonymous audit rows** (0.4.22): a request that has not authenticated (a setup whose sender has not proved its secret, a refused StartPage token, `/punchout/order`) writes its audit rows only within a per-IP budget of 60 requests an hour; later ones are answered but leave no row. Such a setup body is kept as a 4 KB excerpt with its length and SHA-256; an authenticated one keeps up to 64 KB. Identities and the shared secret are blanked before any cut.
- **Lifetimes**: StartPage links and visits live at most 7 days (`Partner::MAX_TTL`), whatever a connection or the settings say.
- **Pricing-leak guards**: every `/punchout/*` response sends `no-store` + `X-Robots-Tag: noindex` + `X-Content-Type-Options: nosniff`; the handoff page is never cacheable; add-to-cart validates range server-side against WooCommerce's own visibility and purchasability chain (hiding a button is not access control); and inside a visit the request is refused at wp-admin, `/wp/v2/users*`, `/wp/v2/application-passwords*`, application passwords for the account, every My Account page the connection has not ticked, wp-login.php except logout, another visit's basket (including by Cart-Token) and any quote order that is not this visit's own. No user can be created inside a visit through WordPress's user insert. Add `Disallow: /punchout/` to robots.txt at deployment (see runbook) — the plugin does not rewrite robots.txt itself.
- **Audit**: every setup — including a refusal for an unbound account or an over-cap connection — token redemption, login, rejected add-to-cart, cart send (full XML), quote order event, rotation and GC action lands in `wp_pow_log` with secrets redacted, and the buyer is named there by a 12-hex identity hash, never by a raw e-mail address. WooCommerce log files rotate away; the dispute evidence (prices quoted to a named buyer at a timestamp) must not.

---

## Install

1. Copy the `punchout-woocommerce` directory into `wp-content/plugins/` (or build a zip with `bin/build-zip.sh` and upload it). Activate. Activation creates the three tables and schedules nothing.
2. Put the sealing key in `wp-config.php` **before** storing customer secrets (changing the key later invalidates every stored secret):

```bash
wp punchout generate-key
# → define( 'POW_SECRET_KEY', '…' );  — paste into wp-config.php
```

3. Configure at **WooCommerce ▸ PunchOut**: add a customer; create (or pick) and group the one WooCommerce customer account its buyers will shop as and bind it to the connection; generate the shared secret from the form (it is shown exactly once); then enable the master switch on the Settings tab. An unbound connection answers cXML Status 500 and says so in an admin notice.
4. Prove the plumbing before involving the buyer: POST a sample `PunchOutSetupRequest` at `/punchout/setup` from the shell and check the Log tab (a local punchout simulator such as `punchout-simulator` or `cxml-tester` drives the full loop).

### Settings

| Setting | Default | Notes |
|---|---|---|
| Enable punchout | off | Master switch; off = endpoints and surfaces inert |
| Landing page | shop page | Post-login redirect + route-guard home; inside an active visit the site's front page also redirects here (0.4.22) |
| Other pages in a visit | every page | 0.5.2: "Shop pages only, plus the pages listed" sends a buyer in a visit from any page outside the shop to the landing page with a notice (see "Pages outside the shop in a visit"); "Every page" keeps the behaviour before 0.5.2 |
| Pages a visit may also open | empty | 0.5.2: comma-separated page IDs a visit opens besides the shop while only shop pages open |
| Punchout integration tab in My Account | accounts bound to a connection | 0.5.2: who sees the setup-XML tab (and its opt-in reset): accounts bound to a connection, every signed-in account (the behaviour before 0.5.2) or nobody (administrators hand out the setup XML from Integration docs). Never inside a visit |
| Login link needs a click | off | Off: a GET of the login link shows a page that posts itself back, so a mail scanner that only fetches the link does not use it up. On: the buyer presses the button on that page once, for scanners that also run scripts (0.4.23) |
| Login link button | "Open the catalog" | Text on the button of the page the login link opens (0.4.23); blank = the default; filter `punchout_start_link_button_label` |
| Login link lifetime | 300 s | One-time StartPage token TTL; at most 7 days (604800 s), per-partner override within the same cap |
| Session lifetime | 4 h | Punchout login TTL (per-partner override); at most 7 days (604800 s). A longer stored value is used as 7 days |
| Setup rate limit | 30/min | Per partner+IP on `/punchout/setup`; 0 uses the default (30). The public self-test preserves positive values and uses 10/min when this is 0. |
| Setup edge limit | 120/min | Per IP before method checks, body reads, parsing or audit storage on `/punchout/setup`; 0 uses the default (120) |
| Log retention | 400 days | Audit-table trim horizon |
| Checkout button label | "Proceed to checkout" | 0.5.0: during a visit, the text of the cart's proceed button, the Cart widget's checkout button and the Cart block's button, which lead to WooCommerce's checkout; blank = the default; filter `punchout_checkout_button_label` |
| Saved addresses only in a visit | on | 0.5.1: during a visit, checkout offers only the delivery addresses saved on the connection's account: an address book's "add new" choice is removed, the billing and shipping fields post hidden (the chosen saved address fills them; nothing can be typed), and a posted delivery address that is not a saved entry is refused before any order exists. An account with no saved delivery address takes what checkout posts. Filter `punchout_visit_saved_addresses_only` |
| Cancel button label | "Cancel and return" | Text on the abandon control (Cancel and return); blank = the default; filter `pow_abandon_button_label` |
| Ask before leaving | off | 0.5.0: during a visit, the abandon control asks first when the cart has items (see "Leaving without a cart"); the question is the shortcode's `question` attribute |
| Leave button label | "Return without sending" | 0.5.0: the question's button that leaves without sending; blank = the default; filter `punchout_leave_confirm_label` |
| Stay button label | "Keep shopping" | 0.5.0: the question's button that closes it; blank = the default; filter `punchout_leave_stay_label` |
| State page | none | 0.5.0: a page holding `[punchout_state]`; with one chosen, the visit's end draws inside the theme (see "State page"); none keeps the plain pages |
| State page button label | "Continue to your purchasing system" | 0.5.0: the fallback button of the plain page that posts the cart, and the state page's button when its shortcode names a `button_url`; blank = the default; filter `punchout_state_button_label` |
| Extra button classes | empty | Space-separated classes added to the abandon control (Cancel and return); pow-* classes are ignored. The checkout buttons keep WooCommerce's own classes |
| Default UNSPSC | empty | Classification fallback for unmapped SKUs |
| Convert quotes to | Pending payment | Status the order action moves a Punchout Quote to |

The PunchOut **payment method** has its own settings at **WooCommerce ▸ Settings ▸ Payments ▸ PunchOut** (0.5.0): its title (default "PunchOut"), its description (blank by default) and the place-order button's text (default "Place order"). Nothing else is a setting: titles, body text and the leave question ship as neutral, translatable defaults that a layout overrides as shortcode attributes.

Retired in 0.5.0 with the review page, and listed in the changelog: Punchout button label, Attachment field, Attachment label, Attachment help text, Delivery estimate note, Review page title, Review Submit button, Review items heading, Review total label and Review tax sentence. A save keeps a stored value of any of them as it was, so a rollback to 0.4.23 finds its words again.
| Quote retention | 90 days | Unconverted quotes cancelled (never deleted) after this; 0 = keep for ever |

---

## Partner onboarding runbook

Punchout onboarding is a data exchange followed by testing in the buyer's own purchasing system. Its administrator configures the external catalogue connection, identity mapping and returned-line mapping using the agreed values. A successful supplier-side test is only one part of acceptance.

**You provide the buyer:**

| Item | Value |
|---|---|
| Punchout catalog URL (test and production) | `https://{canonical-host}/punchout/setup` — the exact canonical origin: any webserver/CDN redirect (apex→www, http→https) will silently drop the POST body |
| Your identity + domain | the partner row's To credential (DUNS number, or an agreed `NetworkID` value) |
| Shared secret | generate in the partner form or `wp punchout rotate-secret`; state the rotation policy (dual-slot overlap; suggest annual + on-suspicion) |
| Supported cXML version | `cxml_version` per connection, default 1.2.008; use the exact optional-field support table above |
| Vendor logo | Format and dimensions required by the buyer's catalogue |
| Vendor/commercial data | vendor account number, name, contacts, approved procurement categories |

**The buyer provides you (per partner row):**

| Item | Why |
|---|---|
| Their From credential (domain + identity) and Sender identity | registry row; the Sender pair is the auth lookup key |
| Which legal entities get catalog access | Multiple entities can require separate identities or partner rows. Every returned cart currently uses ZAR. |
| The cXML version their system emits | codec config |
| Evidence of a successful cross-site browser form return in the intended browser and purchasing system | Verify the receiver retains the buyer's authenticated session |
| Test environment access, a named buyer administrator and an acceptance window | Receiver consumption is proved in that environment |
| Exact UOM codes and currency configured in the receiver | Verify mapped merchandise and freight units and currency, not just XML parsing |
| Agreed extrinsic names and value sources (`UserEmail`, `UniqueUsername`, `UniqueName`, `UserPrintableName`) | Attribution: the name and e-mail are recorded on the visit and stamped on the quote order, and a returning identity supersedes its own earlier visit. They select no account and create none, so a buyer changing e-mail address no longer creates anything |
| Which cart-return encoding the receiver accepts (`cxml-base64` / `cxml-urlencoded`) | Per-connection switch, verified with an actual received basket |
| Whether an empty PunchOutOrderMessage closes a session cleanly | Cancel and return's behaviour |
| IP allowlisting requirements and the agreed destination, code, notes and freight mapping | Deployment plus independent optional-field acceptance; no address-list API is implied |

**Deployment checklist (outside the plugin):** the buyer-facing URL must be the canonical host with no redirect in the path; any WAF/bot-management layer needs a skip rule for `POST /punchout/setup` (a challenge page kills setup invisibly); CDN cache rules must bypass `/punchout/*` and cookie-carrying requests; `robots.txt` gets `Disallow: /punchout/`. Prove all of it by POSTing a real setup body through the full public path.

**Browser return acceptance:** the cart return is a cross-site, top-level form POST. Verify that the buyer stays signed in and receives the basket in the intended browser and purchasing system. A supplier-generated handoff alone cannot prove the receiver's authentication and cookie behaviour.

---

## The way out of a visit (0.5.0)

Inside a live visit the cart's proceed button and the Cart widget's checkout button lead to WooCommerce's classic checkout and carry the **Checkout button label** (`Cart\Surface::maybe_unhook_checkout_button()`: every callback on `woocommerce_proceed_to_checkout` and on the mini-cart buttons hook is cleared, express-pay buttons included, and only the labelled buttons come back; "View cart" is unchanged). The Cart block's button keeps its native link and takes the label through `assets/js/cart-blocks.js`. `[punchout_cart_exits]` renders WooCommerce's checkout link for every shopper, carrying the label in a live visit. A theme whose cart draws its own buttons without those hooks places the visit's button itself (gate it on `pow-visit` or `POW\pow_is_punchout()`).

At checkout the `punchout` method is the only one offered (`Checkout\PaymentMethod`, available only while a live visit owns the request, filtered in last so another plugin's gateway rules cannot remove it); its button text is the method's place-order setting, set before any method is selected so "Place order" never flashes. Before any order exists checkout refuses, with a plain message naming the item, a line without a SKU, a cart priced in another currency than the store's and a note cXML cannot carry (`Checkout\Validation`); the note is cleaned of control characters and capped at 2,000 characters. Submit (`Checkout\Submission`) builds the cXML from the placed order (`Checkout\OrderMessage`), stamps the provenance keys, saves the handoff and wins the visit's return under the visit lock; after the win each remaining step runs on its own and cannot stop the handoff: the stock reservation and coupon holds are released, the cart emptied and the order set to **Punchout Quote** last, which is the one trigger of the "PunchOut order received" e-mail. A build failure answers failure with a plain message and leaves the order pending, never failed; WooCommerce reuses it on resubmit. A zero-total cart still reaches the method, PunchOut orders are excluded from WooCommerce's unpaid-order auto-cancel, and no failed or cancelled e-mail is ever sent (`Checkout\Lifecycle`). Of two racing Submits the loser trashes its own order and releases its holds; a Submit after the return is caught before WooCommerce's nonce check and sent to the Sent state (`Checkout\LateSubmit`, `GET /punchout/sent`).

The checkout response redirects to the theme-free GET handoff `/punchout/handoff` (`Checkout\Handoff`): no-store, noindex, nosniff and no-referrer, served once, only to the browser and visit that won (keyed to the order key and that visit's login; claimed by its delete, so two GETs at once get one page), it auto-posts the message and ends the login; the checkout response itself never clears the login cookie. The page carries `pow-state pow-state--sending` and the **State page button label** on its fallback button. With a state page chosen, a spent handoff and a late Submit go to the Sent state there.

**Retired in 0.5.0:** the review page (`/punchout/confirm`, `[punchout_delivery_confirmation]`), the cart's "Punchout" return button and its template, setting and `pow_return_button_label` filter. `[punchout_return_button]` and `pow_return_button()` stay for this one release and render nothing, so a layout that still carries them shows nothing rather than a tag; the next release removes them. The 0.4.x stylesheet that hid checkout links in a visit is gone with them.

## Leaving without a cart

`[punchout_abandon_button]` (or `pow_abandon_button();`) renders the mid-session abandon control, Cancel and return — a POST to `/punchout/return` of an empty PunchOutOrderMessage, the cXML cancel semantic, so the buyer's procurement application learns the session ended with no items. Since 0.5.0 that endpoint does only this: every POST is the cancel, authorised by the visit's own login token and the `pow_return` nonce, closing that one visit and tearing down that login, basket and cookie only; a repeated click gets the same page again (`Sessions\HandoffReplay`). It has no automatic placement, because no core hook means "the session chrome". Label: the **Cancel button label** setting, or the `pow_abandon_button_label` filter, which runs after it.

**Ask before leaving (0.5.0).** With the setting on and a line in the cart, the control also draws a `<dialog class="pow-leave">` that asks first: `[punchout_abandon_button question="Return without sending?"]` (default "Return without sending your cart?"), with the **Leave button label** (`.pow-leave__confirm`) and the **Stay button label** (`.pow-leave__stay`, focused; it closes the dialog without a script, `formmethod="dialog"`). Pressing the control opens the dialog through the plugin's own script (`assets/js/leave-confirmation.js`); only the dialog's own form carries `pow_leave=confirmed`, and only that post leaves. Without the script, the control's post comes back to the page it came from with `?pow_leave=ask`, where the dialog is drawn open (`Cart\LeaveConfirmation`, checked again by `/punchout/return`). An empty cart leaves at once, and so does every cancel while the setting is off. The page Cancel and return answers with stays plain (it posts the empty message) and carries `pow-state pow-state--not-sent`. A theme override of `abandon-button.php` must keep the dialog, or the buyer is asked again and again.

The control is a submit button inside a real form, never a link, and is placed in a builder's global header or footer as often as on the cart — i.e. outside the `.woocommerce` wrapper most themes hang their button styling off. It carries only its own class plus the **Extra button classes**; `pow_button_classes` replaces the whole list.

## State page (0.5.0)

A visit ends on a page the site chooses: create a normal page holding `[punchout_state]` and pick it as **State page**. The browser is then sent there, so the end of a visit draws inside the theme instead of on a plain page:

| State | Modifier | When the browser is sent there |
|---|---|---|
| Sending | `pow-state--sending` | Never: the page that posts the cart stays plain, because it holds the cXML and the visit's BuyerCookie. That page carries these classes too (and Cancel and return's carries `--not-sent`); the shortcode draws Sending only for a layout's preview |
| Sent | `pow-state--sent` | A Submit after the cart has gone (a second tab, a double click), the handoff fetched again after it was served, a page from the browser after a return |
| Not sent | `pow-state--not-sent` | A handoff whose cart went to the store but never reached the browser (its page expired first), a page or Submit from the browser after Cancel and return |
| Link expired | `pow-state--expired` | A start link that is used, expired or malformed |
| Session ended | `pow-state--ended` | A return or Cancel and return after the visit ended, a Submit or a page from the browser of a visit that ended (its time ran out, it was ended, the store was switched off); the dead login cookie is cleared on the way, once |

The markup is `<div class="pow-state pow-state--<state>">` with `pow-state__title`, `pow-state__text` and, only when the shortcode names a `button_url`, `pow-state__actions` holding the link `pow-state__button` (label: **State page button label**). By then the visit's login has ended, so style these classes, not `pow-visit`. Titles and text are neutral, translatable defaults; a layout overrides them as attributes: `sending_title`, `sending_text`, `sent_title`, `sent_text`, `not_sent_title`, `not_sent_text`, `expired_title`, `expired_text`, `ended_title`, `ended_text` (an empty attribute draws nothing). The address names the state (`?pow_state=sent`); without one the page shows the `state` attribute (a preview) or Session ended. The page sends no-store, noindex, nosniff and no-referrer on `template_redirect`, before it renders, as does any page whose content holds the shortcode. It carries no cXML and no secret. Without a chosen page every plain page stays as it was. Template: `templates/state.php` (`pow_template_state`).

## Punchout Quote orders

A visit's Submit leaves a WooCommerce **Punchout Quote** (`punchout-quote`): the order WooCommerce's own checkout placed, with its lines, its shipping address and shipping line, the buyer's note and the returned cXML basket, set to this status last, after the handoff is won and the holds released (0.5.0, `Checkout\Submission`). Until 0.4.23 the quote was assembled from the reviewed cart by the plugin; those Quotes are read as they were written (`Orders\QuoteCompatibility`). The quote records the basket; the requisition itself lives in the buyer's procurement system.

Three properties come from WooCommerce core rather than from code here, which is what makes the status safe to leave in the orders list: **no stock movement** (stock reduction binds completed/processing/on-hold and payment-complete only), **no transactional email** (core's email list is a fixed set of status pairs; no custom status can join it), and **no Analytics revenue** (excluded by filter, so the operator's own Analytics setting is untouched and deactivating the plugin reverts it). The status has its own link in the orders list filter row and its own "Change status to Punchout Quote" bulk action.

A Submit whose message cannot be built answers failure with a plain message and leaves the order pending (never failed, never cancelled by WooCommerce's unpaid-order clean-up); WooCommerce reuses that pending order on the next Submit. The loser of two racing Submits trashes its own order and releases its stock and coupon holds. Each step after the win (holds, cart, status) runs on its own and is audited when it fails; none can stop the handoff.

**Converting.** The order screen's *Order actions* box offers **Convert Punchout Quote to order** on a quote and on nothing else. It moves the order to the **Convert quotes to** status (Pending payment, Processing or On hold), leaves a note, and writes a `quote_order_converted` audit row naming the administrator who did it. The converted quote is a normal WooCommerce order: Processing and On hold run native stock reduction where applicable, and normal email and fulfilment hooks remain enabled. The returned basket XML stays on the order. A failed conversion is recorded as `quote_order_convert_failed` with an error result, without a success audit row.

**Retention.** The hourly housekeeping job queries up to 100 oldest quotes created before the **Quote retention** cutoff, using UTC second precision. Each fetched order must still be a Punchout Quote before it can be cancelled. Successful cancellations leave a note and a `quote_order_expired` audit row; orders are never deleted and the basket XML stays on them. Native cancellation restores stock only if that order previously reduced it; quotes created by this plugin have not reduced stock. A failed query or order operation records `quote_order_expire_failed` with an error result; a failed order does not count as a successful expiry and does not stop later candidates. Set retention to 0 to keep quotes indefinitely.

Before deploying, verify conversion on staging for each configured target: the status and note must land, Processing/On hold must apply normal stock reduction where applicable, and Pending payment must leave an unreduced quote's stock unchanged. Check native email behaviour with a mail logger. Stub tests do not prove these WooCommerce effects.

**Order meta.** The returned document and the visit's provenance use protected, underscore-prefixed keys. A 0.5.0 order writes the first eight; the delivery keys below them belong to Quotes returned before 0.5.0 and are read, never written:

| Key | Holds |
|---|---|
| `_pow_poom_xml` | The cXML PunchOutOrderMessage that was returned to the buyer |
| `_pow_session_id` | The punchout session (`wp_pow_sessions.id`) the order came from |
| `_pow_partner_id` | The customer connection |
| `_pow_partner_name` | The connection's name as it stood when the order was placed |
| `_pow_buyer_cookie` | The BuyerCookie of the visit, the purchasing system's own correlator |
| `_pow_delivery_code` | Before 0.5.0: the code on the confirmed destination, when one was available |
| `_pow_delivery` | Before 0.5.0: the accepted delivery estimate, package rates, freight configuration and emission state |
| `_pow_delivery_choice` | Before 0.5.0: the stored destination-choice JSON of the return winner |
| `_pow_delivery_confirmation` | Before 0.5.0: the stored confirmation JSON, cart/policy binding and notes; Quotes created before 0.4.23 may carry a preferred delivery date (schema 2) |
| `_pow_delivery_notes` | Before 0.5.0: the confirmed plain notes, also copied to the native order customer note (a 0.5.0 order keeps the note WooCommerce's checkout saved) |
| `_pow_preferred_delivery_date` | The buyer's preferred delivery date (Y-m-d) on a Quote created before 0.4.23. Never sent in the PunchOutOrderMessage; old Quotes still show it on the order screen and in the e-mail. A delivery date belongs to the shop's checkout and reaches the cXML through "Order details sent" |
| `_pow_buyer_identity` | The buyer's e-mail or identity from the cXML request (`UserEmail`, `UniqueUsername`, `UniqueName`, `Contact/Email`); written empty when the request named nobody, and absent altogether on a quote taken before 0.4.0 |
| `_pow_buyer_name` | The buyer's own spelling of their name (`UserPrintableName`, `UserFullName`, `User`); written empty when the request sent no name, and absent altogether on a quote taken before 0.4.0 |

The order's `customer_id` stays the bound store account, because that is who shopped. The same two facts appear twice more for people who never read order meta: as an order note, and as a **Bought by** line under the billing address on the order screen — `Bought by {name} ({email}) via PunchOut ({connection})`, the e-mail alone when no name was sent, and a plain statement that the purchasing system named nobody when neither was. The address is parenthesised rather than wrapped in angle brackets because an order note is rendered through kses, which would read `<{email}>` as a tag and delete the address. A quote taken before 0.4.0 carries no buyer meta and gets no line at all, rather than being told it named nobody. No e-mail is ever sent to a buyer.

**The cXML from the order** (`Checkout\OrderMessage`): the lines are the order's product lines (SKU; the product and variation ids as the auxiliary id; the ex-tax unit price from the discounted line total divided by the quantity; EA; the Classification; capitals and flattening as the connection sets them); the Description is the name, or with `line_description` = `name_short_description` the name, " - " and the plain short description; ShortName is always the name. ShipTo is the order's shipping address while the connection sends ShipTo. DeliveryInstructions holds the cleaned note, then the connection's "Order details sent". Freight travels only with "Send delivery cost". The envelope is unchanged: From and To reversed, Sender identity only, BuyerCookie echoed, the visit's deployment mode, "create" only, ShortName as a child element, cXML 1.2.008 by default, a top-level browser form POST to that visit's BrowserFormPost URL, and the empty message for Cancel and return. SupplierOrderInfo stays omitted on 1.2.008.

## Integration documentation page

The page covers endpoints, identities, the annotated setup request, returned carts, status codes, delivery and the order's cXML, independent delivery flags, rate limits and secret rotation. Store administrators also receive a complete Dynamics setup template for each active company; the holder of that connection's bound store account downloads the same XML from My Account. It uses the company's static From, Sender, To, cXML version, mode and HTTPS supplier setup URL, retains only a SharedSecret placeholder, and leaves buyer-system runtime fields blank. UserEmail is added separately through the buyer system's extrinsics mapping and is not duplicated in the downloaded XML. The populated fictional buyer-sent specimen is parsed by `Cxml\Parser` and checked against its exact offline DTD; outbound examples use `Cxml\Builder` and the real freight-line producer with invented rates. Flags-off and full enabled examples use exact verified DTD fixtures; displayed totals are derived from the same lines. These samples do not certify a receiving tenant or execute native shipping callbacks.

Publish it by creating a page and adding the shortcode, then send buyers that page's URL:

```
[punchout_docs]
```

Shortcode only — core's Shortcode block inserts it in the block editor, so a custom block would buy nothing but a JS build step. The page renders while punchout is switched off (which is when a new buyer usually reads it) and exposes no session surface.

It carries a **self-test**: paste a PunchOutSetupRequest or ProfileRequest, get back the cXML Status the live endpoint would have answered with, and why. Nothing is stored, no session is created, and any `SharedSecret` in the paste is redacted before it is echoed back. For an anonymous visitor the three authentication stages collapse into one result — splitting them on a public page would be a credential oracle — and the run is throttled on the setup endpoint's own rate limiter and written to the audit log (`docs_self_test`).

Store administrators get the same page with more on it at **WooCommerce > PunchOut > Integration docs**: a per-connection block with the complete setup XML and a self-test that names the exact authentication stage. The holder of a connection's bound store account downloads the same template from **My Account → Punchout integration**; ownership is resolved server-side and the request carries a nonce. That tab is the one surviving customer-facing surface and it is download-only — no editing, no secret, no rotation — unless an administrator switches on the opt-in **Reset connection** for that connection (see *Account holder actions*), and it does not exist inside a punchout visit. Since 0.5.2 the setting **Punchout integration tab in My Account** (`account_tab`) decides who sees it: by default only an account a connection is bound to (whatever the connection's status); **Every signed-in account** restores the behaviour before 0.5.2 (other accounts saw the tab with its no-connection state); **Nobody** hides it, the reset included, and administrators hand out the setup XML from **Integration docs**. A GET of the tab's address by an account it is not shown to lands on the account dashboard.

Override the markup by copying to `{theme}/punchout-woocommerce/docs/page.php` (or `docs/self-test.php`), or via the `pow_template_docs/page` filter.

## Extension points

| Hook | Type | Purpose |
|---|---|---|
| `pow_abandon_button_label` | filter | "Cancel and return" text (applied after the setting) |
| `punchout_checkout_button_label` | filter | 0.5.0: the cart's checkout-button text in a visit (applied after the setting; a blank result keeps the default) |
| `pow_button_classes` | filter | Class list of a plugin control (`$classes, $base, $themed`): the cancel control and the checkout links |
| `pow_template_{abandon-button,handoff,state,docs/page,docs/self-test,account/dashboard}` | filter | Replace any buyer-facing template. A theme override of `abandon-button.php` must keep the leave dialog, or the buyer is asked again and again |
| `punchout_delivery_estimate_note` | filter | 0.5.0: the estimate sentence on the freight line, sent only with "Send delivery cost"; a blank result sends none |
| `pow_delivery_codes_enabled` | filter | Legacy custom-docs-template display hint; the bundled docs show all delivery settings, and this filter does not control emission |
| `pow_handoff_copy` / `pow_expired_token_message` | filter | Buyer-facing strings (0.5.0: `pow_handoff_copy` also gets the state, `sending` or `not-sent`) |
| `punchout_state_button_label`, `punchout_leave_confirm_label`, `punchout_leave_stay_label` | filter | 0.5.0: the state page's button and the leave question's two buttons (applied after the settings; a blank result keeps the default) |
| `shortcode_atts_punchout_state`, `shortcode_atts_punchout_abandon_button` | filter | 0.5.0: WordPress's own attribute filters for the state page's words and the leave question |
| `pow_start_redirect` | filter | Post-login destination |
| `punchout_visit_front_page_redirect` | filter | Where the site's front page sends a buyer inside an active visit (`$url, $visit`); default the landing page, `''` keeps the front page. Never for administrators or shop managers, outside a visit or when the landing page is the front page |
| `punchout_visit_page_open` | filter | 0.5.2: whether a page outside the shop opens in an active visit (`$open, $object, $visit`); true opens, false closes, in either "Other pages in a visit" mode. Not asked for the shop's own pages, the visit's own pages, administrators or shop managers, or outside a visit |
| `punchout_line_description_limit` | filter | 0.5.0: the most characters a line Description with the short description may have (default and ceiling 2,000) |
| `punchout_order_details_sent` | filter | 0.5.0: the connection's "Order details sent" for one order (`$entries, $order, $partner`); entries are checked again after the filter |
| `pow_client_ip` | filter | Trust a proxy header for rate limiting / allowlists / audit |
| `pow_is_punchout()` | function | Presentation gating for themes/builders (never access control). Namespaced: call it as `POW\pow_is_punchout()`, or check `function_exists( 'POW\\pow_is_punchout' )` — the unqualified name does not exist. |
| `pow-visit` | body class | Added to `<body>` on every page served inside a live visit. Page builders and theme CSS gate presentation on it (`body.pow-visit .my-trade-console { display: none }`, or a builder's body-class condition); never access control. |

## WP-CLI

```bash
wp punchout partners          # registry overview
wp punchout rotate-secret 1   # dual-slot rotation; prints the new secret once
wp punchout close-rotation 1  # drop the previous secret
wp punchout generate-key      # POW_SECRET_KEY material for wp-config.php
wp punchout gc                # run housekeeping now
wp punchout migrate-addresses 1 [--dry-run]   # copy the company book's enabled entries into the bound account's saved addresses
```

## Tests

`tests/` holds a pure-PHP unit suite for the codec, the checkout path and session/token logic — no WordPress loaded, WP-free by design. Run `php tests/run-tests.php` anywhere (a PHPUnit-compatible shim runs without Composer), or `phpunit -c phpunit.xml.dist` where PHPUnit exists. DOM-based codec tests skip automatically on a PHP without `ext-dom`. The opt-in native suites, the end-to-end HTTP driver and the real-browser run (which drives the checkout in Chromium) need a disposable local fixture. See `tests/README.md`.

---

## What is deliberately NOT built

- **Inbound cXML `OrderRequest` receiver** (`/punchout/order`): answers cXML 450. Building it means idempotent Woo order creation, SKU/price reconciliation and an agreed acknowledgement contract — priced separately once a buyer confirms cXML PO delivery.
- **edit/inspect re-entry**: non-create operations answer cXML 450 and the POOM declares `operationAllowed="create"`. The registry retains `allow_reentry` for compatibility; no re-entry flow is implemented.
- **Ariba network hops, CredentialMac, client certificates, `ds:Signature`**: only direct punchout with SharedSecret auth is implemented.
- **Invoicing (`InvoiceDetailRequest`), order-status write-back, catalog uploads**: out of scope.
- **Paying inside a visit, per-company payment gateway lists and external address-list APIs**: not implemented. PunchOut is the only payment method a visit sees, a PunchOut order is never payable, and the address-book API is found by shape on the site. A company book or inbound `ShipTo` does not imply an external address service.
- **Checkout block support**: a visit needs the classic checkout; the plugin declares itself incompatible with the cart and checkout blocks.
- **A parallel requisition cart**: rejected by design; the live cart is the single source of truth.

## Receiver acceptance

The supplier implements company-owned addresses, explicit delivery confirmation and optional destination, code, notes and freight export. Generated samples validate against the exact 1.2.008 and 1.2.071 DTDs. **Positive supplier support and valid XML do not establish receiver consumption**, including in Dynamics 365. Before enabling a company connection, verify:

1. **Returned basket and encoding:** inspect the actual received lines for the configured `cxml-base64` or `cxml-urlencoded` mapping.
2. **Empty closeout:** verify zero-line returns from the abandon control. No extra freight is added.
3. **Browser authentication:** test the cross-site return without losing the buyer session.
4. **Destination, code and notes:** enable each mapping independently and verify consumed values, including the repeated per-merchandise-line `DeliveryInstructions` policy. An address code is a reference, not a registration request.
5. **Freight, units and currency:** verify one freight charge, merchandise-plus-freight arithmetic, accepted UOM/classification and the explicitly omitted unavailable estimate. Do not infer acceptance from the absence of a parse error.
6. **Catalogue-restriction plugins**: the add-to-cart guard is WooCommerce's own visibility and purchasability chain, applied to the bound account — whatever decides what that account may see decides what a visit may add, and the plugin offers no seam of its own to widen or narrow it. Whether a specific third-party restriction setup filters every leak surface (search, direct URL, REST) is a site-deployment test, not a plugin guarantee — verify on staging with a contract-priced catalogue, signed in as the bound account, before onboarding a buyer whose pricing is confidential.
