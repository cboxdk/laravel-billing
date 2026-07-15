<?php

declare(strict_types=1);

use Cbox\Billing\Metering\Storage\DatabaseEventLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->log = new DatabaseEventLog($this->app->make('db')->connection());
});

it('persists, dedups and sums from durable rows', function () {
    expect($this->log->append([usageEvent('e1', 5, 1000), usageEvent('e2', 3, 2000)]))->toBe(2)
        ->and($this->log->append([usageEvent('e1', 5, 1000)]))->toBe(0)     // duplicate ignored (unique index)
        ->and($this->log->sum('org1', 'api.calls', 0, 3000))->toBe(8)
        ->and($this->log->sum('org1', 'api.calls', 0, 1500))->toBe(5);
});

it('scopes sums by organization', function () {
    $this->log->append([
        usageEvent('a', 5, 1000, 'org1'),
        usageEvent('b', 7, 1000, 'org2'),
    ]);

    expect($this->log->sum('org1', 'api.calls', 0, 2000))->toBe(5)
        ->and($this->log->sum('org2', 'api.calls', 0, 2000))->toBe(7);
});
