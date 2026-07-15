<?php

declare(strict_types=1);

namespace Cbox\Billing\Ledger\ValueObjects;

use Cbox\Billing\Ledger\Enums\Direction;
use Cbox\Billing\Ledger\Exceptions\UnbalancedTransaction;
use Cbox\Billing\Money\Money;

/**
 * A balanced set of {@see LedgerLine}s posted atomically. Enforces the
 * double-entry invariant at construction: at least two lines, a single currency,
 * and total debits exactly equal to total credits. An unbalanced or mixed-currency
 * transaction can never exist.
 *
 * `key` is the application-level natural key the post is idempotent on (see
 * ADR-0002): a re-post carrying the same `(org, source, reference)` is a no-op.
 * When omitted, {@see postingKey()} derives a degenerate key from the transaction
 * id, so an id-only post is still idempotent. The `id` groups this transaction's
 * lines in the durable store; it is not the dedupe key.
 */
readonly class LedgerTransaction
{
    /**
     * @param  list<LedgerLine>  $lines
     *
     * @throws UnbalancedTransaction
     */
    public function __construct(
        public string $id,
        public array $lines,
        public string $memo = '',
        public int $occurredAt = 0,
        public ?PostingKey $key = null,
    ) {
        if (count($lines) < 2) {
            throw new UnbalancedTransaction('A ledger transaction needs at least two lines.');
        }

        $currency = $lines[0]->amount->currency();
        $debits = Money::zero($currency);
        $credits = Money::zero($currency);

        foreach ($lines as $line) {
            if ($line->amount->currency() !== $currency) {
                throw new UnbalancedTransaction("Ledger transaction [{$id}] mixes currencies.");
            }
            if ($line->amount->isNegative()) {
                throw new UnbalancedTransaction("Ledger line amounts must be non-negative; use the opposite direction instead (transaction [{$id}]).");
            }

            $debits = $line->direction === Direction::Debit ? $debits->plus($line->amount) : $debits;
            $credits = $line->direction === Direction::Credit ? $credits->plus($line->amount) : $credits;
        }

        if (! $debits->equals($credits)) {
            throw new UnbalancedTransaction("Ledger transaction [{$id}] is unbalanced: debits {$debits} != credits {$credits}.");
        }
    }

    /**
     * The natural key this post is deduplicated on: the explicit `key` when given,
     * otherwise the degenerate key derived from the transaction id. Always returns a
     * key, so every post claims exactly one unpartitioned dedupe record.
     */
    public function postingKey(): PostingKey
    {
        return $this->key ?? PostingKey::forTransaction($this->id);
    }
}
