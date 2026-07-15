<?php

declare(strict_types=1);

namespace Cbox\Billing\Quote\ValueObjects;

use Cbox\Billing\Money\Money;
use Cbox\Tax\Enums\TaxTreatment;

/**
 * A priced quote line with its tax split. While tax is pending, `treatment` and
 * `taxRatePercentage` are null and `tax` is zero — `taxNote` explains why.
 */
readonly class QuoteLine
{
    public function __construct(
        public string $description,
        public int $quantity,
        public Money $net,
        public Money $tax,
        public Money $gross,
        public ?TaxTreatment $treatment,
        public ?string $taxRatePercentage,
        public string $taxNote,
    ) {}
}
