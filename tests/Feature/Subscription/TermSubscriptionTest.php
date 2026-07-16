<?php

declare(strict_types=1);

use Cbox\Billing\Catalog\Enums\PriceKind;
use Cbox\Billing\Catalog\Enums\PricingModel;
use Cbox\Billing\Catalog\Enums\ProductShape;
use Cbox\Billing\Catalog\Enums\TermUnit;
use Cbox\Billing\Catalog\InMemoryCatalog;
use Cbox\Billing\Catalog\ValueObjects\Price;
use Cbox\Billing\Catalog\ValueObjects\Product;
use Cbox\Billing\Catalog\ValueObjects\Term;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Quote\Contracts\QuoteBuilder;
use Cbox\Billing\Quote\ValueObjects\QuoteContext;
use Cbox\Billing\Subscription\Enums\TermSubscriptionStatus;
use Cbox\Billing\Subscription\Exceptions\TermPriceNotAvailable;
use Cbox\Billing\Subscription\TermLifecycle;
use Cbox\Billing\Subscription\TermPurchase;
use Cbox\Billing\Subscription\ValueObjects\RegistrarWindows;
use Cbox\Billing\Subscription\ValueObjects\TermSubscription;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\ValueObjects\SellerRegistrations;

function yr(int $count): Term
{
    return new Term($count, TermUnit::Year);
}

function domainPricePoint(string $id, Term $term, PriceKind $kind, int $minor): Price
{
    return new Price(
        $id,
        'domain-com',
        PricingModel::Flat,
        Money::ofMinor($minor, 'EUR'),
        new DateTimeImmutable('2024-01-01'),
        null,
        $term,
        $kind,
    );
}

function domainProduct(): Product
{
    return new Product('domain-com', '.com domain', shape: ProductShape::FixedTerm);
}

function fixedTermCatalog(): InMemoryCatalog
{
    return new InMemoryCatalog(
        products: [domainProduct()],
        prices: [
            domainPricePoint('reg-1y', yr(1), PriceKind::Register, 1000),
            domainPricePoint('reg-2y', yr(2), PriceKind::Register, 1900),
            domainPricePoint('ren-1y', yr(1), PriceKind::Renewal, 1200),
            domainPricePoint('red-1y', yr(1), PriceKind::Redemption, 9000),
            domainPricePoint('tra-1y', yr(1), PriceKind::Transfer, 1100),
        ],
    );
}

function dkConsumerContext(): QuoteContext
{
    /** @var JurisdictionRepository $geo */
    $geo = app(JurisdictionRepository::class);

    return new QuoteContext(
        place: $geo->find(new CountryCode('DK')),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('DK')),
    );
}

function termPurchase(): TermPurchase
{
    return new TermPurchase(fixedTermCatalog(), app(QuoteBuilder::class));
}

// The common registrar windows used across the lapse scenarios: 30-day grace + 30-day redemption.
function windows(): RegistrarWindows
{
    return new RegistrarWindows(new Term(30, TermUnit::Day), new Term(30, TermUnit::Day));
}

it('registers a 2yr term: Active, termEndsAt = registeredAt + P2Y, quote at the Register price + DK VAT', function () {
    $registeredAt = new DateTimeImmutable('2026-01-15');

    $quote = termPurchase()->quote(domainProduct(), yr(2), PriceKind::Register, 1, dkConsumerContext(), $registeredAt);

    // Register 2yr = 19.00 net; DK 25% VAT → 23.75 gross.
    expect($quote->totals->net->minor())->toBe(1900)
        ->and($quote->totals->tax->minor())->toBe(475)
        ->and($quote->totals->gross->minor())->toBe(2375);

    $instance = TermSubscription::register('ts-1', 'org-1', 'domain-com', 'acme.com', yr(2), $registeredAt);

    expect($instance->status)->toBe(TermSubscriptionStatus::Active)
        ->and($instance->termEndsAt)->toEqual(new DateTimeImmutable('2028-01-15'))
        ->and($instance->instanceRef)->toBe('acme.com');
});

it('renews for 1yr at the Renewal price: extends termEndsAt by P1Y from the current end', function () {
    $lifecycle = new TermLifecycle;
    $registeredAt = new DateTimeImmutable('2026-01-15');
    $instance = TermSubscription::register('ts-1', 'org-1', 'domain-com', 'acme.com', yr(2), $registeredAt);
    // termEndsAt = 2028-01-15

    // Renew early (before expiry): stacks onto the remaining term.
    $renewed = $lifecycle->renew($instance, yr(1), new DateTimeImmutable('2027-06-01'));

    expect($renewed->status)->toBe(TermSubscriptionStatus::Active)
        ->and($renewed->termEndsAt)->toEqual(new DateTimeImmutable('2029-01-15'));

    // The renewal is priced at the Renewal point: 12.00 net → 15.00 gross.
    $quote = termPurchase()->quote(domainProduct(), yr(1), PriceKind::Renewal, 1, dkConsumerContext(), new DateTimeImmutable('2027-06-01'));

    expect($quote->totals->net->minor())->toBe(1200)
        ->and($quote->totals->gross->minor())->toBe(1500);
});

it('walks the lapse lifecycle: Active → Grace → Redemption → Expired with no auto-renew', function () {
    $lifecycle = new TermLifecycle;
    $registeredAt = new DateTimeImmutable('2026-01-15');
    $instance = TermSubscription::register('ts-1', 'org-1', 'domain-com', 'acme.com', yr(1), $registeredAt, autoRenew: false);
    // termEndsAt = 2027-01-15; grace ends 2027-02-14; redemption ends 2027-03-16.

    expect($lifecycle->phaseAt($instance, windows(), new DateTimeImmutable('2026-06-01')))->toBe(TermSubscriptionStatus::Active)
        ->and($lifecycle->phaseAt($instance, windows(), new DateTimeImmutable('2027-01-15')))->toBe(TermSubscriptionStatus::Active) // inclusive end
        ->and($lifecycle->phaseAt($instance, windows(), new DateTimeImmutable('2027-01-20')))->toBe(TermSubscriptionStatus::Grace)
        ->and($lifecycle->phaseAt($instance, windows(), new DateTimeImmutable('2027-03-01')))->toBe(TermSubscriptionStatus::Redemption)
        ->and($lifecycle->phaseAt($instance, windows(), new DateTimeImmutable('2027-04-01')))->toBe(TermSubscriptionStatus::Expired);
});

it('recovers from Grace with a Renewal, and from Redemption with a Redemption-priced redeem', function () {
    $lifecycle = new TermLifecycle;
    $purchase = termPurchase();
    $context = dkConsumerContext();
    $registeredAt = new DateTimeImmutable('2026-01-15');
    $instance = TermSubscription::register('ts-1', 'org-1', 'domain-com', 'acme.com', yr(1), $registeredAt, autoRenew: false);

    // In grace → renew at the Renewal price. Renewal from the renewal instant (past the end).
    $graceInstant = new DateTimeImmutable('2027-01-25');
    expect($lifecycle->phaseAt($instance, windows(), $graceInstant))->toBe(TermSubscriptionStatus::Grace);
    $recovered = $lifecycle->renew($instance, yr(1), $graceInstant);
    expect($recovered->status)->toBe(TermSubscriptionStatus::Active)
        ->and($recovered->termEndsAt)->toEqual(new DateTimeImmutable('2028-01-25'));
    expect($purchase->quote(domainProduct(), yr(1), PriceKind::Renewal, 1, $context, $graceInstant)->totals->net->minor())->toBe(1200);

    // In redemption → redeem at the Redemption price (90.00 net → 112.50 gross).
    $redemptionInstant = new DateTimeImmutable('2027-03-01');
    expect($lifecycle->phaseAt($instance, windows(), $redemptionInstant))->toBe(TermSubscriptionStatus::Redemption);
    $redeemed = $lifecycle->redeem($instance, yr(1), $redemptionInstant);
    expect($redeemed->status)->toBe(TermSubscriptionStatus::Active)
        ->and($redeemed->termEndsAt)->toEqual(new DateTimeImmutable('2028-03-01'));
    $redeemQuote = $purchase->quote(domainProduct(), yr(1), PriceKind::Redemption, 1, $context, $redemptionInstant);
    expect($redeemQuote->totals->net->minor())->toBe(9000)
        ->and($redeemQuote->totals->gross->minor())->toBe(11250);
});

it('auto-renew past term end stays Active and reports a due renewal, not Grace', function () {
    $lifecycle = new TermLifecycle;
    $registeredAt = new DateTimeImmutable('2026-01-15');
    $instance = TermSubscription::register('ts-1', 'org-1', 'domain-com', 'acme.com', yr(1), $registeredAt, autoRenew: true);
    // termEndsAt = 2027-01-15. Past it, an auto-renew instance is NOT in Grace.
    $past = new DateTimeImmutable('2027-01-20');

    expect($lifecycle->phaseAt($instance, windows(), $past))->toBe(TermSubscriptionStatus::Active)
        ->and($lifecycle->isAutoRenewalDue($instance, $past))->toBeTrue()
        ->and($lifecycle->isAutoRenewalDue($instance, new DateTimeImmutable('2026-12-01')))->toBeFalse();

    // Renewing keeps it billable/Active on the next term.
    $renewed = $lifecycle->renew($instance, yr(1), $past);
    expect($renewed->status)->toBe(TermSubscriptionStatus::Active)
        ->and($lifecycle->isAutoRenewalDue($renewed, $past))->toBeFalse();
});

it('transfers in at the Transfer price (Active) and transfers out (TransferredOut)', function () {
    $lifecycle = new TermLifecycle;
    $at = new DateTimeImmutable('2026-03-01');

    $transferred = $lifecycle->transferIn('ts-9', 'org-1', 'domain-com', 'moved.com', yr(1), $at, autoRenew: true);
    expect($transferred->status)->toBe(TermSubscriptionStatus::Active)
        ->and($transferred->termEndsAt)->toEqual(new DateTimeImmutable('2027-03-01'))
        ->and($transferred->autoRenew)->toBeTrue();

    // Priced at the Transfer point: 11.00 net → 13.75 gross.
    $quote = termPurchase()->quote(domainProduct(), yr(1), PriceKind::Transfer, 1, dkConsumerContext(), $at);
    expect($quote->totals->net->minor())->toBe(1100)
        ->and($quote->totals->gross->minor())->toBe(1375);

    $out = $lifecycle->transferOut($transferred, new DateTimeImmutable('2026-06-01'));
    expect($out->status)->toBe(TermSubscriptionStatus::TransferredOut)
        // A terminal state is preserved by the phase computation, not recomputed to Active.
        ->and($lifecycle->phaseAt($out, windows(), new DateTimeImmutable('2026-06-02')))->toBe(TermSubscriptionStatus::TransferredOut);
});

it('holds many concurrent instances of the same product for one org with independent lifecycles', function () {
    $lifecycle = new TermLifecycle;

    $a = TermSubscription::register('ts-a', 'org-1', 'domain-com', 'a.com', yr(1), new DateTimeImmutable('2026-01-15'), autoRenew: false);
    $b = TermSubscription::register('ts-b', 'org-1', 'domain-com', 'b.com', yr(2), new DateTimeImmutable('2026-06-01'), autoRenew: true);
    $c = TermSubscription::register('ts-c', 'org-1', 'domain-com', 'c.com', yr(1), new DateTimeImmutable('2025-11-01'), autoRenew: false);

    // Evaluate all three on the same day: each resolves its own phase from its own term end.
    $now = new DateTimeImmutable('2027-01-20');

    expect($lifecycle->phaseAt($a, windows(), $now))->toBe(TermSubscriptionStatus::Grace)       // ended 2027-01-15
        ->and($lifecycle->phaseAt($b, windows(), $now))->toBe(TermSubscriptionStatus::Active)    // ends 2028-06-01
        ->and($lifecycle->phaseAt($c, windows(), $now))->toBe(TermSubscriptionStatus::Expired);  // ended 2026-11-01, well past redemption

    // Distinct instances, distinct refs — no collision.
    expect([$a->instanceRef, $b->instanceRef, $c->instanceRef])->toEqualCanonicalizing(['a.com', 'b.com', 'c.com']);
});

it('refuses a (term x kind) price point the catalog does not offer', function () {
    $purchase = termPurchase();

    expect(fn () => $purchase->quote(domainProduct(), yr(5), PriceKind::Redemption, 1, dkConsumerContext(), new DateTimeImmutable('2026-01-01')))
        ->toThrow(TermPriceNotAvailable::class);
});
