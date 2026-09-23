# Delivery and confirmation

## Current behavior

The local 0.4.0 build keeps ordinary shopping and an editable cart, then requires review of products, destination, available shipping and notes before every physical cart return. The final button is **Submit for approval**, with **Back to cart** before final submission. There is no payment step: checkout is not available inside a punchout visit. That label means returning the basket for the purchasing workflow; it does not approve a requisition itself.

Store administrators maintain each connection's enabled addresses at **WooCommerce → PunchOut**. Every punchout visit selects from that connection's entries and cannot edit them; there is no owner-facing address screen and no editing from inside a visit. Two visits on the same store account choose independently: a selection made in one visit is not the other's. Core storage and shipping use WordPress/WooCommerce, without an address-book plugin. Preserve a valid selected shipping method, otherwise use Woo's configured default. Distinguish free delivery, pickup and unavailable rates. No available rate must not silently become free shipping.

Confirmation preserves an immutable address, code, method, charges and notes snapshot, and the optional preferred delivery date, bound to the visit that made it. Changing the cart, destination or accepted rates requires renewed confirmation, and a confirmation from one visit can never be spent by another. When optional freight export is enabled and a rate is available, freight appears once with matching Quote and cXML totals.

## Three address authorities

| Message/stage | Meaning |
|---|---|
| Incoming setup ShipTo | Buyer-supplied shopping context or candidate destination. |
| Supplier confirmation/outgoing ShipTo | Supplier's accepted destination snapshot and optional reference. |
| Final purchase order | Authorized purchasing instruction requiring reconciliation before fulfilment. |

Address codes identify data; they are not individual endpoint URLs. Outgoing address/reference emission and freight ItemIn are separate options, off by default and constrained by the declared dialect. An absent field can therefore mean the relevant connection option is off or the receiver dialect does not support it.

For Dynamics, `PUNCHOUTSHIPTO` supplies legal-entity context; `PUNCHOUTSHIPTOUSER` can substitute the user's address. PO properties such as `DELIVERTO` and fixed address IDs apply to different message stages. Microsoft's [enhancements reference](https://learn.microsoft.com/en-us/dynamics365/supply-chain/procurement/purchasing-cxml-enhancements#set-the-order-properties) explains them. It does not prove a tenant will import supplier-selected addresses or accept freight lines.

## Preferred delivery date, collection and delivery periods

**Preferred delivery date.** The review page has an optional native date field for physical carts. It defaults to 14 days ahead, and its minimum is tomorrow, both in the site timezone (Settings → General). The minimum is checked when the buyer reviews and confirms, not at return, so consent given before midnight still returns after it. An emptied field means no preference. A virtual-only cart never stores a date. The date is stored in confirmation schema 2 and bound to the stored cart fingerprint but not the review digest, so the buyer can change it without pressing **Update delivery options**. On the Quote it is stamped as `_pow_preferred_delivery_date` (only when set), written in the Quote creation note and shown on the admin PunchOut line. It is never sent in the PunchOutOrderMessage.

**Collection.** A cart counts as collection when every selected rate is WooCommerce Local Pickup: `local_pickup` (the zone method), `legacy_local_pickup`, or `pickup_location` (the Blocks Local Pickup method). This is a fixed list and is worked out from the stored `method_id`, not stored separately. The review then calls the method section **Collection**. Collection points or hubs are the store owner's own pickup methods or zones. The buyer still chooses an address, because WooCommerce offers zone pickup based on the destination; when there is only one address it is already selected. Collection with no address at all would put the store's own address in ShipTo and is not supported; that is an open question for the owner.

**Delivery periods.** There is no separate setting. Write the delivery period in the shipping method title, for example `Courier (3–5 working days)`. Keep the title to 190 characters or fewer: the review refuses a longer one. Changing a title changes the stored rate, so open confirmations need a fresh review. The review shows the title beside each method, the Quote creation note carries it (`Delivery method: …` or `Collection: …`), and with `emit_delivery_line` the freight ItemIn Description is the selected title or titles, as plain text joined with `; ` in package order and not uppercased. When a title strips to nothing the Description falls back to `Delivery`.

**Confirmation schema.** Schema 1 records (9 keys) are still read exactly as stored and still return. New confirmations are schema 2 (10 keys: schema 1 plus `preferred_delivery_date`, a `Y-m-d` string or null). Each schema's field set is exact.
