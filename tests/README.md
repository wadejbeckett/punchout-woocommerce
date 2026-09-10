# Test suite

Pure-PHP unit tests for the layers that carry the protocol and security guarantees: the cXML codec (parser, builder, form packing, money boundary), one-time tokens, replay policy, the session state machine, secret sealing/dual-slot verification, CIDR matching, the rate-limit window, the per-partner ALL-CAPS transform, the exit-control label resolution (setting, default, filter order), and the Punchout Quote order rules. **No WordPress, WooCommerce or database is required or loaded** — calls across those boundaries use guarded stubs.

Quote creation, conversion and retention are stubbed, not integrated: `tests/Support/wc-stubs.php` records order changes and query arguments, returns fixture query IDs, and can inject false updates or thrown failures. These tests prove our lines, prices, metadata, status, notes, delivery address, failure containment and audit rules. Retention tests check disabled/empty sweeps, bounded oldest-first querying with a UTC timestamp cutoff, missing/stale orders, cancellation counts, repeat sweeps and continuation after an individual failure. The query stub does not implement database filtering or date parsing. Nothing here proves WooCommerce stock, email or persistence behaviour; conversion to Processing/On hold intentionally keeps native real-order effects. Those require staging checks.

Five support files load from `bootstrap.php`: the TestCase shim, the two stub files below, the setup I/O seams, and the shared quote service doubles. The stub files own disjoint symbol sets — the `function_exists`/`class_exists` guards protect each symbol individually, but two files claiming one symbol makes the winning body depend on require order. Check the other file before adding to either:

| File | Owns |
|---|---|
| `Support/wp-stubs.php` | the WordPress surface: `__`, `_x`, `_n_noop`, the `esc_*` family, `apply_filters`, `locate_template`, the nonce pair, `nocache_headers`, `wp_unslash`, `sanitize_text_field`, `sanitize_title`, `wp_strip_all_tags`, `get_option`, `wp_parse_args` (array arguments only), `home_url`, `wp_parse_url` (absolute URLs only), `status_header`, the transient pair, `wp_mail`, `get_current_user_id`, `is_user_logged_in`, `wp_get_current_user`, `wp_get_session_token`, `wp_clear_auth_cookie`, `WP_Session_Tokens`, `wp_json_encode`, `HOUR_IN_SECONDS`, `MINUTE_IN_SECONDS`, `ARRAY_A`, `is_wp_error` and the class `WP_Error` |
| `Support/wc-stubs.php` | the WooCommerce surface: `get_woocommerce_currency`, `WC`, `wc_create_order`, `wc_get_order`, `wc_get_orders`, `wc_get_product`, and the classes `WC_Order`, `WC_Order_Item_Product`, `WC_Product`, `WC_Customer`, `POW_Test_WC` |

`Support/setup-io-stubs.php` owns only the namespaced PHP seams `POW\Http\fopen`, `POW\Http\header` and `POW\Cxml\libxml_use_internal_errors`. They record body-stream opens, response headers and DOM parsing starts while `pow_test_setup_io` is set; otherwise they delegate to native PHP. `SetupEdgeLimitTest` runs the real endpoint, parser, registry, provisioner and limiters with an audit recorder and a database fixture. It proves the exact edge threshold, early rejection before method/body/parser/archive/lookup/provisioning, downstream budget preservation, per-IP isolation and HTTP-200/cXML-550 emission. The authenticated-path test deliberately throws at the provisioner's identity filter before any user mutation. It does not prove routing, real HTTP transport or user/session persistence.

The shared transient stub records every expiration passed to `set_transient` in `pow_test_transient_expirations`, alongside the existing value store. Limiter tests execute the default setter and assert 60-second/3600-second TTLs and separate window keys; expiry, cache eviction and concurrency are not simulated.

`Support/quote-doubles.php` holds the four service doubles a `QuoteOrder` is constructed with (`QuoteOrderTestStore`, `QuoteOrderTestLog`, `QuoteOrderTestSettings`, `QuoteOrderTestLogger`). Multiple suites share them, and the standalone runner requires each test file only when it reaches it — doubles declared inside one test file do not exist yet for the file that sorts before it. It extends plugin classes, so the bootstrap loads it after the autoloader.

`ReturnIntegrationTest` executes the real return endpoint, mapper, builder, templates, quote service, audit writer and session Store. `Support/return-database.php` supplies the database boundary fixture, including conditional SQL outcomes; nested requests reproduce stale snapshots. `QuoteReturnFailureTest` injects false and throwing persistence, audit, logger and mail outcomes, including Woo returning an existing ID without the intended saved state. The Woo stub exposes save/note callbacks and product/item/property rejection results; the WordPress stub records token destruction, cookie clearing and mail attempts. These prove plugin behavior at those boundaries, not native database concurrency, browser cookie policy or Woo persistence. Separate private WordPress/Woo/MariaDB HTTP runs are recorded in the local integration report; their fixtures, paths and captured mail are not distributed with this repository.

`CartGuardTest` runs the registered seed callback between simulated cart restoration and form addition, checking the resulting contents rather than an exact priority number. It covers persisted-latch reuse on a later request, no-cart deferral, unresolved/ordinary logins, edit/inspect sessions, and a false latch write stopping the request with a retryable 503 before additions can be accepted. `Support/cart-hooks.php` owns only the `POW\Cart` hook-registration and fatal-response seams; the existing shared stubs are unchanged. These are unit boundaries, not native Woo cart persistence. The separate private HTTP regression boots actual WordPress/WooCommerce once per request, using authenticated cookies, native cart restoration and `WC_Form_Handler::add_to_cart_action`, followed by later-request readback. It also checks guest-cart preservation during native token redemption and a REST-shaped request where Woo does not initialize a cart. This does not prove browser rendering, public rewrites, redirects, SameSite behavior or concurrent cart initialization.

`Settings`, `Logger`, `Sessions\Store` and `Audit\Log` are deliberately **not** `final`: a service a WordPress-free suite must double cannot be sealed, and a `final` keyword that only exists to block a test double is a testability defect rather than a design rule. No method of theirs is overridden in production.

## Running

With PHPUnit (>= 10) available:

```bash
phpunit -c phpunit.xml.dist
```

Without PHPUnit or Composer (any PHP >= 8.2 CLI — e.g. straight on the server):

```bash
php tests/run-tests.php
```

The standalone runner uses a minimal PHPUnit-compatible `TestCase` shim (`tests/Support/testcase-shim.php`) that defines itself only when the real PHPUnit classes are absent, so the same test files run identically under both.

Cart block integration has separate PHP and JavaScript checks. Run both from the repository root alongside the unit suite; they use PHP and Node's built-in test runner without installing packages:

```bash
php tests/CartBlocks/surface.php
node --test tests/CartBlocks/cart-blocks.test.js
```

These checks exercise policy resolution, script dependencies and WooCommerce's public button-filter contract. A real browser must also verify the hydrated Cart block and subsequent cart updates; passing the isolated checks does not establish that behavior.

## Required PHP extensions

| Extension | Needed by | If missing |
|---|---|---|
| `dom` / `libxml` | ParserTest, BuilderTest, PoomEnvelopeTest | those suites **skip** with a message |
| `sodium` | SecretsTest | that suite skips |

A dev box without `php-xml` (Debian/Ubuntu package `php8.x-xml`) will show the codec document tests as SKIP — run them wherever the plugin will actually execute (any normal WP host has ext-dom). The exit code is non-zero only on failures, never on skips.

## What is deliberately NOT covered here

Integration behaviour that requires WordPress/WooCommerce or a real buyer tenant: endpoint routing, auto-login cookie mechanics, cart contents, checkout lifecycle, and everything in the staging test matrix of the build scope (§10) — including the D365 certification items (cart-return encoding acceptance, empty-POOM close-out behaviour, SameSite punchback). Those are staging/certification tests, not unit tests.

## Native admin approval and credentials

`Unit/AdminActionsTest.php` and `Admin/doubles.php` exercise the real Admin actions, page, Registry and Registration through recording SQL and WordPress I/O boundaries. `php tests/Admin/run.php` is the focused entry point. The namespaced admin seams own only admin I/O and do not replace shared global test stubs. Coverage includes pending preparation and explicit approval, supplier identity/entitlement validation, every admin handler's POST/capability/nonce gates, escaped direct credentials for manual creation/replacement and generated creation/rotation/approval/reset, rotation overlap, reset failure notices, legacy-owner validation and lock order, stale claim checks, owner-ID auditing, legacy plaintext-notice suppression and native message-renderer cache-header replacement. Recorded writes, captured mail and notices are scanned for the generated plaintext. These are unit boundary checks, not native persistence, wire headers or real concurrent transactions.

`Integration/AdminActionsNative.php` is an opt-in CLI script for the coordinator's disposable local WordPress/WooCommerce candidate. It creates neutral users/connections and checks actual admin hook callbacks, native nonce/capability denial, pending preparation/approval, sealed credential persistence, native audit ownership records, captured mail, invalid/duplicate/provisioned owner denial, reset token/session revocation, preservation of company association and an injected SQL failure notice. It captures terminal responses through WordPress's own `wp_die_handler` and redirect filters, intercepts mail before transport, and prints no credentials. It does not delete its fixtures. Execute only with `POW_NATIVE_TESTS=disposable`, WordPress environment type `local`, a capable fixture administrator and the candidate plugin active. Its file header supplies the invocation. Native HTTP no-store/GET behavior, browser rendering and independent-process ownership races remain separate coordinated acceptance checks; merely linting or passing the standalone suite does not establish them.
