<?php

declare(strict_types=1);

namespace Cbox\Billing\Reconciliation\Testing;

use Cbox\Billing\Ledger\Contracts\Ledger;
use Cbox\Billing\Metering\Contracts\EventLog;
use Cbox\Billing\Reconciliation\Contracts\CheckpointStore;
use Cbox\Billing\Reconciliation\DefaultReconciler;
use Cbox\Billing\Reconciliation\ValueObjects\ReconcileTarget;
use Closure;

/**
 * Wire up convergent reconciliation in tests:
 *
 *     $reconciler = $this->makeReconciler($eventLog, $this->ledger(), ingestLagMs: 60_000);
 *     $report = $reconciler->reconcile([$this->target('org_a', 'api.calls')], now: $now);
 *     expect($this->ledger()->balance('usage:org_a:api.calls', 'EUR')->minor())->toBe(500);
 *
 * The default window is wide (nothing ages out) so aged-out tests opt in with a small
 * `windowMs`. Reconcile is driven with an explicit `now` for deterministic clamping.
 */
trait InteractsWithReconciliation
{
    private ?FakeCheckpointStore $checkpointStoreFake = null;

    protected function checkpointStore(): FakeCheckpointStore
    {
        return $this->checkpointStoreFake ??= new FakeCheckpointStore;
    }

    protected function target(string $org, string $meter): ReconcileTarget
    {
        return new ReconcileTarget($org, $meter);
    }

    /**
     * @param  Closure(): int|null  $clock
     */
    protected function makeReconciler(
        EventLog $eventLog,
        Ledger $ledger,
        int $ingestLagMs = 0,
        int $windowMs = 30 * 86_400 * 1_000,
        string $currency = 'EUR',
        ?CheckpointStore $store = null,
        ?Closure $clock = null,
    ): DefaultReconciler {
        return new DefaultReconciler(
            checkpoints: $store ?? $this->checkpointStore(),
            eventLog: $eventLog,
            ledger: $ledger,
            ingestLagMs: $ingestLagMs,
            windowMs: $windowMs,
            currency: $currency,
            clock: $clock ?? static fn (): int => 0,
        );
    }
}
