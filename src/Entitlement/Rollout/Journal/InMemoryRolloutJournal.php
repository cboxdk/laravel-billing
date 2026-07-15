<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement\Rollout\Journal;

use Cbox\Billing\Entitlement\Resolvers\EntitlementMeterPolicyResolver;
use Cbox\Billing\Entitlement\Rollout\Contracts\RolloutJournal;
use Cbox\Billing\Entitlement\Rollout\ValueObjects\RolloutApplication;
use Cbox\Billing\Entitlement\Rollout\ValueObjects\RolloutAuditRow;
use Cbox\Billing\Entitlement\Rollout\ValueObjects\RolloutChunk;
use Cbox\Billing\Metering\ValueObjects\MeterPolicy;

/**
 * In-memory {@see RolloutJournal} — the zero-config default and the base the testing fake
 * extends. It applies grants into the same {@see EntitlementMeterPolicyResolver} the
 * metering enforcer reads through, so a rollout write is visible to resolution exactly as
 * in production, and records one audit row per `(rolloutId, org)`.
 *
 * ATOMICITY: {@see commit()} BUFFERS the whole chunk before touching the resolver or the
 * audit map, so a fault raised part-way through the chunk (the fake injects one to model a
 * crash) discards the partial work — the chunk is all-or-nothing, the single-process
 * analogue of the database transaction a durable journal would open. IDEMPOTENCY: audit
 * rows are keyed by `(rolloutId, org)`, so a re-run overwrites in place and never
 * duplicates; resolver grants are upserts by `(org, meter)`.
 */
class InMemoryRolloutJournal implements RolloutJournal
{
    /** @var array<string, RolloutAuditRow> keyed by "rolloutId|org" */
    protected array $audit = [];

    public function __construct(protected readonly EntitlementMeterPolicyResolver $resolver) {}

    public function commit(RolloutChunk $chunk): void
    {
        /** @var list<array{string, string, MeterPolicy}> $grants */
        $grants = [];
        /** @var array<string, RolloutAuditRow> $rows */
        $rows = [];

        // Buffer first: if beforeApply() throws mid-chunk, nothing below has run, so no
        // org in the chunk is left half-applied.
        foreach ($chunk->applications as $application) {
            $this->beforeApply($application);

            foreach ($application->grants as $meter => $policy) {
                $grants[] = [$application->org, $meter, $policy];
            }

            $rows[$this->key($chunk->rolloutId, $application->org)] = new RolloutAuditRow(
                $chunk->rolloutId,
                $chunk->plan,
                $application->org,
                $application->via,
            );
        }

        // Commit the buffered work as a unit.
        foreach ($grants as [$org, $meter, $policy]) {
            $this->resolver->grant($org, $meter, $policy);
        }

        foreach ($rows as $rowKey => $row) {
            $this->audit[$rowKey] = $row;
        }
    }

    /**
     * Extension hook for the testing fake to simulate a crash mid-chunk. A no-op here, so
     * the shipped journal always commits the whole chunk.
     */
    protected function beforeApply(RolloutApplication $application): void
    {
        // Intentionally does nothing.
    }

    /** @return list<RolloutAuditRow> every audit row committed, in insertion order. */
    public function auditRows(): array
    {
        return array_values($this->audit);
    }

    /** The audit row for one `(rolloutId, org)`, or null if the rollout never touched it. */
    public function auditFor(string $rolloutId, string $org): ?RolloutAuditRow
    {
        return $this->audit[$this->key($rolloutId, $org)] ?? null;
    }

    private function key(string $rolloutId, string $org): string
    {
        return $rolloutId.'|'.$org;
    }
}
