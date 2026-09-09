# Delivery and confirmation

## Current behavior and planned experience

Current cart return sends immediately after the existing return control; its handoff page auto-submits. It does not yet provide the complete delivery-confirmation experience below. Local Quote work can retain delivery information from a validated integration filter, incoming ShipTo or the buyer's saved Woo shipping address. The pure code allocator is not a complete address book.

The approved 0.3 experience keeps ordinary shopping and an editable cart, then provides checkout-like review of products, destination, available shipping and notes. The final button is **Submit for approval**, with **Back to cart** before final submission. For Punchout-only connections there is no payment step. That label means returning the basket for the purchasing workflow; it does not approve a requisition itself.

Company owners and supplier administrators maintain shared enabled addresses; employees select from their company's entries. Core storage and shipping use WordPress/WooCommerce, without an address-book plugin. Preserve a valid selected shipping method, otherwise use Woo's configured default. Distinguish free delivery, pickup and unavailable rates. No available rate must not silently become free shipping.

Confirmation will preserve an immutable address, code, method, charges and notes snapshot. Changing the cart, destination or accepted rates requires renewed confirmation. Planned optional freight must appear once, with matching Quote and cXML totals.

## Three address authorities

| Message/stage | Meaning |
|---|---|
| Incoming setup ShipTo | Buyer-supplied shopping context or candidate destination. |
| Supplier confirmation/outgoing ShipTo | Supplier's accepted destination snapshot and optional reference. |
| Final purchase order | Authorized purchasing instruction requiring reconciliation before fulfilment. |

Address codes identify data; they are not individual endpoint URLs. Outgoing address/reference emission and freight ItemIn are separate planned options, each constrained by the declared dialect. Current omission does not mean addresses are impossible in cXML.

For Dynamics, `PUNCHOUTSHIPTO` supplies legal-entity context; `PUNCHOUTSHIPTOUSER` can substitute the user's address. PO properties such as `DELIVERTO` and fixed address IDs apply to different message stages. Microsoft's [enhancements reference](https://learn.microsoft.com/en-us/dynamics365/supply-chain/procurement/purchasing-cxml-enhancements#set-the-order-properties) explains them. It does not prove a tenant will import supplier-selected addresses or accept freight lines.
