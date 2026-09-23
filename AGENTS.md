# PunchOut for WooCommerce — agent handoff (written 2026-09-22 for Codex / any agent)

Read this first, then `.remember/remember.md` (latest state) and `docs/single-login-mode.md` (the spec). Owner: Wade Beckett (plain English, no essays, HIGH priority; he does not read long briefs).

## What this is
A standalone cXML PunchOut plugin for WooCommerce (namespace `POW`, source in `includes/`). Only install: **lema.co.za** (Plesk host neo.noiz.co.za, `ssh -p 24 root@neo.noiz.co.za`, site user `jcaxkarn`, table prefix `wp5l_`, WooCommerce 11.1.2, WordPress 7.1.1, Avada theme, B2BKing). Customer: Coca-Cola (tester Thys Wessels), Dynamics 365 sends the PunchOutSetupRequest.

## Owner's rules (binding)
- Standalone: no mu-plugin, no site glue file, no hook whose purpose is site glue, no `if plugin X` branch, never name another plugin or theme in code or strings. Solve inside the plugin with WordPress/WooCommerce primitives.
- The customer's WooCommerce account IS the punchout login (`partners.owner_user_id`). The plugin never creates, renames or deletes users. One WooCommerce session row per visit (`sessions.wc_session_key`, `pow_` + 28 hex). PunchOut-only exit (no checkout). Admin-only management; My Account keeps only the setup-XML download.
- Never post a PunchOut return (PunchOutOrderMessage) from a test session on live. Remove test items; let test sessions expire.
- Production changes only when Wade says so; back up first (`backup.sh`), record in `lema.co.za/memory-bank/custom-deploys.md`.
- Keep secrets out of files and logs. The Coke shared secret lives only in `/home/noiz/Projects/Noiz/Clients/lema.co.za/context/meetings/2026-09-10-coke/private/lema-coke-dynamics-template-2026-09-12.xml` (mode 600) — read it at runtime, never print it.

## Current state (22 Sep 2026)
- **Live on lema.co.za: 0.4.7 = cad2077** (branch `feature/0.4.7-hide-logout-in-visit`, tag `v0.4.7`, merged to master), installed 23 Sep 21:15 SAST: a visit's My Account dashboard shows no "Log out" link. Before it: 0.4.6 = 97ed427 (`feature/0.4.6-endpoint-checkboxes`, tag v0.4.6, 23 Sep 20:27), 0.4.5 = 1089f86 (`feature/0.4.5-visit-endpoints`, tag v0.4.5, 23 Sep 17:03) and 0.4.4 = 7cfdfe7 (`feature/0.4.0-single-login`, tag `lema-live-2026-09-21-7cfdfe7`). master holds 0.4.0–0.4.7 (merges 55a2715, 63aab53 and the 0.4.7 merge).
- Unit suite: `php tests/run-tests.php` (1058 pass at 0.4.7, no Composer). Cart surface suite: `php tests/CartBlocks/surface.php`. Native suites (opt-in, real WP): see "Local fixture".
- Lema cleanup done: 20 legacy `punchout_buyer` accounts, 6 test quotes and the mu-plugin `lema-punchout-groups.php` deleted (backups under `/var/www/vhosts/lema.co.za/AVADA-OPTION-BACKUPS/punchout-20260921-*`). User 4 `coca-cola-company` is connection 1's account, B2BKing group 10717.
- Browser-verified as a visit: cart page shows the "Punchout" exit under the totals, no checkout button. Nine smoke visits (132–140) expire ~22 Sep evening; visit 135 still holds 2 test items in its own cart row (the sweep deletes it).

## Next steps
1. Visual pass with Wade on the live cart/shop as a punchout visit (label of the exit = connection setting "return button label", now "Punchout").
2. Coke re-test with Thys, incl. two testers at once. Watch `wp-content/uploads/wc-logs/punchout-woocommerce-*.log` and `wp5l_pow_log`.
3. Then `git checkout master && git merge --no-ff feature/0.4.0-single-login && git tag v0.4.0 && git push --tags`.
4. Deferred: fold the guarded cart save into one module.

## How to deploy to Lema
Tooling (latest): `/home/noiz/Projects/Noiz/Clients/lema.co.za/punchout/tooling/deploy-live-0.4.7-hide-logout/` (copy it for the next release) — `config.env` (COMMIT, PACKAGE, PACKAGE_SHA256, BACKUP dir), then `./backup.sh`, `./install.sh`, `./verify-installed.sh`, `./rollback.sh`. Build a package with `bin/build-zip.sh <outdir>` (bump `Version:` header, `const VERSION`, readme `Stable tag` and changelog first). Put builds under `lema.co.za/punchout/context/builds/<date>-<topic>-<sha7>/`.

## How to smoke-test live without a browser
`.remember/single-login-deliberation-20260921/live-smoke.py` (python3, stdlib): fills the private Dynamics template with a payloadID/timestamp/BuyerCookie/dummy BrowserFormPost and a UserEmail extrinsic, POSTs to `https://www.lema.co.za/punchout/setup`, redeems the StartPage URL, loads `/cart/`, checks `/wp-json/wp/v2/users/me` (must be 401) and `/wp-admin/` (must redirect). Never posts a return. Note: `/cart/?add-to-cart=` gets a Plesk 403 on this site; use `/shop/?add-to-cart=ID` or `?wc-ajax=add_to_cart`.

## Local fixture (real WordPress 7.1 + WooCommerce 11.1.0 + MariaDB, disposable)
`F=/home/noiz/Projects/Noiz/Clients/lema.co.za/tmp/pow-plan06-native-20260913`; `export PHP_INI_SCAN_DIR=/etc/php/8.3/cli/conf.d:$F/conf.d WP_ENVIRONMENT_TYPE=local`; `W="php $F/wp-cli.phar --path=$F/site"`; admin user id 1. Plugin is symlinked from `$F/site/wp-content/plugins/punchout-woocommerce` to this repo. Native suites: `POW_NATIVE_TESTS=disposable POW_NATIVE_SCRIPT=$PWD/tests/Integration/TwoBuyerNative.php $W --user=1 eval 'require getenv("POW_NATIVE_SCRIPT");'` (same for VisitLockdownNative, AddressSchemaNative, AdminActionsNative, DeliveryStoreNative); `RegistrationLifecycleNative` via `eval-file`; ConcurrencyNative needs `POW_NATIVE_CONCURRENCY_MODE=suite POW_NATIVE_WP_CLI=$F/wp-cli.phar POW_NATIVE_WP_PATH=$F/site POW_NATIVE_WP_USER=1 POW_NATIVE_FIXTURE_ROOT=$F`.

## Records
- Lema: `/home/noiz/Projects/Noiz/Clients/lema.co.za/memory-bank/custom-deploys.md` (deploy register), `punchout/memory-bank/activeContext.md`, `punchout/memory-bank/decisionLog.md` (decision 028 = this model), `/home/noiz/Projects/Noiz/Ops/AGENTS.md` + `OPERATIONS.md` (read before touching the server).
- This repo: `.remember/remember.md` (handoff), `.remember/single-login-deliberation-20260921/` (code map, review findings, per-ticket packets, smoke script; gitignored).
