<?php

declare(strict_types=1);

use Cbox\Billing\Subscription\PlanChange\FamilyTransitionPolicy;
use Cbox\Billing\Subscription\PlanChange\ValueObjects\TransitionEdge;

beforeEach(function (): void {
    $this->hostedPro = $this->plan('hosted-pro', family: 'hosted');
    $this->hostedTeam = $this->plan('hosted-team', family: 'hosted');
    $this->onPrem = $this->plan('on-prem', family: 'on-prem');
    $this->legacyHosted = $this->legacyPlan('hosted-legacy', family: 'hosted');
});

it('allows a same-family move with no declared edge', function (): void {
    $policy = new FamilyTransitionPolicy;

    $decision = $policy->canTransition($this->hostedPro, $this->hostedTeam);

    expect($decision->isAllowed())->toBeTrue()
        ->and($decision->carryOver)->toBeFalse(); // reset by default
});

it('blocks a cross-family move when no edge is declared', function (): void {
    $policy = new FamilyTransitionPolicy;

    $decision = $policy->canTransition($this->onPrem, $this->hostedPro);

    expect($decision->isAllowed())->toBeFalse()
        ->and($decision->reason)->toContain('on-prem')
        ->and($decision->reason)->toContain('hosted');
});

it('allows a cross-family move along an explicitly declared edge, carrying its guidance and carryOver', function (): void {
    $policy = new FamilyTransitionPolicy(
        new TransitionEdge('on-prem', 'hosted', guidance: 'requires migration', carryOver: true),
    );

    $decision = $policy->canTransition($this->onPrem, $this->hostedPro);

    expect($decision->isAllowed())->toBeTrue()
        ->and($decision->guidance)->toBe('requires migration')
        ->and($decision->carryOver)->toBeTrue();

    // The edge is directed: the reverse move is still refused.
    expect($policy->canTransition($this->hostedPro, $this->onPrem)->isAllowed())->toBeFalse();
});

it('treats a legacy plan as a valid source but never a target', function (): void {
    $policy = new FamilyTransitionPolicy;

    // Source: a legacy plan can be left (same family, allowed).
    expect($policy->canTransition($this->legacyHosted, $this->hostedPro)->isAllowed())->toBeTrue();

    // Target: even within the family, you can never switch (back) to a legacy plan.
    $back = $policy->canTransition($this->hostedPro, $this->legacyHosted);
    expect($back->isAllowed())->toBeFalse()
        ->and($back->reason)->toContain('legacy');
});

it('lists only the allowed targets in the catalog', function (): void {
    $policy = new FamilyTransitionPolicy(
        new TransitionEdge('on-prem', 'hosted', guidance: 'requires migration'),
    );
    $catalog = $this->catalogOf($this->hostedPro, $this->hostedTeam, $this->onPrem, $this->legacyHosted);

    $ids = array_map(static fn ($p) => $p->id, $policy->availableTransitions($this->onPrem, $catalog));

    // on-prem may reach both hosted (offered) plans via the edge, but never itself or the legacy plan.
    expect($ids)->toEqualCanonicalizing(['hosted-pro', 'hosted-team']);
});
