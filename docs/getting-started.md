# Getting started

For supplier administrators and company managers. This approved 0.3 application workflow still needs release integration. Existing installations use the supplier's admin connection screen.

## Prepare the supplier

1. Install alongside WordPress and WooCommerce. The manifest declares PHP 8.2+, WordPress 6.4+ and WooCommerce 8.0+; these are minimum declarations, not a fresh compatibility certification. Enable sodium and DOM/libxml.
2. Configure a canonical HTTPS origin. For illustration only: `https://supplier.example.com/punchout/setup`. Verify that the server, cache and firewall pass requests to WordPress without login redirects or browser challenges.
3. Configure the secret-sealing key before storing credentials. In **WooCommerce → PunchOut**, create/configure the company connection, supplier identity, dialect, return encoding, access policy and lifetimes. Enable the master switch when ready.

## Application through approval

Any existing ordinary customer may request a connection. There is no extra eligible-user flag, and shopping-only provisioned buyer logins cannot manage company credentials.

The company manager supplies technical details **during application**: connection name, buyer From domain/identity, Sender domain/identity, deployment mode and optional notes. Blank optional Sender fields use From. Fictional identities could be `NetworkID / BUYER-EXAMPLE` and supplier `NetworkID / SUPPLIER-EXAMPLE`; agree actual values with buyer IT.

Submission creates a pending connection without a secret. The supplier administrator reviews the request, completes supplier identity and purchasing permissions, then explicitly approves. Notification contains no credential. Company approval does not require entering or approving every employee separately.

Use the generator for company credentials and arrange private handover. The new owner response displays a rotated secret directly; legacy admin reveal handling still needs its planned replacement before claiming the whole build has that behavior. Never put credentials in examples, tickets or email templates.

After activation, owner identities are read-only. Rotation temporarily accepts both secrets; verify the replacement in the purchasing system before finishing rotation. An already-open rotation is refused. Deactivation is immediate, including session cleanup; an incomplete cleanup result requires supplier attention.

## Mode and acceptance

Incoming `Request/@deploymentMode` is parsed, stored in the shopping session and reused on return. The inspected setup handler does not require it to match the configured partner mode. Consequently, `test` is **not an isolated sandbox or enforced test safety boundary**: real local sessions and Quote records can still be created. Both modes use the agreed setup endpoint.

Arrange separate test infrastructure/data where isolation is required. Mode does not simulate features, authorize payment or create a PO automatically. Verify actual shopping/return with buyer IT using the [acceptance checklist](troubleshooting.md); a preview or self-test is insufficient.
