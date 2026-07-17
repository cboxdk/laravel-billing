<?php

declare(strict_types=1);

namespace Cbox\Billing\Reporting\ValueObjects;

/**
 * A retention ratio held as an exact minor-unit fraction (numerator/denominator) so
 * it never suffers float drift. The denominator is the starting-cohort MRR in minor
 * units; the numerator is the retained MRR in minor units. Basis points are derived
 * with integer rounding (half away from zero) on demand.
 *
 * A non-positive denominator means retention is undefined (no cohort to retain); the
 * ratio then reports zero rather than dividing by zero.
 */
readonly class RetentionRatio
{
    public function __construct(
        public int $numerator,
        public int $denominator,
    ) {}

    public function isDefined(): bool
    {
        return $this->denominator > 0;
    }

    /**
     * The ratio in basis points (10000 = 100%), rounded half away from zero. Returns
     * 0 when the ratio is undefined.
     */
    public function basisPoints(): int
    {
        if ($this->denominator <= 0) {
            return 0;
        }

        $scaled = $this->numerator * 10000;
        $quotient = intdiv($scaled, $this->denominator);
        $remainder = $scaled - $quotient * $this->denominator;

        // Round half away from zero. The denominator is positive, so the remainder
        // carries the sign of the numerator.
        if (abs($remainder) * 2 >= $this->denominator) {
            $quotient += $scaled <=> 0;
        }

        return $quotient;
    }

    /**
     * The ratio as a float — lossy, for display only. Prefer {@see basisPoints()} or
     * the raw numerator/denominator for any comparison or storage.
     */
    public function toFloat(): float
    {
        if ($this->denominator <= 0) {
            return 0.0;
        }

        return $this->numerator / $this->denominator;
    }
}
