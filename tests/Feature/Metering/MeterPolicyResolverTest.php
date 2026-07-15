<?php

declare(strict_types=1);

use Cbox\Billing\Entitlement\Resolvers\EntitlementMeterPolicyResolver;
use Cbox\Billing\Metering\Contracts\MeterPolicyResolver;
use Cbox\Billing\Metering\Enums\OverageBehaviour;
use Cbox\Billing\Metering\ValueObjects\MeterPolicy;

it('resolves entitlement-granted policy through the metering contract', function (): void {
    $resolver = new EntitlementMeterPolicyResolver;
    $resolver->grant('org_a', 'api.calls', MeterPolicy::metered(100, 0.5, OverageBehaviour::Bill));

    $policy = $resolver->resolve('org_a', 'api.calls');

    expect($policy)->not->toBeNull()
        ->and($policy->enabled)->toBeTrue()
        ->and($policy->allowance)->toBe(100)
        ->and($policy->overage)->toBe(OverageBehaviour::Bill);
});

it('refuses an ungranted meter by default (deny-by-default)', function (): void {
    $resolver = new EntitlementMeterPolicyResolver;

    expect($resolver->resolve('org_a', 'unknown'))->toBeNull();
});

it('revokes a granted policy back to deny-by-default', function (): void {
    $resolver = new EntitlementMeterPolicyResolver;
    $resolver->grant('org_a', 'api.calls', MeterPolicy::unlimited());
    $resolver->revoke('org_a', 'api.calls');

    expect($resolver->resolve('org_a', 'api.calls'))->toBeNull();
});

it('is the bound default MeterPolicyResolver, deny-by-default out of the box', function (): void {
    $resolver = app(MeterPolicyResolver::class);

    expect($resolver)->toBeInstanceOf(EntitlementMeterPolicyResolver::class)
        ->and($resolver->resolve('org_a', 'anything'))->toBeNull();
});
