# ADR-0006 — Credit lot accounting, expiry, and forfeiture

**Status:** Accepted (2026-07-15)

## Context

Credits within a pool are granted in **lots**, each with an independent `expiresAt`. Two
operations are easy to get subtly wrong:

- **Expiry.** When a lot expires, only its **unconsumed remainder** may be removed — not the
  whole lot (that destroys a *newer* lot's still-valid credit) and not the pool balance (that
  destroys everything). A naive `min(transaction, balance)` over-expires whenever an older lot
  ages out while a younger lot in the same pool still holds unconsumed credit.
- **Forfeiture.** Ending a subscription must forfeit the right pools — and **"cancel to no
  plan" is a distinct transition from "downgrade"**. A forfeiture rule keyed on a specific
  destination plan (e.g. "on move to pay-as-you-go") silently misses a cancellation that
  resolves to a *null* plan.

## Decision

- **Lots are attributed.** A debit reduces specific lots in burn-down order
  (soonest-expiring → priority → oldest), so each lot's remaining is always known and expiry
  debits exactly the expiring lot's remainder.
  - *Scale escape hatch:* if the durable ledger is later made lot-anonymous for volume, expiry
    reconstructs at-risk amounts at **read time** — pour the pool balance into non-offset lots
    oldest-expiry-first — and marks each expiry with the offset transaction id for idempotency.
- **Forfeiture is keyed on the transition, not the destination.** It fires whenever an org
  **leaves a subscription and does not land on another** (covering cancel-to-null). It affects
  only `forfeitsOnCancel` pools and **floors each at zero**, so a negative pay-as-you-go pool
  cannot offset a forfeitable allotment.

## Consequences

- `Wallet` tracks per-lot remaining; an expiry sweep (bounded look-back window) and a forfeiture
  handler are added.
- The subscription lifecycle models the **transition** (left-without-landing) rather than
  reacting to a target plan id.
- Expiry and forfeiture are idempotent (offset markers / floor-at-zero).

## Alternatives considered

- **`min(transaction, balance)` expiry.** Rejected — over-expires overlapping lots.
- **Forfeiture on a specific destination plan.** Rejected — misses cancel-to-null (the exact
  blind spot this ADR closes).
- **Lot-anonymous ledger from day one.** Deferred — attributed lots are simpler and correct
  now; the read-time reconstruction is the documented path if scale demands it.
