# cbox-billing — Foundation Contracts

The coherence layer every billing workstream builds against — the data model, the
core contracts, the ledger invariants, the real-time enforcement path, and the
cbox-id seam. Mirrors how `laravel-id` started: agree the contracts before code.

Status: **draft / pre-code.** Grounded in a verified competitive+architecture
research pass (2026-07-13). Items marked **⚡ SPIKE** are deliberately unsettled
and must be prototyped before they harden.

---

## 1. What this is

`cboxdk/laravel-billing` is the **monetization engine** for the whole cbox
portfolio — a framework package that drops into any Laravel app, exactly like
`cboxdk/laravel-id`. `cboxdk/cbox-billing` is the deployable app (admin console +
customer portal, cboxbilling.com) built on it. Positioning: a **self-hostable
Chargebee** — Chargebee's feature scope, Lago's delivery model (OSS, self-host or
hosted), Metronome's real-time metering, and native cbox-id identity/entitlements.

**Division of labor with cbox-id.** ID = *who you are + what you may* (identity,
sessions, entitlement enforcement). Billing = *what you owe + why* (catalog,
contracts, money, entitlement computation). Billing is an OIDC client of cbox-id;
**one billing account == one cbox-id organization**; billing reuses ID's
environment/organization tenancy verbatim.

---

## 2. The three-layer architecture (the load-bearing decision)

Market leaders (Orb, OpenMeter) **never** treat their real-time counters as the
billing source of truth — *"the invoice is computed, not retrieved."* We adopt
that. Enforcement, metering truth, and money are **three separate concerns**:

| Layer | Question it answers | Store | Authority | Loss tolerance |
| --- | --- | --- | --- | --- |
| **1 · Enforcement** | *May this request proceed?* (sub-ms) | **App-local** counter (in-memory or the app's own Redis) | none — **eventually consistent**, small accepted drift | lose only usage since last sync (small, backfillable) |
| **2 · Metering truth** | *What actually happened?* | Immutable append-only event log (ClickHouse), fronted by a billing-side ingest hot counter | **metering source of truth** | none — durable, idempotent |
| **3 · Money** | *What is owed / paid / owned?* | Double-entry ledger (Postgres) | **money source of truth** | none — derived from postings |

**Design stance: accept a small, bounded drift.** We deliberately do NOT do strict
cross-node atomic hard limits (they'd force a shared/co-located Redis on every app
deployment — a no-go operationally). Instead the app enforces **locally** and
eventually reconciles to billing. This matches how Orb/OpenMeter actually run
(eventually-consistent counters, authoritative billing recomputed from events) and
removes the hardest, unproven part of the design.

### Topology (hot-path is app-local; async ingest to billing)

```
App (consuming service)
 ├─ laravel-billing-client SDK
 │   ├─ enforce(): decrement an APP-LOCAL counter (in-memory / the app's own Redis)
 │   │     against a leased allowance slice → local HARD limit, no shared/co-located dep
 │   ├─ durable local buffer: append each usage event BEFORE sync (crash-safe)
 │   └─ sync(): ship buffered events → billing Ingest API (async, idempotent, cumulative)
 ↓  event-based
cbox-billing (engine)
 ├─ Ingest hot counter (billing-side Redis) accumulates incoming usage           [L2 front]
 ├─ dedup(id+source, bounded window) → immutable event log (ClickHouse)          [L2 truth]
 ├─ Ledger projection every N min: aggregate events → double-entry postings       [L3]
 ├─ Invoicing: recompute charges from L2 with the pinned price version
 └─ Entitlement: coarse plan tier → cbox-id (sparingly); fine/usage stays app-local
```

**Bounding the drift (so "small" is actually small).** Billing **leases/refills** a
slice of the remaining allowance to each app node (a distributed token-bucket: the
node enforces against its lease and requests a refill when depleted). Worst-case
overshoot ≈ `lease_size × node_count` before reconciliation — bounded by the lease
size, not `N ×` the whole allowance. Tune lease size + refill cadence per metric.

**Backfill / crash-safety.** The SDK appends every usage event to a **durable local
buffer** before counting, then syncs. If the in-memory counter dies, replay the
buffer. If the whole node dies, lose only usage since the last sync (a small
window → sync frequently). Reporting **cumulative** counters (not deltas) lets a
lost delta self-correct on the next sync. Billing reconciles and backfills from the
durable event log.

**Ingest hot path (same pattern, one level up).** Billing's ingest keeps a hot
in-memory/Redis counter accumulating incoming usage until the ledger projection
persists it every N min — so an ingest-cache loss also costs at most the un-persisted
window, re-projectable from L2.

---

## 3. Core data model

**Catalog** (versioned — see §6 grandfathering)
- `Product` — what you sell (a plan family, an add-on family). Cross-product: a
  product belongs to a cbox app.
- `Price` — how much / how often (a version). Pricing models: **flat**, **per-seat**,
  **tiered** (`volume` vs `graduated`), **usage/metered**, **one-time**. Multi-currency
  price sets per version.
- `Plan` — a sellable bundle of `Price` components + included allowances + entitlements.
- `Addon` — an à-la-carte `Price`/allowance attachable to a subscription.

**Account & contacts**
- `BillingAccount` — 1:1 with a cbox-id organization. Currency, tax IDs (VAT),
  addresses, payment methods, billing contacts.

**Subscription** (the running agreement)
- `Subscription` — pins a `Plan` **version** (→ grandfathering), quantity, addons,
  discounts, billing cycle + anchor, trial, status (`trialing|active|past_due|
  paused|canceled`), term/commitment.
- `SubscriptionSchedule` — a staged future change (upgrade/downgrade/plan swap),
  **mutable/cancelable until its effective date** (§7).

**Allowances, credits, wallets** (the policy layer, §7)
- `Allowance` — included usage per metric per cycle (reset | rollover).
- `Wallet` — an org's credit balances; every balance is a **derived ledger
  balance**, never a stored number.
- `CreditGrant` — one grant into a wallet. Grants are first-class and **multiple
  types coexist**, each with:
  - **Denomination** — a grant is either **monetary** (a currency amount, e.g.
    €50) or **unit** (a specific meter's units, e.g. 10 000 `api.calls`). Money and
    unit credits live side by side; usage is covered by whichever applies, falling
    back to a money charge.
  - **Type** — `promotional | prepaid | granted | free_tier | …` — a category with
    its own rules (promotional often expires and burns first; prepaid is what the
    customer paid for and is preserved).
  - **Expiry** — optional. Expired credits are void (use-it-or-lose-it) and drop
    out of the available balance.
  - **Priority / weight** — where it sits in the burn-down order when several
    grants can cover the same charge (§7).

**Metering**
- `Meter` — a billable metric: `(key, aggregation: sum|count|max|unique|last)`.
- `UsageEvent` — an ingested event `(org, meter, service, value, ts, dedup_key)`.
  L2 immutable.

**Invoicing & money**
- `Invoice` (`draft→finalized→paid|void|uncollectible`), `InvoiceLine`, `TaxLine`,
  `CreditNote`. Legal sequential numbering per jurisdiction/series.
- `LedgerAccount`, `LedgerEntry`, `Transfer` (incl. two-phase pending transfers).

---

## 4. Core contracts (interface-first, one per module)

Namespaced `Cbox\Billing\*`, each fronted by a contract in `*/Contracts`, bound in
its service provider, with a shippable `Testing/` fake — same standards as
`laravel-id`.

| Contract | Module | Responsibility |
| --- | --- | --- |
| `Catalog` | Catalog | CRUD products/plans/prices/addons; **version** on price change; resolve the price version in effect at a date. |
| `Subscriptions` | Subscription | create/upgrade/downgrade/cancel/pause; schedule staged changes; compute proration. |
| `MeterIngest` | Metering | accept usage events (idempotent, dedup by key) → L2. |
| `MeterQuery` | Metering | aggregate usage over L2 for a window (drives invoicing + reporting). |
| `Enforcement` | Metering | hot-path `reserve / commit / release / balance` against L1 (the SDK-facing API). |
| `Ledger` | Ledger | post balanced double-entry transactions; two-phase `reserve→post/void`; derive balances. |
| `Wallets` | Ledger | credit grants, top-ups, draw-down — all as ledger postings. |
| `Invoicing` | Invoice | build/finalize/void invoices + credit notes from subscriptions + L2 usage; numbering. |
| `Pricing` | Pricing | coupons/discounts, scheduled price increases, per-customer overrides/custom contracts, quotes. |
| `PaymentGateway` | Payment | charge/refund/setup-intent/webhook — gateway-agnostic; adapters implement it. |
| `TaxCalculator` | Tax (`laravel-tax`) | compute tax lines for a place-of-supply; VAT built-in, US via adapter. |
| `EntitlementProjector` | Entitlement | compute an org's entitlements from active subs/addons; push COARSE to cbox-id, serve fine/usage to the SDK. |

### The hot-path `Enforcement` API (SDK-facing)

```php
interface Enforcement
{
    // Atomically hold `estimate` units for `meter` on `org`. Returns a token, or
    // denies if it would breach the limit under the metric's overage policy.
    public function reserve(string $org, string $meter, int $estimate): Reservation;

    // Settle a reservation to the actual amount (<= estimate); the difference is
    // released. Emits the durable usage event (L2).
    public function commit(Reservation $r, int $actual): void;

    // Release a reservation without charging (error paths); also happens on TTL.
    public function release(Reservation $r): void;

    // Current available balance for UX/pre-checks (never the billing source).
    public function balance(string $org, string $meter): int;
}
```

Semantics under the app-local model: `reserve`/`commit` operate on the node's
**leased allowance slice** (not a shared store); `reserve` requests a refill when
the lease is depleted and denies only if the org's remaining allowance (as billing
last granted it) is exhausted. `commit` settles to the actual amount and appends
the durable usage event for sync. `balance` reflects the local lease, so it may lag
billing by up to one drift window — fine for UX/pre-checks, never for accounting.

---

## 5. Ledger invariants (non-negotiable)

1. **Double-entry.** Every transaction has ≥1 debit and ≥1 credit across ≥2
   accounts; `sum(debits) == sum(credits)`. Money that leaves one account arrives
   in another in the **same atomic operation**.
2. **Immutable / append-only** (matches ID's hash-chained-audit stance). Never
   mutate a posted entry; correct via a **reversing** entry (full reversal +
   re-post, or a delta).
3. **Derived balances.** A balance is *always* recomputed from posted entries;
   never store a mutable running total you can recompute.
4. **Money = integer minor units** via `brick/money` (immutable value objects);
   never floats. Rounding rules explicit and posted.
5. **Two-phase transfers** are the ledger-native `reserve→commit`: a pending
   transfer reserves; `post` confirms, `void`/timeout releases. L1's reservation
   maps onto a pending transfer when a hold must be money-accurate.

---

## 6. Pricing, versioning & grandfathering (Kill Bill model)

- Prices are **versioned by effective date**; each version supersedes the prior.
- **New** subscriptions / plan changes use the **most recent** version.
- **Existing** subscriptions are invoiced against the version **in effect when the
  subscription was created** — price-pinning / grandfathering **by default**.
- **Scheduled price increases**: a new version with an `effective_for_existing_at`
  date — new subscribers pay the new price immediately; existing ones migrate only
  on that later date (with notice). Opt-in per version.
- **Overrides / custom contracts**: a per-subscription price override pins a
  bespoke price outside the public catalog.

---

## 7. Subscription ↔ usage policy layer

Per `(plan, meter)`:
- **Included allowance** per cycle (`reset | rollover`) and/or **credits per cycle**.
- **Overage policy**: `hard_block` · `draw_credits` (spend wallet grants, §3) ·
  `bill_overage` (postpaid — meter it, invoice the excess at an overage/tiered price).
- **Watermarks**: threshold actions — `notify` (e.g. 80%) → `soft_degrade` →
  `hard_block` (100%), distinct per meter.
- The **hot path** evaluates this atomically: consume included → then credits →
  then block *or* accrue overage, per policy.

**Credit burn-down order** — when several grants can cover the same charge, the
consumption engine picks in a deterministic, configurable order. Default:
1. **Denomination match** — unit-credits for *this* meter before monetary credits
   (which must be priced first).
2. **Expiry** — soonest-expiring first (use-it-or-lose-it), so nothing is wasted.
3. **Priority / weight** — lower-value-to-the-customer first (e.g. promotional
   before prepaid), preserving what they paid for.
4. **Age** — oldest grant first as the tiebreaker.

A single charge may draw across **multiple grants**; each drawdown posts to the
ledger against its grant, so per-grant balances (and expiry write-offs) stay
exactly reconcilable. The **hot path** only needs the aggregate "can this be
covered?" answer — the *which-grant-drew-down* accounting is resolved in billing at
commit/reconciliation, keeping the enforcement path simple.

Plan-change timing:
- **Instant upgrade** — effective now; proration charges the delta; allowances
  expand immediately.
- **Proration policy** (configurable): `prorate_invoice | prorate_credit | none |
  always_invoice`.
- **Downgrade at period end** — scheduled `effective = period_end`; no refund;
  paid capacity retained until then.
- **Mutable scheduled change** — staged via `SubscriptionSchedule`, editable /
  cancelable until its effective date.

---

## 8. The cbox-id seam

cbox-id already ships the receiving contract:
`Cbox\Id\Kernel\Authorization\Contracts\EntitlementWriter`
(`set / revoke / reconcile(orgId, …, EntitlementSource::Billing, sourceRef)`).

- **Coarse, stable entitlements** (plan tier, big feature flags) → pushed to
  cbox-id via `EntitlementWriter` **sparingly**, as token-claim material for cheap
  edge gating. `reconcile()` guards against drift from lost pushes.
- **Fine-grained / usage / quota** entitlements are **NOT** synced into ID tokens
  (token bloat + staleness). They live in billing and are checked **app-side on the
  hot path** at org level via the SDK's `Enforcement` API. This is the deliberate
  refinement: ID enforces identity + coarse tier; billing enforces spend/quota.
- Auth: cbox-billing (and every consuming app) is an OIDC client of cbox-id;
  `org` on every billing/enforcement call is the cbox-id organization id.

---

## 9. Package boundaries (apply cbox-id's anti-over-split lesson)

- **`cboxdk/laravel-billing`** — one consolidated engine (Catalog, Subscription,
  Metering, Ledger, Invoice, Pricing, Payment contracts, Entitlement). MIT.
- **`cboxdk/laravel-tax`** — EU VAT engine (VIES validation, place-of-supply,
  reverse charge, inclusive/exclusive) + `TaxCalculator` contract with adapters.
  Standalone (portfolio-reusable, like `laravel-ssrf`).
- **`cboxdk/laravel-billing-stripe`**, **`cboxdk/laravel-billing-mollie`** — gateway
  adapters, opt-in deps.
- **`cboxdk/laravel-billing-client`** — the app-side SDK (hot-path `Enforcement` +
  event `meter()`), like `laravel-id-client`.
- **`cboxdk/cbox-billing`** — the deployable app (admin + portal), OIDC client of
  cbox-id.
- Adopt **`brick/money`** (don't build money). Reuse the observability stack
  (telemetry/health/queue-metrics/autoscale). **Do NOT derive from Lago** — it is
  **AGPL-3.0**; learn the architecture only.

The **Ledger** stays internal to `laravel-billing` initially (tightly coupled to
invoicing/wallets); extract `cboxdk/laravel-ledger` later only if a boundary earns it.

---

## 10. ⚡ Open design spikes (prototype before hardening)

1. **Allowance leasing + drift bounds.** The lease/refill protocol (lease size,
   refill cadence, depletion→refill request, how a node returns an unused lease on
   graceful shutdown) and the measured worst-case overshoot per metric. This is a
   known distributed-rate-limiting shape (local buckets + central refill), so it is
   **lower-risk than strict atomic enforcement** — but the drift bounds must be
   soak-tested against real concurrency + node counts. Durable-local-buffer replay +
   cumulative reporting is the crash-safety story.
2. **Reconciliation / backfill.** How billing trues up the ledger from the durable
   event log after drift or node loss, detects a node that stopped syncing, and
   handles late/duplicate events outside the dedup window. Counters (app-local and
   ingest-side) are disposable; the event log is the truth they reconcile to.
3. **Tax boundary.** Confirm EU VAT is fully buildable (OSS one-stop-shop: single
   registration, quarterly return, €10k cross-border B2C threshold; reverse charge;
   VIES) and the concrete economics/thresholds justifying integrate-not-build for US
   sales tax. Needs a dedicated tax research round before locking `laravel-tax` scope.
4. **The wedge is a thesis.** Identity-native hot-path entitlement enforcement is
   our differentiator but was **not** validated by research — prove it with the first
   integrated app, don't assume it.

---

## 11. Build order

0. `brick/money` + `laravel-tax` (VAT/VIES) + the **Ledger** spine + the **metering
   hot-path** (Enforcement/L1 + Ingest/L2) — core, per the real-time requirement.
1. Catalog (versioned) + `BillingAccount` ↔ cbox-id org.
2. Subscriptions + invoicing + proration (recompute from L2, post to L3).
3. `PaymentGateway` + Stripe + Mollie adapters + dunning/retries.
4. Pricing-ops (coupons, scheduled increases, grandfathering, overrides, quotes) +
   `EntitlementProjector` → cbox-id.
5. Wallets/prepaid deepening + overage policies.
6. Reporting/RevOps (MRR/ARR/churn, AR aging; ASC-606 deferred later) + the
   `cbox-billing` app + unified portal + cross-sell.
