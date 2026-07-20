<?php

declare(strict_types=1);

use Cbox\Billing\Metering\DefaultMeterIngest;
use Cbox\Billing\Metering\Storage\InMemoryEventLog;
use Cbox\Billing\Metering\ValueObjects\UsageEvent;

function usageEvent(string $id, int $value, int $at, string $org = 'org1', string $meter = 'api.calls'): UsageEvent
{
    return new UsageEvent($id, $org, $meter, 'svc', $value, $at);
}

it('dedups by event id and sums within a window (in-memory)', function () {
    $log = new InMemoryEventLog;

    expect($log->append([usageEvent('e1', 5, 1000), usageEvent('e2', 3, 2000)]))->toBe(2)
        ->and($log->append([usageEvent('e1', 5, 1000)]))->toBe(0)          // duplicate ignored
        ->and($log->sum('org1', 'api.calls', 0, 3000))->toBe(8)
        ->and($log->sum('org1', 'api.calls', 0, 1500))->toBe(5)            // window excludes e2
        ->and($log->sum('org1', 'other', 0, 3000))->toBe(0);              // other meter
});

it('counts a boundary-ms event once across two adjacent half-open periods (in-memory)', function () {
    $log = new InMemoryEventLog;
    // An event sitting exactly on the shared boundary ms of two adjacent billing periods
    // [1000, 2000) and [2000, 3000). Half-open [start, end) => it belongs to the SECOND
    // period only, never both (which would double-bill usage/overage at the boundary).
    $log->append([usageEvent('boundary', 7, 2000)]);

    expect($log->sum('org1', 'api.calls', 1000, 2000))->toBe(0)   // [1000, 2000): excludes the boundary event
        ->and($log->sum('org1', 'api.calls', 2000, 3000))->toBe(7) // [2000, 3000): includes it, exactly once
        ->and($log->sum('org1', 'api.calls', 1000, 2000) + $log->sum('org1', 'api.calls', 2000, 3000))->toBe(7);
});

it('ingests idempotently through the event log', function () {
    $ingest = new DefaultMeterIngest($log = new InMemoryEventLog);

    expect($ingest->ingest([usageEvent('e1', 5, 1000)]))->toBe(1)
        ->and($ingest->ingest([usageEvent('e1', 5, 1000)]))->toBe(0)       // retry, no double-count
        ->and($log->sum('org1', 'api.calls', 0, 2000))->toBe(5);
});
