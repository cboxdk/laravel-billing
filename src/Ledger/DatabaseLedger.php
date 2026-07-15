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
 * summing. Posting is atomic and idempotent — a re-post of the same natural key is a
 * no-op — so a retried post never double-counts.
 *
 * **Idempotency (ADR-0002).** Dedupe is keyed on the transaction's application-level
 * natural key {@see LedgerTransaction::postingKey()} — `(org, source, reference)` —
 * held in a *separate, unpartitioned* dedupe table {@see POSTINGS}, never a UNIQUE
 * index on {@see TABLE}. The ledger-lines table carries no unique constraint, so it
 * can later be time-partitioned (where every UNIQUE index would have to include the
 * partition key) with no change to the idempotency story.
 *
 * **Gap-lock-safe claim (task #32).** The post claims its dedupe row with an
 * `INSERT … ON CONFLICT DO NOTHING` (`insertOrIgnore`) and branches on the affected
 * count — it never `SELECT … FOR UPDATE`s a row that may not yet exist. A missing
 * row cannot take a shared gap lock, so two concurrent posters of the same key
 * serialize on the unique index instead of deadlocking: the loser sees 0 rows
 * claimed and returns without writing lines.
 *
 * **Append-only.** The class exposes no update or delete of posted lines; posting is
 * the only mutation. For hardening beyond the ORM, ship the immutability-trigger
 * migration (`..._harden_billing_ledger_immutability`) on MySQL.
 */
readonly class DatabaseLedger implements Ledger
{
    private const TABLE = 'billing_ledger_lines';

    private const POSTINGS = 'billing_ledger_postings';

    public function __construct(private ConnectionInterface $db) {}

    public function post(LedgerTransaction $transaction): void
    {
        $key = $transaction->postingKey();

        $this->db->transaction(function () use ($transaction, $key): void {
            // Claim the natural key in the unpartitioned dedupe table. insertOrIgnore
            // is an atomic INSERT-or-no-op on the unique key: a not-yet-existing row
            // can't take a shared gap lock, so concurrent posters of the same key
            // serialize here instead of deadlocking. A retry sees 0 rows claimed.
            $claimed = $this->db->table(self::POSTINGS)->insertOrIgnore([
                'org' => $key->org,
                'source' => $key->source,
                'reference' => $key->reference,
                'transaction_id' => $transaction->id,
                'posted_at' => $transaction->occurredAt,
            ]);

            if ($claimed === 0) {
                return; // already posted this natural key — no-op
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
