<?php

declare(strict_types=1);

namespace Cbox\Billing\Catalog\ValueObjects;

use Cbox\Billing\Money\Money;

/**
 * What a percentage price charged for one period, and how: the volume and fee units it read, the
 * fixed fees (exact, no rounding), and the total, rounded once. `$bound` says whether the minimum
 * or the maximum set the total, so an invoice can say so.
 */
final readonly class PercentageCharge
{
    /** @param  'minimum'|'maximum'|null  $bound */
    public function __construct(
        public int $volume,
        public int $feeUnits,
        public Money $fees,
        public Money $total,
        public ?string $bound = null,
    ) {}
}
