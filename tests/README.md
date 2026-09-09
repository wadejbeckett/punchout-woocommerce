# Test suite

Pure-PHP unit tests for the layers that carry the protocol and security guarantees: the cXML codec (parser, builder, form packing, money boundary), one-time tokens, replay policy, the session state machine, secret sealing/dual-slot verification, CIDR matching, the rate-limit window, the per-partner ALL-CAPS transform, the exit-control label resolution (setting, default, filter order), and the Punchout Quote order rules. **No WordPress, WooCommerce or database is required or loaded** — calls across those boundaries use guarded stubs.

Quote creation, conversion and retention are stubbed, not integrated: `tests/Support/wc-stubs.php` records order changes and query arguments, returns fixture query IDs, and can inject false updates or thrown failures. These tests prove our lines, prices, metadata, status, notes, delivery address, failure containment and audit rules. Retention tests check disabled/empty sweeps, bounded oldest-first querying with a UTC timestamp cutoff, missing/stale orders, cancellation counts, repeat sweeps and continuation after an individual failure. The query stub does not implement database filtering or date parsing. Nothing here proves WooCommerce stock, email or persistence behaviour; conversion to Processing/On hold intentionally keeps native real-order effects. Those require staging checks.

Four support files load from `bootstrap.php`: the TestCase shim, the two stub files below, and the shared quote service doubles. The stub files own disjoint symbol sets — the `function_exists`/`class_exists` guards protect each symbol individually, but two files claiming one symbol makes the winning body depend on require order. Check the other file before adding to either:

| File | Owns |
|---|---|
| `Support/wp-stubs.php` | the WordPress surface: `__`, `_x`, `_n_noop`, the `esc_*` family, `apply_filters`, `locate_template`, the nonce pair, `nocache_headers`, `wp_unslash`, `sanitize_text_field`, `sanitize_title`, `wp_strip_all_tags`, `get_option`, the transient pair, `wp_mail`, `get_current_user_id`, `HOUR_IN_SECONDS`, `is_wp_error` and the class `WP_Error` |
| `Support/wc-stubs.php` | the WooCommerce surface: `get_woocommerce_currency`, `WC`, `wc_create_order`, `wc_get_order`, `wc_get_orders`, `wc_get_product`, and the classes `WC_Order`, `WC_Order_Item_Product`, `WC_Product`, `WC_Customer`, `POW_Test_WC` |

`Support/quote-doubles.php` holds the four service doubles a `QuoteOrder` is constructed with (`QuoteOrderTestStore`, `QuoteOrderTestLog`, `QuoteOrderTestSettings`, `QuoteOrderTestLogger`). Multiple suites share them, and the standalone runner requires each test file only when it reaches it — doubles declared inside one test file do not exist yet for the file that sorts before it. It extends plugin classes, so the bootstrap loads it after the autoloader.

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

## Required PHP extensions

| Extension | Needed by | If missing |
|---|---|---|
| `dom` / `libxml` | ParserTest, BuilderTest, PoomEnvelopeTest | those suites **skip** with a message |
| `sodium` | SecretsTest | that suite skips |

A dev box without `php-xml` (Debian/Ubuntu package `php8.x-xml`) will show the codec document tests as SKIP — run them wherever the plugin will actually execute (any normal WP host has ext-dom). The exit code is non-zero only on failures, never on skips.

## What is deliberately NOT covered here

Integration behaviour that requires WordPress/WooCommerce or a real buyer tenant: endpoint routing, auto-login cookie mechanics, cart contents, checkout lifecycle, and everything in the staging test matrix of the build scope (§10) — including the D365 certification items (cart-return encoding acceptance, empty-POOM close-out behaviour, SameSite punchback). Those are staging/certification tests, not unit tests.
