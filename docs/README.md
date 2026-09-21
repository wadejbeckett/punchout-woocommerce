# PunchOut for WooCommerce documentation

Turn a WooCommerce store into an external purchasing catalog. Employees enter from their purchasing system, shop and return product and price information for organizational approval.

Each customer connection is bound to one ordinary WooCommerce customer account that you create, group and price: every employee of that customer shops as that account and sees exactly what it sees, while each punchout visit keeps its own basket, delivery selection and returned quote. These documents are written for store administrators. There is no buyer- or customer-facing management screen to configure: everything is at **WooCommerce > PunchOut**.

A returned basket is not a purchase order or payment. In Dynamics 365 it contributes requisition lines. Every cart return also records a **Punchout Quote**: a native WooCommerce order carrying the custom `punchout-quote` status, owned by the bound account and naming the buyer who sent it. A later authorized purchase order still needs reconciliation before fulfilment.

## Availability

Source reviewed on 21 September 2026 for the 0.4.0 single-login release. Check the installed build before following development-only instructions.

| State | Features |
|---|---|
| Released 0.4.0 functionality | Admin connection settings; shared-secret setup; one bound customer account per connection; one basket, delivery selection and quote per visit; buyer attribution on the visit and the quote; cart return; PunchOut-only exit; audit logging. |
| Also in 0.4.0 | Quote recording, documentation/self-test, the setup-template download, the company address book, mandatory delivery review and optional address/freight export. |
| Separate acceptance work | Buyer-system launch and cart-return acceptance, production installation and release/version integration. |
| Not implemented | Incoming purchase-order receiver, multi-currency conversion, ERP profile picker and manufacturer-field mapping. |

An interactive preview demonstrates proposed screens; it neither configures connections nor proves live transactions.

## Read by task

- [Single-login mode](single-login-mode.md): the 0.4.0 model in full — one bound account per customer, one session row per visit, buyer attribution, PunchOut only, admin-only management and what a visit may not reach — followed by how the build actually landed. Written for whoever operates or changes the plugin rather than for a buyer.
- [Getting started](getting-started.md): supplier setup, the bound customer account and credential handover.
- [Dynamics 365](dynamics365.md): buyer IT configuration and mapping boundaries.
- [Delivery](delivery.md): confirmation, addresses and shipping authority.
- [Protocol reference](protocol-reference.md): messages, formats and mapper limits.
- [Troubleshooting](troubleshooting.md): isolate failures and record acceptance evidence.
- [Native session compatibility](native-session-compatibility.md): how one WooCommerce login carries several independent baskets, and what that costs.
- [Transport security](transport-security.md): the HTTPS policy every punchout surface enforces.

WordPress and WooCommerce are required, together with PHP's sodium and DOM/libxml capabilities. No additional commercial, B2B or address-book plugin is required for the core design. Optional pricing/catalog adapters remain separate.

The plugin retains [AGPL-3.0-or-later](../LICENSE). Its bundled upstream cXML DTD has separate provenance, explained in the [protocol reference](protocol-reference.md). Supporting direct cXML does not certify every purchasing product, tenant, theme or extension.
