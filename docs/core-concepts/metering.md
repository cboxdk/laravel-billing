---
title: Metering & enforcement
description: Real-time hard limits against a leased allowance, multi-dimensional buckets with isolated allowances, the three-way enforcement outcome, and the immutable event log.
weight: 21
---

# Metering & enforcement

Metering is layer 1 (enforcement) and layer 2 (metering truth) of the
[three-layer model](../getting-started/architecture.md). It answers *"may this
request proceed?"* in sub-millisecond time and records *what actually happened*
to an immutable log — without ever letting the fast counter become the source of
truth for money.

## The hot path: `reserve` / `commit` / `release`

`Cbox\Billing\Metering\Contracts\Enforcement` is the SDK-facing API:

```php
interface Enforcement
{
    public function reserve(string $org, string $meter, int $estimate): Reservation;
    public function commit(Reservation $reservation, int $actual): void;
    public function release(Reservation $reservation): void;
    public function balance(string $org, string $meter): int;

    // multi-dimensional (a set of buckets)
    public function reserveBuckets(string $org, array $requests): ReservationSet;
    public function commitBuckets(ReservationSet $set, array $actuals): void;
    public function releaseBuckets(ReservationSet $set): void;

    // outcome-returning variants (no exception on denial)
    public function reserveOutcome(string $org, string $meter, int $estimate): EnforcementOutcome;
    public function reserveBucketsOutcome(string $org, array $requests): EnforcementOutcome;
}
```

`reserve` atomically holds `estimate` units. `commit` settles to the actual amount
(`actual <= estimate`), releases the difference, and appends the durable usage
event. `release` returns the whole hold on an error path (a TTL also releases it).
`balance` reflects the local lease and may lag billing by one drift window — it is
for UX and pre-checks, never for accounting.

`LeasedEnforcement` is the shipped implementation. It enforces against a node-local
slice of the org's allowance and refills from an `AllowanceLeaseSource` when
depleted, so hard limits work with **no shared or co-located Redis**. Worst-case
overshoot before reconciliation is bounded by `lease_size × node_count`, not the
whole allowance.

### The app-local store

`CacheLocalStore` is a Laravel-cache-backed `LocalStore`: an atomic
decrement-and-compensate that only ever *over-rejects*, never over-grants. No
custom Lua. `UsageBuffer` (default `ArrayUsageBuffer`) is a durable local buffer
that appends each event before sync so a crash loses at most the un-synced window.

### Derived, never a loose scalar (ADR-0008)

The hot-path balance is **derived**:

```
available = ledger_balance − unflushed_usage − active_reservations
```

Grants and debits move the ledger; the derived balance follows. This makes the
classic double-spend bug — re-setting a cached balance to the ledger sum and
wiping in-flight spend — unreachable by construction. Period claim-counters expire
only at the period boundary via TTL; clearing one mid-period would re-grant the
included allowance to everyone. See
[ADR-0008](../../adr/0008-derived-hot-path-balance.md).

## Multi-dimensional buckets (ADR-0005)

Usage is rarely one scalar. A single request can consume several **metered
dimensions** at once, each with its own price weight, and some carry **isolated
allowances** that must not be funded from a shared pool. Each `(org, meter)` is an
independent bucket carrying its own `MeterPolicy`:

```php
readonly class MeterPolicy
{
    public function __construct(
        public bool $enabled,
        public int $allowance = 0,
        public ?float $multiplier = null,
        public bool $unlimited = false,
        public OverageBehaviour $overage = OverageBehaviour::Block,
        public Aggregation $aggregation = Aggregation::Sum,
    ) {}
}
```

Evaluation order per bucket:

1. **Entitlement (`enabled?`) is checked first.** A disabled feature is refused
   *before* any allowance or cost math — otherwise an under-allowance call to a
   disabled feature would compute zero overage and run for free.
2. **Allowance** — included units per period. Isolated allowances are excluded
   from any shared basis and never draw another meter's pool.
3. **Weight / multiplier** — cost per unit. `unlimited` zeroes cost *explicitly*;
   a `null` multiplier does **not** fall back to `1.0` (no phantom cost).
4. **Overage behaviour** — `Block` or `Bill`, per bucket.

Buckets are evaluated independently and **never collapsed** into one number; total
cost is the sum of per-bucket weighted usage. Included units are claimed with an
**atomic disjoint-slice claim** (`LocalStore::claimAllowance`) so each included
unit is exempted exactly once under concurrency. Single-meter `reserve` is just
the set-of-one. See [ADR-0005](../../adr/0005-multi-dimensional-metering.md).

Policies are resolved through the Entitlement module via `MeterPolicyResolver`
(`EntitlementMeterPolicyResolver`), deny-by-default: an unresolved policy denies.

## The three-way outcome (ADR-0004)

Deny-by-default is right for *semantics* but wrong for *infrastructure*: if a
cache blip makes the enforcer throw and you deny, a dependency hiccup becomes a
customer-facing outage. So enforcement is split by cause:

- `Allowed` / `Denied(reason)` — a real decision was reached. Semantic unknowns
  (unrecognized or `null` overage behaviour, disabled feature, missing
  entitlement) always resolve to **`Denied`** — fail *closed*.
- `Indeterminate(infra)` — a dependency was unavailable. The default policy is
  **allow and report** (fail *open*), configurable to `deny` for strict tenants
  via `metering.enforcement.infra_failure`. The durable ledger remains the
  authority and reconciliation recovers the truth.

`EnforcementOutcome` surfaces this so callers and telemetry see which path fired:

```php
$outcome = $enforcement->reserveOutcome($org, 'api.calls', estimate: 5);

$outcome->admitted();  // Allowed, or Indeterminate resolved to Allow
$outcome->refused();   // otherwise
$outcome->failedOpen(); // true only when infra failure was allowed through
```

See [ADR-0004](../../adr/0004-enforcement-failure-policy.md).

## The event log (metering truth)

`EventLog` is the immutable, append-only source of truth: append is idempotent by
event id, and sum drives invoice computation. Storage is pluggable:

- `InMemoryEventLog` — default, zero config.
- `DatabaseEventLog` — MySQL / Postgres / sqlite, plenty for most deployments.
- A ClickHouse adapter can bind the same contract for event-heavy scale
  (optional, not required).

`DefaultMeterIngest` appends to the log; `billing.metering.event_log` selects the
adapter. Dedup keys are kept for `metering.dedup_window_days`; late duplicates
outside the window are caught by [reconciliation](reconciliation.md), not
double-counted.

## Billable-metric aggregations

A meter's raw events collapse into ONE billable quantity for a period via an
`Aggregation`, resolved by `EventLog::aggregate($org, $meter, $fromMs, $toMs, $agg)`:

| Aggregation | Billable quantity |
| --- | --- |
| `Count` | number of events |
| `Sum` | sum of every event's `value` (the classic usage total) |
| `Max` | the largest single `value` (e.g. peak seats) |
| `UniqueCount` | number of **distinct** `uniqueKey`s (e.g. unique active users) |
| `Latest` | the `value` of the most recent event — a gauge's last reading |
| `WeightedSum` | sum of `value × weight` (a cost-weighted total) |

`UsageEvent` carries the fields these read: `value` (the measurement), plus the
optional `uniqueKey` (counted distinctly by `UniqueCount`; a null key contributes no
distinct value) and `weight` (the `WeightedSum` multiplier, default `1`). Both
optional fields are trailing and defaulted, so existing ingest is unchanged and
`WeightedSum` with no weights equals a plain `Sum`. `sum()` is retained as the
shorthand for `aggregate(Sum)` and both `InMemoryEventLog` and `DatabaseEventLog`
(the latter computing every aggregation **in the database** — `count`, `sum(value)`,
`max(value)`, `count(distinct unique_key)`, latest-by-timestamp, `sum(value*weight)`)
implement it. An empty window yields `0`, `Max`/`Latest` included.

The aggregation choice lives on the meter's `MeterPolicy` (`aggregation`, defaulting
to `Sum`). `BillableUsageResolver` composes the two halves — aggregate a period's
usage per the policy, then price the resulting quantity through a `Price` (flat,
per-unit, or a [tiered model](catalog-and-pricing.md#pricing-models)):

```php
$resolver = new BillableUsageResolver($eventLog);

$quantity = $resolver->quantity($org, 'seats', $from, $to, $policy);       // events → aggregate
$charge   = $resolver->charge($org, 'seats', $from, $to, $policy, $price);  // → tiered price → Money
```

This is the usage-events → aggregate → tiered-price → Money pipeline, each side
swappable behind its contract.

## Testing

`Cbox\Billing\Metering\Testing\InteractsWithMetering` plus
`FakeAllowanceLeaseSource`, `FakeMeterPolicyResolver`, `OutageLocalStore` (to
drive the infra-failure path), and `RecordingEnforcementSignals`. See
[testing](../extension-points/testing.md).

## Related

- [Cookbook: enforce a hard limit](../cookbook/enforce-a-hard-limit.md)
- [Cookbook: meter multiple dimensions](../cookbook/meter-multiple-dimensions.md)
- [Reconciliation](reconciliation.md)
