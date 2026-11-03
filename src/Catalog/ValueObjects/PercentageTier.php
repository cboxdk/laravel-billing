<?php

declare(strict_types=1);

namespace Cbox\Billing\Catalog\ValueObjects;

/**
 * One bracket of a percentage price, by volume. It covers the volume from the end of the previous
 * tier up to and including `$upTo`, in the price currency's minor units; `null` marks the last,
 * unbounded tier.
 */
final readonly class PercentageTier
{
    public function __construct(
        public ?int $upTo,
        public PercentageRate $rate,
    ) {}

    public function contains(int $volume): bool
    {
        return $this->upTo === null || $volume <= $this->upTo;
    }
}
