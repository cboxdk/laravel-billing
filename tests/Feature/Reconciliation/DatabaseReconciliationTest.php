<?php

declare(strict_types=1);

use Cbox\Billing\Ledger\DatabaseLedger;
use Cbox\Billing\Metering\Storage\DatabaseEventLog;
use Cbox\Billing\Metering\ValueObjects\UsageEvent;
use Cbox\Billing\Reconciliation\DefaultReconciler;
use Cbox\Billing\Reconciliation\Storage\DatabaseCheckpointStore;
use Cbox\Billing\Reconciliation\ValueObjects\ReconcileTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->db = $this->app->make('db')->connection();
    $this->eventLog = new DatabaseEventLog($this->db);
    $this->durableLedger = new DatabaseLedger($this->db);
    $this->checkpoints = new DatabaseCheckpointStore($this->db);

    // EventLog, Ledger and CheckpointStore share one connection, so a delta post and
    // its checkpoint advance commit together.
    $this->reconciler = new DefaultReconciler(
        checkpoints: $this->checkpoints,
        eventLog: $this->eventLog,
        ledger: $this->durableLedger,
        ingestLagMs: 0,
        windowMs: 30 * 86_400 * 1_000,
        currency: 'EUR',
        clock: static fn (): int => 0,
    );
});

it('reconciles durable usage into the durable ledger and persists the checkpoint', function (): void {
    $this->eventLog->append([
        new UsageEvent('e1', 'org1', 'api.calls', 'svc', 5, 1_000),
        new UsageEvent('e2', 'org1', 'api.calls', 'svc', 3, 2_000),
    ]);

    $this->reconciler->reconcile([new ReconcileTarget('org1', 'api.calls')], now: 3_000);

    expect($this->durableLedger->balance('usage:org1:api.calls', 'EUR')->minor())->toBe(8);

    $checkpoint = $this->checkpoints->load('org1', 'api.calls');
    expect($checkpoint->meterTotal)->toBe(8)
        ->and($checkpoint->reconciledThroughMs)->toBe(3_000)
        ->and($checkpoint->sequence)->toBe(1)
        ->and($this->db->table('billing_usage_checkpoints')->count())->toBe(1);
});

it('is a no-op re-run when nothing new landed', function (): void {
    $target = new ReconcileTarget('org1', 'api.calls');
    $this->eventLog->append([new UsageEvent('e1', 'org1', 'api.calls', 'svc', 5, 1_000)]);

    $this->reconciler->reconcile([$target], now: 2_000);
    $linesAfterFirst = $this->db->table('billing_ledger_lines')->count();

    // Re-run with no new usage: delta is zero, so no new ledger lines are written.
    $report = $this->reconciler->reconcile([$target], now: 2_100);

    expect($report->reconciled[0]->meterDelta)->toBe(0)
        ->and($this->db->table('billing_ledger_lines')->count())->toBe($linesAfterFirst)
        ->and($this->durableLedger->balance('usage:org1:api.calls', 'EUR')->minor())->toBe(5);
});
