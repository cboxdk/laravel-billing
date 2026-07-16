# ADR-0015 — Product shapes and term-based (registrar-style) products

**Status:** Accepted (2026-07-16)

## Context

The engine sells two shapes today: a **rolling `Subscription`** (a cycle anchor + proration,
renewing indefinitely until cancelled — ADR-0012) and **usage-metered plans** (entitlement +
real-time metering against allowances/credits). Neither expresses a **committed term**. A
registrar-style product — a domain, a hosting term, a TLS certificate — is bought for a
**chosen length** (1, 2, or 5 years), and that length is both a commitment and a **pricing
dimension**. The same product also has several **price kinds** that are genuinely different
numbers: registering is not renewing, renewing is not transferring in, and recovering a lapsed
instance (**redemption**) carries a premium. After the term ends there is a **post-expiry
lifecycle** — a grace window, then a redemption window, then true expiry — none of which the
rolling-subscription state machine models. Finally, one billing account holds **many
instances**, including **many instances of the same product** (a customer with a dozen
domains), each with its own term end and its own lifecycle state.

A unified platform must carry all three shapes — metered plan, rolling subscription, and
term-based product — on **one billing account**, priced and taxed through **one** pipeline.

## Decision

1. **`ProductShape` is a first-class Catalog attribute** — `Metered | Recurring | FixedTerm |
   OneTime`. It selects which fulfilment/billing semantics drive an instance. It is added to
   `Product` as the **last constructor parameter with a BC-safe default of `Recurring`**, so
   every existing product construction keeps its exact meaning.

2. **`Price` gains a `Term` + `PriceKind` dimension.** A `Term` is a `{count, TermUnit}`
   (`Day | Month | Year`) with calendar arithmetic (`addTo`, `toIso8601`, `equals`). A
   `PriceKind` is `Standard | Register | Renewal | Transfer | Redemption`. A `FixedTerm`
   product's catalog is a **set of (term × kind) price points** — `P2Y`/`Register` distinct
   from `P1Y`/`Renewal` — each **grandfathered by effective date** exactly like every other
   price version. Recurring/metered prices leave `term` null and `kind` at `Standard`. The
   Catalog contract gains `termPriceFor(productId, term, kind, at)`; `priceFor` keeps
   resolving the non-term prices (the two never collide on a mixed catalog).

3. **Fixed-term products bill as `TermSubscription` instances.** A `TermSubscription` is a
   committed term with a **definite end** — `{id, orgId, productId, instanceRef, term,
   registeredAt, termEndsAt, autoRenew, status}` — where `instanceRef` is the resource (the
   domain name). It carries the registrar lifecycle `Active → Grace → Redemption → Expired`
   plus `TransferredOut` / `Cancelled`, computed by a pure `TermLifecycle` service over the
   instance, the product's `RegistrarWindows`, and an instant. **Many instances per (org,
   product)** are the norm. The Subscription module now hosts **two shapes** — the rolling
   `Subscription` and the term-based `TermSubscription`.

4. **Term purchase/renew/redeem/transfer compose through the existing Quote → Invoice
   pipeline.** A `TermPurchase` helper selects the (term, kind) price via `termPriceFor` and
   produces the `LineInput`(s) the shared `QuoteBuilder` turns into a taxed, credit-aware
   `Quote`, which the `Invoicer` issues. Register / Renewal / Redemption / Transfer are just a
   choice of `PriceKind` — **tax, seller-of-record, credits, and dunning are unchanged**, and
   no tax/credit logic is duplicated.

5. **Entitlements stay per (org, instance via `sourceRef`).** A mixed portfolio — metered plan
   + rolling subscription + several term instances — resolves without collision because each
   entitlement is keyed by its own `sourceRef`, and two instances of the same product carry
   two distinct refs.

**Lifecycle rules as decided.** Phase boundaries are inclusive at each upper edge: `Active`
while `now ≤ termEndsAt`; `Grace` while `now ≤ termEndsAt + grace`; `Redemption` while `now ≤
termEndsAt + grace + redemption`; otherwise `Expired`. **`renew`** extends `termEndsAt` by the
new term from **the later of `now` and `termEndsAt`** — so an early renewal stacks onto the
remaining term while a late renewal extends from the renewal instant — and returns `Active`
(caller prices it `Renewal`). **`redeem`** brings a `Redemption`-phase instance back to
`Active` for a new term measured from `now` (priced `Redemption`). **`transferOut`** →
`TransferredOut`; **`transferIn`** is a factory minting a fresh `Active` instance whose term
runs from the transfer instant (priced `Transfer`). `TransferredOut` and `Cancelled` are
terminal — the phase computation preserves them rather than recomputing. **Auto-renew
boundary:** when `autoRenew` is true, passing `termEndsAt` does **not** drop the instance into
`Grace` — it stays `Active` and `isAutoRenewalDue` reports a renewal is due, so a billing run
charges the `Renewal` price and extends the term. `Grace` is the *manual-lapse* path only.

## Consequences

- Hosts can model **registrar-style catalogs without inventing a separate product per term**:
  one `FixedTerm` product carries its (term × kind) price grid.
- The Subscription module now hosts **two shapes** (rolling + term) behind their own value
  objects and services; the rolling machinery is untouched.
- Term selling reuses the **entire commercial pipeline** — quotes, tax, seller routing,
  credits, invoicing, dunning — so there is one place money is priced and taxed.
- **Out of scope:** the registry/EPP/DNS integration — auth codes, actual provisioning,
  nameserver changes, real transfer orchestration — is a **connector concern**. This billing
  model owns only the **commercial lifecycle** (term, phase, and the money movements that
  register/renew/redeem/transfer imply). A connector maps these commercial transitions onto a
  registry; the engine neither knows nor cares which registry.

## Alternatives considered

- **Each term as a separate `Product`** (`domain-com-1y`, `domain-com-2y`, …). Rejected:
  catalog explosion, no way to renew "the same thing" for a *different* term, and no home for
  the register/renew/transfer/redemption price kinds — they would each need their own product
  too, multiplying the explosion.
- **Shoehorn into the rolling `Subscription` with a 1-year interval.** Rejected: a rolling
  subscription has no committed **term end**, no register-vs-renewal-vs-redemption pricing, and
  no per-instance post-expiry lifecycle. Bending it to fit would corrupt the rolling model.
- **A separate package for term-based selling.** Rejected: term-based selling is **core
  billing**, not a reusable primitive with its own consumers — splitting it out would fracture
  the one commercial pipeline (tax, credits, invoicing) across package boundaries for no
  reuse gain (anti-over-split).
