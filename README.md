# PunchOut for WooCommerce

An AGPLv3 WordPress plugin that makes a WooCommerce store a **cXML PunchOut supplier site** for direct-cXML procurement buyers, configured independently per company.

A buyer opens the store from their procurement system. It sends a cXML `PunchOutSetupRequest`; the plugin authenticates the company, provisions a separate buyer account and returns a one-time StartPage login URL. The buyer shops the ordinary WooCommerce cart at their configured prices, reviews the destination, native shipping methods and notes, then returns a cXML `PunchOutOrderMessage` for requisition/RFQ approval. Where their effective exit policy permits it, they can instead pay through native WooCommerce checkout.

**Status: supplier implementation; release acceptance and receiver certification are separate gates.** The generated samples are checked against the exact offline cXML 1.2.008 and 1.2.071 DTDs. This does not establish consumption by a buyer system, including Dynamics 365. See [Receiver acceptance](#receiver-acceptance) before onboarding.

- **Licence:** AGPL-3.0-or-later (full text in `LICENSE`)
- **Requires:** PHP 8.2+, WordPress 6.4+, WooCommerce 8.0+
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
        │                                │    provision/locate buyer user      │
        │                                │    create session row (pending)     │
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
        │                                │ 8. cart exits:                      │
        │                                │    (a) "Send for approval"          │
        │                                │        → /punchout/confirm          │
        │                                │        review, confirm and return   │
        │                                │    (b) stock Woo checkout           │
        │                                │        (when exit policy allows)    │
        │ 9. browser POSTs the cXML PunchOutOrderMessage (cxml-base64          │
        │    hidden field) top-level to the BrowserFormPost URL; the           │
        │    store login is destroyed either way                               │
        │◀───────────────────────────────┼─────────────────────────────────────│
```

Steps 1–3 are server-to-server; steps 5+ are the buyer's browser. The one-time token links the pending setup to the buyer login. The pay exit (b) offers "Return to your purchasing system" on the order-received page, posting an **empty** PunchOutOrderMessage. The Woo order reference uses `SupplierOrderInfo` only in verified cXML 1.2.071; 1.2.008 omits it. Empty and paid closeouts add no delivery charge.

### Key design decisions

**Native checkout, scoped exit permission.** The reviewed cart return is an additional exit. Global, company and buyer policy determine whether the punchout login may also use native checkout. Punchout-only sessions are blocked at classic, Blocks and direct payment entry points; ordinary shoppers are unaffected. The allowed pay path retains native checkout, order linkage, payment lifecycle handling and an empty-cart closeout.

**Multi-tenant registry, policy over code.** Every customer difference is a column, not a branch: identities, cXML version, return encoding, exit mode, TTLs, IP allowlist, ALL-CAPS outbound transform, optional customer-group mapping. Adding a second buyer is a registry row plus a certification exercise.

**cXML codec on DOMDocument.** The parser accepts `PunchOutSetupRequest` and `ProfileRequest`; the builder emits setup/profile responses, `PunchOutOrderMessage` and `Status`. Parsing rejects entity declarations, uses `LIBXML_NONET`, performs structural validation and never fetches a DTD at runtime. Tests validate generated samples against hash-verified offline fixtures for the exact declared 1.2.008 or 1.2.071 version. Optional field support uses those exact versions, not a guessed version threshold.

**Raw `parse_request` routing, no rewrites.** `/punchout/setup` must accept a raw `text/xml` POST whose body survives untouched. The router matches the raw request path at `parse_request` priority 0 and exits — no rewrite rules, no canonical processing (WP core cannot 301 a POST), no theme bootstrap. Webserver/CDN-level redirects remain a deployment concern: the URL given to buyers must be the exact canonical origin (see the runbook).

**cXML failures inside HTTP 200.** The setup endpoint answers `Status` codes (`401` auth, `406` invalid, `409` replay, `450` unsupported, `500`, `550` rate-limited) in an HTTP 200 — procurement clients read the envelope, and an HTTP 4xx alongside a valid response has broken real integrations.

**One WP user per (company, buyer identity).** WooCommerce keys the session and cart on the user ID. Identity comes from `UserEmail`, then `UniqueUsername`, then `UniqueName`, then `Contact/Email`, falling back to a flagged ephemeral user for that session. The purchasing system's authorised buyers are automatically recognised without a second employee approval queue; they do not share the company owner's login. Same buyer punching out twice: **latest punchout wins** — the new setup expires the old session and destroys its login.

**One cart, two exits.** The live `WC()->cart` serves both exits, so the POOM quotes exactly the prices the buyer would have paid at checkout — pricing plugins apply their prices at cart time and the mapper reads the cart's own line totals. Persistent carts are disabled inside punchout sessions and the cart is emptied once at session start.

**Master switch off by default.** A fresh install exposes no pre-auth XML endpoint until an operator has configured a customer and enabled the feature.

### File tree

```
punchout-woocommerce/
├── punchout-woocommerce.php        Plugin header, constants, bootstrap, HPOS declare,
│                                   pow()/pow_is_punchout()/pow_return_button() helpers
├── uninstall.php                   Drops the tables, options, role; keeps buyer users
├── readme.txt                      WordPress plugin directory format
├── bin/build-zip.sh                Builds the distributable zip (runtime files only)
├── phpunit.xml.dist
├── templates/                      Theme-overridable buyer-facing surfaces
│   ├── return-button.php           The "send for approval" cart button
│   ├── abandon-button.php          The "return without a cart" control
│   ├── closeout-button.php         The pay-path close-out CTA
│   ├── handoff.php                 The auto-submitting cart-return page
│   └── docs/                       The integration documentation page
│       ├── page.php                The page itself
│       └── self-test.php           The paste-a-document self-test box
├── tests/                          Pure-PHP unit suite (no WordPress) + shim runner
└── includes/
    ├── Autoloader.php              PSR-4-style spl_autoload, no Composer
    ├── Plugin.php                  Container / wiring, master-switch gating
    ├── Settings.php                The single option (global knobs only)
    ├── Installer.php               dbDelta schema (3 tables), punchout_buyer role
    ├── Logger.php                  wc_get_logger() wrapper, redacts credentials
    ├── Cron.php                    Session GC, buyer deactivation, log retention
    ├── RouteGuard.php              Session-scoped access control (302s, checkout block)
    ├── Cxml/                       Pure codec: Parser, Builder, FormPack, Money (+DTD)
    ├── Docs/                       Integration documentation page: Page (shortcode),
    │                               Reference (endpoints/status/support), Samples
    │                               (generated by the live codec), SelfTest
    ├── Partners/                   Partner row, Registry (CRUD/auth), Secrets (sodium)
    ├── Sessions/                   Session row+state machine, Store, Tokens, ReplayPolicy
    ├── Http/                       Router, Setup/Start/Return endpoints, RateLimiter
    ├── Buyers/                     Provisioner (fires pow_buyer_provisioned for site glue)
    ├── Cart/                       Guard, Surface (button/shortcode), PoomMapper
    ├── Orders/                     Status (punchout-quote), QuoteOrder (create, convert,
    │                               retention)
    ├── Checkout/PayExit.php        Pay-path listeners (tag, flip, close-out CTA)
    ├── Audit/Log.php               Compliance trail (wp_pow_log)
    ├── Support/                    Ip (CIDR), Templates (theme-overridable rendering)
    ├── Admin/                      Page (4 tabs, Settings API) + Actions (admin-post)
    └── CLI/Command.php             wp punchout …
```

### Data model

Four custom indexed tables (options/postmeta neither index nor GC well for per-request session lookups and an append-heavy audit trail):

| Table | Holds |
|---|---|
| `wp_pow_partners` | Company-connection registry: identities, sealed secrets (current+previous), cXML version, deployment mode, return encoding, exit entitlement, delivery configuration, ALL-CAPS flag, IP allowlist, ordinary owner account association, TTLs |
| `wp_pow_sessions` | One row per PunchOutSetupRequest: BuyerCookie, BrowserFormPost URL, user, hashed one-time token, exact WP session token, state machine (`pending → active → returned/ordered/closed/expired`), payloadID + body hash (replay), stored response (pending replay), captured ShipTo/SelectedItem/extrinsics, confirmed delivery choice and cart/policy/rate/notes snapshot |
| `wp_pow_log` | The audit/compliance trail: every transaction, full POOM XML archives, secrets redacted; retention-trimmed by cron |

---

## Company applications and admin approval

Ordinary logged-in customer accounts can request a company connection from My Account. Applications collect the technical From/Sender identity and deployment mode before store approval. The company account manages its connection; employees authorised by the purchasing system receive separate company-scoped buyer accounts from the supplied stable identifier, normally UserEmail. There is no second employee approval queue or shared company login.

At **WooCommerce ▸ PunchOut ▸ Customers**, pending requests link to the existing edit form. An administrator with `manage_woocommerce` prepares the supplier To identity and connection entitlements, saves, then uses the separate **Approve company** POST. Saving a pending row never activates it or generates credentials. Identity and entitlement changes remain admin-only.

Admin creation, replacement, rotation, approval and reset show any newly issued secret directly in the successful authorized POST response with a no-store policy. Copy it then: later GETs cannot retrieve it, and notices, emails and logs contain no plaintext credential. Approval emails carry no secret; arrange its handover out of band. Rotation retains the previous credential until **Close rotation**; another rotation is refused while that overlap is open.

Save identity changes before **Reset connection**. Reset uses the saved identity, immediately revokes both credentials and recorded buyer sessions, then issues a replacement only after checked cleanup. Pending connections cannot reset. If cleanup or persistence fails, the admin notice reports the incomplete reset instead of claiming success; inspect the connection and retry after recovery. The owner and company book association stay intact. Owner **Deactivate connection now** immediately disables the connection and revokes its recorded sessions; it is not a request for later manual review.

For a legacy connection with owner user ID zero, the edit screen offers a separate admin-only **Associate company account** POST. Select an existing ordinary WordPress user ID that owns no other connection. Provisioned buyers and claimed accounts are refused; matching is never inferred from email, names or third-party company groups. Existing nonzero associations cannot be transferred or cleared. Association does not copy addresses or sign employees in as the owner.

## Company delivery book and confirmation

Cart confirmation currently supports **ZAR only**. Set the WooCommerce store currency and the receiving catalogue currency to ZAR. Confirmation refuses a different currency; the plugin does not convert currencies.

The core book belongs to the associated company account. The company owner and authorised shop administrators manage **Company delivery addresses** on the account integration or customer administration screen; company buyers select all enabled entries in their own shopping sessions. No third-party address-book plugin, additional credential store or directory integration is required.

Add an address manually, or preview and explicitly copy the owner's normal WooCommerce billing or shipping address. New entries and imports start disabled. Review the saved entry, then enable it separately (`use_for_punchout`). A normal Woo address save never synchronises the book. Inbound `ShipTo` and the current buyer's saved shipping address are session candidates for explicit review; they do not silently replace the company master data or provide an address-list pull API.

Every physical cart return requires the **same logged-in buyer** to review and confirm a full destination, a native shipping method for each package and optional notes. This includes pickup, a singleton address or method, and all export flags being off. The cart control opens `/punchout/confirm`; `[punchout_delivery_confirmation]` renders the same review on a page. Both post to that dedicated nonced route. A direct `/punchout/return` POST cannot bypass the confirmation checks. Virtual-only baskets record delivery as `not_required`.

A valid existing buyer shipping choice is preserved; otherwise WooCommerce's native default helper selects within the current cart context. The plugin adds no cheapest-rate or pickup preference. Unknown delivery is `null`/unavailable, never zero. With `emit_delivery_line` enabled, the default `delivery_unknown_policy=require_rate` refuses confirmation if a rate is unavailable. Explicit `quote_separately` allows the buyer to acknowledge the omission and continue without a freight line or Quote shipping charge. A real quoted zero remains a known rate. With charge export off, the available estimate or unavailable state is reviewed and retained locally without a charge.

Confirmation binds the cart, destination, rates, configuration and notes to the buyer session. Changes require fresh review. A selected company entry must still exist, be enabled and match its confirmed address, label and code at confirmation and final return. Removal or disablement requires an eligible reselection; a changed selected entry requires reconfirmation. An unrelated revision of the book does not invalidate an unchanged selected entry. Completed return and Quote snapshots remain immutable after later master edits.

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

### Global, company and buyer exit policy

Shop administrators set global and company `exit_policy` to `inherit`, `punchout_only` or `punchout_and_checkout`. A company inherits the global setting unless explicitly configured; global `inherit` resolves securely to `punchout_only`. The effective company entitlement is the upper bound for an existing buyer. An administrator may restrict that buyer to punchout only; a buyer setting cannot grant checkout above the company entitlement. Company owners and buyers cannot grant themselves entitlement.

The effective policy is checked for the current buyer at return and payment boundaries. Punchout only blocks classic, Blocks and direct pay entry points inside the punchout session. Punchout and checkout retains native checkout alongside the confirmed cart return. Ordinary shoppers are unaffected.

## Security model

- **Shared secrets** are sealed at rest with `sodium_crypto_secretbox` under a wp-config key (`POW_SECRET_KEY`; a documented auth-salt-derived fallback keeps the key out of the database either way), compared with `hash_equals`, write-only in the admin UI, and rotated dual-slot: current and previous both verify during an overlap window, the audit log records which slot matched, and the window is closed explicitly (admin POST form or `wp punchout close-rotation`).
- **Outbound baskets never carry a shared secret.** The builder has no code path that writes a `SharedSecret` node, and the unit suite asserts its absence on both POOM variants (browser-transported Messages carry an identity-only Sender by DTD rule).
- **StartPage tokens** are 256-bit random, URL-safe, single-use (an atomic one-query status flip), short-TTL, and stored only as SHA-256. Invalid/expired/used all produce the same detail-free 403.
- **Replay**: `UNIQUE(partner_id, payloadID)` plus written-down semantics — a duplicate with an identical body while `pending` replays the stored response byte-identically (legitimate retry); any duplicate after token redemption is a cXML 409.
- **Sessions**: the exact WP session token created at auto-login is recorded, so either exit destroys *that* login only; auth-cookie expiry is the customer's session TTL (default 4 h), and cron reaps stragglers.
- **Pre-auth surface** is `/punchout/setup` alone: 2 MB body cap, content-type check, XXE hardening, per-(customer, IP) rate limiting, optional per-customer CIDR allowlist, generic 401s.
- **Pricing-leak guards**: every `/punchout/*` response sends `no-store` + `X-Robots-Tag: noindex`; the handoff page is never cacheable; add-to-cart validates range server-side (hiding a button is not access control); the route guard 302s punchout sessions away from account surfaces and other users' orders. Add `Disallow: /punchout/` to robots.txt at deployment (see runbook) — the plugin does not rewrite robots.txt itself.
- **Audit**: every setup, token redemption, login, rejected add-to-cart, cart send (full XML), order, payment event, rotation and GC action lands in `wp_pow_log` with secrets redacted — WooCommerce log files rotate away; the dispute evidence (prices quoted to a named buyer at a timestamp) must not.

---

## Install

1. Copy the `punchout-woocommerce` directory into `wp-content/plugins/` (or build a zip with `bin/build-zip.sh` and upload it). Activate. Activation creates the three tables and the `punchout_buyer` role, and schedules nothing.
2. Put the sealing key in `wp-config.php` **before** storing customer secrets (changing the key later invalidates every stored secret):

```bash
wp punchout generate-key
# → define( 'POW_SECRET_KEY', '…' );  — paste into wp-config.php
```

3. Configure at **WooCommerce ▸ PunchOut**: add a customer (generate the shared secret from the form — it is shown exactly once), then enable the master switch on the Settings tab.
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
| Buyer inactivity | 90 days | Flag (never delete) unseen buyers |
| Punchout button label | "Punchout" | Text on the cart-return button; blank = the default |
| Cancel button label | "Return without a cart" | Text on the abandon control; blank = the default |
| Exit policy (`exit_policy`) | `inherit` | Global Inherit resolves to Punchout only; company entitlement and buyer restriction are resolved as described above |
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
| Agreed extrinsic names and value sources (`UserEmail`, `UniqueUsername`, `UniqueName`) | Stable company-scoped buyer identity mapping |
| Which cart-return encoding the receiver accepts (`cxml-base64` / `cxml-urlencoded`) | Per-connection switch, verified with an actual received basket |
| Whether an empty PunchOutOrderMessage closes a session cleanly (and whether `SupplierOrderInfo` is retained in their cart message log) | pay-path close-out behaviour |
| IP allowlisting requirements and the agreed destination, code, notes and freight mapping | Deployment plus independent optional-field acceptance; no address-list API is implied |
| For dual-exit: written sign-off that direct pay from a punchout session is sanctioned, and how paid orders reconcile in their AP | a paid order creates no requisition/PO/receipt on their side |

**Deployment checklist (outside the plugin):** the buyer-facing URL must be the canonical host with no redirect in the path; any WAF/bot-management layer needs a skip rule for `POST /punchout/setup` (a challenge page kills setup invisibly); CDN cache rules must bypass `/punchout/*` and cookie-carrying requests; `robots.txt` gets `Disallow: /punchout/`. Prove all of it by POSTing a real setup body through the full public path.

**Browser return acceptance:** the cart return is a cross-site, top-level form POST. Verify that the buyer stays signed in and receives the basket in the intended browser and purchasing system. A supplier-generated handoff alone cannot prove the receiver's authentication and cookie behaviour.

---

## Placing the button

Three equivalent ways; all render only inside an active punchout session and output nothing otherwise:

- Shortcode: `[punchout_return_button]` (page builders, widgets, block editor shortcode block)
- PHP: `pow_return_button();` in any template
- Automatic: injected on the cart page after the checkout button (`woocommerce_proceed_to_checkout`, priority 30)

The cart-return control opens the mandatory delivery review at `/punchout/confirm`. For a dedicated review placement, use `[punchout_delivery_confirmation]`; its forms still post to the protected confirmation route. Custom cart markup cannot bypass the final return checks.

Markup comes from `templates/return-button.php` — override it by copying to `{theme}/punchout-woocommerce/return-button.php`, or point the `pow_template_return-button` filter anywhere. Same pattern for `handoff.php`, `closeout-button.php` and `abandon-button.php`.

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

The confirmed cart-return path uses the winner's immutable destination, delivery estimate and notes. It does not resolve a fresh fallback or let `pow_quote_shipping_address` replace that accepted snapshot. Legacy direct Quote callers without a prepared destination retain that filter, then inbound `ShipTo`, then saved-customer shipping precedence.

When `emit_delivery_line` is enabled and the confirmed rate is known, the POOM adds one quantity-one freight line equal to the sum of selected native package rates. The matching optional Quote adds native shipping items per package with the same ex-tax sum, exactly once; freight is never added as merchandise or a second fee. Quote totals and emitted XML use the same integer-cent amounts. When charge export is off, or the buyer acknowledges an unavailable estimate under `quote_separately`, the Quote keeps delivery metadata and an estimate note but adds no shipping charge. Later company-book changes cannot rewrite this completed snapshot.

## Integration documentation page

The page covers endpoints, identities, the annotated setup request, returned carts, status codes, company addresses and confirmation, independent delivery flags, exit hierarchy, rate limits and secret rotation. Store administrators also receive a complete Dynamics setup template for each active company; company owners download the same XML from My Account. It uses the company's static From, Sender, To, cXML version, mode and HTTPS supplier setup URL, retains only a SharedSecret placeholder, and leaves buyer-system runtime fields blank. UserEmail is added separately through the buyer system's extrinsics mapping and is not duplicated in the downloaded XML. The populated fictional buyer-sent specimen is parsed by `Cxml\Parser` and checked against its exact offline DTD; outbound examples use `Cxml\Builder` and the real freight-line producer with invented rates. Flags-off and full enabled examples use exact verified DTD fixtures; displayed totals are derived from the same lines. These samples do not certify a receiving tenant or execute native shipping callbacks.

Publish it by creating a page and adding the shortcode, then send buyers that page's URL:

```
[punchout_docs]
```

Shortcode only — core's Shortcode block inserts it in the block editor, so a custom block would buy nothing but a JS build step. The page renders while punchout is switched off (which is when a new buyer usually reads it) and exposes no session surface.

It carries a **self-test**: paste a PunchOutSetupRequest or ProfileRequest, get back the cXML Status the live endpoint would have answered with, and why. Nothing is stored, no session is created, and any `SharedSecret` in the paste is redacted before it is echoed back. For an anonymous visitor the three authentication stages collapse into one result — splitting them on a public page would be a credential oracle — and the run is throttled on the setup endpoint's own rate limiter and written to the audit log (`docs_self_test`).

Store administrators get the same page with more on it at **WooCommerce > PunchOut > Integration docs**: a per-connection block with the complete setup XML and a self-test that names the exact authentication stage. Company owners download their own template from **My Account → Punchout integration**; ownership is resolved server-side and the request carries a nonce.

Override the markup by copying to `{theme}/punchout-woocommerce/docs/page.php` (or `docs/self-test.php`), or via the `pow_template_docs/page` filter.

## Extension points

| Hook | Type | Purpose |
|---|---|---|
| `pow_return_button_label` | filter | RFQ button text (applied after the setting) |
| `pow_abandon_button_label` | filter | "Return without a cart" text (applied after the setting) |
| `pow_button_classes` | filter | Class list of either exit control (`$classes, $base, $themed`) |
| `pow_template_{return-button,abandon-button,handoff,closeout-button,docs/page,docs/self-test}` | filter | Replace any buyer-facing template |
| `pow_delivery_codes_enabled` | filter | Legacy custom-docs-template display hint; the bundled docs show all delivery settings, and this filter does not control emission |
| `pow_handoff_copy` / `pow_closeout_copy` / `pow_expired_token_message` | filter | Buyer-facing strings |
| `pow_buyer_identity` | filter | Change how buyer identity is derived from the setup request |
| `pow_product_in_range` | filter | Tighten the add-to-cart range check (e.g. a contract-range rule) |
| `pow_poom_unit_price_cents` | filter | VAT treatment / price policy per POOM line |
| `pow_poom_lines` | filter | The assembled line set before the document is built |
| `pow_quote_shipping_address` | filter | Legacy Quote address candidate (`null, $session, $partner`); cannot replace a prepared, confirmed return destination |
| `pow_start_redirect` | filter | Post-login destination |
| `pow_client_ip` | filter | Trust a proxy header for rate limiting / allowlists / audit |
| `pow_route_guard` | action | Extend the blocked-surface set inside punchout sessions |
| `pow_is_punchout()` | function | Presentation gating for themes/builders (never access control). Namespaced: call it as `POW\pow_is_punchout()`, or check `function_exists( 'POW\\pow_is_punchout' )` — the unqualified name does not exist. |

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
- **Per-company payment gateway lists and external address-list APIs**: not implemented. The exit entitlement controls whether native checkout is available; native Woo shipping calculates the reviewed delivery estimate. A company book or inbound `ShipTo` does not imply an external address service.
- **A parallel requisition cart**: rejected by design; the live cart is the single source of truth for both exits.

## Receiver acceptance

The supplier implements company-owned addresses, explicit delivery confirmation and optional destination, code, notes and freight export. Generated samples validate against the exact 1.2.008 and 1.2.071 DTDs. **Positive supplier support and valid XML do not establish receiver consumption**, including in Dynamics 365. Before enabling a company connection, verify:

1. **Returned basket and encoding:** inspect the actual received lines for the configured `cxml-base64` or `cxml-urlencoded` mapping.
2. **Empty and paid closeout:** verify zero-line returns and any order-reference mapping. `SupplierOrderInfo` is omitted for 1.2.008; no extra freight is added.
3. **Browser authentication:** test the cross-site return without losing the buyer session.
4. **Destination, code and notes:** enable each mapping independently and verify consumed values, including the repeated per-merchandise-line `DeliveryInstructions` policy. An address code is a reference, not a registration request.
5. **Freight, units and currency:** verify one freight charge, merchandise-plus-freight arithmetic, accepted UOM/classification and the explicitly omitted unavailable estimate. Do not infer acceptance from the absence of a parse error.
6. **Catalogue-restriction plugins**: the add-to-cart guard uses WooCommerce's own visibility filter chain plus the `pow_product_in_range` seam. Whether a specific third-party restriction setup filters every leak surface (search, direct URL, REST) is a site-deployment test, not a plugin guarantee — verify on staging with a contract-priced catalogue before onboarding a buyer whose pricing is confidential.
