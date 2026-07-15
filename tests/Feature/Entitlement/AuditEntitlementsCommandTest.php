<?php

declare(strict_types=1);

use Cbox\Billing\Entitlement\Audit\Contracts\ExpectedEntitlements;
use Cbox\Billing\Entitlement\Audit\Sources\InMemoryExpectedEntitlements;
use Cbox\Billing\Entitlement\Resolvers\EntitlementMeterPolicyResolver;
use Cbox\Billing\Metering\ValueObjects\MeterPolicy;

it('succeeds and reports clean with the empty default source', function (): void {
    $this->artisan('billing:entitlements:audit')
        ->expectsOutputToContain('Entitlement audit clean')
        ->assertSuccessful();
});

it('fails and renders the outage when an expected key is missing', function (): void {
    // Grant only 'api.calls' on the container resolver; 'seats' stays dark.
    $this->app->make(EntitlementMeterPolicyResolver::class)
        ->grant('org_a', 'api.calls', MeterPolicy::unlimited());

    $this->app->instance(
        ExpectedEntitlements::class,
        (new InMemoryExpectedEntitlements)->expect('org_a', 'pro', ['api.calls', 'seats']),
    );

    $this->artisan('billing:entitlements:audit')
        ->expectsOutputToContain('MISSING org_a/pro')
        ->expectsOutputToContain('Entitlement outage')
        ->assertFailed();
});
