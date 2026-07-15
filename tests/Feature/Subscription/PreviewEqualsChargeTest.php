<?php

declare(strict_types=1);

use Cbox\Billing\Money\Money;
use Cbox\Billing\Quote\ValueObjects\QuoteContext;
use Cbox\Billing\Subscription\Enums\AnchorMode;
use Cbox\Billing\Subscription\Enums\GatewayRounding;
use Cbox\Billing\Subscription\PlanChange\PlanChangePreviewer;
use Cbox\Billing\Subscription\Proration\ProrationCalculator;
use Cbox\Billing\Subscription\Proration\ProrationRequest;
use Cbox\Billing\Subscription\ValueObjects\BillingPeriod;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\ValueObjects\SellerRegistrations;

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
    $this->previewer = $this->app->make(PlanChangePreviewer::class);
    $this->calculator = $this->app->make(ProrationCalculator::class);

    // 30-day period; mid-period leaves 15 of 30 days (half) to run.
    $this->period = new BillingPeriod(new DateTimeImmutable('2025-09-01'), new DateTimeImmutable('2025-10-01'));
    $this->mid = new DateTimeImmutable('2025-09-16');

    $this->ctx = fn (): QuoteContext => new QuoteContext(
        place: $this->geo->find(new CountryCode('DK')),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('DK')),
    );

    // Same-family plans, so the transition policy allows the change under test.
    $this->fromPlan = $this->plan('pro', family: 'standard');
    $this->toPlan = $this->plan('pro-plus', family: 'standard');
});

it('preview and charge produce an identical breakdown from identical inputs', function () {
    // The charge path is the single calculator; the preview delegates to it.
    $request = new ProrationRequest(
        currentPrice: Money::ofMinor(5000, 'EUR'),
        newPrice: Money::ofMinor(6000, 'EUR'),
        period: $this->period,
        at: $this->mid,
    );

    $charge = $this->calculator->compute($request);

    $preview = $this->previewer->preview(
        $this->fromPlan,
        $this->toPlan,
        Money::ofMinor(5000, 'EUR'),
        Money::ofMinor(6000, 'EUR'),
        $this->period,
        $this->mid,
        ($this->ctx)(),
    );

    // Identical by construction — same object shape, cent for cent.
    expect($preview->proration->breakdown())->toBe($charge->breakdown())
        ->and($preview->proration->dueNow()->minor())->toBe($charge->dueNow()->minor())
        ->and($preview->proratedNet->minor())->toBe($charge->net->minor());
});

it('rounds each line independently to whole minor units, aligned to the gateway', function () {
    // Reset with 10 of 30 days remaining: the unused-base credit is fractional
    // (5000 * 10/30 = 1666.66…), so the gateway's rounding mode is observable on the line.
    $at = new DateTimeImmutable('2025-09-21');

    $halfUp = $this->calculator->compute(new ProrationRequest(
        currentPrice: Money::ofMinor(5000, 'EUR'),
        newPrice: Money::ofMinor(3000, 'EUR'),
        period: $this->period,
        at: $at,
        anchor: AnchorMode::Reset,
        rounding: GatewayRounding::HalfUp,
    ));

    $down = $this->calculator->compute(new ProrationRequest(
        currentPrice: Money::ofMinor(5000, 'EUR'),
        newPrice: Money::ofMinor(3000, 'EUR'),
        period: $this->period,
        at: $at,
        anchor: AnchorMode::Reset,
        rounding: GatewayRounding::Down,
    ));

    // Fresh period line is the full new price on both; only the credit line differs by mode.
    expect($halfUp->lines[0]->amount->minor())->toBe(3000)
        ->and($halfUp->lines[1]->amount->minor())->toBe(-1667)   // 1666.66 rounds away
        ->and($down->lines[1]->amount->minor())->toBe(-1666)     // truncated toward zero
        // Net is the sum of already-rounded lines, never a rounded total.
        ->and($halfUp->net->minor())->toBe(3000 - 1667)
        ->and($down->net->minor())->toBe(3000 - 1666);
});

it('keep anchor upgrade prorates only the delta over the remaining period', function () {
    $proration = $this->calculator->compute(new ProrationRequest(
        currentPrice: Money::ofMinor(5000, 'EUR'),
        newPrice: Money::ofMinor(6000, 'EUR'),
        period: $this->period,
        at: $this->mid,
        anchor: AnchorMode::Keep,
    ));

    // (6000 - 5000) * 15/30 = 500, one line, charged now.
    expect($proration->anchor)->toBe(AnchorMode::Keep)
        ->and($proration->deferred)->toBeFalse()
        ->and($proration->lines)->toHaveCount(1)
        ->and($proration->net->minor())->toBe(500)
        ->and($proration->dueNow()->minor())->toBe(500)
        ->and($proration->effectiveAt)->toEqual($this->mid);
});

it('reset anchor charges a fresh period and credits the unused base (differs from keep)', function () {
    $reset = $this->calculator->compute(new ProrationRequest(
        currentPrice: Money::ofMinor(5000, 'EUR'),
        newPrice: Money::ofMinor(6000, 'EUR'),
        period: $this->period,
        at: $this->mid,
        anchor: AnchorMode::Reset,
    ));

    // Fresh 6000, unused base credit -5000 * 15/30 = -2500, net 3500 — not the keep-anchor 500.
    expect($reset->lines)->toHaveCount(2)
        ->and($reset->lines[0]->amount->minor())->toBe(6000)
        ->and($reset->lines[1]->amount->minor())->toBe(-2500)
        ->and($reset->net->minor())->toBe(3500)
        ->and($reset->net->minor())->not->toBe(500);
});

it('nets a credit on reset when the unused base exceeds the fresh price', function () {
    // Early in the period (25 of 30 days remain) resetting from an expensive plan to a
    // cheap one: the unused expensive base outweighs the cheap fresh period.
    $reset = $this->calculator->compute(new ProrationRequest(
        currentPrice: Money::ofMinor(6000, 'EUR'),
        newPrice: Money::ofMinor(1000, 'EUR'),
        period: $this->period,
        at: new DateTimeImmutable('2025-09-06'),
        anchor: AnchorMode::Reset,
    ));

    // Fresh 1000, credit -6000 * 25/30 = -5000, net -4000 (a credit): nothing due now.
    expect($reset->net->minor())->toBe(-4000)
        ->and($reset->isCredit())->toBeTrue()
        ->and($reset->dueNow()->minor())->toBe(0);

    // The preview shows the credit and quotes nothing to charge now.
    $preview = $this->previewer->preview(
        $this->fromPlan,
        $this->toPlan,
        Money::ofMinor(6000, 'EUR'),
        Money::ofMinor(1000, 'EUR'),
        $this->period,
        new DateTimeImmutable('2025-09-06'),
        ($this->ctx)(),
        anchor: AnchorMode::Reset,
    );

    expect($preview->dueNowQuote)->toBeNull()
        ->and($preview->isUpgrade)->toBeFalse()
        ->and($preview->proratedNet->minor())->toBe(-4000);
});

it('defers a kept-anchor downgrade: no money now, lands at the period end', function () {
    $proration = $this->calculator->compute(new ProrationRequest(
        currentPrice: Money::ofMinor(6000, 'EUR'),
        newPrice: Money::ofMinor(5000, 'EUR'),
        period: $this->period,
        at: $this->mid,
        anchor: AnchorMode::Keep,
    ));

    expect($proration->deferred)->toBeTrue()
        ->and($proration->lines)->toBe([])
        ->and($proration->net->minor())->toBe(0)
        ->and($proration->dueNow()->minor())->toBe(0)
        ->and($proration->effectiveAt)->toEqual($this->period->end);

    $preview = $this->previewer->preview(
        $this->fromPlan,
        $this->toPlan,
        Money::ofMinor(6000, 'EUR'),
        Money::ofMinor(5000, 'EUR'),
        $this->period,
        $this->mid,
        ($this->ctx)(),
    );

    expect($preview->dueNowQuote)->toBeNull()
        ->and($preview->effectiveAt)->toEqual($this->period->end);
});

it('charges a full fresh period from pay-as-you-go with no credit', function () {
    // No committed base (currentPrice null): reset charges the whole new period, credits nothing.
    $proration = $this->calculator->compute(new ProrationRequest(
        currentPrice: null,
        newPrice: Money::ofMinor(6000, 'EUR'),
        period: $this->period,
        at: $this->mid,
        anchor: AnchorMode::Reset,
    ));

    expect($proration->lines)->toHaveCount(1)
        ->and($proration->lines[0]->amount->minor())->toBe(6000)
        ->and($proration->net->minor())->toBe(6000)
        ->and($proration->dueNow()->minor())->toBe(6000);
});

it('clamps a proration instant that precedes the period start to the full period', function () {
    // An instant before the period start counts the whole period, not a negative slice.
    $proration = $this->calculator->compute(new ProrationRequest(
        currentPrice: Money::ofMinor(5000, 'EUR'),
        newPrice: Money::ofMinor(6000, 'EUR'),
        period: $this->period,
        at: new DateTimeImmutable('2025-08-20'),
        anchor: AnchorMode::Keep,
    ));

    // Full delta 6000 - 5000 = 1000 (fraction clamped to 1), not more.
    expect($proration->net->minor())->toBe(1000);
});

it('handles a zero-length period without dividing by zero', function () {
    $zeroLength = new BillingPeriod(new DateTimeImmutable('2025-09-01'), new DateTimeImmutable('2025-09-01'));

    $proration = $this->calculator->compute(new ProrationRequest(
        currentPrice: Money::ofMinor(5000, 'EUR'),
        newPrice: Money::ofMinor(6000, 'EUR'),
        period: $zeroLength,
        at: new DateTimeImmutable('2025-09-01'),
        anchor: AnchorMode::Keep,
    ));

    // No exception; the prorated line is simply zero.
    expect($proration->net->minor())->toBe(0);
});
