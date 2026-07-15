<?php

declare(strict_types=1);

namespace Cbox\Billing\Ledger\Contracts;

use Cbox\Billing\Ledger\ValueObjects\LedgerTransaction;
use Cbox\Billing\Money\Money;

/**
 * A ledger with two-phase (pending) transfers — the ledger-native reserve/commit.
 * A reservation IS a pending transfer: it counts toward the *available* balance
 * immediately but not the *posted* balance, so concurrent requests cannot commit
 * more than is available. Commit turns it real; release cancels it.
 */
interface TwoPhaseLedger extends Ledger
{
    /** Record a pending (reserved) transfer. */
    public function reserve(LedgerTransaction $transaction): void;

    /** Turn a pending transfer into a posted one. */
    public function commit(string $transactionId): void;

    /** Cancel a pending transfer (it never affects the posted balance). */
    public function release(string $transactionId): void;

    /**
     * The account's available balance: the posted balance adjusted by outstanding
     * pending transfers (as if they were posted).
     */
    public function available(string $account, string $currency): Money;
}
