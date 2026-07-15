<?php

declare(strict_types=1);

use Cbox\Billing\Entitlement\DefaultEntitlementProjector;
use Cbox\Billing\Entitlement\Testing\FakeEntitlementWriter;
use Cbox\Billing\Subscription\SubscriptionManager;
use Cbox\Billing\Subscription\ValueObjects\BillingPeriod;

beforeEach(function () {
    $this->writer = new FakeEntitlementWriter;
    $this->projector = new DefaultEntitlementProjector($this->writer);
    $this->manager = new SubscriptionManager;
    $this->period = new BillingPeriod(new DateTimeImmutable('2025-09-01'), new DateTimeImmutable('2025-10-01'));
});

it('grants a per-product tier for an active subscription', function () {
    $sub = $this->manager->create('sub_a', 'org_1', 'analytics', 'analytics-pro', $this->period);

    $this->projector->project($sub, 'pro', ['dashboards']);

    $entitlements = $this->writer->forOrganization('org_1');
    expect($entitlements)->toHaveCount(1)
        ->and($entitlements[0]->productId)->toBe('analytics')
        ->and($entitlements[0]->tier)->toBe('pro')
        ->and($entitlements[0]->sourceRef)->toBe('sub_a');
});

it('scopes entitlements per product — one org, many products', function () {
    $analytics = $this->manager->create('sub_a', 'org_1', 'analytics', 'analytics-pro', $this->period);
    $support = $this->manager->create('sub_b', 'org_1', 'support', 'support-ent', $this->period);

    $this->projector->project($analytics, 'pro');
    $this->projector->project($support, 'enterprise');

    $tiers = array_map(fn ($e) => $e->productId.':'.$e->tier, $this->writer->forOrganization('org_1'));

    expect($tiers)->toContain('analytics:pro', 'support:enterprise')
        ->and($this->writer->forOrganization('org_1'))->toHaveCount(2);
});

it('revoking one product leaves the org\'s other products intact', function () {
    $analytics = $this->manager->create('sub_a', 'org_1', 'analytics', 'analytics-pro', $this->period);
    $support = $this->manager->create('sub_b', 'org_1', 'support', 'support-ent', $this->period);
    $this->projector->project($analytics, 'pro');
    $this->projector->project($support, 'enterprise');

    // Cancel the analytics subscription and re-project → revokes only analytics.
    $canceledAnalytics = $this->manager->renew($this->manager->cancelAtPeriodEnd($analytics), new BillingPeriod(new DateTimeImmutable('2025-10-01'), new DateTimeImmutable('2025-11-01')));
    $this->projector->project($canceledAnalytics, 'pro');

    $remaining = $this->writer->forOrganization('org_1');
    expect($remaining)->toHaveCount(1)
        ->and($remaining[0]->productId)->toBe('support');
});
