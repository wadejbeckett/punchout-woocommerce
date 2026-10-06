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
- Each released version is tagged `v<version>` at its release commit and merged to `master`. Latest release: 0.4.10 (`v0.4.10`).
- Before a release, bump the `Version:` header, `const VERSION`, the readme `Stable tag` and the changelog, then build the package with `bin/build-zip.sh <outdir>`.

## Tests
- Unit, no Composer needed: `php tests/run-tests.php` (1184 pass at 0.4.10). Also `php tests/CartBlocks/surface.php` (22), `node --test tests/CartBlocks/cart-blocks.test.js` (5), `php tests/Account/run.php` (29), `php tests/Admin/run.php` (44).
- Native suites (opt-in) need a disposable WordPress + WooCommerce + MariaDB site with this plugin linked in; see `tests/README.md`. Example: `POW_NATIVE_TESTS=disposable POW_NATIVE_SCRIPT=$PWD/tests/Integration/TwoBuyerNative.php wp --user=1 eval 'require getenv("POW_NATIVE_SCRIPT");'` (the same for VisitLockdownNative, SessionSafetyNative, AddressSchemaNative, AdminActionsNative and DeliveryStoreNative). RegistrationLifecycleNative runs via `eval-file`. ConcurrencyNative also needs `POW_NATIVE_CONCURRENCY_MODE=suite`, `POW_NATIVE_WP_CLI`, `POW_NATIVE_WP_PATH`, `POW_NATIVE_WP_USER` and `POW_NATIVE_FIXTURE_ROOT`.

## Backlog
- Per-connection return-button label (the label is plugin-wide today).
- Fold the guarded cart save into one module.
