<?php

declare(strict_types=1);

use Cbox\Billing\Money\Money;
use Cbox\Billing\Pricing\CouponApplier;
use Cbox\Billing\Pricing\Enums\DiscountType;
use Cbox\Billing\Pricing\ValueObjects\Coupon;
use Cbox\Billing\Quote\Contracts\QuoteBuilder;
use Cbox\Billing\Quote\ValueObjects\LineInput;
use Cbox\Billing\Quote\ValueObjects\QuoteContext;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\ValueObjects\SellerRegistrations;

it('applies a percentage discount to the net', function () {
    $net = (new CouponApplier)->apply(
        Money::ofMinor(10000, 'EUR'),
        new Coupon('SAVE20', DiscountType::Percentage, percentage: 20),
        new DateTimeImmutable('2025-09-01'),
    );

    expect($net->minor())->toBe(8000);
});

it('applies a fixed discount, floored at zero', function () {
    $applier = new CouponApplier;
    $at = new DateTimeImmutable('2025-09-01');

    $some = $applier->apply(Money::ofMinor(10000, 'EUR'), new Coupon('OFF30', DiscountType::Fixed, amount: Money::ofMinor(3000, 'EUR')), $at);
    $over = $applier->apply(Money::ofMinor(2000, 'EUR'), new Coupon('OFF30', DiscountType::Fixed, amount: Money::ofMinor(3000, 'EUR')), $at);

    expect($some->minor())->toBe(7000)
        ->and($over->minor())->toBe(0);
});

it('ignores an out-of-window coupon', function () {
    $net = (new CouponApplier)->apply(
        Money::ofMinor(10000, 'EUR'),
        new Coupon('EXPIRED', DiscountType::Percentage, percentage: 50, validUntil: new DateTimeImmutable('2025-01-01')),
        new DateTimeImmutable('2025-09-01'),
    );

    expect($net->minor())->toBe(10000);
});

it('discounts before tax, end to end', function () {
    $geo = $this->app->make(JurisdictionRepository::class);
    $builder = $this->app->make(QuoteBuilder::class);

    $discounted = (new CouponApplier)->apply(
        Money::ofMinor(10000, 'EUR'),
        new Coupon('SAVE20', DiscountType::Percentage, percentage: 20),
        new DateTimeImmutable('2025-09-01'),
    );

    $quote = $builder->build(
        [new LineInput('Pro plan', 1, $discounted)],
        new QuoteContext($geo->find(new CountryCode('DK')), CustomerType::Consumer, new SellerRegistrations(new CountryCode('DK'))),
    );

    // 100 - 20% = 80 net; + 25% DK VAT = 100.00 gross
    expect($quote->totals->net->minor())->toBe(8000)
        ->and($quote->totals->gross->minor())->toBe(10000);
});
