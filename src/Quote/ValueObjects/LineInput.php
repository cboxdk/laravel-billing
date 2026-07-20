<?php

declare(strict_types=1);

namespace Cbox\Billing\Quote\ValueObjects;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Quote\Exceptions\InvalidQuoteLine;
use Cbox\Tax\Enums\TaxCategory;

/**
 * A requested quote line: what is being bought, how many, and the unit amount (net
 * or gross per the quote's pricing mode). The catalog resolves prices into these.
 *
 * The quantity is validated positive at construction: a zero or negative quantity
 * would multiply a flat/per-unit price into a zero or negative charge, so it is
 * refused here (deny-by-default) rather than producing a wrong invoice downstream.
 */
readonly class LineInput
{
    public function __construct(
        public string $description,
        public int $quantity,
        public Money $unitAmount,
        public TaxCategory $category = TaxCategory::Standard,
    ) {
        if ($quantity <= 0) {
            throw InvalidQuoteLine::nonPositiveQuantity($quantity);
        }
    }
}
