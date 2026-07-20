<?php

declare(strict_types=1);

namespace Cbox\Billing\Reconciliation\Storage;

use Cbox\Billing\Reconciliation\Contracts\CheckpointStore;
use Cbox\Billing\Reconciliation\ValueObjects\Checkpoint;
use Illuminate\Database\ConnectionInterface;
use stdClass;

/**
 * Durable {@see CheckpointStore}: one row per `(org, meter)` entity.
 *
 * {@see transactionally()} opens a transaction, takes a `SELECT … FOR UPDATE` lock on
 * the entity's checkpoint row, hands the mutator the locked checkpoint, and upserts
 * the advanced one — so the ledger delta the mutator posts and the checkpoint advance
 * commit together or not at all. A concurrent reconcile of the same entity blocks on
 * the row lock; a deadlock/serialization failure surfaces as a query exception and is
 * left to propagate (the reconciler rethrows it) rather than being swallowed, which
 * would leave this transaction half-rolled-back.
 *
 * Pair this with a database-backed ledger on the SAME connection so the delta post
 * and the checkpoint advance share one transaction.
 */
readonly class DatabaseCheckpointStore implements CheckpointStore
{
    private const TABLE = 'billing_usage_checkpoints';

    public function __construct(private ConnectionInterface $db) {}

    public function load(string $org, string $meter): Checkpoint
    {
        $row = $this->db->table(self::TABLE)
            ->where('org', $org)
            ->where('meter', $meter)
            ->first();

        return $row instanceof stdClass ? $this->hydrate($org, $meter, $row) : Checkpoint::genesis($org, $meter);
    }

    public function transactionally(string $org, string $meter, callable $mutator): Checkpoint
    {
        // Claim the entity's row up front with an atomic INSERT-or-no-op on the
        // UNIQUE(org, meter) index BEFORE taking the row lock: on a first-ever reconcile
        // two concurrent workers would otherwise both find no row, both plan from genesis
        // over an unlocked gap, and both post a delta (a double-count until ledger
        // idempotency catches it). Seeding the genesis row here means the SELECT … FOR
        // UPDATE below always locks a real row, so concurrent first-reconciles serialize
        // on the unique index instead of racing (mirrors DatabaseLedger / DatabaseWallet,
        // ADR-0002). A re-run writes nothing (idempotent).
        $this->db->table(self::TABLE)->insertOrIgnore($this->genesisRow($org, $meter));

        return $this->db->transaction(function () use ($org, $meter, $mutator): Checkpoint {
            $row = $this->db->table(self::TABLE)
                ->where('org', $org)
                ->where('meter', $meter)
                ->lockForUpdate()
                ->first();

            $current = $row instanceof stdClass ? $this->hydrate($org, $meter, $row) : Checkpoint::genesis($org, $meter);

            $next = $mutator($current);

            $this->db->table(self::TABLE)->updateOrInsert(
                ['org' => $org, 'meter' => $meter],
                [
                    'aged_through_ms' => $next->agedThroughMs,
                    'reconciled_through_ms' => $next->reconciledThroughMs,
                    'meter_total' => $next->meterTotal,
                    'aged_total' => $next->agedTotal,
                    'sequence' => $next->sequence,
                ],
            );

            return $next;
        });
    }

    /**
     * The all-zero genesis row for an entity, used to atomically seed the checkpoint
     * before locking it. Mirrors {@see Checkpoint::genesis()}.
     *
     * @return array<string, int|string>
     */
    private function genesisRow(string $org, string $meter): array
    {
        return [
            'org' => $org,
            'meter' => $meter,
            'aged_through_ms' => 0,
            'reconciled_through_ms' => 0,
            'meter_total' => 0,
            'aged_total' => 0,
            'sequence' => 0,
        ];
    }

    private function hydrate(string $org, string $meter, stdClass $row): Checkpoint
    {
        return new Checkpoint(
            $org,
            $meter,
            $this->intOf($row->aged_through_ms ?? 0),
            $this->intOf($row->reconciled_through_ms ?? 0),
            $this->intOf($row->meter_total ?? 0),
            $this->intOf($row->aged_total ?? 0),
            $this->intOf($row->sequence ?? 0),
        );
    }

    private function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
