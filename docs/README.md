# PunchOut for WooCommerce documentation

Turn a WooCommerce store into an external purchasing catalog. Employees enter from their purchasing system, shop and return product and price information to their purchasing system.

Each customer connection is bound to one ordinary WooCommerce customer account that you create, group and price: every employee of that customer shops as that account and sees exactly what it sees, while each punchout visit keeps its own basket, delivery selection and returned quote. These documents are written for store administrators. There is no buyer- or customer-facing management screen to configure: everything is at **WooCommerce → Settings → PunchOut** (its own tab beside Payments; the PunchOut payment method's settings link there).

A returned basket is not a purchase order or payment. In Dynamics 365 it contributes requisition lines. Every Submit leaves a **Punchout Quote**: the order the store's classic checkout placed with the PunchOut payment method (0.5.0), carrying the custom `punchout-quote` status, owned by the bound account and naming the buyer who sent it. It is never paid. A later authorized purchase order still needs reconciliation before fulfilment.

## Availability

These documents describe 0.4.0 (single login) and 0.5.0 (checkout as the way out) onwards. Check the installed build before following development-only instructions.

| State | Features |
|---|---|
| Released 0.4.0 functionality | Admin connection settings; shared-secret setup; one bound customer account per connection; one basket and quote per visit; buyer attribution on the visit and the quote; cart return; no payment in a visit; audit logging. |
| Also in 0.4.0 | Quote recording, documentation/self-test, the setup-template download, the company address book and optional address/freight export. |
| 0.5.0 | The visit leaves through WooCommerce's classic checkout with the PunchOut payment method; the cXML is built from the placed order; the state page and the leave question; schema 10 (line description, order details sent). The review page and its consent are retired. |
| Left to each store | Launch and cart-return acceptance testing with each buyer system, and production installation. |
| Not implemented | Incoming purchase-order receiver, multi-currency conversion, ERP profile picker and manufacturer-field mapping. |

## Read by task

- [Single-login mode](single-login-mode.md): the 0.4.0 model in full — one bound account per customer, one session row per visit, buyer attribution, no payment in a visit, admin-only management and what a visit may not reach — followed by how the build actually landed, with the 0.5.0 note at the top. Written for whoever operates or changes the plugin rather than for a buyer.
- [Getting started](getting-started.md): supplier setup, the bound customer account and credential handover.
- [Dynamics 365](dynamics365.md): buyer IT configuration and mapping boundaries.
- [Delivery](delivery.md): the order's address, the account's saved addresses, delivery cost and the cXML (0.5.0), and what the old review stored.
- [Protocol reference](protocol-reference.md): messages, formats and mapper limits.
- [Troubleshooting](troubleshooting.md): isolate failures and record acceptance evidence.
- [Native session compatibility](native-session-compatibility.md): how one WooCommerce login carries several independent baskets, and what that costs.
- [Transport security](transport-security.md): the HTTPS policy every punchout surface enforces.

WordPress and WooCommerce are required, together with PHP's sodium and DOM/libxml capabilities. No additional commercial, B2B or address-book plugin is required for the core design. Optional pricing/catalog adapters remain separate.

The plugin retains [AGPL-3.0-or-later](../LICENSE). Its bundled upstream cXML DTD has separate provenance, explained in the [protocol reference](protocol-reference.md). Supporting direct cXML does not certify every purchasing product, tenant, theme or extension.
