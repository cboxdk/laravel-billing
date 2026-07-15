<?php

declare(strict_types=1);

namespace Cbox\Billing\Reconciliation;

use Cbox\Billing\Reconciliation\Contracts\CheckpointStore;
use Cbox\Billing\Reconciliation\Storage\DatabaseCheckpointStore;
use Cbox\Billing\Reconciliation\Testing\FakeCheckpointStore;
use Cbox\Billing\Reconciliation\ValueObjects\Checkpoint;

/**
 * In-memory {@see CheckpointStore} — the zero-config default and the base the test
 * {@see FakeCheckpointStore} extends. Single
 * process, so {@see transactionally()} just runs the mutator and stores the result;
 * the per-entity lock is implicit. Not durable — production uses
 * {@see DatabaseCheckpointStore}.
 */
class InMemoryCheckpointStore implements CheckpointStore
{
    /** @var array<string, Checkpoint> keyed by entity */
    protected array $checkpoints = [];

    public function load(string $org, string $meter): Checkpoint
    {
        return $this->checkpoints[$this->key($org, $meter)] ?? Checkpoint::genesis($org, $meter);
    }

    public function transactionally(string $org, string $meter, callable $mutator): Checkpoint
    {
        $next = $mutator($this->load($org, $meter));

        $this->checkpoints[$this->key($org, $meter)] = $next;

        return $next;
    }

    protected function key(string $org, string $meter): string
    {
        return $org.'|'.$meter;
    }
}
