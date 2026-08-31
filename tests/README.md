# Test suite

Pure-PHP unit tests for the layers that carry the protocol and security guarantees: the cXML codec (parser, builder, form packing, money boundary), one-time tokens, replay policy, the session state machine, secret sealing/dual-slot verification, CIDR matching, the rate-limit window, and the per-partner ALL-CAPS transform. **No WordPress, WooCommerce or database is required or loaded** — the classes under test call no WP functions (that separation is a design rule, not an accident).

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
