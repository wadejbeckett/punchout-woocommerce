# PunchOut for WooCommerce — agent handoff (written 2026-09-22 for Codex / any agent)

Read this first, then `.remember/remember.md` (latest state) and `docs/single-login-mode.md` (the spec). Owner: Wade Beckett (plain English, no essays, HIGH priority; he does not read long briefs).

## What this is
A standalone cXML PunchOut plugin for WooCommerce (namespace `POW`, source in `includes/`). Only install: **example.com** (Plesk host server.example, `ssh -p 24 root@server.example`, site user `jcaxkarn`, table prefix `wp_`, WooCommerce 11.1.1, WordPress 7.1.1, Avada theme, B2BKing). Customer: Globex (tester the tester Wessels), Dynamics 365 sends the PunchOutSetupRequest.

## Owner's rules (binding)
- Standalone: no mu-plugin, no site glue file, no hook whose purpose is site glue, no `if plugin X` branch, never name another plugin or theme in code or strings. Solve inside the plugin with WordPress/WooCommerce primitives.
- The customer's WooCommerce account IS the punchout login (`partners.owner_user_id`). The plugin never creates, renames or deletes users. One WooCommerce session row per visit (`sessions.wc_session_key`, `pow_` + 28 hex). PunchOut-only exit (no checkout). Admin-only management; My Account keeps only the setup-XML download.
- Never post a PunchOut return (PunchOutOrderMessage) from a test session on live. Remove test items; let test sessions expire.
- Production changes only when Wade says so; back up first (`backup.sh`), record in `example.com/memory-bank/custom-deploys.md`.
- Keep secrets out of files and logs. The Globex shared secret lives only in `(local path)` (mode 600) — read it at runtime, never print it.

## Current state (22 Sep 2026)
- **Live on example.com: 0.4.6 = 97ed427** (branch `feature/0.4.6-endpoint-checkboxes`, tag `v0.4.6`, not pushed or merged), installed 23 Sep 20:27 SAST. Before it: 0.4.5 = 1089f86 (`feature/0.4.5-visit-endpoints`, tag v0.4.5, 23 Sep 17:03) and 0.4.4 = 7cfdfe7 (`feature/0.4.0-single-login`, tag `acme-live-2026-09-21-7cfdfe7`). The 0.4.5/0.4.6 branches sit on top of `feature/0.4.0-single-login`. master is at v0.3.0. Merge to master and tag v0.4.0 only after Globex's re-test.
- Unit suite: `php tests/run-tests.php` (1009 pass, no Composer). Cart surface suite: `php tests/CartBlocks/surface.php`. Native suites (opt-in, real WP): see "Local fixture".
- Acme cleanup done: 20 legacy `punchout_buyer` accounts, 6 test quotes and the mu-plugin `acme-punchout-groups.php` deleted (backups under `/var/www/vhosts/example.com/AVADA-OPTION-BACKUPS/punchout-20260921-*`). User 4 `Globex-company` is connection 1's account, B2BKing group 10717.
- Browser-verified as a visit: cart page shows the "Punchout" exit under the totals, no checkout button. Nine smoke visits (132–140) expire ~22 Sep evening; visit 135 still holds 2 test items in its own cart row (the sweep deletes it).

## Next steps
1. Visual pass with Wade on the live cart/shop as a punchout visit (label of the exit = connection setting "return button label", now "Punchout").
2. Globex re-test with the tester, incl. two testers at once. Watch `wp-content/uploads/wc-logs/punchout-woocommerce-*.log` and `wp_pow_log`.
3. Then `git checkout master && git merge --no-ff feature/0.4.0-single-login && git tag v0.4.0 && git push --tags`.
4. Deferred: fold the guarded cart save into one module.

## How to deploy to Acme
Tooling (latest): `(local path)` (copy it for the next release) — `config.env` (COMMIT, PACKAGE, PACKAGE_SHA256, BACKUP dir), then `./backup.sh`, `./install.sh`, `./verify-installed.sh`, `./rollback.sh`. Build a package with `bin/build-zip.sh <outdir>` (bump `Version:` header, `const VERSION`, readme `Stable tag` and changelog first). Put builds under `example.com/punchout/context/builds/<date>-<topic>-<sha7>/`.

## How to smoke-test live without a browser
`.remember/single-login-deliberation-20260921/live-smoke.py` (python3, stdlib): fills the private Dynamics template with a payloadID/timestamp/BuyerCookie/dummy BrowserFormPost and a UserEmail extrinsic, POSTs to `https://www.example.com/punchout/setup`, redeems the StartPage URL, loads `/cart/`, checks `/wp-json/wp/v2/users/me` (must be 401) and `/wp-admin/` (must redirect). Never posts a return. Note: `/cart/?add-to-cart=` gets a Plesk 403 on this site; use `/shop/?add-to-cart=ID` or `?wc-ajax=add_to_cart`.

## Local fixture (real WordPress 7.1 + WooCommerce 11.1.0 + MariaDB, disposable)
`F=(local path)`; `export PHP_INI_SCAN_DIR=/etc/php/8.3/cli/conf.d:$F/conf.d WP_ENVIRONMENT_TYPE=local`; `W="php $F/wp-cli.phar --path=$F/site"`; admin user id 1. Plugin is symlinked from `$F/site/wp-content/plugins/punchout-woocommerce` to this repo. Native suites: `POW_NATIVE_TESTS=disposable POW_NATIVE_SCRIPT=$PWD/tests/Integration/TwoBuyerNative.php $W --user=1 eval 'require getenv("POW_NATIVE_SCRIPT");'` (same for VisitLockdownNative, AddressSchemaNative, AdminActionsNative, DeliveryStoreNative); `RegistrationLifecycleNative` via `eval-file`; ConcurrencyNative needs `POW_NATIVE_CONCURRENCY_MODE=suite POW_NATIVE_WP_CLI=$F/wp-cli.phar POW_NATIVE_WP_PATH=$F/site POW_NATIVE_WP_USER=1 POW_NATIVE_FIXTURE_ROOT=$F`.

## Records
- Acme: `(local path)` (deploy register), `punchout/memory-bank/activeContext.md`, `punchout/memory-bank/decisionLog.md` (decision 028 = this model), `(local path)` + `OPERATIONS.md` (read before touching the server).
- This repo: `.remember/remember.md` (handoff), `.remember/single-login-deliberation-20260921/` (code map, review findings, per-ticket packets, smoke script; gitignored).
