<?php

declare(strict_types=1);

namespace Cbox\Billing\Ledger;

use Cbox\Billing\Ledger\Contracts\TwoPhaseLedger;
use Cbox\Billing\Ledger\Enums\Direction;
use Cbox\Billing\Ledger\Enums\TransferState;
use Cbox\Billing\Ledger\Exceptions\InvalidTransfer;
use Cbox\Billing\Ledger\ValueObjects\LedgerTransaction;
use Cbox\Billing\Money\Money;

/**
 * In-memory {@see TwoPhaseLedger} — proves the reserve/commit mechanics. `balance`
 * sums posted transfers; `available` sums posted plus still-pending ones, so a
 * reservation lowers available immediately while the posted balance only moves on
 * commit. A durable database implementation follows the same contract.
 */
class InMemoryTwoPhaseLedger implements TwoPhaseLedger
{
    /** @var array<string, array{tx: LedgerTransaction, state: TransferState}> */
    private array $entries = [];

    public function post(LedgerTransaction $transaction): void
    {
        $this->put($transaction, TransferState::Posted);
    }

    public function reserve(LedgerTransaction $transaction): void
    {
        $this->put($transaction, TransferState::Pending);
    }

    public function commit(string $transactionId): void
    {
        $this->transition($transactionId, TransferState::Posted);
    }

    public function release(string $transactionId): void
    {
        $this->transition($transactionId, TransferState::Voided);
    }

    public function balance(string $account, string $currency): Money
    {
        return $this->sum($account, $currency, [TransferState::Posted]);
    }

    public function available(string $account, string $currency): Money
    {
        return $this->sum($account, $currency, [TransferState::Posted, TransferState::Pending]);
    }

    private function put(LedgerTransaction $transaction, TransferState $state): void
    {
        if (isset($this->entries[$transaction->id])) {
            throw InvalidTransfer::duplicate($transaction->id);
        }

        $this->entries[$transaction->id] = ['tx' => $transaction, 'state' => $state];
    }

    private function transition(string $transactionId, TransferState $to): void
    {
        if (! isset($this->entries[$transactionId]) || $this->entries[$transactionId]['state'] !== TransferState::Pending) {
            throw InvalidTransfer::notPending($transactionId);
        }

        $this->entries[$transactionId]['state'] = $to;
    }

    /**
     * @param  list<TransferState>  $states
     */
    private function sum(string $account, string $currency, array $states): Money
    {
        $balance = Money::zero($currency);

        foreach ($this->entries as $entry) {
            if (! in_array($entry['state'], $states, true)) {
                continue;
            }

            foreach ($entry['tx']->lines as $line) {
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
