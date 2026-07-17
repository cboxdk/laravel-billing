<?php

declare(strict_types=1);

use Cbox\Billing\Catalog\Enums\PricingModel;
use Cbox\Billing\Catalog\ValueObjects\Price;
use Cbox\Billing\Catalog\ValueObjects\PriceTier;
use Cbox\Billing\Metering\BillableUsageResolver;
use Cbox\Billing\Metering\Enums\Aggregation;
use Cbox\Billing\Metering\Enums\OverageBehaviour;
use Cbox\Billing\Metering\Storage\InMemoryEventLog;
use Cbox\Billing\Metering\ValueObjects\MeterPolicy;
use Cbox\Billing\Metering\ValueObjects\UsageEvent;
use Cbox\Billing\Money\Money;

/**
 * A meter whose events exercise every aggregation, over window [0, 5000]:
 *   e1  value 10  @1000  key u1  weight 2
 *   e2  value 30  @2000  key u2  weight 1
 *   e3  value 20  @3000  key u1  weight 3   (u1 repeated → not a new distinct key)
 *   e4  value  5  @4000  key null weight 1   (latest by timestamp)
 *
 * @return list<UsageEvent>
 */
function seatEvents(): array
{
    return [
        new UsageEvent('e1', 'org1', 'seats', 'svc', 10, 1000, 'u1', 2),
        new UsageEvent('e2', 'org1', 'seats', 'svc', 30, 2000, 'u2', 1),
        new UsageEvent('e3', 'org1', 'seats', 'svc', 20, 3000, 'u1', 3),
        new UsageEvent('e4', 'org1', 'seats', 'svc', 5, 4000, null, 1),
    ];
}

it('computes each billable-metric aggregation (in-memory)', function () {
    $log = new InMemoryEventLog;
    $log->append(seatEvents());

    expect($log->aggregate('org1', 'seats', 0, 5000, Aggregation::Count))->toBe(4)
        ->and($log->aggregate('org1', 'seats', 0, 5000, Aggregation::Sum))->toBe(65)          // 10+30+20+5
        ->and($log->aggregate('org1', 'seats', 0, 5000, Aggregation::Max))->toBe(30)          // largest value
        ->and($log->aggregate('org1', 'seats', 0, 5000, Aggregation::UniqueCount))->toBe(2)   // {u1, u2}
        ->and($log->aggregate('org1', 'seats', 0, 5000, Aggregation::Latest))->toBe(5)        // e4 @4000
        ->and($log->aggregate('org1', 'seats', 0, 5000, Aggregation::WeightedSum))->toBe(115); // 20+30+60+5
});

it('routes sum() through aggregate(Sum) and stays window/meter scoped', function () {
    $log = new InMemoryEventLog;
    $log->append(seatEvents());

    expect($log->sum('org1', 'seats', 0, 5000))->toBe(65)
        ->and($log->sum('org1', 'seats', 0, 2500))->toBe(40)          // e1 + e2 only
        ->and($log->sum('org1', 'other', 0, 5000))->toBe(0);         // other meter
});

it('yields zero on an empty window, including Max and Latest', function () {
    $log = new InMemoryEventLog;

    expect($log->aggregate('org1', 'seats', 0, 5000, Aggregation::Count))->toBe(0)
        ->and($log->aggregate('org1', 'seats', 0, 5000, Aggregation::Sum))->toBe(0)
        ->and($log->aggregate('org1', 'seats', 0, 5000, Aggregation::Max))->toBe(0)
        ->and($log->aggregate('org1', 'seats', 0, 5000, Aggregation::Latest))->toBe(0)
        ->and($log->aggregate('org1', 'seats', 0, 5000, Aggregation::UniqueCount))->toBe(0)
        ->and($log->aggregate('org1', 'seats', 0, 5000, Aggregation::WeightedSum))->toBe(0);
});

it('resolves the billable quantity per the meter policy aggregation', function () {
    $log = new InMemoryEventLog;
    $log->append(seatEvents());
    $resolver = new BillableUsageResolver($log);

    $summed = MeterPolicy::metered(0, 1.0, OverageBehaviour::Bill, Aggregation::Sum);
    $peak = MeterPolicy::metered(0, 1.0, OverageBehaviour::Bill, Aggregation::Max);
    $unique = MeterPolicy::metered(0, 1.0, OverageBehaviour::Bill, Aggregation::UniqueCount);

    expect($resolver->quantity('org1', 'seats', 0, 5000, $summed))->toBe(65)
        ->and($resolver->quantity('org1', 'seats', 0, 5000, $peak))->toBe(30)
        ->and($resolver->quantity('org1', 'seats', 0, 5000, $unique))->toBe(2);
});

it('prices aggregated usage end-to-end: events → aggregate → tiered price → Money', function () {
    $log = new InMemoryEventLog;
    $log->append(seatEvents());
    $resolver = new BillableUsageResolver($log);

    // Graduated tiers: 0–50 @ 1.00/unit, 51+ @ 0.50/unit.
    $price = new Price(
        'seats-graduated',
        'seats-plan',
        PricingModel::Graduated,
        Money::ofMinor(0, 'EUR'),
        new DateTimeImmutable('2025-01-01'),
        tiers: [
            new PriceTier(50, Money::ofMinor(100, 'EUR')),
            new PriceTier(null, Money::ofMinor(50, 'EUR')),
        ],
    );

    // Sum aggregation → 65 units → 50×100 + 15×50 = 5000 + 750 = 5750
    $policy = MeterPolicy::metered(0, 1.0, OverageBehaviour::Bill, Aggregation::Sum);
    $charge = $resolver->charge('org1', 'seats', 0, 5000, $policy, $price);

    expect($charge->minor())->toBe(5_750)
        ->and($charge->currency())->toBe('EUR');
});
