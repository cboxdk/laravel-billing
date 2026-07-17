<?php

declare(strict_types=1);

use Cbox\Billing\Money\Money;
use Cbox\Billing\Subscription\SubscriptionManager;
use Cbox\Billing\Subscription\ValueObjects\BillingPeriod;
use Cbox\Billing\Subscription\ValueObjects\RampSchedule;
use Cbox\Billing\Subscription\ValueObjects\RampStep;

/** The month period starting `$monthsFromSep2025` months after 2025-09-01. */
function rampPeriod(int $monthsFromSep2025): BillingPeriod
{
    $start = new DateTimeImmutable('2025-09-01');
    $start = $start->modify("+{$monthsFromSep2025} months");
    $end = $start->modify('+1 month');

    return new BillingPeriod($start, $end);
}

beforeEach(function (): void {
    $this->manager = new SubscriptionManager;

    // 100.00 for periods 0–2, then 150.00 from period 3 onward.
    $this->ramp = new RampSchedule([
        new RampStep(0, Money::ofMinor(10000, 'USD')),
        new RampStep(3, Money::ofMinor(15000, 'USD')),
    ]);
});

it('resolves the ramp step covering a period index', function (): void {
    expect($this->ramp->amountForPeriod(0)->minor())->toBe(10000)
        ->and($this->ramp->amountForPeriod(1)->minor())->toBe(10000)
        ->and($this->ramp->amountForPeriod(2)->minor())->toBe(10000)
        ->and($this->ramp->amountForPeriod(3)->minor())->toBe(15000)
        ->and($this->ramp->amountForPeriod(4)->minor())->toBe(15000)
        ->and($this->ramp->amountForPeriod(11)->minor())->toBe(15000);
});

it('charges 100 before the ramp boundary then 150 after it across successive renewals', function (): void {
    $sub = $this->manager->withRamp(
        $this->manager->create('sub_1', 'org_1', 'pro', 'pro-v1', rampPeriod(0)),
        $this->ramp,
    );

    $charged = [];
    $charged[] = $sub->effectiveRecurringAmount()?->minor(); // period 0

    for ($i = 1; $i <= 4; $i++) {
        $sub = $this->manager->renew($sub, rampPeriod($i));
        expect($sub->periodIndex)->toBe($i);
        $charged[] = $sub->effectiveRecurringAmount()?->minor();
    }

    // periods 0,1,2 → 100.00; periods 3,4 → 150.00
    expect($charged)->toBe([10000, 10000, 10000, 15000, 15000]);
});

it('rejects a ramp that does not open at period 0', function (): void {
    expect(fn () => new RampSchedule([new RampStep(2, Money::ofMinor(10000, 'USD'))]))
        ->toThrow(InvalidArgumentException::class);
});
