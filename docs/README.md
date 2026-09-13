# PunchOut for WooCommerce documentation

Turn a WooCommerce store into an external purchasing catalog. Employees enter from their purchasing system, shop and return product and price information for organizational approval.

A returned basket is not a purchase order or payment. In Dynamics 365 it contributes requisition lines. The local development build can also record a **Punchout Quote**: a native WooCommerce order carrying the custom `punchout-quote` status. A later authorized purchase order still needs reconciliation before fulfilment.

## Availability

Source reviewed on 9 September 2026. Check the installed build before following development-only instructions.

| State | Features |
|---|---|
| Existing 0.2.4 functionality | Admin connection settings; shared-secret setup; automatic buyer accounts; cart return; connection-specific checkout policy; audit logging. |
| Local 0.3 release-candidate work | Quote recording, documentation/self-test, company application and lifecycle services, owner Account management, company setup-template download, company address book, mandatory delivery review, optional address/freight export and expanded exit-policy inheritance. This is not a released or deployed feature promise. |
| Separate acceptance work | Buyer-system launch and cart-return acceptance, production installation and release/version integration. |
| Not implemented | Incoming purchase-order receiver, multi-currency conversion, ERP profile picker and manufacturer-field mapping. |

An interactive preview demonstrates proposed screens; it neither configures connections nor proves live transactions.

## Read by task

- [Getting started](getting-started.md): supplier setup and company applications.
- [Dynamics 365](dynamics365.md): buyer IT configuration and mapping boundaries.
- [Delivery](delivery.md): confirmation, addresses and shipping authority.
- [Protocol reference](protocol-reference.md): messages, formats and mapper limits.
- [Troubleshooting](troubleshooting.md): isolate failures and record acceptance evidence.

WordPress and WooCommerce are required, together with PHP's sodium and DOM/libxml capabilities. No additional commercial, B2B or address-book plugin is required for the core design. Optional pricing/catalog adapters remain separate.

The plugin retains [AGPL-3.0-or-later](../LICENSE). Its bundled upstream cXML DTD has separate provenance, explained in the [protocol reference](protocol-reference.md). Supporting direct cXML does not certify every purchasing product, tenant, theme or extension.
