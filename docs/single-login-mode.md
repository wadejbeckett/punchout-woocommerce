# Single-login mode — v0.4.0 (breaking)

Decided by the owner on 21 September 2026 after a fourteen-agent deliberation (three code/industry readers, four independent designs, three judges, three adversarial verifiers, one writer). The plain-English brief and the full technical record live in the owner's client workspace; a copy sits beside this repo's handoff in `.remember/single-login-deliberation-20260921/`.

## The rule

The plugin must do its whole job on any WordPress site with WooCommerce and nothing else. No site-specific code, no MU plugin, no hook whose purpose is site glue, no knowledge of any other plugin. It follows a normal login and punchout process: authenticate the procurement system, sign the buyer in as the customer's one store login, shop, return the cart, receive the PO. Product visibility and pricing for that login are whatever the site owner configured for it, exactly as for a password login.

## The model

- **One store login per customer connection.** `partners.shop_user_id` points at an existing WooCommerce customer the admin picks (or creates once, by hand). Nothing bound → setup refused with a cXML 500, an audit row and an admin notice. The plugin never creates, renames or deletes users.
- **One basket per punchout visit.** Every PunchOutSetupRequest opens its own WordPress session (its own auth cookie and `WP_Session_Tokens` entry) and its own WooCommerce session row, keyed by `Cart\SessionKey::for( Session )` (a `pow_`-prefixed 32-char HMAC of the punchout session id, stored in a UNIQUE `sessions.wc_session_key` column). Two buyers of the same customer are the same login but two visits: two baskets, two delivery addresses, no shared state.
- **Buyer identity is data, not a user.** Name/email/extrinsics from the cXML request are stored on the session (`buyer_identity`, `buyer_name`; empty is legal and audited) and stamped on the order as meta, an order note and a "Bought by" admin line. Orders keep `customer_id` = the bound login. BuyerCookie stays the correlator. No POOM header extrinsics (the DTDs forbid them).
- **Password door shut.** Per-connection `punchout_only_login` (default on) drives the existing `wp_authenticate_user` / `allow_password_reset` denials. Bind-time guard: if the target login has ever logged in or owns its own orders, the flag defaults off and turning it on needs a second typed confirmation.
- **Removed:** `Buyers\Provisioner`, ephemeral identities, the per-partner identity mutex, latest-wins, the dormant-buyer cron, and both glue hooks `pow_buyer_provisioned` / `pow_buyer_deactivated`.

## Verifier findings folded into the tickets

Six (not two) `get_customer_id()` vs `user_id` comparisons across Cart/ and Addresses/; the identity fingerprint in `NativeSessionGuard::raw_auth()` snapshots all usermeta and would refuse a colleague's in-flight commit; `WC_Customer` reads the user-meta store first so addresses leak between buyers and any usermeta write invalidates live snapshots; the Store API `OrderController::sync_customer_data_with_order()` writes addresses to the shared profile with no filter; application passwords and `/wp/v2/users/me` would give a buyer a standing credential on the real account; quote orders stamp `_pow_session_id` while `RouteGuard` checks `_pow_session`; the cart-commit mutex is per-partner; `wp_delete_user($id,$reassign)` does not move WooCommerce orders.

## Tickets

| # | Title | Done when |
| --- | --- | --- |
| T1 | Schema + `Buyers\Account` resolver | DB_VERSION 7 adds `partners.shop_user_id`, `punchout_only_login`, `sessions.buyer_identity`, `buyer_name`, `wc_session_key` (UNIQUE), `KEY partner_buyer`; upgrade expires open sessions and destroys their WP tokens with a log line. `resolve( $partner )` is a pure read re-proved at setup and redeem (exists, `read`, no privileged capability, not deactivated). Unbound → cXML 500, audit `setup_no_login`, persistent notice. |
| T2 | Admin binding screen | Picker reusing `Registry::associate_owner()` validation; one login per connection; re-bind under `with_owner_lock` + `with_partner_lock`, refused while any open session references the old login; legacy `punchout_buyer` accounts refused; "has logged in / owns orders" warning and second confirmation for the password flag; audit rows. |
| T3 | `Cart\SessionKey` | Stored variant written inside `Store::bind_login()`; `pow_` prefix; `32 === strlen()` asserted; salt-rotation and prefix tests. |
| T4 | `NativeSessionHandler` | `init()` resolves the memoised session first and bypasses `init_session()` (cookie read, `is_session_cookie_valid()`, `migrate_guest_session_to_user_session()`, clone path all unreachable); override `generate_customer_id()`, `set_customer_session_cookie()` (no-op), `set_session_expiration()`; `get_session()` refuses foreign keys; `delete_session()` gated on own key; fail-closed 409 when WooCommerce's handler shape changes; `Docs` self-test. |
| T5 | `NativeSessionGuard` | `protected_key()` = shape test, `owned_key()` = this request's; `login()` requires `row->user_id === get_current_user_id()` and `row->wp_session_token === wp_get_session_token()`; `select_handler()` 403 re-scoped to "this request has a punchout session"; cleanup fence = own key and `Store::find()` still carries the same token; Cart-Token and `?session=` refused permanently. |
| T6 | Identity fingerprint + commit mutex | `raw_auth()` narrowed to `wp_capabilities`, `wp_user_level` and the binding meta (no `session_tokens`, no `umeta_id` ordering); cart-commit lock re-keyed to the punchout session, partner lock kept for redeem/teardown/registry; 20-concurrent-buyer load test; test: colleague redeems a StartPage token between A's stage and commit, A still commits. |
| T7 | Key/user-id comparisons | All six sites (`Confirmation.php:256`, `DeliveryEstimate.php:33,:95,:168`, `NativeSessionHandler.php:83`, `NativeSessionGuard.php:117`) go through `SessionKey`; CI grep fails the build on `get_customer_id()` near `user_id` outside that class; fixtures re-keyed. |
| T8 | Concurrency policy | Latest-wins deleted; per-connection open-session cap (`Store::open_for_partner()`, default 50, filterable, cXML 500 over cap, expired rows swept first); per-identity supersede keyed `(partner_id, sha256(partner_id|identity))` only where identity exists. |
| T9 | Address isolation | `update_user_metadata` short-circuit for `billing_*`/`shipping_*` on the bound login while a punchout session is live; `woocommerce_checkout_update_customer_data` and `woocommerce_checkout_update_user_meta` no-ops; tests for classic and blocks checkout; test that a usermeta write on the login does not discard a live buyer's customer snapshot. |
| T10 | Order and payment scoping | `RouteGuard.php:63,175,187` fence on whichever of `_pow_session`/`_pow_session_id` is present, refuse when absent or mismatched, `customer_id` dropped as an ownership signal; sweep emails, order-again, downloads; cross-session refusal tests. |
| T11 | REST/admin lockdown | `wp_is_application_passwords_available_for_user` false for a bound PunchOut-only login; `rest_pre_dispatch` 403 on `/wp/v2/users*` and `/wp/v2/application-passwords*` during a session; `admin_init` redirect (not only `woocommerce_prevent_admin_access`); `IntegrationTab::actor()` returns 0 whenever a session is live. |
| T12 | Deletions | `Provisioner`, ephemeral path, identity mutex, dead `latest_wins()`, both hooks, dormant-buyer cron removed; role registration and password denial kept for legacy accounts. |
| T13 | Attribution | Session columns, `_pow_buyer_identity` / `_pow_buyer_name` order meta beside the existing session/partner meta, order note, "Bought by" via `woocommerce_admin_order_data_after_billing_address`; anonymous case named as such. |
| T14 | Authorisation ripple | All 26 `Installer::ROLE` sites split into request-scoped (live punchout session) and user-scoped (is this login bound), reviewed as one commit against a checklist; exit policy per connection, per-buyer exit policy deleted. |
| T15 | WP-CLI + docs | `wp punchout backfill-attribution` (idempotent, dry-run first) and `wp punchout retire-buyers` (dry-run default, per-account confirmation, explicit `set_customer_id()` reassignment, never `wp_delete_user($id,$reassign)`); readme.txt and docs rewritten to "one account per customer, which you create and group yourself, once"; 0.4.0 changelog marks the hooks removed and unbound connections refusing. |
| T16 | Two-buyer suite | Interleaved add/remove, logout mid-session, terminal cleanup of own row only, colleague login during a save, foreign-key read returns default, cart-token clone refused, blocks and classic checkout. |

Estimate: eight working days build and test, then the customer re-test (including two concurrent testers).

## Rollout on an existing site (generic)

1. Upgrade (T1) expires open sessions; nothing is auto-bound.
2. Run `wp punchout backfill-attribution` before touching any legacy account.
3. Bind each connection to its customer's store login; the site owner assigns that login's group/pricing in whatever B2B plugin they use, by hand, once.
4. Re-test with the customer, including two buyers at once.
5. Delete any site-specific glue that consumed the removed hooks.
6. Legacy `punchout_buyer` accounts stay (they own orders), marked `_pow_legacy`, hidden from the customer screen; retire later with the CLI if wanted.
