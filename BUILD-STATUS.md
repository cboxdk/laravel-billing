# cbox-billing — build status

Living status of `cboxdk/laravel-billing`. Architecture spec:
[`docs/foundation-contracts.md`](docs/foundation-contracts.md).

Gate (green on every commit): `pint --test` · `phpstan` level max · `pest` ·
`composer audit` · `license-check` · `sbom`.

## Shipped

| Module | What | State |
| --- | --- | --- |
| **Metering / enforcement** | `Enforcement` (reserve/commit/release/balance), `LeasedEnforcement` (app-local, lease-backed hard limit), `CacheLocalStore` (Laravel atomic cache, no Lua), `AllowanceLeaseSource`, `UsageBuffer` + `UsageEvent`, `MeterIngest` contract. Dogfooded `InteractsWithMetering` + `FakeAllowanceLeaseSource`. | ✅ contracts + in-memory/cache impls + tests |
| **Money** | `Money` VO wrapping brick/money (integer minor units, immutable). | ✅ |
| **Ledger** | Double-entry `LedgerTransaction` (validates balance/currency), `LedgerLine`, `Direction`, `Ledger` contract, `InMemoryLedger` (append-only, derived balances). | ✅ mechanics + tests |
| **Wallet / credits** | `CreditGrant` (denomination money-or-unit, type, expiry, priority), `CreditConsumer` (pure burn-down: denomination → expiry → priority → age), `ConsumptionPlan`. | ✅ burn-down engine + tests |

Tests: 12 · assertions: 35.

## Next (per the foundation build order)

1. **Persistence** — `DatabaseLedger` (Eloquent, immutable rows) + two-phase
   pending transfers; a durable `Wallet` store that applies a `ConsumptionPlan`
   (decrement grants + post monetary drawdowns to the ledger).
2. **Ingest side** — dedup (id+source, bounded window) → immutable event log
   (ClickHouse) so the enforcement loop connects end-to-end; ledger projection.
3. **Catalog** — `Product`/`Price` (Stripe model) with effective-date versioning
   + price-pinning/grandfathering (Kill Bill model); plans, addons, allowances.
4. **Subscriptions** — lifecycle, proration policies, scheduled/mutable changes.
5. **Payments** — `PaymentGateway` contract + Stripe + Mollie adapters + dunning.
6. **Pricing-ops** — coupons, scheduled increases, overrides/contracts, quotes.
7. **`EntitlementProjector`** → cbox-id (coarse tier only; fine/usage stays on the
   app hot path).
8. **`laravel-billing-client` SDK** — the app-side hot path + event sync + the
   production `AllowanceLeaseSource`.
9. **App** — `cboxdk/cbox-billing` (admin + portal), OIDC client of cbox-id.

## Open design spikes

See foundation-contracts §10: allowance-leasing drift bounds (soak test),
reconciliation/backfill from the event log, the EU-VAT-vs-integrate tax boundary,
and proving the identity-native entitlement wedge.
