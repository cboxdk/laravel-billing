<?php

declare(strict_types=1);

use Cbox\Billing\Money\Money;
use Cbox\Billing\Quote\Contracts\QuoteBuilder;
use Cbox\Billing\Quote\ValueObjects\QuoteContext;
use Cbox\Billing\Subscription\PlanChange\CreditConsequenceCalculator;
use Cbox\Billing\Subscription\PlanChange\Exceptions\TransitionNotAllowed;
use Cbox\Billing\Subscription\PlanChange\FamilyTransitionPolicy;
use Cbox\Billing\Subscription\PlanChange\PlanChangePreviewer;
use Cbox\Billing\Subscription\PlanChange\ValueObjects\CreditConsequenceRequest;
use Cbox\Billing\Subscription\PlanChange\ValueObjects\TransitionEdge;
use Cbox\Billing\Subscription\Proration\ProrationCalculator;
use Cbox\Billing\Subscription\ValueObjects\BillingPeriod;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\ValueObjects\SellerRegistrations;

beforeEach(function (): void {
    $this->geo = $this->app->make(JurisdictionRepository::class);
    $this->quotes = $this->app->make(QuoteBuilder::class);
    $this->period = new BillingPeriod(new DateTimeImmutable('2025-09-01'), new DateTimeImmutable('2025-10-01'));
    $this->midPeriod = new DateTimeImmutable('2025-09-16');
});

function previewerWith(FamilyTransitionPolicy $policy): PlanChangePreviewer
{
    return new PlanChangePreviewer(
        new ProrationCalculator,
        test()->quotes,
        $policy,
        new CreditConsequenceCalculator,
    );
}

function ctx(): QuoteContext
{
    return new QuoteContext(
        place: test()->geo->find(new CountryCode('DK')),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('DK')),
    );
}

it('refuses a disallowed target before proration, with the policy reason', function (): void {
    $previewer = previewerWith(new FamilyTransitionPolicy); // no edges
    $onPrem = $this->plan('on-prem', family: 'on-prem');
    $hosted = $this->plan('hosted', family: 'hosted');

    $call = fn () => $previewer->preview(
        $onPrem,
        $hosted,
        Money::ofMinor(5000, 'EUR'),
        Money::ofMinor(6000, 'EUR'),
        $this->period,
        $this->midPeriod,
        ctx(),
    );

    expect($call)->toThrow(TransitionNotAllowed::class);

    try {
        $call();
    } catch (TransitionNotAllowed $e) {
        expect($e->fromPlanId)->toBe('on-prem')
            ->and($e->toPlanId)->toBe('hosted')
            ->and($e->reason)->toContain('No transition path');
    }
});

it('warns of irreversibility when the current plan is legacy', function (): void {
    $previewer = previewerWith(new FamilyTransitionPolicy);
    $legacy = $this->legacyPlan('pro-legacy', family: 'standard');
    $current = $this->plan('pro', family: 'standard');

    $preview = $previewer->preview(
        $legacy,
        $current,
        Money::ofMinor(5000, 'EUR'),
        Money::ofMinor(6000, 'EUR'),
        $this->period,
        $this->midPeriod,
        ctx(),
    );

    expect($preview->isIrreversible())->toBeTrue()
        ->and($preview->irreversibilityWarning)->toContain('legacy')
        ->and($preview->irreversibilityWarning)->toContain('cannot switch back');
});

it('shows the credit delta beside the money delta: forfeit-and-regrant by default', function (): void {
    $previewer = previewerWith(new FamilyTransitionPolicy);
    $from = $this->plan('pro', family: 'standard');
    $to = $this->plan('pro-plus', family: 'standard');

    $preview = $previewer->preview(
        $from,
        $to,
        Money::ofMinor(5000, 'EUR'),
        Money::ofMinor(6000, 'EUR'),
        $this->period,
        $this->midPeriod,
        ctx(),
        // 40 unspent included, incoming grants 100, PAYG pool is -15.
        new CreditConsequenceRequest(outgoingAllotmentRemaining: 40, incomingAllotment: 100, payAsYouGoBalance: -15),
    );

    expect($preview->creditDelta->forfeited)->toBe(40)
        ->and($preview->creditDelta->granted)->toBe(100)
        ->and($preview->creditDelta->carried)->toBe(0)
        ->and($preview->creditDelta->poolLeftNegative)->toBe(-15)
        ->and($preview->proratedNet->minor())->toBe(500); // money delta still computed
});

it('carries the outgoing allotment over when the edge opts in', function (): void {
    $previewer = previewerWith(new FamilyTransitionPolicy(
        new TransitionEdge('on-prem', 'hosted', carryOver: true),
    ));
    $from = $this->plan('on-prem', family: 'on-prem');
    $to = $this->plan('hosted', family: 'hosted');

    $preview = $previewer->preview(
        $from,
        $to,
        Money::ofMinor(5000, 'EUR'),
        Money::ofMinor(6000, 'EUR'),
        $this->period,
        $this->midPeriod,
        ctx(),
        new CreditConsequenceRequest(outgoingAllotmentRemaining: 40, incomingAllotment: 100),
    );

    expect($preview->creditDelta->forfeited)->toBe(0)
        ->and($preview->creditDelta->carried)->toBe(40)
        ->and($preview->creditDelta->granted)->toBe(100);
});
