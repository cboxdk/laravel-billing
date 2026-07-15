<?php

declare(strict_types=1);

namespace Cbox\Billing\Ledger\ValueObjects;

/**
 * The application-level natural key a ledger post is idempotent on: the origin
 * `org`, the `source` system that produced the posting (e.g. "wallet", "invoice",
 * "cycle-grant"), and the source's own `reference` for the underlying event (e.g. a
 * drawdown id, an invoice number). A re-post carrying the same key is a no-op.
 *
 * This is deliberately NOT the ledger row's technical id: per ADR-0002 idempotency
 * is an application property, deduplicated by a key we own, never by a UNIQUE index
 * on the (future time-partitioned) ledger table — such an index becomes illegal the
 * day the ledger is partitioned. The key lives in a separate, unpartitioned dedupe
 * record instead.
 *
 * When a caller posts without an explicit key, {@see LedgerTransaction::postingKey()}
 * derives the degenerate key {@see PostingKey::forTransaction()} from the transaction
 * id, so every post still claims exactly one dedupe record.
 */
readonly class PostingKey
{
    public function __construct(
        public string $org,
        public string $source,
        public string $reference,
    ) {}

    /**
     * The degenerate key for a transaction posted without an application-level key:
     * dedupe on the transaction id alone. Production callers pass a real
     * `(org, source, reference)`; this keeps id-only posts idempotent too.
     */
    public static function forTransaction(string $transactionId): self
    {
        return new self(org: '', source: 'ledger', reference: $transactionId);
    }

    /** A stable, collision-free string form of the key (for in-memory dedupe maps). */
    public function token(): string
    {
        return $this->org.'|'.$this->source.'|'.$this->reference;
    }
}
