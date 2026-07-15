---
title: Ledger
description: Double-entry, append-only, derived-balance money — with idempotency that survives table partitioning and two-phase transfers for money-accurate holds.
weight: 23
---

# Ledger

The ledger is layer 3 — the **money source of truth**. Balances are always derived
from immutable postings; there is no mutable running total to corrupt.

## Invariants

1. **Double-entry.** Every `LedgerTransaction` has at least two lines and
   `sum(debits) == sum(credits)` in a single currency. Line amounts are
   non-negative — direction (`Debit` / `Credit`) carries the sign. The constructor
   throws `UnbalancedTransaction` on a mixed-currency, negative-amount, or
   unbalanced transaction.
2. **Immutable / append-only.** A posted entry is never mutated; corrections are
   *reversing* entries. The `harden_billing_ledger_immutability` migration enforces
   append-only at the database layer.
3. **Derived balances.** `balance($account, $currency)` recomputes from posted
   lines and returns a `Money`. Never store a total you can recompute.
4. **Money is integer minor units** via `brick/money`, wrapped in the immutable
   `Cbox\Billing\Money\Money` value object — never floats.

```php
interface Ledger
{
    public function post(LedgerTransaction $transaction): void;
    public function balance(string $account, string $currency): Money;
}
```

`InMemoryLedger` is the default; `DatabaseLedger` is the durable append-only
adapter (rows in `billing_ledger_lines` / `billing_ledger_postings`, balances
derived by summing).

## Idempotency independent of partitioning (ADR-0002)

At volume the ledger is partitioned by time, and on a partitioned table every
UNIQUE index must include the partition key — so `UNIQUE(event_id)`, the obvious
way to make posting idempotent, becomes **impossible**. Building idempotency on a
constraint you will later be forced to drop is a trap that passes every
single-writer test and fails silently under the production schema.

So idempotency is an **application-level** property, never a partitioned-table
unique constraint. Ledger posting is idempotent on a **natural key** — a
`PostingKey(org, source, reference)` — enforced in code; a re-post is a no-op:

```php
readonly class PostingKey
{
    public function __construct(
        public string $org,
        public string $source,
        public string $reference,
    ) {}
}
```

`DatabaseLedger` uses an upsert / existence check and never assumes an index it
will lose to partitioning. This is the same idempotency the
[reconciler](reconciliation.md) relies on to post a delta exactly once, and the
same principle governs recurring cycle grants (idempotent on *time*, not a marker
— a grant with `created_at >= period_start` means "already granted this cycle").
See [ADR-0002](../../adr/0002-ledger-idempotency-independent-of-partitioning.md).

## Two-phase transfers

`TwoPhaseLedger` (`InMemoryTwoPhaseLedger`) is the ledger-native `reserve → commit
/ release`. A reservation is a pending transfer that lowers `available`
immediately but not the posted `balance`; `commit` confirms it, `release` (or a
timeout) drops it. This is what a metering hold maps onto when a reservation must
be money-accurate rather than just an allowance count.

## Testing

`Cbox\Billing\Ledger\Testing\InteractsWithLedger` posts and asserts balances over
the in-memory ledger.

## Related

- [Reconciliation](reconciliation.md)
- [Wallets & credits](wallets.md)
- [Refunds & chargebacks](refunds-and-chargebacks.md)
