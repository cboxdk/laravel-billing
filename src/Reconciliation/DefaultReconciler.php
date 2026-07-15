<?php

declare(strict_types=1);

namespace Cbox\Billing\Reconciliation;

use Cbox\Billing\Ledger\Contracts\Ledger;
use Cbox\Billing\Ledger\Enums\Direction;
use Cbox\Billing\Ledger\ValueObjects\LedgerLine;
use Cbox\Billing\Ledger\ValueObjects\LedgerTransaction;
use Cbox\Billing\Ledger\ValueObjects\PostingKey;
use Cbox\Billing\Metering\Contracts\EventLog;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Reconciliation\Contracts\CheckpointStore;
use Cbox\Billing\Reconciliation\Contracts\Reconciler;
use Cbox\Billing\Reconciliation\ValueObjects\Checkpoint;
use Cbox\Billing\Reconciliation\ValueObjects\EntityReconciliation;
use Cbox\Billing\Reconciliation\ValueObjects\ReconcileFailure;
use Cbox\Billing\Reconciliation\ValueObjects\ReconcileReport;
use Cbox\Billing\Reconciliation\ValueObjects\ReconcileTarget;
use Closure;
use Illuminate\Database\DetectsConcurrencyErrors;
use Throwable;

/**
 * Convergent reconciliation (ADR-0003). For each entity it derives the current
 * cumulative usage from the durable {@see EventLog}, subtracts the prior checkpoint
 * total, and posts the **delta** to the {@see Ledger} — never a per-event replay, so
 * it needs no exactly-once or in-order delivery from the async usage pipeline.
 *
 * Per cycle, for `(org, meter)` at wall-clock `now` (ms epoch):
 *
 * 1. **Ingest-lag clamp** — `ceiling = now − ingestLag`, so in-flight events that
 *    have not fully landed are not counted early (they are caught next cycle, once the
 *    ceiling has advanced past them).
 * 2. **Aged-out boundary** — `agedThrough = ceiling − window`. Usage at or above it is
 *    the live meter bucket; usage below it is attributed to the `aged_out` bucket,
 *    never dropped. Both bounds are clamped monotonic against the checkpoint.
 * 3. **Delta** — `meterDelta = sum(agedThrough, ceiling) − checkpoint.meterTotal` and
 *    `agedDelta = sum(0, agedThrough−1) − checkpoint.agedTotal`. Each delta is posted
 *    to its bucket (a `meterDelta` can be negative when usage ages out of the live
 *    window into the aged bucket — a proper double-entry re-attribution). The
 *    checkpoint then advances.
 *
 * Because every cycle posts `currentCumulative − alreadyPosted`, the ledger total
 * always converges to the event log's cumulative usage: a late event raises the sum
 * and is posted next cycle; a duplicate is deduped by the event log so the sum is
 * unchanged and nothing double-counts; an out-of-order event is just another
 * contribution to the same sum. Idempotency (ADR-0002) is on the natural
 * {@see PostingKey} `(org, 'reconcile', "<meter>:<bucket>:<sequence>")` — a retried
 * cycle recomputes the same delta under the same key and re-posting is a no-op.
 */
readonly class DefaultReconciler implements Reconciler
{
    use DetectsConcurrencyErrors;

    /** @param  Closure(): int  $clock  wall-clock in ms epoch */
    public function __construct(
        private CheckpointStore $checkpoints,
        private EventLog $eventLog,
        private Ledger $ledger,
        private int $ingestLagMs,
        private int $windowMs,
        private string $currency,
        private Closure $clock,
    ) {}

    public function reconcile(iterable $targets, ?int $now = null): ReconcileReport
    {
        $at = $now ?? ($this->clock)();
        $reconciled = [];
        $skipped = [];

        foreach ($targets as $target) {
            $outcome = null;

            try {
                $this->checkpoints->transactionally(
                    $target->org,
                    $target->meter,
                    function (Checkpoint $checkpoint) use ($target, $at, &$outcome): Checkpoint {
                        [$next, $outcome] = $this->reconcileEntity($checkpoint, $target, $at);

                        return $next;
                    },
                );
            } catch (Throwable $e) {
                // A deadlock/serialization failure must NOT be swallowed: it would
                // leave the outer transaction half-rolled-back. Rethrow to abort the
                // batch. Any other per-entity error is reported and skipped so one bad
                // entity cannot fail the whole batch (ADR-0003).
                if ($this->causedByConcurrencyError($e)) {
                    throw $e;
                }

                $skipped[] = new ReconcileFailure($target, $e);

                continue;
            }

            if ($outcome instanceof EntityReconciliation) {
                $reconciled[] = $outcome;
            }
        }

        return new ReconcileReport($reconciled, $skipped);
    }

    /**
     * Compute and post one entity's delta, returning the advanced checkpoint and the
     * outcome. Runs inside the store's per-entity locked transaction.
     *
     * @return array{0: Checkpoint, 1: EntityReconciliation}
     */
    private function reconcileEntity(Checkpoint $checkpoint, ReconcileTarget $target, int $now): array
    {
        // Ingest-lag clamp and aged-out boundary, both monotonic against the checkpoint.
        $ceiling = max($checkpoint->reconciledThroughMs, $now - $this->ingestLagMs);
        $agedThrough = max($checkpoint->agedThroughMs, $ceiling - $this->windowMs);

        // Current cumulative usage, split at the aged-out boundary.
        $meterTotal = $this->eventLog->sum($target->org, $target->meter, $agedThrough, $ceiling);
        $agedTotal = $agedThrough > 0
            ? $this->eventLog->sum($target->org, $target->meter, 0, $agedThrough - 1)
            : 0;

        $meterDelta = $meterTotal - $checkpoint->meterTotal;
        $agedDelta = $agedTotal - $checkpoint->agedTotal;

        $sequence = $checkpoint->sequence;

        if ($meterDelta !== 0) {
            $this->postDelta($target, 'meter', $meterDelta, $sequence, $ceiling);
        }

        if ($agedDelta !== 0) {
            $this->postDelta($target, 'aged_out', $agedDelta, $sequence, $ceiling);
        }

        $posted = $meterDelta !== 0 || $agedDelta !== 0;

        $next = $checkpoint->advance(
            agedThroughMs: $agedThrough,
            reconciledThroughMs: $ceiling,
            meterTotal: $meterTotal,
            agedTotal: $agedTotal,
            sequence: $posted ? $sequence + 1 : $sequence,
        );

        $outcome = new EntityReconciliation(
            target: $target,
            meterDelta: $meterDelta,
            agedDelta: $agedDelta,
            meterTotal: $meterTotal,
            agedTotal: $agedTotal,
            reconciledThroughMs: $ceiling,
            agedThroughMs: $agedThrough,
        );

        return [$next, $outcome];
    }

    /**
     * Post a signed usage delta to a bucket as a balanced transfer between the usage
     * account and the contra clearing account, idempotently on the natural key. A
     * positive delta accrues usage (debit the usage account); a negative delta
     * reverses it (e.g. usage aging out of the meter bucket).
     */
    private function postDelta(ReconcileTarget $target, string $bucket, int $delta, int $sequence, int $occurredAt): void
    {
        $amount = Money::ofMinor(abs($delta), $this->currency);

        $usageAccount = $bucket === 'aged_out'
            ? "usage-aged-out:{$target->org}:{$target->meter}"
            : "usage:{$target->org}:{$target->meter}";
        $clearingAccount = "usage-clearing:{$target->org}:{$target->meter}";

        [$debit, $credit] = $delta > 0
            ? [$usageAccount, $clearingAccount]
            : [$clearingAccount, $usageAccount];

        $this->ledger->post(new LedgerTransaction(
            id: "rec:{$target->org}:{$target->meter}:{$bucket}:{$sequence}",
            lines: [
                new LedgerLine($debit, Direction::Debit, $amount),
                new LedgerLine($credit, Direction::Credit, $amount),
            ],
            memo: "reconcile:{$bucket}:{$target->meter}",
            occurredAt: $occurredAt,
            key: new PostingKey($target->org, 'reconcile', "{$target->meter}:{$bucket}:{$sequence}"),
        ));
    }
}
