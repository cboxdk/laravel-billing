<?php

declare(strict_types=1);

namespace Cbox\Billing\Pricing\ValueObjects;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Pricing\Enums\DiscountType;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A discount coupon: a percentage off, or a fixed amount off, valid over an
 * optional date window. Applied to the net (taxable) amount before tax.
 */
readonly class Coupon
{
    public function __construct(
        public string $code,
        public DiscountType $type,
        public int $percentage = 0,
        public ?Money $amount = null,
        public ?DateTimeImmutable $validFrom = null,
        public ?DateTimeImmutable $validUntil = null,
    ) {
        if ($type === DiscountType::Percentage && ($percentage < 0 || $percentage > 100)) {
            throw new InvalidArgumentException('A percentage coupon must be between 0 and 100.');
        }

        if ($type === DiscountType::Fixed && $amount === null) {
            throw new InvalidArgumentException('A fixed coupon requires an amount.');
        }
    }

    public function isValidAt(DateTimeImmutable $at): bool
    {
        return ($this->validFrom === null || $at >= $this->validFrom)
            && ($this->validUntil === null || $at < $this->validUntil);
    }
}
