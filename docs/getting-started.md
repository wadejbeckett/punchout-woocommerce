# Getting started

For store administrators. This workflow is present in the local 0.4.0 build; installation and release integration remain separate.

## Prepare the supplier

1. Install alongside WordPress and WooCommerce. The manifest declares PHP 8.2+, WordPress 6.4+ and WooCommerce 11.1+. The WooCommerce minimum is hard: a punchout visit stands on session-handler internals verified against 11.1 and is refused with a 409 on an older store. Enable sodium and DOM/libxml.
2. Configure a canonical HTTPS origin. For illustration only: `https://supplier.example.com/punchout/setup`. Verify that the server, cache and firewall pass requests to WordPress without login redirects or browser challenges.
3. Configure the secret-sealing key before storing credentials. In **WooCommerce → PunchOut**, create/configure the company connection, supplier identity, dialect, return encoding, access policy and lifetimes. Enable the master switch when ready.
4. Create, or choose, the one ordinary WooCommerce customer account this customer's buyers will shop as, and put it in whatever pricing or visibility group should apply to them. Bind that account to the connection. Every employee of that customer punches in as this one account and sees exactly what it sees, so its group, prices and catalogue visibility are the whole of what they get. An unbound connection refuses setup with cXML Status 500 plus an audit row (`setup_no_login`), and raises a persistent admin notice until it is bound.

The bound account must be an ordinary customer: it has to be able to read the store, must hold no administrative capability, and must not already be bound to another connection. The binding cannot be transferred or cleared afterwards, so pick the account deliberately rather than reusing a personal login.

## Configure a connection

There is no self-service application, no pending queue and no per-employee approval. The administrator does all of it.

Collect the technical details from buyer IT out of band, by email or ticket: connection name, buyer From domain/identity, Sender domain/identity, deployment mode and any notes. Blank optional Sender fields use From. Fictional identities could be `NetworkID / BUYER-EXAMPLE` and supplier `NetworkID / SUPPLIER-EXAMPLE`; agree actual values with buyer IT.

Enter them at **WooCommerce → PunchOut**, bind the customer account, generate the shared secret — it is shown once and never displayed again — and hand it over privately. Never put a credential in an example, a ticket or an email template.

The account holder can download the company-specific setup XML from **My Account → Punchout integration** while the connection is active; an administrator can also copy it from the **Integration docs** tab. That tab is read-only: it shows a SharedSecret placeholder rather than the stored secret, offers no editing of the connection or its addresses, and is unreachable while a punchout visit is open. The file contains the saved From, Sender and To credentials, cXML version, deployment mode and HTTPS supplier setup URL. Arrange the actual credential handover privately.

Paste the XML over the buyer system's cXML setup request message and replace only the SharedSecret placeholder inside the pasted text; there is no separate credential field for it. The downloaded payloadID, timestamp, BuyerCookie and BrowserFormPost/URL fields are blank by design. Leave them blank: the buyer system fills them itself when a user punches out. Configure UserEmail separately through its extrinsics mapping; the downloaded XML does not duplicate that element. BrowserFormPost is the buyer's cart-return receiver, not the supplier setup URL. Do not invent substitution tokens.

After activation, the connection's identities are read-only. Every subsequent action is the administrator's, at **WooCommerce → PunchOut**: **Rotate secret**, **Close rotation**, **Reset connection** and **Disable**. Rotation temporarily accepts both secrets; verify the replacement in the purchasing system before closing the rotation. An already-open rotation is refused. Disabling is immediate, including session cleanup; an incomplete cleanup result requires attention. A reset leaves the bound store account and its delivery book in place. The customer cannot rotate, reset or deactivate anything from their own account.

## Mode and acceptance

Incoming `Request/@deploymentMode` is parsed, stored on the visit and reused on return. The inspected setup handler does not require it to match the configured partner mode. Consequently, `test` is **not an isolated sandbox or enforced test safety boundary**: real local visits and Quote records can still be created. Both modes use the agreed setup endpoint.

Arrange separate test infrastructure/data where isolation is required. Mode does not simulate features or create a PO automatically; there is no payment step to simulate. Verify actual shopping/return with buyer IT using the [acceptance checklist](troubleshooting.md); a preview or self-test is insufficient.
