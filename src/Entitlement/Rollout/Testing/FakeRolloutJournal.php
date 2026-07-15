<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement\Rollout\Testing;

use Cbox\Billing\Entitlement\Rollout\Contracts\RolloutJournal;
use Cbox\Billing\Entitlement\Rollout\Journal\InMemoryRolloutJournal;
use Cbox\Billing\Entitlement\Rollout\ValueObjects\RolloutApplication;
use Cbox\Billing\Entitlement\Rollout\ValueObjects\RolloutChunk;
use RuntimeException;
use Throwable;

/**
 * The dogfood {@see RolloutJournal} for tests:
 * the in-memory journal plus two affordances —
 *
 *  - make a chosen org throw when its application is reached ({@see failOn()}), to prove
 *    chunk atomicity: the fault lands mid-chunk, and because the base journal buffers the
 *    whole chunk before applying, NO org in that chunk is persisted (grants or audit);
 *  - inspect how many chunks committed and their sizes ({@see committedChunkSizes()}), to
 *    prove the bulk cohort was chunked at the configured size.
 *
 * A chunk is only counted committed AFTER the base commit returns, so a rolled-back chunk
 * is never counted.
 */
class FakeRolloutJournal extends InMemoryRolloutJournal
{
    /** @var array<string, Throwable> keyed by org */
    private array $faults = [];

    /** @var list<int> committed chunk sizes, in order */
    private array $committedChunkSizes = [];

    /** Make committing a chunk that includes `$org` throw — simulates a crash mid-chunk. */
    public function failOn(string $org, ?Throwable $error = null): self
    {
        $this->faults[$org] = $error ?? new RuntimeException("Simulated rollout crash for {$org}.");

        return $this;
    }

    public function commit(RolloutChunk $chunk): void
    {
        parent::commit($chunk);

        $this->committedChunkSizes[] = $chunk->size();
    }

    protected function beforeApply(RolloutApplication $application): void
    {
        $fault = $this->faults[$application->org] ?? null;

        if ($fault !== null) {
            throw $fault;
        }
    }

    /** @return list<int> the size of each chunk that committed, in order. */
    public function committedChunkSizes(): array
    {
        return $this->committedChunkSizes;
    }

    public function commitCount(): int
    {
        return count($this->committedChunkSizes);
    }
}
