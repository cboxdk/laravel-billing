# ADR-0008 — Hot-path balance is derived, never a loose cached number

**Status:** Accepted (2026-07-15)

## Context

A near-real-time balance that is optimistically decremented on the hot path necessarily drifts
**below** the durable authority by the amount of unreconciled spend. Two classic corruptions
follow if that balance is treated as a loose, mutable number:

- **Re-setting it to the ledger sum on a grant** wipes the in-flight (unreconciled) spend — the
  org gets to spend that usage a second time (double-spend).
- **Clearing a period claim-counter mid-period** reseeds it from lagging durable storage and
  **re-grants** the included allowance to everyone.

## Decision

The hot-path balance is **derived**, not stored as a loose scalar:

```
available = ledger_balance − unflushed_usage − active_reservations
```

Grants and debits move the **ledger**; the derived available balance follows automatically.
This makes the double-spend class **unreachable by construction** — there is no cached scalar to
re-set incorrectly.

If a future optimization *does* cache a scalar balance for read performance, it must:

1. apply **deltas** (`increment`/`decrement`), never `SET` to the ledger sum;
2. seed a **cold** key from the durable source (fail *open* to the authority, per ADR-0004);
3. **never clear** a period claim-counter before its boundary — counters expire only at the
   period boundary via TTL (clearing mid-period re-grants allowances).

## Consequences

- The SET-vs-increment double-spend and the mid-period allowance re-grant are designed out.
- The rule is written down so a later performance optimization can't reintroduce the bug.
- Consistent with ADR-0003: the derived value may lag by one drift window and is never used for
  a hard money decision without the ledger.

## Alternatives considered

- **Cache a scalar balance, `SET` on grant.** Rejected — the canonical double-spend bug.
- **Clear counters on write to "invalidate".** Rejected — re-grants included allowances; counters
  are an authoritative claim register, not a cache.
