# PunchOut for WooCommerce — notes for agents

Read this first, then `docs/single-login-mode.md` (the spec), `README.md` and `tests/README.md`.

## What this is
A standalone cXML PunchOut plugin for WooCommerce (namespace `POW`, source in `includes/`). A buyer's e-procurement system (for example Microsoft Dynamics 365) sends a PunchOutSetupRequest; the buyer shops in a visit and returns the basket as a PunchOutOrderMessage.

## Rules (binding)
- Standalone: no mu-plugin, no site glue file, no hook whose purpose is site glue, no `if plugin X` branch, never name another plugin or theme in code or strings. Solve inside the plugin with WordPress/WooCommerce primitives.
- The customer's WooCommerce account IS the punchout login (`partners.owner_user_id`). The plugin never creates, renames or deletes users. One WooCommerce session row per visit (`sessions.wc_session_key`, `pow_` + 28 hex). PunchOut-only exit (no checkout). Admin-only management; My Account keeps only the setup-XML download.
- Never post a PunchOut return (PunchOutOrderMessage) from a test session on a production site. Remove test items; let test sessions expire.
- This repository is public. Keep client and site names, server and hosting details, account, group and connection identities, people's names, e-mail addresses and every secret out of files, commit messages, branch names and tags. Deployment notes and records belong to whoever runs the deployment, outside this repository.
- Keep secrets out of files and logs. A connection's shared secret is read at runtime from private storage and never printed.

## Releases
- Each released version is tagged `v<version>` at its release commit and merged to `master`. Latest release: 0.4.18 (`v0.4.18`, 9 Oct 2026): the theme-drawn review poses as the shop's Cart page (`wp` priority 0 and `template_redirect`) so layout-driven themes draw their real header and footer, masthead left out in theme mode, filter `punchout_review_page_id`. Before it, 0.4.17 (8 Oct 2026). 0.4.14 added the optional attachment on the review page (`Orders\Attachment`: private upload directory, claimed by the Punchout Quote before the "PunchOut order received" e-mail, staff download through admin-post) and a Reply-To setting on that e-mail; 0.4.15–0.4.17 added delivery addresses from the connection account's saved addresses (`Addresses\AccountBook`, an address-book API found by function shape, never by product name; the plugin's own code map `Addresses\AccountCodes`; `wp punchout migrate-addresses`), with the company book as fallback.
- Address-book API contract (0.4.15): a function named `get_address_book` in any namespace, first parameter typed `WC_Customer`, second the type, returning an object with `addresses()` and `default_key()` (and `add()` for the migration). Keep it that way: detect the shape, do not name a product.
- Before a release, bump the `Version:` header, `const VERSION`, the readme `Stable tag` and the changelog, then build the package with `bin/build-zip.sh <outdir>`.

## Tests
- Unit, no Composer needed: `php tests/run-tests.php` (1234 pass at 0.4.18). Also `php tests/CartBlocks/surface.php` (22), `node --test tests/CartBlocks/cart-blocks.test.js` (5), `php tests/Account/run.php` (29), `php tests/Admin/run.php` (44).
- Native suites (opt-in) need a disposable WordPress + WooCommerce + MariaDB site with this plugin linked in; see `tests/README.md`. Example: `POW_NATIVE_TESTS=disposable POW_NATIVE_SCRIPT=$PWD/tests/Integration/TwoBuyerNative.php wp --user=1 eval 'require getenv("POW_NATIVE_SCRIPT");'` (the same for VisitLockdownNative, SessionSafetyNative, AddressSchemaNative, AdminActionsNative and DeliveryStoreNative). RegistrationLifecycleNative runs via `eval-file`. ConcurrencyNative also needs `POW_NATIVE_CONCURRENCY_MODE=suite`, `POW_NATIVE_WP_CLI`, `POW_NATIVE_WP_PATH`, `POW_NATIVE_WP_USER` and `POW_NATIVE_FIXTURE_ROOT`.

## Backlog
- Per-connection return-button label (the label is plugin-wide today).
- Fold the guarded cart save into one module.
