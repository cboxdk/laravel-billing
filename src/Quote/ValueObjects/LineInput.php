<?php

declare(strict_types=1);

namespace Cbox\Billing\Quote\ValueObjects;

use Cbox\Billing\Money\Money;
use Cbox\Tax\Enums\TaxCategory;

/**
 * A requested quote line: what is being bought, how many, and the unit amount (net
 * or gross per the quote's pricing mode). The catalog resolves prices into these.
 */
readonly class LineInput
{
    public function __construct(
        public string $description,
        public int $quantity,
        public Money $unitAmount,
        public TaxCategory $category = TaxCategory::Standard,
    ) {}
}
