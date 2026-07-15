---
title: Entitlements
description: Coarse tier projection to cbox-id, missing-entitlement outage audit, and event-suppressed bulk rollout that does not storm the cache.
weight: 26
---

# Entitlements

Entitlement is the seam to identity. ID enforces *who you are and your coarse
tier*; billing enforces *spend and quota* on the hot path. The division:

- **Coarse, stable entitlements** (plan tier, big feature flags) are pushed to
  cbox-id **sparingly** as token-claim material for cheap edge gating.
- **Fine-grained / usage / quota** entitlements are **not** synced into ID tokens
  (bloat + staleness). They live in billing and are checked app-side via the
  [`Enforcement`](metering.md) API.

## Projection

`EntitlementProjector` computes an org's coarse tier from a subscription and
pushes it through the `EntitlementWriter` **port** — decoupled from cbox-id, so the
library never hard-depends on the identity package:

```php
interface EntitlementProjector
{
    public function project(Subscription $subscription, string $tier, array $features = []): void;
}
```

`NullEntitlementWriter` is the no-op default; the real writer is bound by the host
app (cbox-id ships the receiving `EntitlementWriter` contract). `project` is scoped
**per (org, product)** — an org holds many concurrent product entitlements.
`EntitlementMeterPolicyResolver` is the same module serving the metering hot path
its per-`(org, meter)` [`MeterPolicy`](metering.md), deny-by-default.

## Missing-entitlement outage audit

A missing entitlement row is a semantic denial (fail closed) — but a *cohort* of
missing rows is an outage that should be visible, not silently denied one request
at a time. `EntitlementAudit` sweeps expected-vs-actual entitlements and reports
the gaps:

```php
interface EntitlementAudit
{
    public function audit(iterable $targets): AuditReport;
}
```

`DefaultEntitlementAudit` compares an `ExpectedEntitlements` source against what is
projected, classifies each `AuditFinding` by `EntitlementOutageKind`, and emits
through `EntitlementAuditSignals` so operators get a signal. Run it with
`php artisan billing:entitlements:audit`.

## Event-suppressed bulk rollout

When a plan-wide entitlement change rolls out to a large cohort, firing a
per-org cache-bust would storm the cache. So `EntitlementRollout` splits the cohort
by `RolloutPath`:

- **Orgs without an override** are applied in **chunks**, each chunk one atomic
  transaction, with **no per-org cache-bust** — invalidation rides the hot-path
  cache TTL. A 100k-org plan does not storm the cache.
- **Orgs with an override** bypass the bulk path: written individually and
  cache-busted immediately, because their state genuinely diverges.

```php
interface EntitlementRollout
{
    public function apply(PlanEntitlementChange $change, iterable $cohort): RolloutReport;
}
```

`chunk_size` (`entitlement.rollout.chunk_size`) tunes rows-per-transaction. Every
application is recorded to a `RolloutJournal` (`DatabaseRolloutJournal`, migration
`billing_entitlement_rollouts`) as `RolloutAuditRow`s for audit.

## Testing

`InteractsWithEntitlementAudit`, `InteractsWithEntitlementRollout`,
`FakeEntitlementWriter`, `FakeRolloutJournal`, and the recording signal/invalidator
fakes drive both paths.

## Related

- [Cookbook: roll out a plan entitlement](../cookbook/roll-out-a-plan-entitlement.md)
- [Metering & enforcement](metering.md)
