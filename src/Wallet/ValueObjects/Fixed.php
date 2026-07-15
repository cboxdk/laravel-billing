<?php

declare(strict_types=1);

namespace Cbox\Billing\Wallet\ValueObjects;

use Cbox\Billing\Wallet\Contracts\GrantAmount;
use Cbox\Billing\Wallet\Enums\GrantCadence;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Grant a fixed `amount` at each cadence boundary in the billing period (ADR-0014):
 * a `Fixed(1_000, Monthly)` grants 1,000 every month, regardless of how many months
 * the period holds. The per-period cost is authored per slice; distribution
 * ({@see Distributed}) is the alternative that splits a period total instead.
 */
readonly class Fixed implements GrantAmount
{
    public function __construct(
        public int $amount,
        public GrantCadence $cadence,
    ) {
        if ($amount < 0) {
            throw new InvalidArgumentException('A Fixed grant amount cannot be negative.');
        }
    }

    public function cadence(): GrantCadence
    {
        return $this->cadence;
    }

    public function slices(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $slices = [];
        foreach ($this->cadence->periods($start, $end) as [$sliceStart, $sliceEnd]) {
            $slices[] = new GrantSlice(
                boundaryMs: self::ms($sliceStart),
                amount: $this->amount,
                periodEndMs: self::ms($sliceEnd),
            );
        }

        return $slices;
    }

    private static function ms(DateTimeImmutable $at): int
    {
        return $at->getTimestamp() * 1000;
    }
}
