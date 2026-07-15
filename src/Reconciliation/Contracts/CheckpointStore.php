<?php

declare(strict_types=1);

namespace Cbox\Billing\Reconciliation\Contracts;

use Cbox\Billing\Reconciliation\ValueObjects\Checkpoint;

/**
 * The durable store of per-entity reconciliation checkpoints (ADR-0003).
 *
 * Reconciling an entity and advancing its checkpoint must be **atomic and serialized
 * per entity**: {@see transactionally()} takes a per-entity lock, hands the mutator
 * the currently-locked checkpoint, runs the mutator (which posts the delta to the
 * ledger and returns the advanced checkpoint), and persists the result — all in one
 * transaction. Posting the ledger delta inside that same transaction is what makes a
 * swallowed deadlock dangerous, so concurrency errors are left to propagate.
 */
interface CheckpointStore
{
    /** The current checkpoint for an entity, or {@see Checkpoint::genesis()} if none. */
    public function load(string $org, string $meter): Checkpoint;

    /**
     * Run `$mutator` under a per-entity lock inside a transaction, then persist the
     * checkpoint it returns. The mutator receives the locked-current checkpoint and
     * returns the advanced one; any ledger posting it performs participates in the
     * same transaction. Concurrency/deadlock errors propagate to the caller — they
     * are never swallowed here.
     *
     * @param  callable(Checkpoint): Checkpoint  $mutator
     */
    public function transactionally(string $org, string $meter, callable $mutator): Checkpoint;
}
