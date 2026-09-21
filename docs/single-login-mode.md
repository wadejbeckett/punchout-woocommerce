# Single-login mode — v0.4.0

Decided by Wade Beckett on 21 September 2026 (Lema decision 049). The plugin must do its whole job on a plain WooCommerce site with nothing else installed and no site-specific code. The per-buyer auto-created users and the `pow_buyer_provisioned` "glue hook" pattern are retired.

## Model

- Each PunchOut **customer** is bound to **one existing WooCommerce customer login**, chosen by the admin on the customer screen. The site owner manages that login like any other customer (roles, groups, pricing in whatever B2B plugin they use). The plugin never references any B2B plugin.
- Every buyer who punches in for that customer is signed in **as that login**.
- The plugin keeps **one basket per buyer**: the WooCommerce session for a PunchOut request is keyed by the PunchOut session, not by the user id, so two buyers from the same customer shopping at once never see each other's basket. Persistent cart is already disabled for PunchOut sessions.
- The **buyer identity** from the cXML request (name, email, extrinsics) is stored on the PunchOut session and stamped on the order and the PunchOutOrderMessage, so the site still knows who bought.
- Password login and password reset stay refused for the bound login while it is used for PunchOut only; if the admin wants to log in as it, they use their own admin account. (Setting: "This login is PunchOut-only", default on.)

## Tickets

| # | Title | Done when |
| --- | --- | --- |
| S1 | Customer → login binding | Customer screen has a "WooCommerce login" picker (existing users, customer/subscriber roles). Stored on the customer row. Validation: one login per customer, login must exist. Unit tests. |
| S2 | Sign-in as bound login | `Provisioner` no longer creates users. On PunchOutSetupRequest it resolves the bound login, refuses with a clear cXML error if none is bound, and starts the WordPress session as that user. Buyer identity captured on the PunchOut session row. Tests cover missing binding, deactivated login, two buyers same customer. |
| S3 | Per-buyer basket | `NativeSessionHandler` keys the WooCommerce session by the PunchOut session id for PunchOut requests (cookie-bound), falls back to native behaviour for everything else. Concurrency tests: two buyers, same login, interleaved add/remove, no cross-contamination; existing CAS/rollback tests still green. |
| S4 | Order stamping | Order meta and the PunchOutOrderMessage carry buyer name/email/extrinsics. Admin order screen shows "Bought by". Tests. |
| S5 | Migration | Upgrade routine: for each customer, if legacy auto-created buyers exist, the admin is prompted to bind a login; legacy `punchout_buyer` users are left in place, marked deprecated, and can be deleted from a tools screen once no open session references them. `pow_buyer_provisioned` removed; changelog marks it a breaking change. |
| S6 | Docs | getting-started, native-session-compatibility, troubleshooting updated; readme.txt 0.4.0 changelog. |
| S7 | Lema rollout | Bind Coca-Cola to the existing Coke company login; deploy via the usual backup/install/verify/rollback set; re-test with Thys Wessels (setup, browse, cart, return, PO); then remove `lema-punchout-groups.php` from mu-plugins and record in custom-deploys.md. |

Estimate: S1–S6 three to four days; S7 depends on Coke's tester.

## Out of scope

Anything that reads or writes another plugin's data. If a site owner needs group assignment, they do it on the bound login in that plugin's own screens.
