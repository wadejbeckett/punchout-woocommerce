# Troubleshooting and acceptance

Establish the installed build and available features. A preview, sample or self-test does not prove an installed Account/confirmation screen or successful buyer transaction.

## Locate the failing stage

| Symptom | Check |
|---|---|
| HTML challenge, redirect or HTTP error | Canonical HTTPS URL, proxy/WAF rules, cache bypass and WordPress routing. |
| cXML authentication failure | Active company connection, exact Sender domain/identity, current credential and IP policy. |
| StartPage refuses access | Token expiry/reuse, connection state and buyer association. Open a fresh session through the purchasing system. |
| Returning buyer becomes new user | Runtime identity value, email changes, blank higher-priority extrinsics or missing identity. |
| Cart returns but lines differ | SKU omissions, EA unit acceptance, classification, currency, quantity/price rounding and ShortName handling. |
| Return reaches purchasing-system login | Cross-site receiver cookies/session and actual BrowserFormPost target. Supplier authentication success does not establish receiver acceptance. |
| Address or freight absent | Whether planned confirmation/export is installed, enabled and accepted by this receiver. |

Default StartPage lifetime is 300 seconds; shopping lifetime is 14,400 seconds, with connection overrides. Latest shopping supersedes the same buyer's prior open session. Different employees should have distinct records. Rate limiting and expiry are separate controls; repeated retries can obscure the original fault.

Use supplier audit records and the Dynamics cart message log. Microsoft documents temporary `TRACEPUNCHOUT` diagnostics in [Purchasing cXML Enhancements](https://learn.microsoft.com/en-us/dynamics365/supply-chain/procurement/purchasing-cxml-enhancements#view-the-cxml-cart-message-log-for-external-catalog-punchout). Remove credentials, tokens and personal data before sharing captures. Recognized secret redaction is not comprehensive free-text sanitization. Audit retention defaults to 400 days; operational Woo logs and Quote retention are separate.

## End-to-end acceptance

1. Confirm an ordinary owner can apply with technical details and pending cannot authenticate; approve through the completed administrator workflow.
2. Launch two distinct employees, verify separate carts, then recognize a returning employee without manual approval.
3. Return actual cart lines and compare names, quantities, units, currency and totals in the purchasing system.
4. When confirmation is installed, exercise Back to cart, changed addresses/rates, free/pickup/no-rate outcomes and snapshot durability. Punchout-only must offer no payment.
5. Verify rotation overlap, closure, deactivation, expiry and secret-free subsequent page loads.

Record each result with its installed build and tenant configuration. Supplier success, DTD validity and business approval are separate outcomes; none proves a future inbound PO receiver works.
