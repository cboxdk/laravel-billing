<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\Storage;

use Cbox\Billing\Metering\Contracts\EventLog;
use Cbox\Billing\Metering\ValueObjects\UsageEvent;

/**
 * In-memory {@see EventLog} — for tests and zero-config use. Dedups by event id;
 * sums by filtering. Not durable — production uses {@see DatabaseEventLog} or a
 * ClickHouse adapter.
 */
class InMemoryEventLog implements EventLog
{
    /** @var array<string, UsageEvent> keyed by event id */
    private array $events = [];

    public function append(array $events): int
    {
        $appended = 0;

        foreach ($events as $event) {
            if (! isset($this->events[$event->id])) {
                $this->events[$event->id] = $event;
                $appended++;
            }
        }

        return $appended;
    }

    public function sum(string $org, string $meter, int $fromMs, int $toMs): int
    {
        $sum = 0;

        foreach ($this->events as $event) {
            if ($event->org === $org
                && $event->meter === $meter
                && $event->occurredAt >= $fromMs
                && $event->occurredAt <= $toMs
            ) {
                $sum += $event->value;
            }
        }

        return $sum;
    }
}
