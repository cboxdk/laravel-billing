# Cbox Billing

**`cboxdk/laravel-billing`** — the billing engine for Laravel: a gateway-agnostic
library of billing primitives (catalog, subscriptions, **real-time usage metering
with hard limits**, a double-entry ledger, wallets & credits, invoicing, pricing)
you compose into a billing product. The framework peer to
[`cboxdk/laravel-id`](https://github.com/cboxdk/laravel-id) — UI-free and
domain-free: every capability sits behind a contract you bind, mock or replace.

> This is the **package**, not the product. The deployable, self-hostable
> billing app built on it — with an admin console and customer portal — is the
> separate **`cboxdk/cbox-billing`** application (exactly as `cbox-id` is the app
> built on `laravel-id`). Reach for the app if you don't want to build the
> UI/hosting layer yourself; reach for this package to embed billing in your own
> Laravel app.

> **Status: early / pre-1.0.** The architecture is settled — see
> [`docs/foundation-contracts.md`](docs/foundation-contracts.md), the coherence
> layer every module builds against. The first module shipped is the metering
> **enforcement hot path** (below); catalog, ledger, invoicing, pricing and the
> gateways follow the build order in the foundation doc.

## The three-layer model (why it's correct)

Real-time enforcement, metering truth, and money are **three separate concerns**
— the invoice is **computed from the immutable event log**, never read from a
counter:

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
