# cbox-billing â build status

Living status of `cboxdk/laravel-billing`. Architecture spec:
[`docs/foundation-contracts.md`](docs/foundation-contracts.md).

Gate (green on every commit): `pint --test` Â· `phpstan` level max Â· `pest` Â·
`composer audit` Â· `license-check` Â· `sbom`.

## Shipped

| Module | What | State |
| --- | --- | --- |
| **Metering / enforcement** | `Enforcement` (reserve/commit/release/balance), `LeasedEnforcement` (app-local, lease-backed hard limit), `CacheLocalStore` (Laravel atomic cache, no Lua), `AllowanceLeaseSource`, `UsageBuffer` + `UsageEvent`, `MeterIngest` contract. Dogfooded `InteractsWithMetering` + `FakeAllowanceLeaseSource`. | â contracts + in-memory/cache impls + tests |
| **Metering / event log** | `EventLog` contract = the immutable metering source of truth (append idempotent by event id Â· sum for invoice computation). **Storage is optional/pluggable**: `InMemoryEventLog` (default) Â· `DatabaseEventLog` (MySQL/Postgres/sqlite â plenty for small deployments) Â· a ClickHouse adapter drops in behind the same contract for event-heavy scale (**ClickHouse is optional, not required**). `DefaultMeterIngest` appends to the log; config `billing.metering.event_log = memory\|database`. | â in-memory + relational (dedup + windowed sum) + idempotent ingest tests |
| **Money** | `Money` VO wrapping brick/money (integer minor units, immutable). | â |
| **Ledger** | Double-entry `LedgerTransaction` (validates balance/currency), `LedgerLine`, `Direction`, `Ledger` contract, `InMemoryLedger` (append-only, derived balances). | â mechanics + tests |
| **Wallet / credits** | `CreditGrant` (denomination money-or-unit, type, expiry, priority), `CreditConsumer` (pure burn-down: denomination â expiry â priority â age), `ConsumptionPlan`. | â burn-down engine + tests |
| **Quote / consequence-preview** | `QuoteBuilder` composes **`cboxdk/laravel-tax`** (seller-of-record routing + per-line tax) + wallet credit â a confirmable `Quote` (lines Â· totals net/tax/gross/credit/dueNow Â· `TaxResolution`). **Progressive tax resolution**: an unresolved jurisdiction (e.g. US w/o a state) returns a *tax-pending* quote with an honest reason, never a wrong number. Money interop via `Money::toBrick()/fromBrick()`. | â builder + tests (resolved Â· credit Â· pending Â· reverse-charge) |
| **Catalog** | Stripe-style `Product`/`Price` split; versioned prices with effective-date ranges â **grandfathering** (a subscriber pins the price effective at their start date; new sales get the current version). `PricingModel` (flat Â· per-unit); `Catalog` contract + `InMemoryCatalog` (newest-effective-version resolution). Feeds pinned prices into the Quote. | â resolution + grandfathering + catalogâquote tests |
| **Seller (of record)** | `SellerEntity` (legal identity + tax registrations + invoice prefix) â produces the tax engine's seller-registrations; `EntityRouter` + `DefaultEntityRouter` routes a buyer to the entity registered in their country (domestic) else a default (cross-border) â the multi-entity routing that drives tax. | â routing + entityâregistrations tests |
| **Invoice** | `Invoicer` fixes a confirmed `Quote` to a legal number from the entity's own `InvoiceNumberSequence` (per-entity, monotonic, gapless) â `Invoice`. Refuses a tax-pending quote (`CannotInvoicePendingQuote`). | â per-entity numbering + confirmâinvoice + refuse-pending + end-to-end |
| **Subscription** | `BillingPeriod` + `ProrationCalculator` + `PlanChangePreviewer` (the upgrade/downgrade consequence-preview). Lifecycle: `Subscription` + `SubscriptionManager` (create Â· cancelAtPeriodEnd Â· resume Â· scheduleChange [mutable] Â· renew â enacts cancellation / applies scheduled price change). | â proration + upgrade/downgrade + full lifecycle tests |
| **Payment** | Gateway-agnostic `PaymentGateway` (charge `PaymentIntent` â `PaymentResult`) + dependency-free `ManualPaymentGateway` default; `DunningPolicy` retry schedule. Stripe/Mollie = opt-in adapter packages. | â manual + fake + dunning tests |
| **Pricing** | `Coupon` (percentage/fixed, validity) + `CouponApplier` â discounts the net before tax; out-of-window = no-op, fixed floored at zero. | â percentage + fixed + window + discount-before-tax tests |
| **Entitlement** | `EntitlementProjector` maps a subscription â coarse tier via the `EntitlementWriter` port (decoupled from cbox-id; Null default). **Scoped per (org, product)** â an org holds many concurrent product entitlements; set upserts one product, revoke removes only that sourceRef. | â per-product scoping + revoke-isolation tests |
| **Reporting** | `MrrCalculator` (MRR + ARR per currency) Â· `ChurnCalculator`. | â MRR/ARR + churn tests |
| **Ledger (durable)** | `DatabaseLedger` â immutable append-only rows (migration), balances derived by summing, **atomic + idempotent** posting (re-post is a no-op). Same `Ledger` contract as the in-memory one. | â post + derive + idempotency + accumulation tests |
| **Ledger (two-phase)** | `TwoPhaseLedger` â reserve / commit / release; a reservation is a pending transfer that lowers `available` immediately but not the posted `balance` (so concurrent commits can't exceed available). `InMemoryTwoPhaseLedger`. | ✅ reserve/commit/release + duplicate/not-pending tests |

Tests: 52 Â· assertions: 148.

> **Dependencies:** the Quote module composes `cboxdk/laravel-tax` (`^0.1`) and
> `cboxdk/laravel-geo` (`^0.4`), both from Packagist.

## Next (per the foundation build order)

1. **Persistence** â `DatabaseLedger` (Eloquent, immutable rows) + two-phase
   pending transfers; a durable `Wallet` store that applies a `ConsumptionPlan`
   (decrement grants + post monetary drawdowns to the ledger).
2. **Ingest side** â dedup (id+source, bounded window) â immutable event log
   (ClickHouse) so the enforcement loop connects end-to-end; ledger projection.
3. **Catalog** â `Product`/`Price` (Stripe model) with effective-date versioning
   + price-pinning/grandfathering; plans, addons, allowances.
4. **Subscriptions** â lifecycle, proration policies, scheduled/mutable changes.
5. **Payments** â `PaymentGateway` contract + Stripe + Mollie adapters + dunning.
6. **Pricing-ops** â coupons, scheduled increases, overrides/contracts, quotes.
7. **`EntitlementProjector`** â cbox-id (coarse tier only; fine/usage stays on the
   app hot path).
8. **`laravel-billing-client` SDK** â the app-side hot path + event sync + the
   production `AllowanceLeaseSource`.
9. **App** â `cboxdk/cbox-billing` (admin + portal), OIDC client of cbox-id.

## Open design spikes

See foundation-contracts Â§10: allowance-leasing drift bounds (soak test),
reconciliation/backfill from the event log, the EU-VAT-vs-integrate tax boundary,
and proving the identity-native entitlement wedge.
