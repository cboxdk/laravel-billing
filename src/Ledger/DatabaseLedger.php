<?php

declare(strict_types=1);

namespace Cbox\Billing\Ledger;

use Cbox\Billing\Ledger\Contracts\Ledger;
use Cbox\Billing\Ledger\Enums\Direction;
use Cbox\Billing\Ledger\ValueObjects\LedgerTransaction;
use Cbox\Billing\Money\Money;
use Illuminate\Database\ConnectionInterface;

/**
 * Durable {@see Ledger}: one immutable row per posted line, balances derived by
 * summing. Posting is atomic and idempotent — re-posting a transaction id is a
 * no-op — so a retried post never double-counts.
 */
readonly class DatabaseLedger implements Ledger
{
    private const TABLE = 'billing_ledger_lines';

    public function __construct(private ConnectionInterface $db) {}

    public function post(LedgerTransaction $transaction): void
    {
        $this->db->transaction(function () use ($transaction): void {
            if ($this->db->table(self::TABLE)->where('transaction_id', $transaction->id)->exists()) {
                return;
            }

            $rows = [];

            foreach ($transaction->lines as $line) {
                $rows[] = [
                    'transaction_id' => $transaction->id,
                    'account' => $line->account,
                    'direction' => $line->direction->value,
                    'amount_minor' => $line->amount->minor(),
                    'currency' => $line->amount->currency(),
                    'memo' => $transaction->memo,
                    'occurred_at' => $transaction->occurredAt,
                ];
            }

            $this->db->table(self::TABLE)->insert($rows);
        });
    }

    public function balance(string $account, string $currency): Money
    {
        $sum = $this->db->table(self::TABLE)
            ->where('account', $account)
            ->where('currency', $currency)
            ->selectRaw('COALESCE(SUM(CASE WHEN direction = ? THEN amount_minor ELSE -amount_minor END), 0) AS balance', [Direction::Debit->value])
            ->value('balance');

        return Money::ofMinor(is_numeric($sum) ? (int) $sum : 0, $currency);
    }
}
