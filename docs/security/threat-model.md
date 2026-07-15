---
title: Threat model
description: The correctness invariants the library upholds, the properties it does not claim, and the responsibilities that stay in your application.
weight: 81
---

# Threat model

This is an honest account of what `cboxdk/laravel-billing` defends against and what
it does not. It errs toward under-claiming: where a property depends on how you
deploy the library, it is listed as **your** responsibility, not the library's.

## What the library upholds

These are correctness invariants baked into the code, each traceable to an ADR:

- **No double-post to the ledger.** Posting is idempotent on a natural
  `PostingKey(org, source, reference)`, enforced in application code — not on a
  database unique constraint that table partitioning would later force you to drop
  ([ADR-0002](../../adr/0002-ledger-idempotency-independent-of-partitioning.md)).
  A retried or reprocessed post is a no-op.
- **No double-spend on the hot path.** The near-real-time balance is *derived*
  (`ledger_balance − unflushed_usage − active_reservations`), never a loose cached
  scalar that could be re-set to the ledger sum and wipe in-flight spend
  ([ADR-0008](../../adr/0008-derived-hot-path-balance.md)).
- **No mid-period allowance re-grant.** Period claim-counters are an authoritative
  claim register that expires only at the period boundary via TTL; they are never
  cleared mid-period ([ADR-0008](../../adr/0008-derived-hot-path-balance.md)).
- **No free usage through a disabled feature.** Entitlement (`enabled?`) is checked
  *before* any allowance or cost math, so an under-allowance call to a disabled
  dimension is refused rather than computing zero overage
  ([ADR-0005](../../adr/0005-multi-dimensional-metering.md)).
- **Fail closed on semantics, open on infrastructure.** An unknown scope, disabled
  feature, or missing entitlement is always denied; only an infrastructure fault
  can fail open (configurable), and it emits a signal when it does
  ([ADR-0004](../../adr/0004-enforcement-failure-policy.md)).
- **No silent usage loss.** Usage older than the reconcile window is bucketed to
  `aged_out`, never dropped; late/duplicate/reordered events self-correct on the
  next reconcile cycle ([ADR-0003](../../adr/0003-convergent-reconciliation.md)).
- **No over-refund.** A refund that exceeds the refundable amount raises
  `CannotRefund`; refunds and chargebacks are separate registers, so one following
  the other is reconcilable rather than double-counted.
- **Exactly-once webhook application.** A verified webhook is deduped on its event
  id and guarded against an already-settled invoice, so a redelivery is a no-op.
- **Currency cannot silently change.** An account's currency is locked one-way by
  its first finalized invoice and guarded on every subsequent finalize.

## Tamper-evident, not tamper-proof

The ledger is **append-only** and its immutability is hardened at the database
layer (the `harden_billing_ledger_immutability` migration). Corrections are
reversing entries, never mutations, so the history is auditable and a change stands
out.

It is **not tamper-proof**. The library does not hash-chain entries, sign them, or
otherwise cryptographically prove that a privileged actor with direct database
access has not altered history. If your threat model includes a malicious operator
or a compromised database, that is an infrastructure and access-control problem the
library cannot solve — enforce it with database permissions, audit logging outside
the application, and separation of duties.

## App-layer vs library boundary

The library provides **contracts and correctness**; it does not provide the app
around them. The following are explicitly **your application's** responsibility,
and the library makes no claim about them:

- **Authentication and authorization.** Who may call `reserve`, issue an invoice,
  or grant credit is your app's concern. `org` on every call is an identifier the
  library trusts you to have authenticated (via cbox-id or your own auth).
- **Webhook payload authentication.** The library's `WebhookIngest` handles
  *exactly-once application*; authenticating the payload is the gateway
  `WebhookVerifier` adapter's job. The default `DenyingWebhookVerifier` fails
  closed until you bind a real one, but binding a correct verifier is on you.
- **Transport and at-rest encryption.** TLS, database encryption, and secret
  management are infrastructure concerns.
- **Rate limiting of the enforcement API itself**, input validation at your HTTP
  edge, and PII handling in the identifiers you pass in.
- **The deployed admin console and customer portal.** Those are the separate
  `cboxdk/cbox-billing` application, not this package.

## Reporting

Security-relevant correctness issues in the library (a way to double-post, a
double-spend, an over-refund, a broken idempotency key) belong on the package's
issue tracker. Do not report your own application's auth or infrastructure gaps
here — those are outside the library's boundary as described above.

## Related

- [Architecture & foundation contracts](../getting-started/architecture.md)
- [The ADRs](../../adr/)
