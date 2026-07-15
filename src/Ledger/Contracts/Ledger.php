<?php

declare(strict_types=1);

namespace Cbox\Billing\Ledger\Contracts;

use Cbox\Billing\Ledger\ValueObjects\LedgerTransaction;
use Cbox\Billing\Money\Money;

/**
 * The double-entry money ledger — the money source of truth. Transactions are
 * balanced (guaranteed by {@see LedgerTransaction}) and append-only; a posted
 * transaction is never mutated (corrections are new reversing transactions).
 * Balances are always DERIVED from posted lines, never stored.
 *
 * The default implementation here is in-memory (proving the mechanics); a
 * durable `DatabaseLedger` and two-phase pending transfers follow.
 */
interface Ledger
{
    /**
     * Post a balanced transaction, exactly once.
     *
     * **Idempotency key:** the transaction's application-level natural key
     * {@see LedgerTransaction::postingKey()} — `(org, source, reference)`, or the
     * degenerate key derived from the transaction id when none is given. A second
     * post carrying the same key is a **no-op** (never a double-count, never an
     * error), so a retried or reprocessed post is safe. Per ADR-0002 this dedupe is
     * enforced in code against a separate, unpartitioned record — never by a UNIQUE
     * index on the (future time-partitioned) ledger — so partitioning can be
     * introduced later with no change to the idempotency story.
     */
    public function post(LedgerTransaction $transaction): void;

    /**
     * The account's net balance in `currency`, computed as total debits minus
     * total credits. Debit-normal accounts (receivables) read positive; credit-
     * normal accounts (wallets, revenue) read negative — callers interpret the
     * sign per account type.
     */
    public function balance(string $account, string $currency): Money;
}
