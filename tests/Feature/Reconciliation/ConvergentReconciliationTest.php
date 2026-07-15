<?php

declare(strict_types=1);

use Cbox\Billing\Metering\Storage\InMemoryEventLog;
use Cbox\Billing\Metering\ValueObjects\UsageEvent;
use Cbox\Billing\Reconciliation\Exceptions\NonMonotonicCheckpoint;
use Cbox\Billing\Reconciliation\ValueObjects\Checkpoint;

/** A usage event for org1/api.calls unless overridden. */
function reconcileEvent(string $id, int $value, int $at, string $org = 'org1', string $meter = 'api.calls'): UsageEvent
{
    return new UsageEvent($id, $org, $meter, 'svc', $value, $at);
}

beforeEach(function (): void {
    $this->eventLog = new InMemoryEventLog;
});

it('posts a cumulative delta against the checkpoint, not a replay', function (): void {
    $this->eventLog->append([reconcileEvent('e1', 5, 1_000), reconcileEvent('e2', 3, 2_000)]);
    $reconciler = $this->makeReconciler($this->eventLog, $this->ledger());

    $reconciler->reconcile([$this->target('org1', 'api.calls')], now: 3_000);
    expect($this->ledger()->balance('usage:org1:api.calls', 'EUR')->minor())->toBe(8);

    // A new event on the next cycle posts only the DELTA (4), not the whole sum again.
    $this->eventLog->append([reconcileEvent('e3', 4, 2_500)]);
    $report = $reconciler->reconcile([$this->target('org1', 'api.calls')], now: 3_000);

    expect($this->ledger()->balance('usage:org1:api.calls', 'EUR')->minor())->toBe(12)
        ->and($report->reconciled[0]->meterDelta)->toBe(4)
        ->and($this->checkpointStore()->checkpointFor('org1', 'api.calls')->meterTotal)->toBe(12);
});

it('clamps to now minus the ingest lag so in-flight events are not counted early', function (): void {
    $this->eventLog->append([reconcileEvent('e1', 5, 1_000)]);
    $reconciler = $this->makeReconciler($this->eventLog, $this->ledger(), ingestLagMs: 1_000);

    // now=1_500 → ceiling=500; the event at 1_000 has not "landed" yet.
    $reconciler->reconcile([$this->target('org1', 'api.calls')], now: 1_500);
    expect($this->ledger()->balance('usage:org1:api.calls', 'EUR')->minor())->toBe(0);

    // Once the clamp advances past it, the event is reconciled.
    $reconciler->reconcile([$this->target('org1', 'api.calls')], now: 2_500);
    expect($this->ledger()->balance('usage:org1:api.calls', 'EUR')->minor())->toBe(5);
});

it('catches a late, out-of-order event on the next cycle by arithmetic', function (): void {
    $this->eventLog->append([reconcileEvent('e1', 5, 1_000)]);
    $reconciler = $this->makeReconciler($this->eventLog, $this->ledger());

    $reconciler->reconcile([$this->target('org1', 'api.calls')], now: 2_000);
    expect($this->ledger()->balance('usage:org1:api.calls', 'EUR')->minor())->toBe(5);

    // A straggler lands with a timestamp BELOW the last reconciled ceiling.
    $this->eventLog->append([reconcileEvent('e_late', 7, 1_500)]);
    $reconciler->reconcile([$this->target('org1', 'api.calls')], now: 2_100);

    expect($this->ledger()->balance('usage:org1:api.calls', 'EUR')->minor())->toBe(12);
});

it('does not double-count a duplicate event', function (): void {
    $this->eventLog->append([reconcileEvent('e1', 5, 1_000)]);
    $reconciler = $this->makeReconciler($this->eventLog, $this->ledger());

    $reconciler->reconcile([$this->target('org1', 'api.calls')], now: 2_000);

    // Re-delivering the same event id is deduped by the log, so the sum is unchanged.
    expect($this->eventLog->append([reconcileEvent('e1', 5, 1_000)]))->toBe(0);
    $report = $reconciler->reconcile([$this->target('org1', 'api.calls')], now: 2_100);

    expect($this->ledger()->balance('usage:org1:api.calls', 'EUR')->minor())->toBe(5)
        ->and($report->reconciled[0]->meterDelta)->toBe(0);
});

it('attributes usage older than the window to the aged-out bucket, never dropping it', function (): void {
    $this->eventLog->append([reconcileEvent('e_old', 5, 1_000), reconcileEvent('e_recent', 3, 9_000)]);
    $reconciler = $this->makeReconciler($this->eventLog, $this->ledger(), windowMs: 1_000);

    // now=10_000 → ceiling=10_000, aged boundary=9_000. e_old (t=1_000) is aged out.
    $report = $reconciler->reconcile([$this->target('org1', 'api.calls')], now: 10_000);

    expect($this->ledger()->balance('usage:org1:api.calls', 'EUR')->minor())->toBe(3)          // live meter
        ->and($this->ledger()->balance('usage-aged-out:org1:api.calls', 'EUR')->minor())->toBe(5)  // aged, not dropped
        ->and($report->reconciled[0]->meterDelta)->toBe(3)
        ->and($report->reconciled[0]->agedDelta)->toBe(5);
});

it('keeps the checkpoint monotonic when the clock regresses', function (): void {
    $this->eventLog->append([reconcileEvent('e1', 5, 1_000)]);
    $reconciler = $this->makeReconciler($this->eventLog, $this->ledger());

    $reconciler->reconcile([$this->target('org1', 'api.calls')], now: 5_000);
    $reconciler->reconcile([$this->target('org1', 'api.calls')], now: 3_000); // clock went backwards

    $checkpoint = $this->checkpointStore()->checkpointFor('org1', 'api.calls');

    expect($checkpoint->reconciledThroughMs)->toBe(5_000)  // never regressed
        ->and($checkpoint->sequence)->toBe(1)               // the regressed cycle posted nothing
        ->and($this->ledger()->balance('usage:org1:api.calls', 'EUR')->minor())->toBe(5);
});

it('rejects a checkpoint that tries to advance backwards', function (): void {
    $checkpoint = new Checkpoint('org1', 'api.calls', 0, 5_000, 5, 0, 1);

    expect(fn (): Checkpoint => $checkpoint->advance(
        agedThroughMs: 0,
        reconciledThroughMs: 4_000, // backwards
        meterTotal: 5,
        agedTotal: 0,
        sequence: 1,
    ))->toThrow(NonMonotonicCheckpoint::class);
});

it('reports and skips one bad entity without failing the batch', function (): void {
    $this->eventLog->append([reconcileEvent('e1', 5, 1_000)]);
    $this->checkpointStore()->failWith('org2', 'api.calls', new RuntimeException('storage blew up'));

    $report = $this->makeReconciler($this->eventLog, $this->ledger())->reconcile([
        $this->target('org1', 'api.calls'),
        $this->target('org2', 'api.calls'),
    ], now: 2_000);

    expect($report->reconciled)->toHaveCount(1)
        ->and($report->reconciled[0]->target->org)->toBe('org1')
        ->and($report->skipped)->toHaveCount(1)
        ->and($report->skipped[0]->target->org)->toBe('org2')
        ->and($report->skipped[0]->error->getMessage())->toBe('storage blew up')
        ->and($this->ledger()->balance('usage:org1:api.calls', 'EUR')->minor())->toBe(5);
});

it('rethrows a concurrency error and aborts the batch', function (): void {
    $this->eventLog->append([reconcileEvent('e1', 5, 1_000, 'org2')]);
    $deadlock = new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');
    $this->checkpointStore()->failWith('org1', 'api.calls', $deadlock);

    $reconciler = $this->makeReconciler($this->eventLog, $this->ledger());

    // org1 deadlocks first → the whole batch aborts; org2 is never processed.
    expect(fn () => $reconciler->reconcile([
        $this->target('org1', 'api.calls'),
        $this->target('org2', 'api.calls'),
    ], now: 2_000))->toThrow(PDOException::class);

    expect($this->ledger()->balance('usage:org2:api.calls', 'EUR')->minor())->toBe(0);
});
