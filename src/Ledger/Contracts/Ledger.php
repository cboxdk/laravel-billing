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
    public function post(LedgerTransaction $transaction): void;

    /**
     * The account's net balance in `currency`, computed as total debits minus
     * total credits. Debit-normal accounts (receivables) read positive; credit-
     * normal accounts (wallets, revenue) read negative — callers interpret the
     * sign per account type.
     */
    public function balance(string $account, string $currency): Money;
}
