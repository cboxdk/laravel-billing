<?php

declare(strict_types=1);

use Cbox\Billing\Metering\Enums\Aggregation;
use Cbox\Billing\Metering\Storage\DatabaseEventLog;
use Cbox\Billing\Metering\ValueObjects\UsageEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->log = new DatabaseEventLog($this->app->make('db')->connection());
    $this->log->append([
        new UsageEvent('e1', 'org1', 'seats', 'svc', 10, 1000, 'u1', 2),
        new UsageEvent('e2', 'org1', 'seats', 'svc', 30, 2000, 'u2', 1),
        new UsageEvent('e3', 'org1', 'seats', 'svc', 20, 3000, 'u1', 3),
        new UsageEvent('e4', 'org1', 'seats', 'svc', 5, 4000, null, 1),
    ]);
});

it('computes each billable-metric aggregation in the database', function () {
    expect($this->log->aggregate('org1', 'seats', 0, 5000, Aggregation::Count))->toBe(4)
        ->and($this->log->aggregate('org1', 'seats', 0, 5000, Aggregation::Sum))->toBe(65)
        ->and($this->log->aggregate('org1', 'seats', 0, 5000, Aggregation::Max))->toBe(30)
        ->and($this->log->aggregate('org1', 'seats', 0, 5000, Aggregation::UniqueCount))->toBe(2)
        ->and($this->log->aggregate('org1', 'seats', 0, 5000, Aggregation::Latest))->toBe(5)
        ->and($this->log->aggregate('org1', 'seats', 0, 5000, Aggregation::WeightedSum))->toBe(115);
});

it('keeps sum() working through aggregate(Sum) on durable rows', function () {
    expect($this->log->sum('org1', 'seats', 0, 5000))->toBe(65)
        ->and($this->log->sum('org1', 'seats', 0, 2500))->toBe(40)
        ->and($this->log->aggregate('org1', 'seats', 0, 5000, Aggregation::Sum))->toBe(65);
});

it('defaults weight to 1 for rows written without one', function () {
    // A plain append with no weight/uniqueKey → weight 1, so WeightedSum == Sum.
    $this->log->append([new UsageEvent('p1', 'org2', 'api', 'svc', 7, 1000)]);

    expect($this->log->aggregate('org2', 'api', 0, 5000, Aggregation::WeightedSum))->toBe(7)
        ->and($this->log->aggregate('org2', 'api', 0, 5000, Aggregation::UniqueCount))->toBe(0);
});
