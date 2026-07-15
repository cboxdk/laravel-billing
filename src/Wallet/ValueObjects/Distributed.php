<?php

declare(strict_types=1);

namespace Cbox\Billing\Wallet\ValueObjects;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Wallet\Contracts\GrantAmount;
use Cbox\Billing\Wallet\Enums\GrantCadence;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Split a billing-period `total` evenly across the cadence slices in the period,
 * remainder-safe (ADR-0014). A yearly period with `Distributed(1_200_000, Monthly)`
 * drips 12 × 100,000; `Distributed(1_200_000, Daily)` drips one slice per ACTUAL day
 * of that year, the slices summing to EXACTLY 1,200,000 — the remainder is spread one
 * unit at a time ({@see Money::allocate}), never dropped or duplicated, so leap years
 * and 30/31-day months stay drift-free.
 */
readonly class Distributed implements GrantAmount
{
    public function __construct(
        public int $total,
        public GrantCadence $cadence,
    ) {
        if ($total < 0) {
            throw new InvalidArgumentException('A Distributed grant total cannot be negative.');
        }
    }

    public function cadence(): GrantCadence
    {
        return $this->cadence;
    }

    public function slices(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $periods = $this->cadence->periods($start, $end);
        $amounts = Money::allocate($this->total, count($periods));

        $slices = [];
        foreach ($periods as $i => [$sliceStart, $sliceEnd]) {
            $slices[] = new GrantSlice(
                boundaryMs: self::ms($sliceStart),
                amount: $amounts[$i],
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
