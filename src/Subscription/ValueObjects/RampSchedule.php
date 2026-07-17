<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\ValueObjects;

use Cbox\Billing\Money\Money;
use InvalidArgumentException;

/**
 * A ramp deal: an ordered set of {@see RampStep}s that steps a subscription's recurring
 * price over its term (e.g. 100/mo for periods 0–2, then 150/mo for 3–11). A ramp is a
 * *predetermined* schedule of price changes — rather than scheduling each change by
 * hand, the schedule is resolved at every renewal from the subscription's period index.
 *
 * The step covering a period index is the one with the greatest `fromPeriodIndex` that
 * is `<= index`, so the amount holds until the next step's boundary is reached. The
 * schedule must start at index 0 (there is always an opening amount) and its steps are
 * kept sorted by `fromPeriodIndex`.
 */
readonly class RampSchedule
{
    /** @var list<RampStep> */
    public array $steps;

    /**
     * @param  list<RampStep>  $steps
     */
    public function __construct(array $steps)
    {
        if ($steps === []) {
            throw new InvalidArgumentException('A ramp schedule needs at least one step.');
        }

        usort($steps, static fn (RampStep $a, RampStep $b): int => $a->fromPeriodIndex <=> $b->fromPeriodIndex);

        if ($steps[0]->fromPeriodIndex !== 0) {
            throw new InvalidArgumentException('A ramp schedule must define a step from period index 0.');
        }

        $seen = [];
        foreach ($steps as $step) {
            if (isset($seen[$step->fromPeriodIndex])) {
                throw new InvalidArgumentException("Duplicate ramp step for period index {$step->fromPeriodIndex}.");
            }
            $seen[$step->fromPeriodIndex] = true;
        }

        $this->steps = $steps;
    }

    /**
     * The effective recurring amount for `$periodIndex`: the amount of the step covering
     * it (the greatest `fromPeriodIndex <= $periodIndex`). A negative index resolves to
     * the opening step.
     */
    public function amountForPeriod(int $periodIndex): Money
    {
        $amount = $this->steps[0]->amount;

        foreach ($this->steps as $step) {
            if ($step->fromPeriodIndex <= $periodIndex) {
                $amount = $step->amount;

                continue;
            }

            break;
        }

        return $amount;
    }
}
