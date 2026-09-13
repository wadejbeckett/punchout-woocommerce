# Delivery and confirmation

## Current release-candidate behavior

The local 0.3 release candidate keeps ordinary shopping and an editable cart, then requires review of products, destination, available shipping and notes before every physical cart return. The final button is **Submit for approval**, with **Back to cart** before final submission. For Punchout-only connections there is no payment step. That label means returning the basket for the purchasing workflow; it does not approve a requisition itself.

Company owners and supplier administrators maintain shared enabled addresses; employees select from their company's entries. Core storage and shipping use WordPress/WooCommerce, without an address-book plugin. Preserve a valid selected shipping method, otherwise use Woo's configured default. Distinguish free delivery, pickup and unavailable rates. No available rate must not silently become free shipping.

Confirmation preserves an immutable address, code, method, charges and notes snapshot. Changing the cart, destination or accepted rates requires renewed confirmation. When optional freight export is enabled and a rate is available, freight appears once with matching Quote and cXML totals.

## Three address authorities

| Message/stage | Meaning |
|---|---|
| Incoming setup ShipTo | Buyer-supplied shopping context or candidate destination. |
| Supplier confirmation/outgoing ShipTo | Supplier's accepted destination snapshot and optional reference. |
| Final purchase order | Authorized purchasing instruction requiring reconciliation before fulfilment. |

Address codes identify data; they are not individual endpoint URLs. Outgoing address/reference emission and freight ItemIn are separate options, off by default and constrained by the declared dialect. An absent field can therefore mean the relevant connection option is off or the receiver dialect does not support it.

For Dynamics, `PUNCHOUTSHIPTO` supplies legal-entity context; `PUNCHOUTSHIPTOUSER` can substitute the user's address. PO properties such as `DELIVERTO` and fixed address IDs apply to different message stages. Microsoft's [enhancements reference](https://learn.microsoft.com/en-us/dynamics365/supply-chain/procurement/purchasing-cxml-enhancements#set-the-order-properties) explains them. It does not prove a tenant will import supplier-selected addresses or accept freight lines.
