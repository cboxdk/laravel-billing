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
| **Quote / consequence-preview** | `QuoteBuilder` composes **`cboxdk/laravel-tax`** (seller-of-record routing + per-line tax) + wallet credit → a confirmable `Quote` (lines · totals net/tax/gross/credit/dueNow · `TaxResolution`). **Progressive tax resolution**: an unresolved jurisdiction (e.g. US w/o a state) returns a *tax-pending* quote with an honest reason, never a wrong number. Money interop via `Money::toBrick()/fromBrick()`. | ✅ builder + tests (resolved · credit · pending · reverse-charge) |
| **Catalog** | Stripe-style `Product`/`Price` split; versioned prices with effective-date ranges → **grandfathering** (a subscriber pins the price effective at their start date; new sales get the current version). `PricingModel` (flat · per-unit); `Catalog` contract + `InMemoryCatalog` (newest-effective-version resolution). Feeds pinned prices into the Quote. | ✅ resolution + grandfathering + catalog→quote tests |
| **Seller (of record)** | `SellerEntity` (legal identity + tax registrations + invoice prefix) → produces the tax engine's seller-registrations; `EntityRouter` + `DefaultEntityRouter` routes a buyer to the entity registered in their country (domestic) else a default (cross-border) — the multi-entity routing that drives tax. | ✅ routing + entity→registrations tests |
| **Invoice** | `Invoicer` fixes a confirmed `Quote` to a legal number from the entity's own `InvoiceNumberSequence` (per-entity, monotonic, gapless) → `Invoice`. Refuses a tax-pending quote (`CannotInvoicePendingQuote`). | ✅ per-entity numbering + confirm→invoice + refuse-pending + end-to-end |
| **Subscription** | `BillingPeriod` + `ProrationCalculator` + `PlanChangePreviewer` (the upgrade/downgrade consequence-preview). Lifecycle: `Subscription` + `SubscriptionManager` (create · cancelAtPeriodEnd · resume · scheduleChange [mutable] · renew → enacts cancellation / applies scheduled price change). | ✅ proration + upgrade/downgrade + full lifecycle tests |
| **Payment** | Gateway-agnostic `PaymentGateway` (charge `PaymentIntent` → `PaymentResult`) + dependency-free `ManualPaymentGateway` default; `DunningPolicy` retry schedule. Stripe/Mollie = opt-in adapter packages. | ✅ manual + fake + dunning tests |
| **Pricing** | `Coupon` (percentage/fixed, validity) + `CouponApplier` — discounts the net before tax; out-of-window = no-op, fixed floored at zero. | ✅ percentage + fixed + window + discount-before-tax tests |
| **Entitlement** | `EntitlementProjector` maps a subscription → coarse tier via the `EntitlementWriter` port (decoupled from cbox-id; Null default). **Scoped per (org, product)** — an org holds many concurrent product entitlements; set upserts one product, revoke removes only that sourceRef. | ✅ per-product scoping + revoke-isolation tests |
| **Reporting** | `MrrCalculator` (MRR + ARR per currency) · `ChurnCalculator`. | ✅ MRR/ARR + churn tests |
| **Ledger (durable)** | `DatabaseLedger` — immutable append-only rows (migration), balances derived by summing, **atomic + idempotent** posting (re-post is a no-op). Same `Ledger` contract as the in-memory one. | ✅ post + derive + idempotency + accumulation tests |

Tests: 52 · assertions: 148.

> **Dependencies:** the Quote module composes `cboxdk/laravel-tax` (`^0.1`) and
> `cboxdk/laravel-geo` (`^0.4`), both from Packagist.

## Next (per the foundation build order)

1. **Persistence** — `DatabaseLedger` (Eloquent, immutable rows) + two-phase
   pending transfers; a durable `Wallet` store that applies a `ConsumptionPlan`
   (decrement grants + post monetary drawdowns to the ledger).
2. **Ingest side** — dedup (id+source, bounded window) → immutable event log
   (ClickHouse) so the enforcement loop connects end-to-end; ledger projection.
3. **Catalog** — `Product`/`Price` (Stripe model) with effective-date versioning
   + price-pinning/grandfathering; plans, addons, allowances.
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
