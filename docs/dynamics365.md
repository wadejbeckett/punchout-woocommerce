# Dynamics 365 buyer IT setup

Use Microsoft's [external catalog setup instructions](https://learn.microsoft.com/en-us/dynamics365/supply-chain/procurement/set-up-external-catalog-for-punchout). Configure vendor/category access, units and currency; validate and activate the catalog. Validation alone is not a requisition-to-cart-return test.

Download the company setup XML from **My Account → Punchout integration**. Paste its complete cXML document into the external catalog's setup template, then put the privately issued credential in place of `REPLACE-WITH-ISSUED-SHARED-SECRET`. The file contains the company's exact static From, Sender, To, `deploymentMode` and `SupplierSetup/URL`; it never contains a stored secret.

Dynamics must supply `payloadID`, `timestamp`, `BuyerCookie`, the initiating user's `UserEmail` and its own `BrowserFormPost/URL` at runtime. Configure those through Microsoft's documented template and extrinsic controls. The example values in the downloaded XML show the required shape; they are not literal values for every launch or invented Dynamics substitution tokens. `BrowserFormPost/URL` is the Dynamics cart-return receiver and must not be replaced with the supplier setup URL.

## Nine setup fields, nine returned mappings

The setup fields configure outbound connection initiation. Abbreviations below place credentials under `Header`; domains belong to `Credential`.

| Setup field | Meaning |
|---|---|
| From domain | Buyer domain |
| From Identity | Buyer identifier |
| To domain | Supplier domain |
| To Identity | Supplier identifier |
| Sender domain | Sender domain |
| Sender Identity | Sender identifier |
| Sender SharedSecret | Company credential |
| Request deploymentMode | Exchange mode |
| SupplierSetup/URL | Supplier endpoint |

Microsoft separately documents these nine **fixed return mappings**, not nine configurable connection fields:

| Returned field | Requisition use |
|---|---|
| ItemIn/@quantity | Quantity |
| ItemID/SupplierPartID | External identifier |
| UnitPrice/Money/@currency | Currency |
| UnitPrice/Money | Price |
| Description/@ShortName | Name |
| Description text | Description/name fallback |
| UnitOfMeasure | Unit |
| Classification text | Description content |
| Classification/@domain | Description content |

**Format discrepancy:** Microsoft's table shows a ShortName attribute. The current supplier Builder emits a child `ShortName`; the [1.2.008 DTD](https://xml.cxml.org/schemas/cXML/1.2.008/cXML.dtd) defines that child. Do not silently rewrite samples to the attribute. Validate the exact declared dialect, then verify the receiver's displayed name and description. See [protocol reference](protocol-reference.md).

## Employee identity

In the catalog's **Message format → Extrinsics**, add **Name: `UserEmail`**, **Value: `User email`**. Microsoft documents runtime insertion and the value source in [Purchasing cXML Enhancements](https://learn.microsoft.com/en-us/dynamics365/supply-chain/procurement/purchasing-cxml-enhancements#set-the-extrinsic-elements-for-external-catalog-punchout). The supplier chooses the agreed name; Microsoft does not prescribe that literal spelling.

The example fragment below illustrates the resulting value, not a complete setup document or static value to paste for every employee:

```xml
<Extrinsic name="UserEmail">buyer.one@example.com</Extrinsic>
```

Company credentials authenticate the connection. The trusted purchasing system authorizes employees; each stable identity maps to a separate supplier buyer. Do not use random values or a shared mailbox for returning-buyer continuity. `BuyerEmail` is not a default alias. A changed email may create a new buyer; changing the supplier profile does not alter incoming XML.

## Returning and later ordering

Launch through a requisition and check returned lines before submitting the purchasing-system workflow, as described in Microsoft's [usage guide](https://learn.microsoft.com/en-us/dynamics365/supply-chain/procurement/use-external-catalogs-for-punchout).

Microsoft's enhancements also support later PO transmission. This plugin has no PO receiver: do not enable automatic sending to `/punchout/order`. Microsoft's PO batch **Test** generates without transmitting; **Live** sends. This differs from the supplier's cXML deployment mode. See the [enhancements reference](https://learn.microsoft.com/en-us/dynamics365/supply-chain/procurement/purchasing-cxml-enhancements#set-up-global-cxml-parameters).
