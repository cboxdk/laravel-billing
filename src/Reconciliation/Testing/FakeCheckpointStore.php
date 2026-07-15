<?php

declare(strict_types=1);

namespace Cbox\Billing\Reconciliation\Testing;

use Cbox\Billing\Reconciliation\Contracts\CheckpointStore;
use Cbox\Billing\Reconciliation\InMemoryCheckpointStore;
use Cbox\Billing\Reconciliation\ValueObjects\Checkpoint;
use Throwable;

/**
 * The dogfood {@see CheckpointStore} for tests:
 * the in-memory store plus two affordances — inspect an entity's checkpoint, and make
 * a chosen entity throw when reconciled, to prove the batch-isolation guard (a generic
 * error is reported and skipped; a concurrency error rethrows).
 */
class FakeCheckpointStore extends InMemoryCheckpointStore
{
    /** @var array<string, Throwable> keyed by entity */
    private array $failures = [];

    /** Make reconciling `(org, meter)` throw `$error` — simulates a per-entity fault. */
    public function failWith(string $org, string $meter, Throwable $error): self
    {
        $this->failures[$this->key($org, $meter)] = $error;

        return $this;
    }

    /** The stored checkpoint for an entity (genesis if never reconciled). */
    public function checkpointFor(string $org, string $meter): Checkpoint
    {
        return $this->load($org, $meter);
    }

    public function transactionally(string $org, string $meter, callable $mutator): Checkpoint
    {
        $fault = $this->failures[$this->key($org, $meter)] ?? null;

        if ($fault !== null) {
            throw $fault;
        }

        return parent::transactionally($org, $meter, $mutator);
    }
}
