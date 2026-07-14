# Cbox Billing

A self-hostable, gateway-agnostic **monetization engine** for Laravel — the
billing peer to [`cboxdk/laravel-id`](https://github.com/cboxdk/laravel-id).
Catalog, subscriptions, **real-time usage metering with hard limits**, a
double-entry ledger, invoicing, and pricing operations. Drop it into any Laravel
app; the deployable console + portal is the separate `cboxdk/cbox-billing` app.

Positioning: a **self-hostable Chargebee** — Chargebee's feature scope, Lago's
delivery model (OSS, self-host or hosted), Metronome's real-time metering, and
native `cbox-id` identity + entitlements.

> **Status: early / pre-1.0.** The architecture is settled — see
> [`docs/foundation-contracts.md`](docs/foundation-contracts.md), the coherence
> layer every module builds against. The first module shipped is the metering
> **enforcement hot path** (below); catalog, ledger, invoicing, pricing and the
> gateways follow the build order in the foundation doc.

## The three-layer model (why it's correct)

Real-time enforcement, metering truth, and money are **three separate concerns**
(the pattern Orb/OpenMeter use — *"the invoice is computed, not retrieved"*):

1. **Enforcement** — an app-local counter (Laravel cache, atomic increment/
   decrement, no custom Lua) answering *"may this request proceed?"* in sub-ms.
   Disposable; rebuildable from the event log.
2. **Metering truth** — an immutable, append-only usage event log. Invoices are
   recomputed from it with the pinned price, never read from counters.
3. **Money** — a double-entry ledger; balances derived from immutable postings.

Hard limits are enforced **locally per node** against a **leased slice** of the
org's allowance (pessimistic leasing → no cross-node overspend, only a small,
bounded, backfillable drift) — **no shared/co-located Redis** required.

## Metering enforcement — shipped

```php
use Cbox\Billing\Metering\Contracts\Enforcement;

$reservation = $enforcement->reserve($org, 'api.calls', estimate: 5); // hard-blocks when exhausted
// … do the work …
$enforcement->commit($reservation, actual: 5);                        // settles + emits a durable usage event
```

- Lease-backed local hard limit (`LeasedEnforcement`), refilling from an
  `AllowanceLeaseSource` when depleted.
- `CacheLocalStore` — Laravel-cache-backed, atomic decrement-and-compensate (only
  ever over-rejects, never over-grants).
- Durable local `UsageBuffer` for crash-safe sync to billing's ingest.
- Dogfooded `Testing\InteractsWithMetering` + `FakeAllowanceLeaseSource`.

## Requirements

PHP `^8.4`; Laravel `^12 || ^13`. See `composer.json`.

## Development

```bash
composer install
composer qa    # pint --test, phpstan (level max), pest, license-check, audit
```

## License

MIT.
