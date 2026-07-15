<?php

declare(strict_types=1);

namespace Cbox\Billing\Reporting\ValueObjects;

/**
 * Recurring-revenue report: one {@see MrrLine} per currency (revenue in different
 * currencies is never summed).
 */
readonly class RevenueReport
{
    /**
     * @param  list<MrrLine>  $lines
     */
    public function __construct(public array $lines) {}

    public function lineFor(string $currency): ?MrrLine
    {
        foreach ($this->lines as $line) {
            if ($line->currency === $currency) {
                return $line;
            }
        }

        return null;
    }
}
