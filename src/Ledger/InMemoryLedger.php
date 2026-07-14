<?php

declare(strict_types=1);

namespace Cbox\Billing\Ledger;

use Cbox\Billing\Ledger\Contracts\Ledger;
use Cbox\Billing\Ledger\Enums\Direction;
use Cbox\Billing\Ledger\ValueObjects\LedgerTransaction;
use Cbox\Billing\Money\Money;

/**
 * Append-only in-memory {@see Ledger} — proves the double-entry mechanics and
 * balance derivation. A durable `DatabaseLedger` (Eloquent, immutable rows)
 * replaces it in production; the contract is identical.
 */
class InMemoryLedger implements Ledger
{
    /** @var list<LedgerTransaction> */
    private array $transactions = [];

    public function post(LedgerTransaction $transaction): void
    {
        // LedgerTransaction has already validated balance + single currency.
        $this->transactions[] = $transaction;
    }

    public function balance(string $account, string $currency): Money
    {
        $balance = Money::zero($currency);

        foreach ($this->transactions as $transaction) {
            foreach ($transaction->lines as $line) {
                if ($line->account !== $account || $line->amount->currency() !== $currency) {
                    continue;
                }

                $balance = $line->direction === Direction::Debit
                    ? $balance->plus($line->amount)
                    : $balance->minus($line->amount);
            }
        }

        return $balance;
    }
}
