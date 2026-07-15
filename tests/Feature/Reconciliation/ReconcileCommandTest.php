<?php

declare(strict_types=1);

use Cbox\Billing\Ledger\Contracts\Ledger;
use Cbox\Billing\Metering\Contracts\EventLog;
use Cbox\Billing\Metering\ValueObjects\UsageEvent;

it('reconciles the given targets through the container-wired command', function (): void {
    // The bound EventLog / Ledger / CheckpointStore are the zero-config in-memory
    // defaults, so seeding the resolved EventLog is enough to drive the command.
    // Timestamp the event a couple of minutes ago: past the ingest-lag clamp yet
    // well inside the reconcile window, so it lands in the live meter bucket.
    $recent = (int) (microtime(true) * 1_000) - 120_000;
    $this->app->make(EventLog::class)->append([
        new UsageEvent('e1', 'org1', 'api.calls', 'svc', 5, $recent),
    ]);

    $this->artisan('billing:reconcile', ['--target' => ['org1:api.calls']])
        ->assertSuccessful();

    expect($this->app->make(Ledger::class)->balance('usage:org1:api.calls', 'EUR')->minor())->toBe(5);
});

it('warns and succeeds when no targets are given', function (): void {
    $this->artisan('billing:reconcile')
        ->expectsOutputToContain('Nothing to reconcile')
        ->assertSuccessful();
});
