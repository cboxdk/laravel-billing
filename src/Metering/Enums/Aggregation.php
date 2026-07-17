<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\Enums;

use Cbox\Billing\Metering\Contracts\EventLog;
use Cbox\Billing\Metering\ValueObjects\UsageEvent;

/**
 * How a meter's raw {@see UsageEvent}s over a period collapse into ONE billable
 * quantity — the billable-metric aggregation, resolved by {@see EventLog::aggregate()}.
 *
 *  - `Count`       — the number of events (each event is one unit, `value` ignored).
 *  - `Sum`         — the sum of every event's `value` (the classic usage total).
 *  - `Max`         — the largest single `value` in the window (e.g. peak seats).
 *  - `UniqueCount` — the number of DISTINCT `uniqueKey`s (e.g. unique active users);
 *                    events with a null `uniqueKey` do not contribute a distinct key.
 *  - `Latest`      — the `value` of the most recent event (greatest `occurredAt`); a
 *                    gauge's last reading.
 *  - `WeightedSum` — the sum of `value × weight` (a cost-weighted total).
 */
enum Aggregation: string
{
    case Count = 'count';
    case Sum = 'sum';
    case Max = 'max';
    case UniqueCount = 'unique_count';
    case Latest = 'latest';
    case WeightedSum = 'weighted_sum';
}
