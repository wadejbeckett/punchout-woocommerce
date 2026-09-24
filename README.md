# PunchOut for WooCommerce

An AGPLv3 WordPress plugin that makes a WooCommerce store a **cXML PunchOut supplier site** for direct-cXML procurement buyers, configured independently per company.

A buyer opens the store from their procurement system. It sends a cXML `PunchOutSetupRequest`; the plugin authenticates the customer, opens a new visit on that customer's one bound WooCommerce account and returns a one-time StartPage login URL. The buyer shops the ordinary WooCommerce cart at that account's prices, reviews the destination, native shipping methods and notes, then returns a cXML `PunchOutOrderMessage` for requisition/RFQ approval. Checkout is blocked inside the visit.

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
        │                                │◀────────────────────────────────────│
        │                                │ 6. redeem token (single-use,        │
        │                                │    atomic), log the browser in,     │
        │                                │    302 → landing page               │
        │                                │ 7. shop: normal WC()->cart,         │
        │                                │    buyer-priced catalogue           │
        │                                │◀───────────────────────────────────▶│
        │                                │ 8. the one cart exit:               │
        │                                │    "Send for approval"              │
        │                                │        → /punchout/confirm          │
        │                                │        review, confirm and return   │
        │ 9. browser POSTs the cXML PunchOutOrderMessage (cxml-base64          │
        │    hidden field) top-level to the BrowserFormPost URL; this          │
        │    visit's own login token and WooCommerce session row are           │
        │    destroyed, and every other live visit of the account — a          │
        │    colleague shopping in the next room — is untouched                │
        │◀───────────────────────────────┼─────────────────────────────────────│
```

Steps 1–3 are server-to-server; steps 5+ are the buyer's browser. The one-time token links the pending visit to the login that redeems it. The mid-visit abandon control posts an **empty** PunchOutOrderMessage, the cXML cancel semantic, and adds no delivery charge. The builder can carry a `SupplierOrderInfo` order reference in verified cXML 1.2.071 and 1.2.008 omits it, but no return path supplies one: a visit cannot pay here, so no order of ours exists for a returned basket to name.

### Key design decisions

**PunchOut only.** The reviewed cart return is the only exit from a punchout visit. Classic checkout, Blocks checkout and direct payment entry points are all blocked inside a visit; ordinary shoppers, including anyone who signs into the bound account with a password, are unaffected.

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
│                                   pow()/pow_is_punchout()/pow_return_button() helpers
├── uninstall.php                   Drops the tables, the options and the plugin's own
│                                   rows in the WooCommerce sessions table; no user is touched
├── readme.txt                      WordPress plugin directory format
├── bin/build-zip.sh                Builds the distributable zip (runtime files only)
├── phpunit.xml.dist
├── templates/                      Theme-overridable buyer-facing surfaces
│   ├── return-button.php           The "send for approval" cart button
│   ├── abandon-button.php          The "return without a cart" control
│   ├── handoff.php                 The auto-submitting cart-return page
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
    │                               Tokens, ReplayPolicy, ConsentFence
    ├── Http/                       Router, Setup/Start/Return endpoints, RateLimiter
    ├── Buyers/Identity.php         Who punched in, read off the request — a value
    │                               object, never an account
    ├── Cart/                       SessionKey (the per-visit key), NativeSessionGuard,
    │                               NativeSessionHandler/Row (one basket per visit),
    │                               Guard, Surface (button/shortcode), PoomMapper
    ├── Orders/                     Status (punchout-quote), QuoteOrder (create, convert,
    │                               retention, buyer attribution)
    ├── Addresses/                  Company delivery book, destination resolution and the
    │                               mandatory per-visit delivery review
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
| `wp_pow_partners` | Company-connection registry: identities, sealed secrets (current+previous), cXML version, deployment mode, return encoding, delivery configuration, ALL-CAPS flag, IP allowlist, the bound customer account every buyer of this connection punches in as (`owner_user_id`), TTLs, the My Account pages its visits may open (`visit_endpoints`, `dashboard` for the account page itself), whether buyers may add delivery addresses to the company book (`buyer_addresses`, off by default) and the actions the bound account may take itself (`owner_settings`, empty by default; `reset_connection` is the only one). The open-visit cap is a constant, not a column |
| `wp_pow_sessions` | One row per PunchOutSetupRequest — a visit, not a person, so many open rows naming one `user_id` is the normal state: BuyerCookie, BrowserFormPost URL, the bound account, hashed one-time token, exact WP session token, the per-visit WooCommerce session key (`wc_session_key`, UNIQUE, NULL until the token is redeemed), the buyer identity, name and indexed identity hash read from the request, state machine (`pending → active → returned/closed/expired`), payloadID + body hash (replay), stored response (pending replay), captured ShipTo/SelectedItem/extrinsics, confirmed delivery choice and cart/policy/rate/notes snapshot |
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
- The four payment-method pages are shown disabled. They open only for a connection whose exit policy allows WooCommerce's own checkout, and every connection is punchout-only.
- Log out, lost password, order-pay, order-received and the Punchout integration tab are shown disabled, marked "Stays closed in a visit". WooCommerce has no account content for the first four (order-pay and order-received are checkout pages, and checkout stays refused in every visit), and the tab shows nothing inside a visit.
- Pages added by other plugins are off by default. Creating users inside a visit is blocked, whatever those pages offer.

**Defaults.** A new connection ticks the dashboard and nothing else. WooCommerce's other pages and every page another plugin adds stay off until an administrator ticks them. An upgrade changes no existing connection's list: a list saved under 0.4.5 has no dashboard, so its dashboard stays closed until it is ticked.

**Storage.** The ticked pages are one column, `visit_endpoints` (`VARCHAR(255)`, comma-separated endpoint names), and `dashboard` stands for the account page itself. A save is refused whole, with a notice, when an entry is not a lowercase endpoint name, when a payment-method page is ticked on a punchout-only connection, or when the ticked pages do not fit the column. A page that never opens in a visit is left out of the list a visit reads whatever the row holds. Saves run under the connection lock and are audited as `partner_saved` with the list saved. The account holder sees the list, read-only, on **My Account → Punchout integration**.

**What opens.** Inside a visit an account page opens only when every endpoint the request names is ticked and has account content of its own (a `woocommerce_account_<endpoint>_endpoint` action) on that request. The account page naming no endpoint opens when the dashboard is ticked. Without content WooCommerce would show the dashboard in the page's place, so such a page stays closed and leaves the menu, even when the dashboard is ticked. Log out, lost password, order-pay, order-received and the Punchout integration tab never open in a visit, whatever the row holds, and neither does a page another plugin registers under the name `dashboard`, which this list uses for the account page itself. A name that is not registered opens nothing, and neither does an endpoint whose plugin shows its content only to some accounts until the bound account is one of them. The check runs at the start of `template_redirect` and again at its end. The account menu in a visit shows the dashboard when it is ticked and the other ticked pages that open, and nothing else. The dashboard's own text links to other account pages; those stay closed unless ticked. Its "not you? Log out" link does not show in a visit (since 0.4.7): logging out would end the visit and delete its basket, so inside a visit the dashboard is the plugin's `templates/account/dashboard.php`, WooCommerce's dashboard without that sentence, with the same dashboard hooks. Outside a visit WooCommerce's or the theme's dashboard renders as usual. Anything another plugin prints on the dashboard itself shows with it, so check the dashboard as the bound account before ticking it.

WooCommerce shows "Your account is using a temporary password" on the dashboard and on account details for an account whose password it generated (the `default_password_nag` user option, which WordPress also sets on self-registered accounts), with a Resend link that mails the account a new password-reset link. Inside a visit that option reads false, so neither the notice nor the link renders, and a request carrying WooCommerce's Resend action (`?wc-resend-set-password`) is refused before WooCommerce handles it. The account holder's own login keeps the notice.

A listed page runs as the shared account, so anything it saves per user is shared by every buyer of the connection, with no record of which buyer changed it. Check that before listing a page that stores data. Account links your theme or page builder places outside the account menu are not filtered; hide them in a visit with `POW\pow_is_punchout()`.

A visit never creates a WordPress user through WordPress's user insert, the routine that registration forms, the users REST route and other plugins' AJAX actions (a sub-account form, say) all use. Inside a visit it is refused and audited as `visit_user_create_refused`, once per request, with the script, AJAX action, `wc-ajax` action, REST route and path that asked. Code that writes the users table directly, without WordPress, is outside any hook and is not covered.

### Account holder actions (0.4.8)

The connection's edit screen has a row **Account holder actions** with one checkbox: **Reset connection from the My Account “Punchout integration” tab**. It is off by default, for new and existing connections; saving stores `reset_connection` in `owner_settings` and the save is audited as `partner_saved`.

When it is ticked and the connection is active, the bound account, signed in normally (not in a punchout visit), sees a **Reset connection** section on **My Account → Punchout integration**: a warning, a confirmation checkbox and a button, in a form of its own beside the setup-XML download. A reset:

- revokes the current and previous shared secrets and ends every open punchout visit of the connection at once (their baskets go with them);
- issues a new shared secret and shows it once, on the no-store response to that POST. It is not stored in plain text, not in a transient, not logged and not e-mailed; a reload cannot show it again;
- keeps the saved identities, the bound account, the delivery book and every other setting;
- e-mails the account a notice without the secret, and is audited as `registration_owner_reset` (or `registration_owner_reset_failed` with a reason), with the account as the actor.

It is the administrator's reset (same fence, revoke, end-visits and issue sequence under the connection lock), never a rotation, and the purchasing system stops working until the new secret is pasted in. Registration re-checks under the lock that the request comes from the bound account, the connection is active and still grants the action, and the request is not inside a visit. Inside a visit the tab and the reset do not exist. With the box unticked the tab is exactly as before and a reset POST gets no answer.

The return button label is not offered here. It is one plugin-wide setting (**Punchout button label**, below), not a per-connection value, so there is nothing per connection to hand to the account holder.

## Company delivery book and confirmation

Cart confirmation currently supports **ZAR only**. Set the WooCommerce store currency and the receiving catalogue currency to ZAR. Confirmation refuses a different currency; the plugin does not convert currencies.

The core book belongs to the connection's bound store account. Shop administrators manage **Company delivery addresses** on the customer administration screen; buyers select any enabled entry inside their own visit. The book is read-only inside a visit unless the connection ticks **Buyers may add delivery addresses to the company book**; then a buyer can only add an entry (enabled, coded with the connection's prefix, preselected for that cart, and audited as `address_book_buyer_added` with the visit's session, a buyer hash and the buyer's name when sent). Buyers never change or remove entries. No third-party address-book plugin, additional credential store or directory integration is required.

Add an address manually, or preview and explicitly copy the bound account's normal WooCommerce billing or shipping address. New entries and imports start disabled. Review the saved entry, then enable it separately (`use_for_punchout`). A normal Woo address save never synchronises the book. Inbound `ShipTo` and the bound account's own profile address are per-visit candidates for explicit review — on a fresh visit the latter is the company's default destination, not one employee's — and neither silently replaces the company master data or provides an address-list pull API.

Every physical cart return requires the **same punchout visit** to review and confirm a full destination, a native shipping method for each package and optional notes. Two visits on one bound account are two independent confirmations, and neither can confirm or invalidate the other's. This includes pickup, a singleton address or method, and all export flags being off. The cart control opens `/punchout/confirm`; `[punchout_delivery_confirmation]` renders the same review on a page. Both post to that dedicated nonced route. A direct `/punchout/return` POST cannot bypass the confirmation checks. Virtual-only baskets record delivery as `not_required`.

A valid existing shipping choice of that visit is preserved; otherwise WooCommerce's native default helper selects within the current cart context. The plugin adds no cheapest-rate or pickup preference. Unknown delivery is `null`/unavailable, never zero. With `emit_delivery_line` enabled, the default `delivery_unknown_policy=require_rate` refuses confirmation if a rate is unavailable. Explicit `quote_separately` allows the buyer to acknowledge the omission and continue without a freight line or Quote shipping charge. A real quoted zero remains a known rate. With charge export off, the available estimate or unavailable state is reviewed and retained locally without a charge.

Confirmation binds the cart, destination, rates, configuration and notes to the visit — including the key of the basket it was taken from, so one visit's consent can never validate another's cart. Changes require fresh review. A selected company entry must still exist, be enabled and match its confirmed address, label and code at confirmation and final return. Removal or disablement requires an eligible reselection; a changed selected entry requires reconfirmation. An unrelated revision of the book does not invalidate an unchanged selected entry. Completed return and Quote snapshots remain immutable after later master edits.

Notes are sanitised plain text, at most 2,000 characters and 8,000 bytes. They are local by default. `delivery_notes_policy=item_detail_extrinsic` additionally copies the basket note into every merchandise `ItemDetail/Extrinsic` named `DeliveryInstructions`, excluding freight, under either verified DTD. Agree this repetition with the receiver before enabling it.

Address codes are company-scoped references, not URLs or registered receiver records. They allow `A–Z`, `0–9`, `_` and `-`, up to 32 characters; the optional prefix is limited to 24 characters and address labels to 190. Changed or removed issued codes leave their claims retired permanently, so another address cannot reuse them. A blank code preserves an existing code; for a new entry, a configured prefix generates one, otherwise it remains uncoded.

### Independent delivery configuration

These are actual connection keys; optional emission is off by default. The supplier review remains mandatory independently of receiver export.

| Key | Default | Effect |
|---|---|---|
| `emit_ship_to` | `false` | Export the full confirmed destination in `ShipTo`, with or without a code |
| `emit_delivery_code` | `false` | Export a usable code through the fields allowed by the exact cXML version |
| `emit_delivery_line` | `false` | Include one native freight total and matching optional Quote shipping items |
| `delivery_unknown_policy` | `require_rate` | When charge export is enabled, refuse an unavailable estimate; `quote_separately` permits acknowledged omission |
| `delivery_notes_policy` | `off` | Keep notes local; `item_detail_extrinsic` additionally exports repeated merchandise notes |
| `delivery_code_prefix` | empty | Optional prefix for generated company codes |
| `delivery_code_extrinsic_name` | `DeliveryAddressCode` | Name of the optional direct `ItemIn/Extrinsic` |
| `freight_supplier_part_id` | `DELIVERY` | Supplier ID of the typed freight line |
| `freight_uom` | `EA` | Freight unit of measure |
| `freight_classification_domain` | `supplier` | Freight classification domain |
| `freight_classification` | `freight` | Freight classification value |

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
- **Replay**: `UNIQUE(partner_id, payloadID)` plus written-down semantics — a duplicate with an identical body while `pending` replays the stored response byte-identically (legitimate retry); any duplicate after token redemption is a cXML 409.
- **Visits**: the exact WP session token created at auto-login is recorded, so the return destroys *that* login only and a colleague's stays live; the visit also owns one `wp_woocommerce_sessions` row under its own key, which is the only basket its requests read or write and which a foreign key is refused for. Auth-cookie expiry is the customer's session TTL (default 4 h), the WooCommerce session and any Store API cart token are capped at what the visit has left, and cron reaps stragglers — it is the only collector of abandoned visits.
- **Checkout**: blocked at classic checkout, the Store API and direct payment boundaries inside a punchout visit, and nowhere else. A password login to the bound account shops and checks out normally; a Punchout Quote is not payable anywhere.
- **Pre-auth surface** is `/punchout/setup` alone: 2 MB body cap, content-type check, XXE hardening, per-(customer, IP) rate limiting, optional per-customer CIDR allowlist, generic 401s.
- **Pricing-leak guards**: every `/punchout/*` response sends `no-store` + `X-Robots-Tag: noindex`; the handoff page is never cacheable; add-to-cart validates range server-side against WooCommerce's own visibility and purchasability chain (hiding a button is not access control); and inside a visit the request is refused at wp-admin, `/wp/v2/users*`, `/wp/v2/application-passwords*`, application passwords for the account, every My Account page the connection has not ticked, wp-login.php except logout, another visit's basket (including by Cart-Token) and any quote order that is not this visit's own. No user can be created inside a visit through WordPress's user insert. Add `Disallow: /punchout/` to robots.txt at deployment (see runbook) — the plugin does not rewrite robots.txt itself.
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
| Landing page | shop page | Post-login redirect + route-guard home |
| Login link lifetime | 300 s | One-time StartPage token TTL |
| Session lifetime | 4 h | Punchout login TTL (per-partner override) |
| Setup rate limit | 30/min | Per partner+IP on `/punchout/setup`; 0 uses the default (30). The public self-test preserves positive values and uses 10/min when this is 0. |
| Setup edge limit | 120/min | Per IP before method checks, body reads, parsing or audit storage on `/punchout/setup`; 0 uses the default (120) |
| Log retention | 400 days | Audit-table trim horizon |
| Punchout button label | "Punchout" | Text on the cart-return button; blank = the default |
| Cancel button label | "Return without a cart" | Text on the abandon control; blank = the default |
| Extra button classes | empty | Space-separated classes added to the Punchout exit, the abandon control and the review page's Submit/Update buttons; pow-* and checkout-button are ignored; the Cart block's own button does not receive them |
| Default UNSPSC | empty | Classification fallback for unmapped SKUs |
| Convert quotes to | Pending payment | Status the order action moves a Punchout Quote to |
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
| Whether an empty PunchOutOrderMessage closes a session cleanly | the abandon control's behaviour |
| IP allowlisting requirements and the agreed destination, code, notes and freight mapping | Deployment plus independent optional-field acceptance; no address-list API is implied |

**Deployment checklist (outside the plugin):** the buyer-facing URL must be the canonical host with no redirect in the path; any WAF/bot-management layer needs a skip rule for `POST /punchout/setup` (a challenge page kills setup invisibly); CDN cache rules must bypass `/punchout/*` and cookie-carrying requests; `robots.txt` gets `Disallow: /punchout/`. Prove all of it by POSTing a real setup body through the full public path.

**Browser return acceptance:** the cart return is a cross-site, top-level form POST. Verify that the buyer stays signed in and receives the basket in the intended browser and purchasing system. A supplier-generated handoff alone cannot prove the receiver's authentication and cookie behaviour.

---

## Placing the button

Three equivalent ways; all render only inside an active punchout session and output nothing otherwise:

- Shortcode: `[punchout_return_button]` (page builders, widgets, block editor shortcode block)
- PHP: `pow_return_button();` in any template
- Automatic: injected on the cart page at `woocommerce_after_cart_totals` priority 5, outside the proceed-to-checkout container (themes and page-builder cart elements hide or replace that container wholesale). Inside a visit every callback on `woocommerce_proceed_to_checkout` and on the mini-cart buttons hook is cleared and every link to the checkout page is hidden, so the return control stands alone; outside one the cart is untouched

The cart-return control opens the mandatory delivery review at `/punchout/confirm`. For a dedicated review placement, use `[punchout_delivery_confirmation]`; its forms still post to the protected confirmation route. Custom cart markup cannot bypass the final return checks.

Markup comes from `templates/return-button.php` — override it by copying to `{theme}/punchout-woocommerce/return-button.php`, or point the `pow_template_return-button` filter anywhere. Same pattern for `handoff.php` and `abandon-button.php`.

Label: the **Punchout button label** setting (blank = "Punchout"), or the `pow_return_button_label` filter, which runs after it — so a filter always wins over the saved value.

## Leaving without a cart

`[punchout_abandon_button]` (or `pow_abandon_button();`) renders the mid-session abandon control — a POST of an empty PunchOutOrderMessage, the cXML cancel semantic, so the buyer's procurement application learns the session ended with no items. Same endpoint, same nonce and the same authorisation checks as the cart return; it has no automatic placement, because no core hook means "the session chrome". Label: the **Cancel button label** setting, or the `pow_abandon_button_label` filter, which runs after it.

Both controls are submit buttons inside real forms, never links, and both are placed in a builder's global header or footer as often as on the cart — i.e. outside the `.woocommerce` wrapper most themes hang their button styling off. The plugin therefore adds a generic default-button class for the active theme where one exists; `pow_button_classes` replaces the whole list.

## Punchout Quote orders

A successful cart-return winner optionally records a WooCommerce **Punchout Quote** (`punchout-quote`) with the mapped lines, quantities and ex-tax prices, delivery address and returned cXML basket. The endpoint prepares the document and handoff before selecting the winner; only that winner creates the quote from the same mapped snapshot. Construction starts in native `auto-draft` at the first insert, so intermediate saves cannot expose a payable order or an incomplete convertible quote. The completed construction is then promoted to Punchout Quote. WooCommerce's new-order hook is deferred until the order leaves staging, through normal promotion or best-effort cancellation. The quote records the basket; the requisition itself lives in the buyer's procurement system.

Three properties come from WooCommerce core rather than from code here, which is what makes the status safe to leave in the orders list: **no stock movement** (stock reduction binds completed/processing/on-hold and payment-complete only), **no transactional email** (core's email list is a fixed set of status pairs; no custom status can join it), and **no Analytics revenue** (excluded by filter, so the operator's own Analytics setting is untouched and deactivating the plugin reverts it). The status has its own link in the orders list filter row and its own "Change status to Punchout Quote" bulk action.

Quote persistence and diagnostic failures do not block the prepared handoff. Cancellation, logging, audit (`quote_order_failed`) and the administrator notification are individually best effort; notifications are limited to once an hour after successful mail submission. An unsuccessful cancellation is reported as unconfirmed. A creation hook may fail after inserting an auto-draft but before returning its ID, so an unknown ID does not mean no row exists. Such abandoned auto-drafts remain non-payable under native WooCommerce rules, but native WordPress/CPT and WooCommerce/HPOS cleanup can permanently delete them once older than a week. They are not durable audit evidence, and the plugin's quote-retention setting does not preserve them. Check known IDs and available diagnostics promptly; simultaneous reporting failures cannot guarantee an audit record.

**Converting.** The order screen's *Order actions* box offers **Convert Punchout Quote to order** on a quote and on nothing else. It moves the order to the **Convert quotes to** status (Pending payment, Processing or On hold), leaves a note, and writes a `quote_order_converted` audit row naming the administrator who did it. The converted quote is a normal WooCommerce order: Processing and On hold run native stock reduction where applicable, and normal email and fulfilment hooks remain enabled. The returned basket XML stays on the order. A failed conversion is recorded as `quote_order_convert_failed` with an error result, without a success audit row.

**Retention.** The hourly housekeeping job queries up to 100 oldest quotes created before the **Quote retention** cutoff, using UTC second precision. Each fetched order must still be a Punchout Quote before it can be cancelled. Successful cancellations leave a note and a `quote_order_expired` audit row; orders are never deleted and the basket XML stays on them. Native cancellation restores stock only if that order previously reduced it; quotes created by this plugin have not reduced stock. A failed query or order operation records `quote_order_expire_failed` with an error result; a failed order does not count as a successful expiry and does not stop later candidates. Set retention to 0 to keep quotes indefinitely.

Before deploying, verify conversion on staging for each configured target: the status and note must land, Processing/On hold must apply normal stock reduction where applicable, and Pending payment must leave an unreduced quote's stock unchanged. Check native email behaviour with a mail logger. Stub tests do not prove these WooCommerce effects.

**Order meta.** The returned document and confirmation provenance use protected, underscore-prefixed keys:

| Key | Holds |
|---|---|
| `_pow_poom_xml` | The cXML PunchOutOrderMessage that was returned to the buyer |
| `_pow_session_id` | The punchout session (`wp_pow_sessions.id`) the order came from |
| `_pow_partner_id` | The customer connection |
| `_pow_delivery_code` | The code on the confirmed destination, when one is available |
| `_pow_delivery` | Accepted native delivery estimate, package rates, freight configuration and emission state |
| `_pow_delivery_choice` | Exact stored destination-choice JSON from the return winner |
| `_pow_delivery_confirmation` | Exact stored confirmation JSON, including cart/policy binding and notes |
| `_pow_delivery_notes` | Confirmed plain notes, also copied to the native order customer note |
| `_pow_buyer_identity` | The buyer's e-mail or identity from the cXML request (`UserEmail`, `UniqueUsername`, `UniqueName`, `Contact/Email`); written empty when the request named nobody, and absent altogether on a quote taken before 0.4.0 |
| `_pow_buyer_name` | The buyer's own spelling of their name (`UserPrintableName`, `UserFullName`, `User`); written empty when the request sent no name, and absent altogether on a quote taken before 0.4.0 |
| `_pow_buyer_cookie` | The BuyerCookie of the visit, the purchasing system's own correlator |
| `_pow_partner_name` | The connection's name as it stood when the quote was made |

The order's `customer_id` stays the bound store account, because that is who shopped. The same two facts appear twice more for people who never read order meta: as an order note, and as a **Bought by** line under the billing address on the order screen — `Bought by {name} ({email}) via PunchOut ({connection})`, the e-mail alone when no name was sent, and a plain statement that the purchasing system named nobody when neither was. The address is parenthesised rather than wrapped in angle brackets because an order note is rendered through kses, which would read `<{email}>` as a tag and delete the address. A quote taken before 0.4.0 carries no buyer meta and gets no line at all, rather than being told it named nobody. No e-mail is ever sent to a buyer.

The confirmed cart-return path uses the winner's immutable destination, delivery estimate and notes; it never resolves a fresh fallback over an accepted snapshot. A direct Quote caller without a prepared destination falls back to inbound `ShipTo`, then to the bound account's saved shipping address.

When `emit_delivery_line` is enabled and the confirmed rate is known, the POOM adds one quantity-one freight line equal to the sum of selected native package rates. The matching optional Quote adds native shipping items per package with the same ex-tax sum, exactly once; freight is never added as merchandise or a second fee. Quote totals and emitted XML use the same integer-cent amounts. When charge export is off, or the buyer acknowledges an unavailable estimate under `quote_separately`, the Quote keeps delivery metadata and an estimate note but adds no shipping charge. Later company-book changes cannot rewrite this completed snapshot.

## Integration documentation page

The page covers endpoints, identities, the annotated setup request, returned carts, status codes, company addresses and confirmation, independent delivery flags, rate limits and secret rotation. Store administrators also receive a complete Dynamics setup template for each active company; the holder of that connection's bound store account downloads the same XML from My Account. It uses the company's static From, Sender, To, cXML version, mode and HTTPS supplier setup URL, retains only a SharedSecret placeholder, and leaves buyer-system runtime fields blank. UserEmail is added separately through the buyer system's extrinsics mapping and is not duplicated in the downloaded XML. The populated fictional buyer-sent specimen is parsed by `Cxml\Parser` and checked against its exact offline DTD; outbound examples use `Cxml\Builder` and the real freight-line producer with invented rates. Flags-off and full enabled examples use exact verified DTD fixtures; displayed totals are derived from the same lines. These samples do not certify a receiving tenant or execute native shipping callbacks.

Publish it by creating a page and adding the shortcode, then send buyers that page's URL:

```
[punchout_docs]
```

Shortcode only — core's Shortcode block inserts it in the block editor, so a custom block would buy nothing but a JS build step. The page renders while punchout is switched off (which is when a new buyer usually reads it) and exposes no session surface.

It carries a **self-test**: paste a PunchOutSetupRequest or ProfileRequest, get back the cXML Status the live endpoint would have answered with, and why. Nothing is stored, no session is created, and any `SharedSecret` in the paste is redacted before it is echoed back. For an anonymous visitor the three authentication stages collapse into one result — splitting them on a public page would be a credential oracle — and the run is throttled on the setup endpoint's own rate limiter and written to the audit log (`docs_self_test`).

Store administrators get the same page with more on it at **WooCommerce > PunchOut > Integration docs**: a per-connection block with the complete setup XML and a self-test that names the exact authentication stage. The holder of a connection's bound store account downloads the same template from **My Account → Punchout integration**; ownership is resolved server-side and the request carries a nonce. That tab is the one surviving customer-facing surface and it is download-only — no editing, no secret, no rotation — unless an administrator switches on the opt-in **Reset connection** for that connection (see *Account holder actions*), and it does not exist inside a punchout visit.

Override the markup by copying to `{theme}/punchout-woocommerce/docs/page.php` (or `docs/self-test.php`), or via the `pow_template_docs/page` filter.

## Extension points

| Hook | Type | Purpose |
|---|---|---|
| `pow_return_button_label` | filter | RFQ button text (applied after the setting) |
| `pow_abandon_button_label` | filter | "Return without a cart" text (applied after the setting) |
| `pow_button_classes` | filter | Class list of either exit control (`$classes, $base, $themed`) |
| `pow_template_{return-button,abandon-button,handoff,docs/page,docs/self-test,account/dashboard}` | filter | Replace any buyer-facing template |
| `pow_delivery_codes_enabled` | filter | Legacy custom-docs-template display hint; the bundled docs show all delivery settings, and this filter does not control emission |
| `pow_handoff_copy` / `pow_expired_token_message` | filter | Buyer-facing strings |
| `pow_start_redirect` | filter | Post-login destination |
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
```

## Tests

`tests/` holds a pure-PHP unit suite for the codec and session/token logic — no WordPress loaded, WP-free by design. Run `php tests/run-tests.php` anywhere (a PHPUnit-compatible shim runs without Composer), or `phpunit -c phpunit.xml.dist` where PHPUnit exists. DOM-based codec tests skip automatically on a PHP without `ext-dom`. See `tests/README.md`.

---

## What is deliberately NOT built

- **Inbound cXML `OrderRequest` receiver** (`/punchout/order`): answers cXML 450. Building it means idempotent Woo order creation, SKU/price reconciliation and an agreed acknowledgement contract — priced separately once a buyer confirms cXML PO delivery.
- **edit/inspect re-entry**: non-create operations answer cXML 450 and the POOM declares `operationAllowed="create"`. The registry retains `allow_reentry` for compatibility; no re-entry flow is implemented.
- **Ariba network hops, CredentialMac, client certificates, `ds:Signature`**: only direct punchout with SharedSecret auth is implemented.
- **Invoicing (`InvoiceDetailRequest`), order-status write-back, catalog uploads**: out of scope.
- **Per-company payment gateway lists and external address-list APIs**: not implemented. Checkout is simply unavailable inside a punchout visit; native Woo shipping calculates the reviewed delivery estimate. A company book or inbound `ShipTo` does not imply an external address service.
- **A parallel requisition cart**: rejected by design; the live cart is the single source of truth for both exits.

## Receiver acceptance

The supplier implements company-owned addresses, explicit delivery confirmation and optional destination, code, notes and freight export. Generated samples validate against the exact 1.2.008 and 1.2.071 DTDs. **Positive supplier support and valid XML do not establish receiver consumption**, including in Dynamics 365. Before enabling a company connection, verify:

1. **Returned basket and encoding:** inspect the actual received lines for the configured `cxml-base64` or `cxml-urlencoded` mapping.
2. **Empty closeout:** verify zero-line returns from the abandon control. No extra freight is added.
3. **Browser authentication:** test the cross-site return without losing the buyer session.
4. **Destination, code and notes:** enable each mapping independently and verify consumed values, including the repeated per-merchandise-line `DeliveryInstructions` policy. An address code is a reference, not a registration request.
5. **Freight, units and currency:** verify one freight charge, merchandise-plus-freight arithmetic, accepted UOM/classification and the explicitly omitted unavailable estimate. Do not infer acceptance from the absence of a parse error.
6. **Catalogue-restriction plugins**: the add-to-cart guard is WooCommerce's own visibility and purchasability chain, applied to the bound account — whatever decides what that account may see decides what a visit may add, and the plugin offers no seam of its own to widen or narrow it. Whether a specific third-party restriction setup filters every leak surface (search, direct URL, REST) is a site-deployment test, not a plugin guarantee — verify on staging with a contract-priced catalogue, signed in as the bound account, before onboarding a buyer whose pricing is confidential.
