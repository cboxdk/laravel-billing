---
title: Meter multiple dimensions
description: Reserve and commit a set of weighted buckets in one request, with isolated allowances and a disabled dimension blocked before cost.
weight: 42
---

# Meter multiple dimensions

One request can consume several metered dimensions at once — a base operation plus
per-result appends plus a separately weighted operation. Reserve them as a **set**
so each bucket is evaluated independently (see
[ADR-0005](../../adr/0005-multi-dimensional-metering.md)).

## Reserve a bucket set

```php
use Cbox\Billing\Metering\Contracts\Enforcement;
use Cbox\Billing\Metering\ValueObjects\BucketRequest;

$set = $enforcement->reserveBuckets($org, [
    new BucketRequest('search.query',   estimate: 1),
    new BucketRequest('search.results', estimate: 20),
    new BucketRequest('rerank.ops',      estimate: 20),
]);

// … do the work …

$enforcement->commitBuckets($set, actuals: [
    'search.query'   => 1,
    'search.results' => 18,
    'rerank.ops'     => 18,
]);
```

Each `(org, meter)` bucket carries its own [`MeterPolicy`](../core-concepts/metering.md):
its `enabled?` entitlement is checked **first** (a disabled dimension is refused
before any allowance/cost math), its allowance is isolated (never funded from
another meter's pool), and its `multiplier` weights the cost. Total cost is the sum
of the per-bucket weighted usage — buckets are never collapsed into one number.

## Outcome form

```php
$outcome = $enforcement->reserveBucketsOutcome($org, $requests);

if ($outcome->refused()) {
    abort(429);
}

$enforcement->commitBuckets($outcome->reservationSet(), $actuals);
```

## Defining a policy

Policies come from the [entitlement](../core-concepts/entitlements.md) resolver, but
the value object shows the shape:

```php
use Cbox\Billing\Metering\ValueObjects\MeterPolicy;
use Cbox\Billing\Metering\Enums\OverageBehaviour;

MeterPolicy::metered(allowance: 10_000, multiplier: 1.0, overage: OverageBehaviour::Bill);
MeterPolicy::unlimited(); // cost explicitly zero — no phantom 1.0 multiplier
MeterPolicy::disabled();  // refused before cost
```

## Related

- [Metering & enforcement](../core-concepts/metering.md)
- [Entitlements](../core-concepts/entitlements.md)
