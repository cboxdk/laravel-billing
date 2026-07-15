# cbox-billing — build status

Living status of `cboxdk/laravel-billing`. Architecture spec:
[`docs/foundation-contracts.md`](docs/foundation-contracts.md).

Gate (green on every commit): `pint --test` · `phpstan` level max · `pest` ·
`composer audit` · `license-check` · `sbom`.

## Shipped

| Module | What | State |
| --- | --- | --- |
| **Metering / enforcement** | `Enforcement` (reserve/commit/release/balance), `LeasedEnforcement` (app-local, lease-backed hard limit), `CacheLocalStore` (Laravel atomic cache, no Lua), `AllowanceLeaseSource`, `UsageBuffer` + `UsageEvent`, `MeterIngest` contract. Dogfooded `InteractsWithMetering` + `FakeAllowanceLeaseSource`. | ✅ |
| **Metering / multi-dimensional (ADR-0005)** | Independent per-`(org, meter)` buckets, each carrying its own `MeterPolicy` (enabled · isolated allowance · nullable weight · `unlimited` · `OverageBehaviour`) — evaluated independently, never collapsed. Entitlement `enabled?` checked FIRST; `unlimited` zeroes cost explicitly (no phantom `?? 1.0`); allowances isolated; atomic disjoint-slice claim (`LocalStore::claimAllowance`) exempts included units exactly once under concurrency. `Enforcement::reserveBuckets/commitBuckets/releaseBuckets` reserve a SET (total cost = Σ per-bucket weighted usage); single-meter path is the set-of-one. Policy resolved through the Entitlement module (`MeterPolicyResolver` ← `EntitlementMeterPolicyResolver`, deny-by-default). Dogfooded `FakeMeterPolicyResolver`. | ✅ |
| **Metering / event log** | `EventLog` contract = the immutable metering source of truth (append idempotent by event id · sum for invoice computation). **Storage optional/pluggable**: `InMemoryEventLog` (default) · `DatabaseEventLog` (MySQL/Postgres/sqlite — plenty for small deployments) · a ClickHouse adapter drops in behind the same contract for event-heavy scale (**ClickHouse optional, not required**). `DefaultMeterIngest` appends to the log; config `billing.metering.event_log = memory\|database`. | ✅ |
| **Money** | `Money` VO wrapping brick/money (integer minor units, immutable) + `toBrick`/`fromBrick`/`multipliedBy`/`proratedBy`. | ✅ |
| **Ledger** | Double-entry `LedgerTransaction` (validates balance/currency), `Ledger` contract, `InMemoryLedger` (derived balances). | ✅ |
| **Ledger (durable)** | `DatabaseLedger` — immutable append-only rows (migration), balances derived by summing, atomic + idempotent posting (re-post is a no-op). | ✅ |
| **Ledger (two-phase)** | `TwoPhaseLedger` — reserve / commit / release; a reservation is a pending transfer that lowers `available` immediately but not the posted `balance`. `InMemoryTwoPhaseLedger`. | ✅ |
| **Wallet / credits** | `CreditGrant` + `CreditConsumer` (pure burn-down: denomination → expiry → priority → age), `ConsumptionPlan`. | ✅ |
| **Quote / consequence-preview** | `QuoteBuilder` composes **`cboxdk/laravel-tax`** (seller-of-record routing + per-line tax) + wallet credit → a confirmable `Quote` (net/tax/gross/credit/dueNow · `TaxResolution`). Progressive tax resolution: an unresolved jurisdiction returns a *tax-pending* quote — never a wrong number. | ✅ |
| **Catalog** | Stripe-style `Product`/`Price` split; effective-date price versioning → **grandfathering**. `PricingModel` (flat · per-unit); `Catalog` + `InMemoryCatalog`. | ✅ |
| **Seller (of record)** | `SellerEntity` → tax seller-registrations; `EntityRouter`/`DefaultEntityRouter` routes a buyer to the entity registered in their country else a default — the multi-entity routing that drives tax. | ✅ |
| **Invoice** | `Invoicer` fixes a confirmed `Quote` to a per-entity legal number (`InvoiceNumberSequence`, monotonic/gapless) → `Invoice`. Refuses a tax-pending quote. | ✅ |
| **Subscription** | `BillingPeriod` + `ProrationCalculator` + `PlanChangePreviewer` (upgrade/downgrade consequence-preview). Lifecycle: `Subscription` + `SubscriptionManager` (create · cancelAtPeriodEnd · resume · scheduleChange [mutable] · renew). | ✅ |
| **Payment** | Gateway-agnostic `PaymentGateway` + dependency-free `ManualPaymentGateway`; `DunningPolicy`. Stripe/Mollie = opt-in adapter packages. | ✅ |
| **Pricing** | `Coupon` (percentage/fixed, validity) + `CouponApplier` — discounts the net before tax. | ✅ |
| **Entitlement** | `EntitlementProjector` → coarse tier via the `EntitlementWriter` port (decoupled from cbox-id). Scoped **per (org, product)** — an org holds many concurrent product entitlements. | ✅ |
| **Reporting** | `MrrCalculator` (MRR + ARR per currency) · `ChurnCalculator`. | ✅ |
| **Reconciliation (ADR-0003)** | Convergent reconciliation: per-`(org, meter)` cumulative-delta-vs-checkpoint (no per-event replay). `Reconciler`/`DefaultReconciler` post `sum(events) − checkpoint.total` into the `Ledger` idempotently (natural `PostingKey`, ADR-0002). Guards: **ingest-lag clamp** (`ceiling = now − lag`), **aged-out bucketing** (usage older than the window → `aged_out` account, never dropped), **per-entity locked checkpoint** with concurrency errors **rethrown** and other per-entity errors reported+skipped. `CheckpointStore` (contract · `InMemoryCheckpointStore` default · durable `DatabaseCheckpointStore` migration · `FakeCheckpointStore`). `billing:reconcile` command. Dogfooded `InteractsWithReconciliation`. | ✅ |

Tests: 145 · assertions: 455.

> **Dependencies:** composes `cboxdk/laravel-tax` (`^0.1`) and `cboxdk/laravel-geo`
> (`^0.4`) from Packagist. Gateway adapters (`laravel-billing-stripe`,
> `-mollie`) are separate opt-in packages.

## Remaining

- Optional **ClickHouse `EventLog` adapter** package (`cboxdk/laravel-billing-clickhouse`) for event-heavy scale.
- **`laravel-billing-client` SDK** — the app-side hot path + event sync + production `AllowanceLeaseSource`.
- The deployable **`cboxdk/cbox-billing` app** (admin + portal, OIDC client of cbox-id).
- Public + Packagist for `laravel-billing` and the gateway adapters.
