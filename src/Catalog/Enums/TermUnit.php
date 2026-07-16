<?php

declare(strict_types=1);

namespace Cbox\Billing\Catalog\Enums;

use Cbox\Billing\Catalog\ValueObjects\Term;

/**
 * The calendar unit a fixed-term {@see Term} counts in.
 * Maps to the ISO-8601 duration designator used to build the term's `DateInterval`.
 */
enum TermUnit: string
{
    case Day = 'day';
    case Month = 'month';
    case Year = 'year';

    /** The ISO-8601 duration designator for this unit (`D`, `M`, `Y`). */
    public function iso8601Designator(): string
    {
        return match ($this) {
            self::Day => 'D',
            self::Month => 'M',
            self::Year => 'Y',
        };
    }
}
