<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\PlanChange;

use Cbox\Billing\Money\Money;

/**
 * The remainder-safe proration of a per-cycle credit allotment down to the days still
 * to run (ADR-0012's `Prorated` credit mode). It reuses the engine's drift-free split
 * primitive {@see Money::allocate()}: the allotment is split into one integer unit per
 * day of the cycle — the parts summing to EXACTLY the allotment — and the grant is the
 * sum of the days that remain.
 *
 * Remainder-safe proof: `Money::allocate($allotment, $total)` returns `$total` integers
 * `d[0..total-1]` that sum to exactly `$allotment`, each either `floor(allotment/total)`
 * or one more (the remainder is spread one unit at a time over the earliest days, never
 * dropped or duplicated). The remaining grant is the suffix `sum(d[elapsed..total-1])`
 * and the elapsed portion is the prefix `sum(d[0..elapsed-1])`; because
 * `elapsed + remaining = total`, prefix + suffix reconstruct the whole allotment with
 * **no unit lost and none granted twice**. Taking the suffix (the later, floor-valued
 * days) also guarantees the prorated grant never exceeds `allotment × remaining / total`,
 * so a frequent upgrade cannot over-grant.
 */
class ProratedAllotment
{
    /**
     * The remainder-safe share of `$allotment` attributable to `$remainingDays` of a
     * `$totalDays` cycle. A whole (or longer) remainder grants the full allotment; a
     * non-positive remainder or a degenerate cycle grants nothing.
     */
    public static function remainingShare(int $allotment, int $remainingDays, int $totalDays): int
    {
        $allotment = max(0, $allotment);

        if ($totalDays <= 0 || $remainingDays >= $totalDays) {
            return $allotment;
        }

        if ($remainingDays <= 0) {
            return 0;
        }

        $perDay = Money::allocate($allotment, $totalDays);
        $elapsed = $totalDays - $remainingDays;

        return array_sum(array_slice($perDay, $elapsed));
    }
}
