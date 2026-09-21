# Troubleshooting and acceptance

Establish the installed build and available features. A preview, sample or self-test does not prove an installed confirmation screen or a successful buyer transaction.

## Locate the failing stage

| Symptom | Check |
|---|---|
| HTML challenge, redirect or HTTP error | Canonical HTTPS URL, proxy/WAF rules, cache bypass and WordPress routing. |
| cXML authentication failure | Active company connection, exact Sender domain/identity, current credential and IP policy. |
| Setup answers cXML Status 500 | Whether the connection has a bound store account, or whether its open-visit cap is reached. Both are configuration faults, not transient ones: retrying will not clear either. The audit log distinguishes them (`setup_no_login` and `setup_visit_cap`). |
| StartPage refuses access | Token expiry/reuse, connection state, and whether the connection has a bound store account that still exists, is readable and holds no privileged capability. Open a fresh visit through the purchasing system. |
| Quote shows no buyer name | The setup request carried no usable identity extrinsic. This is allowed and audited, not an error: the visit is served, the quote simply carries no buyer. A blank higher-priority extrinsic is the usual cause — an empty `UserEmail` element is treated as absent so the next source is read, but check that the purchasing system is populating the element it maps. |
| A colleague's basket disappeared | Both employees are being sent the same identity value, so the second punchout superseded the first. One stable identity per person; never a shared mailbox or a random value. |
| Cart returns but lines differ | SKU omissions, EA unit acceptance, classification, currency, quantity/price rounding and ShortName handling. |
| Return reaches purchasing-system login | Cross-site receiver cookies/session and actual BrowserFormPost target. Supplier authentication success does not establish receiver acceptance. |
| Address or freight absent | Whether the confirmation flow ran, the separate connection export option is enabled, the selected dialect supports the field and the receiver accepts it. |

Default StartPage lifetime is 300 seconds; shopping lifetime is 14,400 seconds, with connection overrides. A new visit supersedes the earlier visit of the same buyer identity only. Two different identities shop in parallel visits on the same store account, each with its own session row, basket and delivery selection; an unidentified visit supersedes nothing and is never superseded. Rate limiting and expiry are separate controls; repeated retries can obscure the original fault.

Do not edit the bound store account's name or billing/shipping address while punchout visits are open. Any such write bumps the account's `last_update` user meta, which makes WooCommerce discard every live visit's address and chosen-rate snapshot mid-flow, with no error and no audit row. Changing or resetting that account's password ends every live visit immediately, for the same reason WordPress invalidates its auth cookies.

Use supplier audit records and the Dynamics cart message log. Microsoft documents temporary `TRACEPUNCHOUT` diagnostics in [Purchasing cXML Enhancements](https://learn.microsoft.com/en-us/dynamics365/supply-chain/procurement/purchasing-cxml-enhancements#view-the-cxml-cart-message-log-for-external-catalog-punchout). Remove credentials, tokens and personal data before sharing captures. Recognized secret redaction is not comprehensive free-text sanitization. The audit log records a buyer as a short hash and a name, never a raw e-mail address. Audit retention defaults to 400 days; operational Woo logs and Quote retention are separate.

## End-to-end acceptance

1. Confirm an unbound connection refuses setup with cXML Status 500, writes a `setup_no_login` audit row, creates no visit, and raises the admin notice. Then bind the store account from the connection's edit screen and confirm setup succeeds and the notice clears.
2. Launch two employees at once. Verify two visits on the one bound account, with separate baskets and separate delivery selections, and that neither can see the other's basket, quote order, order-received or order-pay page. Then re-launch one of them and verify only that employee's earlier visit is superseded.
3. Return actual cart lines and compare names, quantities, units, currency and totals in the purchasing system. Verify each quote order carries the right buyer name and e-mail, an order note naming the buyer, a "Bought by" line on the admin order screen, and `customer_id` still the bound account.
4. Exercise Back to cart, changed addresses/rates, free/pickup/no-rate outcomes and snapshot durability. No checkout or payment control may appear anywhere in a visit.
5. Confirm the item-7 refusals inside a visit: wp-admin, `/wp/v2/users*`, application passwords, account details and password change are all refused, as is the My Account punchout tab.
6. Verify rotation overlap, closure, disabling, expiry and secret-free subsequent page loads.
7. Verify the prices and catalogue the buyer sees in both concurrent visits are the bound account's own. That is hand configuration on the store, and the only part of this the plugin cannot guarantee for itself.

Record each result with its installed build and tenant configuration. Supplier success, DTD validity and business approval are separate outcomes; none proves a future inbound PO receiver works.
