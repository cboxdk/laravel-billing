# cbox-billing — build status

Living status of `cboxdk/laravel-billing`. Architecture spec:
[`docs/getting-started/architecture.md`](docs/getting-started/architecture.md).

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
| **Wallet / credits** | `CreditGrant` + `CreditConsumer` (pure burn-down: denomination → expiry → priority → age), `ConsumptionPlan`. Pool-attributed expiry/forfeiture (ADR-0006). | ✅ |
| **Wallet (durable)** | `DatabaseWallet` — one row per grant **lot** (migration), balances derived by summing active lots; same `CreditConsumer` burn-down, same expiry/forfeiture semantics as `InMemoryWallet` (a pure storage swap). `grant()` idempotent + gap-lock-safe (`insertOrIgnore` on the grant id); `consume()` locks the org's lots `FOR UPDATE`, plans, then atomically decrements each drawn lot (the PAYG sink may go negative); `expire()`/`forfeit()` remove only a lot's positive remainder under lock; deadlocks propagate. Bound by config `billing.wallet.store = memory\|database`. Dogfooded `databaseWallet()` on `InteractsWithWallet`. | ✅ |
| **Quote / consequence-preview** | `QuoteBuilder` composes **`cboxdk/laravel-tax`** (seller-of-record routing + per-line tax) + wallet credit → a confirmable `Quote` (net/tax/gross/credit/dueNow · `TaxResolution`). Progressive tax resolution: an unresolved jurisdiction returns a *tax-pending* quote — never a wrong number. | ✅ |
| **Catalog** | Stripe-style `Product`/`Price` split; effective-date price versioning → **grandfathering**. `PricingModel` (flat · per-unit); `Catalog` + `InMemoryCatalog`. Plans carry a **`family`** (deny-by-default: an unfamilied plan is its own singleton family) and a **`PlanStatus`** (`offered`/`legacy`); `Catalog::products()` enumerates plans. Dogfooded `InteractsWithCatalog`. | ✅ |
| **Seller (of record)** | `SellerEntity` → tax seller-registrations; `EntityRouter`/`DefaultEntityRouter` routes a buyer to the entity registered in their country else a default — the multi-entity routing that drives tax. | ✅ |
| **Invoice** | `Invoicer` fixes a confirmed `Quote` to a per-entity legal number (`InvoiceNumberSequence`, monotonic/gapless) → `Invoice`. Refuses a tax-pending quote. | ✅ |
| **Subscription** | `BillingPeriod` + `ProrationCalculator` + `PlanChangePreviewer` (upgrade/downgrade consequence-preview). Lifecycle: `Subscription` + `SubscriptionManager` (create · cancelAtPeriodEnd · resume · scheduleChange [mutable] · renew). | ✅ |
| **Subscription / transition policy (ADR-0010)** | `TransitionPolicy` contract + batteries-included `FamilyTransitionPolicy` (deny-by-default): same family → allowed; across families → only along an explicitly declared `TransitionEdge` (optional guidance + `carryOver`); a **legacy** plan is a valid source but never a target. `availableTransitions(from, catalog)`. `PlanChangePreviewer` gates **before** proration — a disallowed target raises `TransitionNotAllowed(reason)`, never a silent proration; a legacy current plan carries an irreversibility warning. | ✅ |
| **Subscription / plan retirement & sunset (ADR-0016)** | A plan carries an optional `PlanRetirement` (`retiresAt` + optional `defaultSuccessorPlanId`); `PlanStatus::Retiring`. A **being-retired plan is never a transition target** (like legacy). Pure `PlanRetirementResolver` `(Subscription, Catalog, now)` → `RetirementResolution` (`NotRetiring` · `RetiringChooseBy(renewalDueDate, default)` · `ResolvedToSuccessor` · `ResolvedToCancel` · `ResolvedToDefault` · `UnresolvedRetirement`); resolves at the **next renewal on/after the cutoff** so no paid time is lost. Thin `RetirementRenewalPolicy` (called in place of `renew`) enacts: plain renewal / policy-validated migration (`renewOntoPlan`) / refusal (`RetirementNotResolved`) — deny-by-default, never auto-charge a retired plan. `ScheduledChange` gained optional `newProductId` (+ `schedulePlanChange`) so a scheduled change can be a plan change. Dogfooded `retiringPlan()` / `retirementResolver()` / `retirementRenewalPolicy()`. | ✅ |
| **Subscription / credit consequences (ADR-0011)** | Plan switch runs a per-cycle reset through the wallet: forfeit the outgoing recurring allotment + regrant the incoming plan's (floored at 0, so a negative PAYG pool is never offset); a `carryOver` edge keeps the outgoing allotment instead. `purchased` always carries, `promotional`/`regulated` follow their own expiry. The preview returns a **`CreditDelta`** (forfeited / granted / carried / pool-left-negative) beside the money delta; cancel-at-period-end defers forfeiture to period end. `ForfeitureHandler::onSwitch` + `SubscriptionLifecycle::switchPlan`; dogfooded `FakeForfeitureHandler`. | ✅ |
| **Payment** | Gateway-agnostic `PaymentGateway` + dependency-free `ManualPaymentGateway`; `DunningPolicy`. Stripe/Mollie = opt-in adapter packages. | ✅ |
| **Pricing** | `Coupon` (percentage/fixed, validity) + `CouponApplier` — discounts the net before tax. | ✅ |
| **Entitlement** | `EntitlementProjector` → coarse tier via the `EntitlementWriter` port (decoupled from cbox-id). Scoped **per (org, product)** — an org holds many concurrent product entitlements. | ✅ |
| **Reporting** | `MrrCalculator` (MRR + ARR per currency) · `ChurnCalculator`. | ✅ |
| **Reconciliation (ADR-0003)** | Convergent reconciliation: per-`(org, meter)` cumulative-delta-vs-checkpoint (no per-event replay). `Reconciler`/`DefaultReconciler` post `sum(events) − checkpoint.total` into the `Ledger` idempotently (natural `PostingKey`, ADR-0002). Guards: **ingest-lag clamp** (`ceiling = now − lag`), **aged-out bucketing** (usage older than the window → `aged_out` account, never dropped), **per-entity locked checkpoint** with concurrency errors **rethrown** and other per-entity errors reported+skipped. `CheckpointStore` (contract · `InMemoryCheckpointStore` default · durable `DatabaseCheckpointStore` migration · `FakeCheckpointStore`). `billing:reconcile` command. Dogfooded `InteractsWithReconciliation`. | ✅ |
| **Licensing (on-prem issuer)** | Wraps the crypto core `cboxdk/license` (never reimplements crypto): mints a signed, offline-verifiable license from a licensable plan. `LicenseProfile` (plan → entitlements + `LicenseLimits`); `LicenseProfileResolver`/`ConfiguredLicenseProfileResolver` (**deny-by-default**: unknown plan → `null`, cannot mint; empty map default). `LicenseMint::issue()` builds the core `LicenseRequest`, signs via injected `LicenseIssuer`, pins the id so record↔`lid` agree; `reissue()` re-mints same deployment/profile with an extended window + fresh id (renewal). `IssuedLicense` record + `IssuedLicenseStore`/`InMemoryIssuedLicenseStore` (by id/customer/deployment). `RevocationRegistry`/`InMemoryRevocationRegistry` + `RevocationPublisher` (signs the current list via injected `RevocationListIssuer`). `SubscriptionLicensePolicy` (pure period-end + grace → `expiresAt`). **Key-agnostic**: the provider does NOT bind `LicenseIssuer`/`RevocationListIssuer` — the host binds them from private-key config. Dogfooded `InteractsWithLicensing` (real Ed25519 keypair, round-trip verified with the core verifier). | ✅ |

Tests: 310 · assertions: 1072.

> **Dependencies:** composes `cboxdk/laravel-tax` (`^0.1`) and `cboxdk/laravel-geo`
> (`^0.4`) from Packagist, plus the framework-agnostic crypto core `cboxdk/license`
> (`^0.1`) for the licensing issuer. Gateway adapters (`laravel-billing-stripe`,
> `-mollie`) are separate opt-in packages.
>
> **Release note:** `cboxdk/license` is pulled via a `vcs` `repositories` entry in
> `composer.json` until it lands on Packagist (see the `TODO(release)` marker) — drop
> that entry once it is published.

## Remaining

- Optional **ClickHouse `EventLog` adapter** package (`cboxdk/laravel-billing-clickhouse`) for event-heavy scale.
- **`laravel-billing-client` SDK** — the app-side hot path + event sync + production `AllowanceLeaseSource`.
- The deployable **`cboxdk/cbox-billing` app** (admin + portal, OIDC client of cbox-id).
- Public + Packagist for `laravel-billing` and the gateway adapters.
