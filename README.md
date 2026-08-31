# PunchOut for WooCommerce

An AGPLv3 WordPress plugin that makes a WooCommerce store a **cXML PunchOut supplier site** for enterprise procurement buyers — Microsoft Dynamics 365 Finance & Operations / Supply Chain Management first, any direct-cXML buyer by configuration.

A buyer clicks the store's catalog tile inside their procurement system; the system POSTs a cXML `PunchOutSetupRequest` to the store; the plugin authenticates it by shared secret, provisions a per-buyer user, and returns a one-time StartPage URL that auto-logs the buyer's browser in. The buyer shops the ordinary WooCommerce cart at their configured prices, then exits by sending the basket back to their purchasing system as a cXML `PunchOutOrderMessage` (requisition/RFQ lines for internal approval) — or, where the partner's policy allows it, by paying through the completely untouched standard WooCommerce checkout.

**Status: complete build, not yet certified against a live buyer tenant.** Every layer is implemented and the protocol/security core is unit-tested, but no cXML document has been exchanged with a real Dynamics 365 environment — see [Untested against a real D365 tenant](#untested-against-a-real-d365-tenant) before any production onboarding.

- **Licence:** AGPL-3.0-or-later (full text in `LICENSE`)
- **Requires:** PHP 8.2+, WordPress 6.4+, WooCommerce 8.0+
- **Dependencies:** none. No Composer, no vendor directory, no external packages. Action Scheduler is used for housekeeping when present (it ships inside WooCommerce), with a WP-Cron fallback.

---

## Why AGPL

The plugin encodes real integration knowledge — the D365 punchout dialect, its parser fragilities, the session/security model — and is exactly the kind of thing a third party would gate behind a SaaS. AGPL means anyone who takes it, including anyone running a modified copy as a hosted punchout service, has to publish their changes.

---

## Architecture

### The flow

```
Buyer's procurement system         The store (this plugin)              Buyer's browser
        │ 1. POST /punchout/setup        │                                     │
        │    text/xml PunchOutSetupReq   │                                     │
        │───────────────────────────────▶│ 2. authenticate partner             │
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
        │                                │        → POST /punchout/return      │
        │                                │    (b) stock Woo checkout           │
        │                                │        (dual_exit partners only)    │
        │ 9. browser POSTs the cXML PunchOutOrderMessage (cxml-base64          │
        │    hidden field) top-level to the BrowserFormPost URL; the           │
        │    store login is destroyed either way                               │
        │◀───────────────────────────────┼─────────────────────────────────────│
```

Steps 1–3 are server-to-server; steps 5+ are the buyer's browser. No session exists between the two — the one-time token is the only bridge. The pay exit (b) still closes the buyer-side session cleanly: the order-received page offers "Return to your purchasing system", which posts an **empty** PunchOutOrderMessage (the cXML cancel semantic) carrying the Woo order reference in `SupplierOrderInfo`.

### Key design decisions

**Additive, never invasive.** The plugin never overrides, replaces or filters the WooCommerce checkout or any payment gateway. The RFQ exit is an extra button; the pay path is stock WooCommerce with three listeners (order meta tagging, a session-state flip at `woocommerce_payment_complete`, a close-out CTA on the thank-you page). The one exception is deliberate and scoped: partners in `requisition_only` mode get a checkout block — 302 plus a `woocommerce_checkout_process` hard fail — applied only inside that partner's punchout sessions.

**Multi-tenant registry, policy over code.** Every partner difference is a column, not a branch: identities, cXML version, return encoding, exit mode, TTLs, IP allowlist, ALL-CAPS outbound transform, optional customer-group mapping. Adding a second buyer is a registry row plus a certification exercise.

**Hand-rolled cXML codec on DOMDocument.** The plugin needs exactly four document shapes (parse `PunchOutSetupRequest`/`ProfileRequest`; emit `PunchOutSetupResponse`, `PunchOutOrderMessage`, `Status`). A library (cxml-php) would drag a Composer graph into a no-vendor plugin for four small documents. Parsing is XXE-hardened (entity declarations rejected outright, `LIBXML_NONET`, no runtime DTD fetch) and validates structurally, not against the DTD; the 1.2.071 DTD ships in `includes/Cxml/dtd/` for offline reference.

**Raw `parse_request` routing, no rewrites.** `/punchout/setup` must accept a raw `text/xml` POST whose body survives untouched. The router matches the raw request path at `parse_request` priority 0 and exits — no rewrite rules, no canonical processing (WP core cannot 301 a POST), no theme bootstrap. Webserver/CDN-level redirects remain a deployment concern: the URL given to buyers must be the exact canonical origin (see the runbook).

**cXML failures inside HTTP 200.** The setup endpoint answers `Status` codes (`401` auth, `406` invalid, `409` replay, `450` unsupported, `500`, `550` rate-limited) in an HTTP 200 — procurement clients read the envelope, and an HTTP 4xx alongside a valid response has broken real integrations.

**One WP user per (partner, buyer identity).** WooCommerce keys the session — and therefore the cart — on the user ID, so a shared punchout user would merge every concurrent buyer into one basket. Identity comes from the agreed extrinsics (`UserEmail`, then `UniqueUsername`, then `Contact/Email`), falling back to a flagged ephemeral per-session user. Same buyer punching out twice: **latest punchout wins** — the new setup expires the old session and destroys its login.

**One cart, two exits.** The live `WC()->cart` serves both exits, so the POOM quotes exactly the prices the buyer would have paid at checkout — pricing plugins apply their prices at cart time and the mapper reads the cart's own line totals. Persistent carts are disabled inside punchout sessions and the cart is emptied once at session start.

**Master switch off by default.** A fresh install exposes no pre-auth XML endpoint until an operator has configured a partner and enabled the feature.

### File tree

```
punchout-woocommerce/
├── punchout-woocommerce.php        Plugin header, constants, bootstrap, HPOS declare,
│                                   pow()/pow_is_punchout()/pow_return_button() helpers
├── uninstall.php                   Drops the four tables, options, role; keeps buyer users
├── readme.txt                      WordPress plugin directory format
├── bin/build-zip.sh                Builds the distributable zip (runtime files only)
├── phpunit.xml.dist
├── templates/                      Theme-overridable buyer-facing surfaces
│   ├── return-button.php           The "send for approval" cart button
│   ├── closeout-button.php         The pay-path close-out CTA
│   └── handoff.php                 The auto-submitting cart-return page
├── tests/                          Pure-PHP unit suite (no WordPress) + shim runner
└── includes/
    ├── Autoloader.php              PSR-4-style spl_autoload, no Composer
    ├── Plugin.php                  Container / wiring, master-switch gating
    ├── Settings.php                The single option (global knobs only)
    ├── Installer.php               dbDelta schema (4 tables), punchout_buyer role
    ├── Logger.php                  wc_get_logger() wrapper, redacts credentials
    ├── Cron.php                    Session GC, buyer deactivation, log retention
    ├── RouteGuard.php              Session-scoped access control (302s, checkout block)
    ├── Cxml/                       Pure codec: Parser, Builder, FormPack, Money (+DTD)
    ├── Partners/                   Partner row, Registry (CRUD/auth), Secrets (sodium)
    ├── Sessions/                   Session row+state machine, Store, Tokens, ReplayPolicy
    ├── Http/                       Router, Setup/Start/Return endpoints, RateLimiter
    ├── Buyers/                     Provisioner, optional B2BKingBridge
    ├── Cart/                       Guard, Surface (button/shortcode), PoomMapper
    ├── Checkout/PayExit.php        Pay-path listeners (tag, flip, close-out CTA)
    ├── SkuMap/SkuMap.php           Per-partner SKU/UOM/UNSPSC decoration, CSV import
    ├── Audit/Log.php               Compliance trail (wp_pow_log)
    ├── Support/                    Ip (CIDR), Templates (theme-overridable rendering)
    ├── Admin/                      Page (4 tabs, Settings API) + Actions (admin-post)
    └── CLI/Command.php             wp punchout …
```

### Data model

Four custom indexed tables (options/postmeta neither index nor GC well for per-request session lookups and an append-heavy audit trail):

| Table | Holds |
|---|---|
| `wp_pow_partners` | Trading-partner registry: identities, sealed secrets (current+previous), cXML version, deployment mode, return encoding, exit mode, ALL-CAPS flag, IP allowlist, optional customer-group mapping, TTLs |
| `wp_pow_sessions` | One row per PunchOutSetupRequest: BuyerCookie, BrowserFormPost URL, user, hashed one-time token, exact WP session token, state machine (`pending → active → returned/ordered/closed/expired`), payloadID + body hash (replay), stored response (pending replay), captured ShipTo/SelectedItem/extrinsics |
| `wp_pow_skumap` | Per-partner outbound decoration: SKU override, UOM, UNSPSC — joins on the Woo SKU, owns nothing else |
| `wp_pow_log` | The audit/compliance trail: every transaction, full POOM XML archives, secrets redacted; retention-trimmed by cron |

---

## Security model

- **Shared secrets** are sealed at rest with `sodium_crypto_secretbox` under a wp-config key (`POW_SECRET_KEY`; a documented auth-salt-derived fallback keeps the key out of the database either way), compared with `hash_equals`, write-only in the admin UI, and rotated dual-slot: current and previous both verify during an overlap window, the audit log records which slot matched, and the window is closed explicitly (admin link or `wp punchout close-rotation`).
- **The secret exists in exactly one place** — the inbound setup comparison. The builder has no code path that writes a `SharedSecret` node, and the unit suite asserts its absence on both POOM variants (browser-transported Messages carry an identity-only Sender by DTD rule).
- **StartPage tokens** are 256-bit random, URL-safe, single-use (an atomic one-query status flip), short-TTL, and stored only as SHA-256. Invalid/expired/used all produce the same detail-free 403.
- **Replay**: `UNIQUE(partner_id, payloadID)` plus written-down semantics — a duplicate with an identical body while `pending` replays the stored response byte-identically (legitimate retry); any duplicate after token redemption is a cXML 409.
- **Sessions**: the exact WP session token created at auto-login is recorded, so either exit destroys *that* login only; auth-cookie expiry is the partner's session TTL (default 4 h), and cron reaps stragglers.
- **Pre-auth surface** is `/punchout/setup` alone: 2 MB body cap, content-type check, XXE hardening, per-(partner, IP) rate limiting, optional per-partner CIDR allowlist, generic 401s.
- **Pricing-leak guards**: every `/punchout/*` response sends `no-store` + `X-Robots-Tag: noindex`; the handoff page is never cacheable; add-to-cart validates range server-side (hiding a button is not access control); the route guard 302s punchout sessions away from account surfaces and other users' orders. Add `Disallow: /punchout/` to robots.txt at deployment (see runbook) — the plugin does not rewrite robots.txt itself.
- **Audit**: every setup, token redemption, login, rejected add-to-cart, cart send (full XML), order, payment event, rotation and GC action lands in `wp_pow_log` with secrets redacted — WooCommerce log files rotate away; the dispute evidence (prices quoted to a named buyer at a timestamp) must not.

---

## Install

1. Copy the `punchout-woocommerce` directory into `wp-content/plugins/` (or build a zip with `bin/build-zip.sh` and upload it). Activate. Activation creates the four tables and the `punchout_buyer` role, and schedules nothing.
2. Put the sealing key in `wp-config.php` **before** storing partner secrets (changing the key later invalidates every stored secret):

```bash
wp punchout generate-key
# → define( 'POW_SECRET_KEY', '…' );  — paste into wp-config.php
```

3. Configure at **WooCommerce ▸ PunchOut**: add a trading partner (generate the shared secret from the form — it is shown exactly once), then enable the master switch on the Settings tab.
4. Prove the plumbing before involving the buyer: POST a sample `PunchOutSetupRequest` at `/punchout/setup` from the shell and check the Log tab (a local punchout simulator such as `punchout-simulator` or `cxml-tester` drives the full loop).

### Settings

| Setting | Default | Notes |
|---|---|---|
| Enable punchout | off | Master switch; off = endpoints and surfaces inert |
| Landing page | shop page | Post-login redirect + route-guard home |
| Login link lifetime | 300 s | One-time StartPage token TTL |
| Session lifetime | 4 h | Punchout login TTL (per-partner override) |
| Setup rate limit | 30/min | Per partner+IP on `/punchout/setup`; 0 disables |
| Log retention | 400 days | Audit-table trim horizon |
| Buyer inactivity | 90 days | Flag (never delete) unseen buyers |
| Default UNSPSC | empty | Classification fallback for unmapped SKUs |

---

## Partner onboarding runbook (D365 buyer)

Punchout onboarding is a data exchange plus a certification session against the buyer's tenant. There is no Microsoft-side registry or certification program: their admin types your values into **Procurement and sourcing ▸ Catalogs ▸ External catalogs** and clicks *Validate settings*.

**You provide the buyer:**

| Item | Value |
|---|---|
| Punchout catalog URL (test and production) | `https://{canonical-host}/punchout/setup` — the exact canonical origin: any webserver/CDN redirect (apex→www, http→https) will silently drop the POST body |
| Your identity + domain | the partner row's To credential (DUNS number, or an agreed `NetworkID` value) |
| Shared secret | generate in the partner form or `wp punchout rotate-secret`; state the rotation policy (dual-slot overlap; suggest annual + on-suspicion) |
| Supported cXML version | emit per partner row (D365 default 1.2.008); any 1.2.x accepted inbound |
| Vendor logo | **square** PNG — D365 renders non-square images wrong |
| Vendor/commercial data | vendor account number, name, contacts, approved procurement categories |

**The buyer provides you (per partner row):**

| Item | Why |
|---|---|
| Their From credential (domain + identity) and Sender identity | registry row; the Sender pair is the auth lookup key |
| Which legal entities get catalog access | multiple entities can mean multiple identities/currencies — possibly multiple partner rows |
| The cXML version their system emits | codec config |
| **SameSite/Chrome-80 punchback remediation status**, in writing, with evidence of a successful cross-site cart return from a non-Microsoft supplier | the top integration risk — see below |
| Test tenant access, a named D365 admin/partner contact, a certification window | certification is only possible against their tenant |
| Exact UOM codes and currency configured in their D365 | UOM/currency mismatches are silent line-level failures; feed the SKU map |
| Agreed extrinsic names and value sources (`UserEmail`, `UniqueUsername`, …) | buyer identity mapping |
| Which cart-return encoding their basket wizard accepts (`cxml-base64` / `cxml-urlencoded`) | undocumented by Microsoft; per-partner switch, settle empirically on day one |
| Whether an empty PunchOutOrderMessage closes a session cleanly (and whether `SupplierOrderInfo` is retained in their cart message log) | pay-path close-out behaviour |
| IP allowlisting requirements, `PUNCHOUTSHIPTO` intentions, PO delivery mode | deployment + future scope |
| For dual-exit: written sign-off that direct pay from a punchout session is sanctioned, and how paid orders reconcile in their AP | a paid order creates no requisition/PO/receipt on their side |

**Deployment checklist (outside the plugin):** the buyer-facing URL must be the canonical host with no redirect in the path; any WAF/bot-management layer needs a skip rule for `POST /punchout/setup` (a challenge page kills setup invisibly); CDN cache rules must bypass `/punchout/*` and cookie-carrying requests; `robots.txt` gets `Disallow: /punchout/`. Prove all of it by POSTing a real setup body through the full public path.

**The SameSite risk, named honestly:** the cart return is a cross-site, top-level POST to an authenticated page in the buyer's D365 web client. Chrome's SameSite=Lax default can drop D365's session cookie on that POST, bouncing the buyer to a sign-in page — a publicly reported, unresolved failure mode for exactly this scenario, fixable only on the Microsoft/buyer side (current evergreen platform build; Chrome enterprise policy as a stopgap). The plugin does everything a supplier can (top-level form POST, correct encoding, immediate return, support reference on the handoff page) — get the buyer's remediation status in writing before committing dates.

---

## Placing the button

Three equivalent ways; all render only inside an active punchout session and output nothing otherwise:

- Shortcode: `[punchout_return_button]` (page builders, widgets, block editor shortcode block)
- PHP: `pow_return_button();` in any template
- Automatic: injected on the cart page after the checkout button (`woocommerce_proceed_to_checkout`, priority 30)

Markup comes from `templates/return-button.php` — override it by copying to `{theme}/punchout-woocommerce/return-button.php`, or point the `pow_template_return-button` filter anywhere. Same pattern for `handoff.php` and `closeout-button.php`.

## Extension points

| Hook | Type | Purpose |
|---|---|---|
| `pow_return_button_label` | filter | RFQ button text |
| `pow_template_{return-button,handoff,closeout-button}` | filter | Replace any buyer-facing template |
| `pow_handoff_copy` / `pow_closeout_copy` / `pow_expired_token_message` | filter | Buyer-facing strings |
| `pow_buyer_identity` | filter | Change how buyer identity is derived from the setup request |
| `pow_product_in_range` | filter | Tighten the add-to-cart range check (e.g. a contract-range rule) |
| `pow_poom_unit_price_cents` | filter | VAT treatment / price policy per POOM line |
| `pow_poom_lines` | filter | The assembled line set before the document is built |
| `pow_start_redirect` | filter | Post-login destination |
| `pow_client_ip` | filter | Trust a proxy header for rate limiting / allowlists / audit |
| `pow_route_guard` | action | Extend the blocked-surface set inside punchout sessions |
| `pow_is_punchout()` | function | Presentation gating for themes/builders (never access control) |

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
- **edit/inspect re-entry**: D365 F&O sends `operation="create"` only and its fixed post-back mapping cannot round-trip the keys re-entry needs; non-create operations answer cXML 450 and the POOM declares `operationAllowed="create"`. (The registry carries an `allow_reentry` column for forward compatibility; no code path services it yet.)
- **Ariba network hops, CredentialMac, client certificates, `ds:Signature`**: only direct punchout with SharedSecret auth is implemented.
- **Invoicing (`InvoiceDetailRequest`), order-status write-back, catalog uploads**: out of scope.
- **Checkout prefill / per-partner gateway filtering / shipping policy wiring**: deliberately omitted — the client requirement is that the pay path be *stock* WooCommerce with no checkout-flow filters. The registry keeps `gateway_allowlist` / `company_profile` columns so this can become policy later without a schema change.
- **A parallel requisition basket**: rejected by design; the live cart is the single source of truth for both exits.

## Untested against a real D365 tenant

Everything cXML-shaped here was built from the cXML 1.2.071 DTD + Reference Guide and Microsoft's public documentation, and the codec is unit-tested against the documented D365 template shape — but **no document has been exchanged with a live Dynamics 365 tenant**. Before first certification, treat these as open until observed:

1. **Cart-return encoding**: whether D365's basket wizard accepts `cxml-base64`, `cxml-urlencoded`, or both is documented nowhere. Default is base64; the fallback is one partner-row flip. Their cXML cart message log is the verification tool.
2. **Empty-POOM close-out**: whether the basket wizard handles a zero-line POOM gracefully (and keeps `SupplierOrderInfo` in the log) is undocumented. Fallback: let the session expire; buyers see the documented expiry behaviour.
3. **SameSite punchback** (above): supplier-side obligations are met; the outcome depends on the buyer's platform build and browser policy.
4. **Exact template dialect**: the parser tolerates the documented fragilities (no DOCTYPE, empty BuyerCookie, timestamps with/without offset, extrinsic placement) — their *Validate settings* run is the first real proof.
5. **UOM/currency acceptance**: emitted UOM codes must pre-exist in the buyer's D365 or lines fail silently on their side; only their configuration answers this.
6. **Catalogue-restriction plugins**: the add-to-cart guard uses WooCommerce's own visibility filter chain plus the `pow_product_in_range` seam. Whether a specific third-party restriction setup filters every leak surface (search, direct URL, REST) is a site-deployment test, not a plugin guarantee — verify on staging with a contract-priced catalogue before onboarding a buyer whose pricing is confidential.
