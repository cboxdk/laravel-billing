<?php

declare(strict_types=1);

use Cbox\Billing\Catalog\Enums\ProductShape;
use Cbox\Billing\Catalog\Enums\TermUnit;
use Cbox\Billing\Catalog\ValueObjects\Product;
use Cbox\Billing\Catalog\ValueObjects\Term;
use Cbox\Billing\Entitlement\Testing\FakeEntitlementWriter;
use Cbox\Billing\Entitlement\ValueObjects\Entitlement;
use Cbox\Billing\Subscription\Enums\TermSubscriptionStatus;
use Cbox\Billing\Subscription\TermLifecycle;
use Cbox\Billing\Subscription\ValueObjects\RegistrarWindows;
use Cbox\Billing\Subscription\ValueObjects\TermSubscription;

it('lets one org hold a metered plan, a rolling subscription, and two fixed-term instances at once', function () {
    // A single billing account (org) with a portfolio of MIXED shapes.
    $api = new Product('api', 'Metered API', shape: ProductShape::Metered);
    $pro = new Product('pro', 'Pro plan', shape: ProductShape::Recurring);
    $domain = new Product('domain-com', '.com domain', shape: ProductShape::FixedTerm);

    expect($api->shape)->toBe(ProductShape::Metered)
        ->and($pro->shape)->toBe(ProductShape::Recurring)
        ->and($domain->isFixedTerm())->toBeTrue()
        // Recurring is the BC-safe default so pre-shape catalogs keep their meaning.
        ->and((new Product('legacy', 'Legacy'))->shape)->toBe(ProductShape::Recurring);

    $lifecycle = new TermLifecycle;
    $registeredAt = new DateTimeImmutable('2026-01-15');
    $acme = TermSubscription::register('ts-acme', 'org-1', 'domain-com', 'acme.com', new Term(1, TermUnit::Year), $registeredAt);
    $widget = TermSubscription::register('ts-widget', 'org-1', 'domain-com', 'widget.io', new Term(2, TermUnit::Year), $registeredAt);
    $windows = new RegistrarWindows(new Term(30, TermUnit::Day), new Term(30, TermUnit::Day));
    $now = new DateTimeImmutable('2026-06-01');

    expect($lifecycle->phaseAt($acme, $windows, $now))->toBe(TermSubscriptionStatus::Active)
        ->and($lifecycle->phaseAt($widget, $windows, $now))->toBe(TermSubscriptionStatus::Active)
        ->and($acme->instanceRef)->not->toBe($widget->instanceRef);
});

it('resolves entitlements per (org, instance sourceRef) so a mixed portfolio never collides', function () {
    $writer = new FakeEntitlementWriter;

    // One org, four concurrent sources of entitlement, keyed by distinct sourceRefs —
    // including two instances of the SAME fixed-term product.
    $writer->set(new Entitlement('org-1', 'api', 'metered', ['api.call'], 'sub-api'));
    $writer->set(new Entitlement('org-1', 'pro', 'pro', ['seats'], 'sub-pro'));
    $writer->set(new Entitlement('org-1', 'domain-com', 'active', ['dns'], 'ts-acme'));
    $writer->set(new Entitlement('org-1', 'domain-com', 'active', ['dns'], 'ts-widget'));

    $held = $writer->forOrganization('org-1');

    // All four coexist; the two same-product domain instances do not overwrite each other.
    expect($held)->toHaveCount(4);

    $bySource = [];
    foreach ($held as $entitlement) {
        $bySource[$entitlement->sourceRef] = $entitlement->productId;
    }

    expect($bySource)->toBe([
        'sub-api' => 'api',
        'sub-pro' => 'pro',
        'ts-acme' => 'domain-com',
        'ts-widget' => 'domain-com',
    ]);

    // Revoking one domain instance leaves the other — and the rest of the portfolio — intact.
    $writer->revoke('org-1', 'ts-acme');
    $remaining = array_map(static fn (Entitlement $e): string => $e->sourceRef, $writer->forOrganization('org-1'));

    expect($remaining)->toEqualCanonicalizing(['sub-api', 'sub-pro', 'ts-widget']);
});
