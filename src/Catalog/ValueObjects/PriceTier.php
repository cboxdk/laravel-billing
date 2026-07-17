<?php

declare(strict_types=1);

namespace Cbox\Billing\Catalog\ValueObjects;

use Cbox\Billing\Catalog\Enums\PricingModel;
use Cbox\Billing\Catalog\Pricing\TierCalculator;
use Cbox\Billing\Money\Money;

/**
 * One tier (bracket) of a tiered {@see Price}. A tier covers the units from the end
 * of the previous tier up to and including `$upTo`; `$upTo = null` marks the final,
 * unbounded (∞) tier.
 *
 * Fields:
 *  - `$upTo`       — inclusive upper bound of the tier IN UNITS, or `null` for the
 *                    last tier (no cap). Bounded tiers must be strictly ascending.
 *  - `$unitAmount` — the per-unit price WITHIN this tier (integer minor units; may be
 *                    zero for a free bracket). Used by `Graduated` and `Volume`.
 *  - `$flatAmount` — an optional flat amount attached to the tier. Its meaning is set
 *                    by the {@see PricingModel}: for `Graduated`/`Volume` it is a flat
 *                    fee added when the quantity reaches/lands in the tier; for
 *                    `Package` it is the block price; for `Stairstep` it is the whole
 *                    bracket price (and `$unitAmount` is unused).
 *
 * Immutable. Ordering/positivity is enforced by {@see TierCalculator} (deny-by-default
 * on a malformed set) rather than silently coerced here.
 */
readonly class PriceTier
{
    public function __construct(
        public ?int $upTo,
        public Money $unitAmount,
        public ?Money $flatAmount = null,
    ) {}

    /** Whether `$quantity` falls at or below this tier's (inclusive) upper bound. */
    public function contains(int $quantity): bool
    {
        return $this->upTo === null || $quantity <= $this->upTo;
    }
}
