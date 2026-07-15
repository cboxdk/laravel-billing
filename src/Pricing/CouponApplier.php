<?php

declare(strict_types=1);

namespace Cbox\Billing\Pricing;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Pricing\Enums\DiscountType;
use Cbox\Billing\Pricing\ValueObjects\Coupon;
use DateTimeImmutable;

/**
 * Applies a coupon to a net amount, before tax. An out-of-window coupon is a no-op
 * (returns the amount unchanged); a fixed discount never takes the amount below zero.
 */
readonly class CouponApplier
{
    public function apply(Money $net, Coupon $coupon, DateTimeImmutable $at): Money
    {
        if (! $coupon->isValidAt($at)) {
            return $net;
        }

        if ($coupon->type === DiscountType::Percentage) {
            return $net->proratedBy(100 - $coupon->percentage, 100);
        }

        $amount = $coupon->amount ?? Money::zero($net->currency());
        $discounted = $net->minus($amount);

        return $discounted->isNegative() ? Money::zero($net->currency()) : $discounted;
    }
}
