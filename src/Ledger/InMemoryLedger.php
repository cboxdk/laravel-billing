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
 * replaces it in production; the contract is identical, including the idempotency
 * story: a re-post of the same natural key ({@see LedgerTransaction::postingKey()})
 * is a no-op, so this fake honours ADR-0002 the same way the durable ledger does.
 */
class InMemoryLedger implements Ledger
{
    /** @var list<LedgerTransaction> */
    private array $transactions = [];

    /** @var array<string, true> natural-key tokens already posted */
    private array $posted = [];

    public function post(LedgerTransaction $transaction): void
    {
        $token = $transaction->postingKey()->token();

        if (isset($this->posted[$token])) {
            return; // already posted this natural key — no-op
        }

        // LedgerTransaction has already validated balance + single currency.
        $this->posted[$token] = true;
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
