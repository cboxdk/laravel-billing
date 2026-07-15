<?php

declare(strict_types=1);

namespace Cbox\Billing\Reconciliation\ValueObjects;

use Cbox\Billing\Reconciliation\Exceptions\NonMonotonicCheckpoint;

/**
 * The durable per-entity reconciliation checkpoint (ADR-0003). It records how far a
 * `(org, meter)` entity has been reconciled and how much cumulative usage has already
 * been posted to the ledger, so the next cycle posts a **delta**, never a replay.
 *
 * The reconciled region is split at {@see $agedThroughMs}:
 *
 * - `[agedThroughMs, reconciledThroughMs]` — the live window; its cumulative total is
 *   {@see $meterTotal}, posted to the meter bucket.
 * - `[0, agedThroughMs)` — older than the reconcile window; its cumulative total is
 *   {@see $agedTotal}, posted to the `aged_out` bucket, never dropped.
 *
 * Convergence invariant: `meterTotal + agedTotal` equals the durable log's cumulative
 * usage up to `reconciledThroughMs`. Advancing only ever moves the high-water marks
 * forward (the ceiling, the aged boundary, the aged total, and the posting sequence
 * are monotonic); {@see $meterTotal} may fall as usage ages out of the live window
 * into the aged bucket, which is a re-attribution, not a loss.
 */
readonly class Checkpoint
{
    public function __construct(
        public string $org,
        public string $meter,
        /** Events with `occurredAt < agedThroughMs` are attributed to the aged-out bucket. */
        public int $agedThroughMs,
        /** The upper bound (ms epoch) reconciled so far — the ingest-lag-clamped ceiling. */
        public int $reconciledThroughMs,
        /** Cumulative usage posted to the meter bucket for `[agedThroughMs, reconciledThroughMs]`. */
        public int $meterTotal,
        /** Cumulative usage posted to the aged-out bucket for `[0, agedThroughMs)`. */
        public int $agedTotal,
        /** Monotonic count of cycles that posted a delta — the natural-key discriminator (ADR-0002). */
        public int $sequence,
    ) {}

    /** The zero checkpoint for an entity that has never been reconciled. */
    public static function genesis(string $org, string $meter): self
    {
        return new self($org, $meter, 0, 0, 0, 0, 0);
    }

    /**
     * Return the advanced checkpoint, rejecting any backwards move of a monotonic
     * bound. The reconciler guarantees monotonicity by clamping with `max(...)`, so
     * this guard only ever catches a programming error.
     *
     * @throws NonMonotonicCheckpoint
     */
    public function advance(
        int $agedThroughMs,
        int $reconciledThroughMs,
        int $meterTotal,
        int $agedTotal,
        int $sequence,
    ): self {
        $this->assertForward('reconciled ceiling', $this->reconciledThroughMs, $reconciledThroughMs);
        $this->assertForward('aged-out boundary', $this->agedThroughMs, $agedThroughMs);
        $this->assertForward('aged-out total', $this->agedTotal, $agedTotal);
        $this->assertForward('posting sequence', $this->sequence, $sequence);

        return new self(
            $this->org,
            $this->meter,
            $agedThroughMs,
            $reconciledThroughMs,
            $meterTotal,
            $agedTotal,
            $sequence,
        );
    }

    private function assertForward(string $bound, int $from, int $to): void
    {
        if ($to < $from) {
            throw new NonMonotonicCheckpoint(
                "Checkpoint [{$this->org}/{$this->meter}] cannot move its {$bound} backwards: {$from} → {$to}.",
            );
        }
    }
}
