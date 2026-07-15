<?php

declare(strict_types=1);

use Cbox\Billing\Money\Money;
use Cbox\Billing\Quote\ValueObjects\QuoteContext;
use Cbox\Billing\Subscription\Enums\AnchorMode;
use Cbox\Billing\Subscription\Enums\CreditGrantMode;
use Cbox\Billing\Subscription\PlanChange\CreditConsequenceCalculator;
use Cbox\Billing\Subscription\PlanChange\PlanChangePreviewer;
use Cbox\Billing\Subscription\PlanChange\ProratedAllotment;
use Cbox\Billing\Subscription\PlanChange\ValueObjects\CreditConsequenceRequest;
use Cbox\Billing\Subscription\ValueObjects\BillingPeriod;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\ValueObjects\SellerRegistrations;

beforeEach(function (): void {
    $this->calculator = new CreditConsequenceCalculator;
    $this->geo = $this->app->make(JurisdictionRepository::class);
    $this->previewer = $this->app->make(PlanChangePreviewer::class);

    // 30-day cycle; mid-cycle leaves 15 of 30 days to run.
    $this->period = new BillingPeriod(new DateTimeImmutable('2025-09-01'), new DateTimeImmutable('2025-10-01'));
    $this->mid = new DateTimeImmutable('2025-09-16');

    $this->ctx = fn (): QuoteContext => new QuoteContext(
        place: $this->geo->find(new CountryCode('DK')),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('DK')),
    );

    $this->fromPlan = $this->plan('pro', family: 'standard');
    $this->toPlan = $this->plan('pro-plus', family: 'standard');
});

it('full-reset (default) grants the whole incoming allotment and forfeits the outgoing', function (): void {
    $delta = $this->calculator->forSwitch(
        new CreditConsequenceRequest(outgoingAllotmentRemaining: 30_000, incomingAllotment: 100_000),
        carryOver: false,
        mode: CreditGrantMode::FullReset,
        remainingDays: 15,
        totalDays: 30,
    );

    // The customer gets the new limit immediately; money proration charged the prorated capacity.
    expect($delta->granted)->toBe(100_000)
        ->and($delta->forfeited)->toBe(30_000)
        ->and($delta->net())->toBe(70_000);
});

it('prorated mode grants only the remaining days share, remainder-safe (never over-granting)', function (): void {
    $delta = $this->calculator->forSwitch(
        new CreditConsequenceRequest(outgoingAllotmentRemaining: 30_000, incomingAllotment: 100_000),
        carryOver: false,
        mode: CreditGrantMode::Prorated,
        remainingDays: 15,
        totalDays: 30,
    );

    // 100_000 split one unit per day of the cycle, the remaining 15 days summed.
    expect($delta->granted)->toBe(49_995)
        ->and($delta->granted)->toBeLessThanOrEqual(50_000) // never over-grants naive 100_000*15/30
        ->and($delta->forfeited)->toBe(30_000)
        ->and($delta->net())->toBe(19_995);
});

it('proves the prorated share is remainder-safe: elapsed prefix + remaining suffix reconstruct the allotment', function (): void {
    foreach ([[100_000, 15, 30], [1_000, 3, 7], [1_200_000, 107, 365], [50_000, 1, 31]] as [$allotment, $remaining, $total]) {
        $perDay = Money::allocate($allotment, $total);           // one unit per day, sums to exactly the allotment
        $elapsed = $total - $remaining;

        $remainingShare = ProratedAllotment::remainingShare($allotment, $remaining, $total);
        $elapsedShare = array_sum(array_slice($perDay, 0, $elapsed));

        expect(array_sum($perDay))->toBe($allotment)                       // the split loses nothing
            ->and($remainingShare)->toBe(array_sum(array_slice($perDay, $elapsed))) // grant is the remaining suffix
            ->and($elapsedShare + $remainingShare)->toBe($allotment);      // prefix + suffix reconstruct the whole
    }
});

it('prorated mode grants the whole allotment for a full (or degenerate) remainder', function (): void {
    expect(ProratedAllotment::remainingShare(100_000, 30, 30))->toBe(100_000) // full remainder
        ->and(ProratedAllotment::remainingShare(100_000, 40, 30))->toBe(100_000) // clamped: pre-start
        ->and(ProratedAllotment::remainingShare(100_000, 0, 30))->toBe(0)         // none remaining
        ->and(ProratedAllotment::remainingShare(100_000, 15, 0))->toBe(100_000);  // zero-length cycle
});

it('a mid-cycle upgrade preview surfaces both the money delta and the credit delta (full reset)', function (): void {
    $preview = $this->previewer->preview(
        $this->fromPlan,
        $this->toPlan,
        Money::ofMinor(5000, 'EUR'),
        Money::ofMinor(6000, 'EUR'),
        $this->period,
        $this->mid,
        ($this->ctx)(),
        new CreditConsequenceRequest(outgoingAllotmentRemaining: 30_000, incomingAllotment: 100_000),
    );

    // Money delta (prorated ADR-0007) and credit delta (full reset ADR-0011/0012) side by side.
    expect($preview->isUpgrade)->toBeTrue()
        ->and($preview->proratedNet->minor())->toBe(500)         // (6000-5000) * 15/30
        ->and($preview->creditDelta->granted)->toBe(100_000)
        ->and($preview->creditDelta->forfeited)->toBe(30_000);
});

it('a mid-cycle upgrade preview honours the prorated credit mode', function (): void {
    $preview = $this->previewer->preview(
        $this->fromPlan,
        $this->toPlan,
        Money::ofMinor(5000, 'EUR'),
        Money::ofMinor(6000, 'EUR'),
        $this->period,
        $this->mid,
        ($this->ctx)(),
        new CreditConsequenceRequest(outgoingAllotmentRemaining: 30_000, incomingAllotment: 100_000),
        creditMode: CreditGrantMode::Prorated,
    );

    expect($preview->creditDelta->granted)->toBe(49_995)
        ->and($preview->proratedNet->minor())->toBe(500);
});

it('a deferred downgrade changes the allotment at period end: nothing forfeited now', function (): void {
    $preview = $this->previewer->preview(
        $this->fromPlan,
        $this->toPlan,
        Money::ofMinor(6000, 'EUR'),   // current 60.00
        Money::ofMinor(5000, 'EUR'),   // new 50.00 — a downgrade, kept anchor → deferred
        $this->period,
        $this->mid,
        ($this->ctx)(),
        new CreditConsequenceRequest(outgoingAllotmentRemaining: 80_000, incomingAllotment: 40_000),
        anchor: AnchorMode::Keep,
    );

    // Money defers to period end; the credit allotment likewise changes at renewal —
    // the current allotment is not forfeited mid-cycle, the new (lower) one lands at period end.
    expect($preview->proration->deferred)->toBeTrue()
        ->and($preview->effectiveAt)->toEqual($this->period->end)
        ->and($preview->creditDelta->forfeited)->toBe(0)
        ->and($preview->creditDelta->granted)->toBe(40_000)
        ->and($preview->creditDelta->carried)->toBe(0);
});

it('a surviving pay-as-you-go debt is reported through, never offset by the allotment', function (): void {
    $delta = $this->calculator->forSwitch(
        new CreditConsequenceRequest(outgoingAllotmentRemaining: 10_000, incomingAllotment: 100_000, payAsYouGoBalance: -2_500),
        carryOver: false,
        mode: CreditGrantMode::Prorated,
        remainingDays: 15,
        totalDays: 30,
    );

    expect($delta->poolLeftNegative)->toBe(-2_500)
        ->and($delta->granted)->toBe(49_995);
});
