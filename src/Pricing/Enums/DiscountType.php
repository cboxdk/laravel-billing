<?php

declare(strict_types=1);

namespace Cbox\Billing\Pricing\Enums;

/**
 * How a coupon reduces an amount: a percentage of it, or a fixed money amount off.
 */
enum DiscountType: string
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';
}
