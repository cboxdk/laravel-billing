---
title: Roll out a plan entitlement
description: Apply a plan-wide entitlement change to a large cohort without storming the cache — chunked, event-suppressed, journaled.
weight: 48
---

# Roll out a plan entitlement

When a plan-wide entitlement change lands, applying it org-by-org with a per-org
cache-bust would storm the cache on a large plan. `EntitlementRollout` splits the
cohort so the common case is cheap.

## Apply the change

```php
use Cbox\Billing\Entitlement\Rollout\Contracts\EntitlementRollout;
use Cbox\Billing\Entitlement\Rollout\ValueObjects\PlanEntitlementChange;

$change = new PlanEntitlementChange(
    id:    'chg-2026-07',
    plan:  'pro',
    grants: $newGrants, // the entitlement grants the plan now confers
);

$report = $rollout->apply($change, cohort: $orgIds);

$report; // RolloutReport: how many applied via the bulk path vs individually
```

Under the hood:

- **Orgs without an override** are written in **chunks** (`entitlement.rollout.chunk_size`
  per transaction), each chunk one atomic transaction, with **no per-org
  cache-bust** — invalidation rides the hot-path cache TTL. A 100k-org plan does
  not storm the cache.
- **Orgs with an override** bypass the bulk path: written individually and
  cache-busted immediately, because their state genuinely diverges from the plan
  default.

Every application is recorded to the `RolloutJournal` (durable
`DatabaseRolloutJournal`) as audit rows.

## Audit for missing entitlements

Separately, sweep for orgs that *should* have an entitlement but don't — a cohort
of gaps is an outage worth surfacing rather than denying one request at a time:

```bash
php artisan billing:entitlements:audit
```

## Related

- [Entitlements](../core-concepts/entitlements.md)
- [Configuration reference](../configuration/reference.md)
