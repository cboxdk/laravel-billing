<?php

declare(strict_types=1);

namespace Cbox\Billing\Reporting\ValueObjects;

/**
 * An MRR-movement decomposition across every currency present in the input: one
 * {@see MrrWaterfall} per currency (revenue in different currencies is never
 * summed). Waterfalls are ordered by currency code for determinism.
 */
readonly class MrrMovementReport
{
    /**
     * @param  list<MrrWaterfall>  $waterfalls
     */
    public function __construct(public array $waterfalls) {}

    public function waterfallFor(string $currency): ?MrrWaterfall
    {
        foreach ($this->waterfalls as $waterfall) {
            if ($waterfall->currency === $currency) {
                return $waterfall;
            }
        }

        return null;
    }
}
